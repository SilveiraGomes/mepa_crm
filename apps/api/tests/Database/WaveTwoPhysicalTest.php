<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Domain\Membership\MemberNumberGenerator;
use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

// Uses the real Laravel Migrator, isolated Capsule, and explicit environment only.
// Never boot the application or read .env. The database must already be empty.
// setUpBeforeClass establishes scaffolding + Wave 1 (an "already deployed Wave 1" baseline),
// so test_migrate_rollback_and_remigrate exercises Wave 2's own migrate/rollback/remigrate
// lifecycle on top of it - the Path B (upgrade) scenario. Path A (fresh, all 79 in one run)
// was separately proven via `php artisan migrate` against a virgin database.
final class WaveTwoPhysicalTest extends TestCase
{
    private static Manager $capsule;
    private static Migrator $migrator;
    private static array $wave1Manifest;
    private static array $wave2Manifest;
    private static array $wave1Paths;
    private static array $wave2Paths;
    private static string $root;
    private static bool $ready = false;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 4);
        $dsn = getenv('WAVE2_DSN') ?: '';
        if (getenv('WAVE2_ALLOW_SYNTHETIC') !== '1' || !$dsn) {
            self::markTestSkipped('BLOCKED FOR EXECUTION: explicit authorized MySQL WAVE2_DSN and WAVE2_ALLOW_SYNTHETIC=1 required.');
        }
        $parts=[];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if (str_contains($part, '=')) { [$key,$value]=explode('=',$part,2); $parts[$key]=$value; }
        }
        if (!str_starts_with($dsn,'mysql:') || !preg_match('/^mepa_wave2_test_[a-z0-9_]+$/D',$parts['dbname']??'')
            || !in_array($parts['host']??'',['127.0.0.1','localhost','::1'],true)) {
            self::fail('Refusing physical tests: require loopback and an empty mepa_wave2_test_* database.');
        }
        $env=getenv();
        $env['DB_CAPABILITIES_DSN']=$dsn;
        $env['DB_CAPABILITIES_USER']=getenv('WAVE2_USER')?:'';
        $env['DB_CAPABILITIES_PASSWORD']=getenv('WAVE2_PASSWORD')?:'';
        $process=proc_open([PHP_BINARY,self::$root.'/scripts/check-database-capabilities.php'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,self::$root,$env);
        if (!is_resource($process)) self::fail('Could not execute mandatory existing preflight');
        fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
        $preflight=json_decode($out,true);
        if ($exit!==0 || ($preflight['engine']['family']??'')!=='MySQL') {
            self::fail('Preflight blocked physical tests. Exit '.$exit.'; '.($preflight['status']??trim($err)));
        }
        self::$capsule=new Manager;
        self::$capsule->addConnection(['driver'=>'mysql','host'=>$parts['host'],'port'=>$parts['port']??3306,
            'database'=>$parts['dbname'],'username'=>getenv('WAVE2_USER')?:'', 'password'=>getenv('WAVE2_PASSWORD')?:'',
            'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'timezone'=>'+00:00',
            'options'=>[\PDO::ATTR_EMULATE_PREPARES=>false]]);
        $db=self::$capsule->getConnection();
        self::assertSame([], $db->select('SHOW TABLES'), 'Physical tests require an empty database; no automatic destructive reset.');
        DB::swap(self::$capsule->getDatabaseManager());
        Schema::swap($db->getSchemaBuilder());
        self::$wave1Manifest=json_decode(file_get_contents(self::$root.'/docs/database/physical/wave1_manifest.json'),true,512,JSON_THROW_ON_ERROR);
        self::$wave2Manifest=json_decode(file_get_contents(self::$root.'/docs/database/physical/wave2_manifest.json'),true,512,JSON_THROW_ON_ERROR);
        self::$wave1Paths=array_map(static fn($m)=>self::$root.'/apps/api/database/migrations/'.$m['file'],self::$wave1Manifest['migrations']);
        self::$wave2Paths=array_map(static fn($f)=>self::$root.'/apps/api/database/migrations/'.$f,self::$wave2Manifest['migrations']);
        $repository=new DatabaseMigrationRepository(self::$capsule->getDatabaseManager(),'migrations');
        $repository->createRepository();
        self::$migrator=new Migrator($repository,self::$capsule->getDatabaseManager(),new Filesystem);
        $scaffolding=array_map(static fn($file)=>self::$root.'/apps/api/database/migrations/'.$file,[
            '2014_10_12_000000_create_users_table.php','2014_10_12_100000_create_password_resets_table.php',
            '2019_08_19_000000_create_failed_jobs_table.php','2019_12_14_000001_create_personal_access_tokens_table.php']);
        self::assertCount(4,self::$migrator->run($scaffolding));
        self::assertCount(31,self::$migrator->run(self::$wave1Paths));
        self::$ready=true;
    }

    protected function setUp(): void
    {
        if (self::$ready && $this->getName(false)!=='test_migrate_rollback_and_remigrate') self::$capsule->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$ready) while (self::$capsule->getConnection()->transactionLevel()>0) self::$capsule->getConnection()->rollBack();
    }

    public function test_migrate_rollback_and_remigrate(): void
    {
        $db=self::$capsule->getConnection();
        $this->assertCount(44,self::$migrator->run(self::$wave2Paths));
        foreach(self::$wave2Manifest['tables'] as $table)$this->assertTrue(Schema::hasTable($table));
        $this->assertTrue(Schema::hasColumn('users','account_kind'));
        $fk=$db->select("SELECT DELETE_RULE,UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_files_owner_department_id'");
        $this->assertCount(1,$fk);$this->assertSame('RESTRICT',$fk[0]->DELETE_RULE);$this->assertSame('RESTRICT',$fk[0]->UPDATE_RULE);

        $this->assertCount(44,self::$migrator->rollback(self::$wave2Paths));
        foreach(self::$wave2Manifest['tables'] as $table)if($table!=='users')$this->assertFalse(Schema::hasTable($table));
        $this->assertFalse(Schema::hasColumn('users','account_kind'),'users reverted to Wave 1 scaffolding shape');
        $this->assertTrue(Schema::hasColumn('files','owner_department_id'),'Wave 1 column survives Wave 2 rollback');
        $fkGone=$db->select("SELECT 1 x FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_files_owner_department_id'");
        $this->assertSame([],$fkGone,'W1-F01 correctly un-materialized after Wave 2 rollback');
        foreach(self::$wave1Manifest['tables'] as $table)$this->assertTrue(Schema::hasTable($table),'Wave 1 survives Wave 2 rollback');

        $this->assertCount(44,self::$migrator->run(self::$wave2Paths));
        foreach(self::$wave2Manifest['tables'] as $table)$this->assertTrue(Schema::hasTable($table));
    }

    // ---- fixture helpers (mirroring WaveOnePhysicalTest's style) ----

    private function lookup(string $table, string $code): int
    {
        return self::$capsule->getConnection()->table($table)->insertGetId(['code'=>$code,'name'=>'Synthetic fixture',
            'is_active'=>1,'created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function person(string $name): int
    {
        $status=$this->lookup('person_statuses','TEST_'.strtoupper(bin2hex(random_bytes(5))));
        return self::$capsule->getConnection()->table('people')->insertGetId(['public_id'=>(string)Str::ulid(),
            'full_name'=>$name,'birth_precision'=>'UNKNOWN','status_id'=>$status,'created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private array $unitTypeCache = [];

    private function unitTypeId(string $typeCode): int
    {
        return $this->unitTypeCache[$typeCode] ??= $this->lookup('organizational_unit_types',$typeCode);
    }

    private function unit(string $typeCode, ?int $parentId, string $code, ?int $municipalityId = null): int
    {
        return self::$capsule->getConnection()->table('organizational_units')->insertGetId(['public_id'=>(string)Str::ulid(),
            'unit_type_id'=>$this->unitTypeId($typeCode),'parent_id'=>$parentId,'municipality_id'=>$municipalityId,'code'=>$code,
            'name'=>'Synthetic '.$code,'status'=>'ACTIVE','created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function membership(int $personId, ?string $approvedAt = null): int
    {
        $status=$this->lookup('membership_statuses','TEST_'.strtoupper(bin2hex(random_bytes(5))));
        return self::$capsule->getConnection()->table('memberships')->insertGetId(['public_id'=>(string)Str::ulid(),
            'person_id'=>$personId,'status_id'=>$status,'date_precision'=>'EXACT','approved_at'=>$approvedAt,
            'origin'=>'APPROVED_ADMISSION','created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function user(): int
    {
        return self::$capsule->getConnection()->table('users')->insertGetId(['account_kind'=>'SERVICE',
            'public_id'=>(string)Str::ulid(),'login'=>'synthetic_'.strtolower(bin2hex(random_bytes(6))),
            'password_hash'=>'synthetic','status'=>'ACTIVE','mfa_required'=>0,'created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function workflowInstance(int $unitId): int
    {
        $workflow=self::$capsule->getConnection()->table('workflows')->insertGetId(['code'=>'TEST_WF_'.strtoupper(bin2hex(random_bytes(3))),
            'version'=>1,'name'=>'Synthetic','status'=>'ACTIVE','created_at'=>'2026-09-13 10:00:00.123456']);
        return self::$capsule->getConnection()->table('workflow_instances')->insertGetId(['public_id'=>(string)Str::ulid(),
            'workflow_id'=>$workflow,'unit_id'=>$unitId,'requested_by'=>$this->user(),'status'=>'COMPLETED',
            'created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function seedSequence(int $lastValue = 0): void
    {
        self::$capsule->getConnection()->table('member_number_sequences')->insert([
            'code'=>'MEPA_NATIONAL','last_value'=>$lastValue,'created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function department(string $code): int
    {
        $category=$this->lookup('department_categories','TEST_CAT_'.strtoupper(bin2hex(random_bytes(3))));
        return self::$capsule->getConnection()->table('departments')->insertGetId(['category_id'=>$category,
            'code'=>$code,'name'=>'Synthetic '.$code,'status'=>'ACTIVE','created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function departmentInstance(int $departmentId, int $unitId): int
    {
        return self::$capsule->getConnection()->table('department_instances')->insertGetId(['public_id'=>(string)Str::ulid(),
            'department_id'=>$departmentId,'unit_id'=>$unitId,'status'=>'ACTIVE','created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function departmentPost(int $instanceId, int $positionId, string $occupancyStatus = 'VACANT'): int
    {
        return self::$capsule->getConnection()->table('department_posts')->insertGetId(['public_id'=>(string)Str::ulid(),
            'instance_id'=>$instanceId,'position_id'=>$positionId,'slot'=>1,'occupancy_status'=>$occupancyStatus,
            'created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function departmentAppointment(int $postId, int $personId, string $startsAt, ?string $endsAt = null): int
    {
        return self::$capsule->getConnection()->table('department_appointments')->insertGetId(['public_id'=>(string)Str::ulid(),
            'post_id'=>$postId,'person_id'=>$personId,'appointment_kind'=>'SUBSTANTIVE','status'=>$endsAt?'ENDED':'ACTIVE',
            'starts_at'=>$startsAt,'ends_at'=>$endsAt,'created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function rejects(callable $write, int $expected): void
    {
        try { $write(); $this->fail('Database accepted invalid fixture'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertSame($expected,(int)($e->errorInfo[1]??0)); }
    }

    /** Same parametrized lookup for both Congregation- and Município-level "who directs X" - proves
     * department definition reuse across territorial levels needs no special-casing per level. */
    private function findDirector(string $departmentCode, int $unitId): ?string
    {
        $row=self::$capsule->getConnection()->select(
            'SELECT p.full_name AS name FROM department_instances di
             JOIN departments d ON d.id = di.department_id AND d.code = ?
             JOIN department_posts dp ON dp.instance_id = di.id
             JOIN positions pos ON pos.id = dp.position_id AND pos.code = ?
             JOIN department_appointments da ON da.post_id = dp.id AND da.status = ? AND da.ends_at IS NULL
             JOIN people p ON p.id = da.person_id
             WHERE di.unit_id = ?',
            [$departmentCode, 'DIRECTOR', 'ACTIVE', $unitId]
        );
        return $row[0]->name ?? null;
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_person_exists_independently_of_membership(): void
    {
        $db=self::$capsule->getConnection();
        $person=$this->person('Synthetic non-member person');
        $this->assertSame(1,$db->table('people')->count());
        $this->assertSame(0,$db->table('memberships')->count());
        $this->assertSame(0,$db->table('users')->count());
        $this->membership($person);
        $this->rejects(fn()=>$db->table('memberships')->insert(['public_id'=>(string)Str::ulid(),'person_id'=>$person,
            'status_id'=>$this->lookup('membership_statuses','TEST_DUP'),'date_precision'=>'EXACT',
            'origin'=>'APPROVED_ADMISSION','created_at'=>'2026-09-13 10:00:00.123456']),1062);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_legacy_member_number_coexists_without_becoming_identifier(): void
    {
        $db=self::$capsule->getConnection();
        $person=$this->person('Synthetic legacy-number member');
        $membership=$this->membership($person,'2026-09-13 09:00:00.000000');
        $this->seedSequence(144);
        $generator=new MemberNumberGenerator($db);
        $result=$generator->generateFor($membership,new DateTimeImmutable('2026-09-13'));
        $this->assertSame('MEPA2609000145',$result['number']);
        $db->table('legacy_member_numbers')->insert(['membership_id'=>$membership,'source_system'=>'LEGACY_BOOK_1998',
            'raw_number'=>'A-0042','normalized_number'=>'A0042','status'=>'MAPPED','created_at'=>'2026-09-13 10:00:00.123456']);
        $this->assertSame(1,$db->table('legacy_member_numbers')->count());
        $this->assertSame(1,$db->table('member_numbers')->where('membership_id',$membership)->count());
        // Legacy coexists but never becomes PK/FK target for anything else, and does not collide
        // with a different membership using the same raw_number under a different source system.
        $otherPerson=$this->person('Synthetic other legacy member');
        $otherMembership=$this->membership($otherPerson,'2026-09-13 09:00:00.000000');
        $db->table('legacy_member_numbers')->insert(['membership_id'=>$otherMembership,'source_system'=>'LEGACY_BOOK_2005',
            'raw_number'=>'A-0042','normalized_number'=>'A0042','status'=>'MAPPED','created_at'=>'2026-09-13 10:00:00.123456']);
        $this->assertSame(2,$db->table('legacy_member_numbers')->count());
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_transfer_preserves_person_and_member_number_with_history(): void
    {
        $db=self::$capsule->getConnection();
        $province=$this->unit('PROVINCIAL_DIRECTION',null,'TEST_PROV');
        $municipality=$this->unit('MUNICIPAL_DIRECTION',$province,'TEST_MUN');
        $centerA=$this->unit('CENTER',$municipality,'TEST_CENTER_A');
        $centerB=$this->unit('CENTER',$municipality,'TEST_CENTER_B');
        $congA=$this->unit('CONGREGATION',$centerA,'TEST_CONG_A');
        $congB=$this->unit('CONGREGATION',$centerB,'TEST_CONG_B');

        $person=$this->person('Synthetic transferred member');
        $membership=$this->membership($person,'2026-01-10 09:00:00.000000');
        $this->seedSequence(0);
        $generator=new MemberNumberGenerator($db);
        $number=$generator->generateFor($membership,new DateTimeImmutable('2026-01-10'))['number'];

        $periodA=$db->table('membership_periods')->insertGetId(['membership_id'=>$membership,'congregation_id'=>$congA,
            'status_id'=>$this->lookup('membership_statuses','TEST_ACTIVE'),'starts_at'=>'2026-01-10 09:00:00.000000',
            'created_at'=>'2026-09-13 10:00:00.123456']);
        $workflow=$this->workflowInstance($congA);
        $transfer=$db->table('transfers')->insertGetId(['public_id'=>(string)Str::ulid(),'membership_id'=>$membership,
            'origin_unit_id'=>$congA,'destination_unit_id'=>$congB,'requested_at'=>'2026-09-01 00:00:00.000000',
            'effective_at'=>'2026-09-13 00:00:00.000000','status'=>'COMPLETED','workflow_instance_id'=>$workflow,
            'created_at'=>'2026-09-13 10:00:00.123456']);
        $db->table('membership_periods')->where('id',$periodA)->update(['ends_at'=>'2026-09-13 00:00:00.000000']);
        $db->table('membership_periods')->insert(['membership_id'=>$membership,'congregation_id'=>$congB,
            'status_id'=>$this->lookup('membership_statuses','TEST_ACTIVE_2'),'starts_at'=>'2026-09-13 00:00:00.000000',
            'created_at'=>'2026-09-13 10:00:00.123456']);

        $this->assertSame(1,$db->table('people')->count(),'Transfer never creates a new Person');
        $this->assertSame($number,$db->table('member_numbers')->where('membership_id',$membership)->value('number'),'Member number survives the transfer');
        $this->assertSame($person,$db->table('memberships')->where('id',$membership)->value('person_id'));
        $this->assertSame(2,$db->table('membership_periods')->where('membership_id',$membership)->count(),'Origin history preserved alongside the new period');
        $this->assertNotNull($db->table('membership_periods')->where('id',$periodA)->value('ends_at'));
        $this->assertSame($congA,$db->table('membership_periods')->where('id',$periodA)->value('congregation_id'),'Congregation A row untouched, not deleted');
        $this->assertSame($congB,$db->table('transfers')->where('id',$transfer)->value('destination_unit_id'));
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_ministerial_history_preserved_across_class_change(): void
    {
        $db=self::$capsule->getConnection();
        $person=$this->person('Synthetic minister');
        $evangelist=$this->lookup('ministerial_classes','EVANGELIST');
        $pastor=$this->lookup('ministerial_classes','PASTOR');
        $periodEvangelist=$db->table('ministerial_class_periods')->insertGetId(['person_id'=>$person,'class_id'=>$evangelist,
            'status'=>'ACTIVE','starts_at'=>'2020-01-01 00:00:00.000000','created_at'=>'2026-09-13 10:00:00.123456']);
        $db->table('ministerial_class_periods')->where('id',$periodEvangelist)->update(['ends_at'=>'2026-09-01 00:00:00.000000','status'=>'ENDED']);
        $periodPastor=$db->table('ministerial_class_periods')->insertGetId(['person_id'=>$person,'class_id'=>$pastor,
            'status'=>'ACTIVE','starts_at'=>'2026-09-01 00:00:00.000000','created_at'=>'2026-09-13 10:00:00.123456']);

        $this->assertSame(2,$db->table('ministerial_class_periods')->where('person_id',$person)->count());
        $this->assertSame($evangelist,$db->table('ministerial_class_periods')->where('id',$periodEvangelist)->value('class_id'),'Old class period preserved, not overwritten');
        $this->assertSame('2026-09-01 00:00:00.000000',$db->table('ministerial_class_periods')->where('id',$periodEvangelist)->value('ends_at'));
        $this->assertNull($db->table('ministerial_class_periods')->where('id',$periodPastor)->value('ends_at'),'Current class period open-ended');
        // Temporal CHECK is real, but same-person overlap rejection is NOT a DB guarantee here
        // (no unique/exclusion constraint ties person_id to "at most one open period") - tracked
        // as application-layer, same discipline as Wave 1's five APPLICATION_ENFORCED skips.
        $this->rejects(fn()=>$db->table('ministerial_class_periods')->insert(['person_id'=>$person,'class_id'=>$pastor,
            'status'=>'ACTIVE','starts_at'=>'2026-09-01 00:00:00.000000','ends_at'=>'2026-09-01 00:00:00.000000',
            'created_at'=>'2026-09-13 10:00:00.123456']),3819);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_department_definition_reused_across_territorial_levels_without_duplication(): void
    {
        $db=self::$capsule->getConnection();
        $province=$this->unit('PROVINCIAL_DIRECTION',null,'TEST_PROV2');
        $municipality=$this->unit('MUNICIPAL_DIRECTION',$province,'TEST_MUN2');
        $center=$this->unit('CENTER',$municipality,'TEST_CENTER2');
        $congregation=$this->unit('CONGREGATION',$center,'TEST_CONG2');

        $department=$this->department('LOUVOR');
        $this->assertSame(1,$db->table('departments')->where('code','LOUVOR')->count());
        $atMunicipality=$this->departmentInstance($department,$municipality);
        $atCongregation=$this->departmentInstance($department,$congregation);

        $this->assertSame(1,$db->table('departments')->where('code','LOUVOR')->count(),'One definition, reused, never duplicated per level');
        $this->assertSame(2,$db->table('department_instances')->where('department_id',$department)->count());
        $this->assertSame([$department,$department],[
            $db->table('department_instances')->where('id',$atMunicipality)->value('department_id'),
            $db->table('department_instances')->where('id',$atCongregation)->value('department_id'),
        ]);
        // (department_id, unit_id) is the uniqueness boundary, not department_id alone.
        $this->rejects(fn()=>$db->table('department_instances')->insert(['public_id'=>(string)Str::ulid(),
            'department_id'=>$department,'unit_id'=>$congregation,'status'=>'ACTIVE','created_at'=>'2026-09-13 10:00:00.123456']),1062);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_vacant_post_representable_and_appointment_fills_then_reverts(): void
    {
        $db=self::$capsule->getConnection();
        $province=$this->unit('PROVINCIAL_DIRECTION',null,'TEST_PROV3');
        $congregation=$this->unit('CONGREGATION',$province,'TEST_CONG3');
        $department=$this->department('DIACONIA');
        $instance=$this->departmentInstance($department,$congregation);
        $position=$this->lookup('positions','DIRECTOR');
        $post=$this->departmentPost($instance,$position,'VACANT');

        $this->assertSame(0,$db->table('department_appointments')->where('post_id',$post)->count(),'SEM NOMEAÇÃO: vacant post, zero appointments, no placeholder Person/string');
        $this->assertSame('VACANT',$db->table('department_posts')->where('id',$post)->value('occupancy_status'));

        $person=$this->person('Synthetic director');
        $appointment=$this->departmentAppointment($post,$person,'2026-09-13 00:00:00.000000');
        $db->table('department_posts')->where('id',$post)->update(['occupancy_status'=>'FILLED']);
        $this->assertSame(1,$db->table('department_appointments')->where('post_id',$post)->where('status','ACTIVE')->count());
        $this->assertSame('FILLED',$db->table('department_posts')->where('id',$post)->value('occupancy_status'));

        $db->table('department_appointments')->where('id',$appointment)->update(['status'=>'ENDED','ends_at'=>'2026-12-01 00:00:00.000000']);
        $db->table('department_posts')->where('id',$post)->update(['occupancy_status'=>'VACANT']);
        $this->assertSame(1,$db->table('department_appointments')->where('post_id',$post)->count(),'Ended appointment preserved as history, not deleted');
        $this->assertSame('VACANT',$db->table('department_posts')->where('id',$post)->value('occupancy_status'));
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_named_queries_for_department_directors(): void
    {
        $db=self::$capsule->getConnection();
        $province=$this->unit('PROVINCIAL_DIRECTION',null,'TEST_PROV4');
        $municipality=$this->unit('MUNICIPAL_DIRECTION',$province,'TEST_MUN4');
        $centerA=$this->unit('CENTER',$municipality,'TEST_CENTER4A');
        $congA=$this->unit('CONGREGATION',$centerA,'TEST_CONG4A');
        $congB=$this->unit('CONGREGATION',$centerA,'TEST_CONG4B');

        $department=$this->department('EVANGELISMO');
        $position=$this->lookup('positions','DIRECTOR');

        $instanceCongA=$this->departmentInstance($department,$congA);
        $postCongA=$this->departmentPost($instanceCongA,$position,'FILLED');
        $directorA=$this->person('Synthetic director of Congregation A');
        $this->departmentAppointment($postCongA,$directorA,'2026-01-01 00:00:00.000000');

        $instanceCongB=$this->departmentInstance($department,$congB);
        $this->departmentPost($instanceCongB,$position,'VACANT');

        $instanceMunicipality=$this->departmentInstance($department,$municipality);
        $postMunicipality=$this->departmentPost($instanceMunicipality,$position,'FILLED');
        $directorMunicipality=$this->person('Synthetic director of Município');
        $this->departmentAppointment($postMunicipality,$directorMunicipality,'2026-01-01 00:00:00.000000');

        $this->assertSame('Synthetic director of Congregation A',$this->findDirector('EVANGELISMO',$congA));
        $this->assertNull($this->findDirector('EVANGELISMO',$congB),'Congregation B has no active director');
        $this->assertSame('Synthetic director of Município',$this->findDirector('EVANGELISMO',$municipality),'Same query shape works at Município level too - no per-level special-casing');

        $lackingDirector=$db->select(
            'WITH RECURSIVE descendants AS (
               SELECT id FROM organizational_units WHERE id = ?
               UNION ALL
               SELECT ou.id FROM organizational_units ou JOIN descendants d ON ou.parent_id = d.id
             )
             SELECT ou.id, ou.code FROM organizational_units ou
             JOIN organizational_unit_types ut ON ut.id = ou.unit_type_id AND ut.code = ?
             WHERE ou.id IN (SELECT id FROM descendants)
             AND NOT EXISTS (
               SELECT 1 FROM department_instances di
               JOIN departments d ON d.id = di.department_id AND d.code = ?
               JOIN department_posts dp ON dp.instance_id = di.id
               JOIN positions pos ON pos.id = dp.position_id AND pos.code = ?
               JOIN department_appointments da ON da.post_id = dp.id AND da.status = ? AND da.ends_at IS NULL
               WHERE di.unit_id = ou.id
             )',
            [$province,'CONGREGATION','EVANGELISMO','DIRECTOR','ACTIVE']
        );
        $this->assertCount(1,$lackingDirector);
        $this->assertSame('TEST_CONG4B',$lackingDirector[0]->code);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_member_number_unique_constraints_are_physical_not_only_application(): void
    {
        $db=self::$capsule->getConnection();
        $personA=$this->person('Synthetic uniqueness A');
        $personB=$this->person('Synthetic uniqueness B');
        $membershipA=$this->membership($personA,'2026-09-13 09:00:00.000000');
        $membershipB=$this->membership($personB,'2026-09-13 09:00:00.000000');
        $row=['membership_id'=>$membershipA,'number'=>'MEPA2609000001','sequence_value'=>1,'issued_year'=>2026,
            'issued_month'=>9,'issued_at'=>'2026-09-13 09:00:00.000000','origin'=>'APPROVED_ADMISSION',
            'created_at'=>'2026-09-13 10:00:00.123456'];
        $db->table('member_numbers')->insert($row);
        $this->rejects(fn()=>$db->table('member_numbers')->insert(array_replace($row,['membership_id'=>$membershipB])),1062);
        $this->rejects(fn()=>$db->table('member_numbers')->insert(array_replace($row,['number'=>'MEPA2609000002','membership_id'=>$membershipB])),1062);
        $this->rejects(fn()=>$db->table('member_numbers')->insert(array_replace($row,['number'=>'MEPA2609000002','sequence_value'=>1,'membership_id'=>$membershipB])),1062);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_member_number_generator_replay_rollback_and_boundaries(): void
    {
        $db=self::$capsule->getConnection();
        $generator=new MemberNumberGenerator($db);

        $unapproved=$this->membership($this->person('Synthetic unapproved'));
        try { $generator->generateFor($unapproved,new DateTimeImmutable('2026-09-13')); $this->fail('Expected rejection'); }
        catch (\RuntimeException $e) { $this->assertSame('MEMBERSHIP_NOT_APPROVED',$e->getMessage()); }

        try { $generator->generateFor(999999999,new DateTimeImmutable('2026-09-13')); $this->fail('Expected rejection'); }
        catch (\RuntimeException $e) { $this->assertSame('MEMBERSHIP_NOT_FOUND',$e->getMessage()); }

        $membership=$this->membership($this->person('Synthetic idempotent'),'2026-09-13 09:00:00.000000');
        $this->seedSequence(999);
        $first=$generator->generateFor($membership,new DateTimeImmutable('2026-09-13'));
        $second=$generator->generateFor($membership,new DateTimeImmutable('2026-09-13'));
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['number'],$second['number']);
        $this->assertSame(1,$db->table('member_numbers')->where('membership_id',$membership)->count());
        $this->assertSame(1000,(int)$db->table('member_number_sequences')->where('code','MEPA_NATIONAL')->value('last_value'));

        // Rollback safety: an outer transaction that gets rolled back must undo the whole allocation.
        $rollbackMembership=$this->membership($this->person('Synthetic rollback'),'2026-09-13 09:00:00.000000');
        $lastValueBefore=(int)$db->table('member_number_sequences')->where('code','MEPA_NATIONAL')->value('last_value');
        $db->beginTransaction();
        $generator->generateFor($rollbackMembership,new DateTimeImmutable('2026-09-13'));
        $db->rollBack();
        $this->assertSame(0,$db->table('member_numbers')->where('membership_id',$rollbackMembership)->count(),'Rolled-back allocation leaves no row');
        $this->assertSame($lastValueBefore,(int)$db->table('member_number_sequences')->where('code','MEPA_NATIONAL')->value('last_value'),'Rolled-back allocation does not advance the sequence');

        // Month and year boundary: the SSSSSS counter must never reset, only AA/MM label the period.
        $db->table('member_number_sequences')->where('code','MEPA_NATIONAL')->update(['last_value'=>145]);
        $sept=$this->membership($this->person('Synthetic Sept'),'2026-09-30 09:00:00.000000');
        $septNumber=$generator->generateFor($sept,new DateTimeImmutable('2026-09-30'))['number'];
        $this->assertSame('MEPA2609000146',$septNumber);
        $oct=$this->membership($this->person('Synthetic Oct'),'2026-10-01 09:00:00.000000');
        $octNumber=$generator->generateFor($oct,new DateTimeImmutable('2026-10-01'))['number'];
        $this->assertSame('MEPA2610000147',$octNumber,'Month boundary: counter continues, does not reset to 000001');
        $jan=$this->membership($this->person('Synthetic Jan'),'2027-01-01 09:00:00.000000');
        $janNumber=$generator->generateFor($jan,new DateTimeImmutable('2027-01-01'))['number'];
        $this->assertSame('MEPA2701000148',$janNumber,'Year boundary: counter continues, does not reset to 000001');

        // Sequence exhaustion guard.
        $db->table('member_number_sequences')->where('code','MEPA_NATIONAL')->update(['last_value'=>999999]);
        $exhausted=$this->membership($this->person('Synthetic exhausted'),'2026-09-13 09:00:00.000000');
        try { $generator->generateFor($exhausted,new DateTimeImmutable('2026-09-13')); $this->fail('Expected rejection'); }
        catch (\RuntimeException $e) { $this->assertSame('MEMBER_NUMBER_SEQUENCE_EXHAUSTED',$e->getMessage()); }
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_information_schema_matches_union_catalog(): void
    {
        $env=getenv();$env['WAVE2_DSN']=getenv('WAVE2_DSN');$env['WAVE2_USER']=getenv('WAVE2_USER')?:'';$env['WAVE2_PASSWORD']=getenv('WAVE2_PASSWORD')?:'';
        $process=proc_open(['node',self::$root.'/scripts/validate-wave2-schema.cjs','--output',self::$root.'/docs/database/physical/wave2_physical_validation.json','--inspection-output',self::$root.'/docs/database/physical/wave2_physical_inspection.json'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,self::$root,$env);
        $this->assertIsResource($process);
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $this->assertSame(0,$exit,$err.' '.$out);
        $result=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('INFORMATION_SCHEMA',$result['mode']);
        $this->assertSame('STRICT_PARITY_PASS',$result['status']);
        $this->assertSame([],$result['errors']);
        $this->assertTrue($result['w1_f01']['verified']);
        $this->assertSame(0,$result['cascade_count']);
    }

    /** @depends test_information_schema_matches_union_catalog */
    public function test_validator_flags_w1_f01_regression_if_the_fk_is_ever_removed(): void
    {
        $db=self::$capsule->getConnection();
        $db->statement('ALTER TABLE `files` DROP FOREIGN KEY `fk_files_owner_department_id`');
        try {
            $env=getenv();$env['WAVE2_DSN']=getenv('WAVE2_DSN');$env['WAVE2_USER']=getenv('WAVE2_USER')?:'';$env['WAVE2_PASSWORD']=getenv('WAVE2_PASSWORD')?:'';
            $process=proc_open(['node',self::$root.'/scripts/validate-wave2-schema.cjs'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,self::$root,$env);
            fclose($pipes[0]);$out=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
            $result=json_decode($out,true,512,JSON_THROW_ON_ERROR);
            $this->assertNotSame(0,$exit,'Validator must fail when W1-F01 is regressed');
            $this->assertSame('DRIFT_DETECTED',$result['status']);
            $this->assertFalse($result['w1_f01']['verified']);
            $this->assertTrue(collect($result['errors'])->contains(fn($e)=>str_contains($e['path'],'files.fk_files_owner_department_id')));
        } finally {
            $db->statement('ALTER TABLE `files` ADD CONSTRAINT `fk_files_owner_department_id` FOREIGN KEY (`owner_department_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if(self::$ready && getenv('WAVE2_KEEP_SCHEMA')!=='1'){self::$migrator->rollback(self::$wave2Paths);self::$migrator->rollback(self::$wave1Paths);}
        DB::clearResolvedInstances();Schema::clearResolvedInstances();
    }
}
