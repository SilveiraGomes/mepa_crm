<?php

declare(strict_types=1);

namespace Tests\Database;

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
final class WaveOnePhysicalTest extends TestCase
{
    private static Manager $capsule;
    private static Migrator $migrator;
    private static array $manifest;
    private static array $paths;
    private static string $root;
    private static bool $ready = false;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 4);
        $dsn = getenv('WAVE1_DSN') ?: '';
        if (getenv('WAVE1_ALLOW_SYNTHETIC') !== '1' || !$dsn) {
            self::markTestSkipped('BLOCKED FOR EXECUTION: explicit authorized MySQL WAVE1_DSN and WAVE1_ALLOW_SYNTHETIC=1 required.');
        }
        $parts=[];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            if (str_contains($part, '=')) { [$key,$value]=explode('=',$part,2); $parts[$key]=$value; }
        }
        if (!str_starts_with($dsn,'mysql:') || !preg_match('/^mepa_wave1_test_[a-z0-9_]+$/D',$parts['dbname']??'')
            || !in_array($parts['host']??'',['127.0.0.1','localhost','::1'],true)) {
            self::fail('Refusing physical tests: require loopback and an empty mepa_wave1_test_* database.');
        }
        $env=getenv();
        $env['DB_CAPABILITIES_DSN']=$dsn;
        $env['DB_CAPABILITIES_USER']=getenv('WAVE1_USER')?:'';
        $env['DB_CAPABILITIES_PASSWORD']=getenv('WAVE1_PASSWORD')?:'';
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
            'database'=>$parts['dbname'],'username'=>getenv('WAVE1_USER')?:'', 'password'=>getenv('WAVE1_PASSWORD')?:'',
            'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'timezone'=>'+00:00',
            'options'=>[\PDO::ATTR_EMULATE_PREPARES=>false]]);
        $db=self::$capsule->getConnection();
        self::assertSame([], $db->select('SHOW TABLES'), 'Physical tests require an empty database; no automatic destructive reset.');
        DB::swap(self::$capsule->getDatabaseManager());
        Schema::swap($db->getSchemaBuilder());
        self::$manifest=json_decode(file_get_contents(self::$root.'/docs/database/physical/wave1_manifest.json'),true,512,JSON_THROW_ON_ERROR);
        self::$paths=array_map(static fn($m)=>self::$root.'/apps/api/database/migrations/'.$m['file'],self::$manifest['migrations']);
        $repository=new DatabaseMigrationRepository(self::$capsule->getDatabaseManager(),'migrations');
        $repository->createRepository();
        self::$migrator=new Migrator($repository,self::$capsule->getDatabaseManager(),new Filesystem);
        $scaffolding=array_map(static fn($file)=>self::$root.'/apps/api/database/migrations/'.$file,[
            '2014_10_12_000000_create_users_table.php','2014_10_12_100000_create_password_resets_table.php',
            '2019_08_19_000000_create_failed_jobs_table.php','2019_12_14_000001_create_personal_access_tokens_table.php']);
        self::assertCount(4,self::$migrator->run($scaffolding));
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
        $this->assertCount(31,self::$migrator->run(self::$paths));
        foreach(self::$manifest['tables'] as $table)$this->assertTrue(Schema::hasTable($table));
        $this->assertCount(31,self::$migrator->rollback(self::$paths));
        foreach(self::$manifest['tables'] as $table)$this->assertFalse(Schema::hasTable($table));
        $this->assertTrue(Schema::hasTable('users'),'Wave 1 rollback preserves existing scaffolding');
        $this->assertCount(31,self::$migrator->run(self::$paths));
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_information_schema_matches_staged_catalog(): void
    {
        $process=proc_open(['node',self::$root.'/scripts/validate-wave1-schema.cjs','--allow-deferred','--output',self::$root.'/docs/database/physical/wave1_physical_validation.json','--inspection-output',self::$root.'/docs/database/physical/wave1_physical_inspection.json'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,self::$root);
        $this->assertIsResource($process);
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $this->assertSame(0,$exit,$err.' '.$out);
        $result=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('INFORMATION_SCHEMA',$result['mode']);
        $this->assertSame([],$result['errors']);
        $this->assertCount(1,$result['strict_errors'],'Strict drift must retain W1-F01');
        $this->assertSame('files.fk_files_owner_department_id',$result['strict_errors'][0]['path']);
    }

    private function lookup(string $table, string $code): int
    {
        return self::$capsule->getConnection()->table($table)->insertGetId(['code'=>$code,'name'=>'Synthetic fixture',
            'is_active'=>1,'created_at'=>'2026-09-12 10:00:00.123456']);
    }

    private function person(string $name): int
    {
        $status=$this->lookup('person_statuses','TEST_'.strtoupper(bin2hex(random_bytes(5))));
        return self::$capsule->getConnection()->table('people')->insertGetId(['public_id'=>(string)Str::ulid(),
            'full_name'=>$name,'birth_precision'=>'UNKNOWN','status_id'=>$status,'created_at'=>'2026-09-12 10:00:00.123456']);
    }

    private function rejects(callable $write, int $expected): void
    {
        try { $write(); $this->fail('Database accepted invalid fixture'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertSame($expected,(int)($e->errorInfo[1]??0)); }
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_person_and_non_member_parent_family(): void
    {
        $db=self::$capsule->getConnection();
        $father=$this->person('Synthetic non-member parent');$child=$this->person('Synthetic child');
        $kind=$this->lookup('relationship_types','TEST_PARENT');
        $relation=['subject_person_id'=>$father,'related_person_id'=>$child,'relationship_type_id'=>$kind,
            'status'=>'ACTIVE','starts_at'=>'2026-09-12 10:00:00.123456','created_at'=>'2026-09-12 10:00:00.123456'];
        $db->table('person_relationships')->insert($relation);
        $this->assertSame(2,$db->table('people')->count());
        $this->assertSame(0,$db->table('users')->count());
        $this->assertFalse(Schema::hasTable('memberships'));
        $this->assertFalse(Schema::hasColumn('people','membership_number'));
        $house=$db->table('households')->insertGetId(['public_id'=>(string)Str::ulid(),'code'=>'TEST_HOME',
            'status'=>'ACTIVE','created_at'=>'2026-09-12 10:00:00.123456']);
        $role=$this->lookup('household_role_types','TEST_ROLE');
        foreach([$father,$child] as $person)$db->table('household_members')->insert(['household_id'=>$house,'person_id'=>$person,
            'role_type_id'=>$role,'status'=>'ACTIVE','starts_at'=>'2026-09-12 10:00:00.123456','created_at'=>'2026-09-12 10:00:00.123456']);
        $this->assertSame(2,$db->table('household_members')->count());
        $this->rejects(fn()=>$db->table('person_relationships')->insert(array_replace($relation,['related_person_id'=>$father])),3819);
        $this->rejects(fn()=>$db->table('person_relationships')->insert(array_replace($relation,['ends_at'=>$relation['starts_at']])),3819);
        $this->rejects(fn()=>$db->table('people')->where('id',$father)->delete(),1451);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_boolean_birth_fk_and_public_id_rejections(): void
    {
        $db=self::$capsule->getConnection();$id=$this->person('Synthetic identifier');
        $p=(array)$db->table('people')->where('id',$id)->first();unset($p['id']);
        $this->rejects(fn()=>$db->table('people')->insert($p),1062);
        $this->rejects(fn()=>$db->table('people')->where('id',$id)->update(['public_id'=>null]),1048);
        $this->rejects(fn()=>$db->table('people')->where('id',$id)->update(['status_id'=>99999999]),1452);
        $this->rejects(fn()=>$db->table('people')->where('id',$id)->update(['birth_precision'=>'EXACT']),3819);
        $this->rejects(fn()=>$db->table('person_statuses')->where('id',$p['status_id'])->update(['is_active'=>2]),3819);
        $this->assertSame('2026-09-12 10:00:00.123456',$p['created_at']);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_contacts_preserve_encrypted_text_and_allow_shared_phone(): void
    {
        $db=self::$capsule->getConnection();$person=$this->person('Synthetic phone');
        $type=$this->lookup('contact_types','TEST_PHONE');
        $key=random_bytes(32);$iv=random_bytes(12);$tag='';$phone='+244923000000';
        $cipher=openssl_encrypt($phone,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
        $contact=['person_id'=>$person,'contact_type_id'=>$type,'value_ciphertext'=>$cipher,
            'value_blind_index'=>hash_hmac('sha256',$phone,$key,true),'key_version'=>1,'is_primary'=>1,
            'status'=>'ACTIVE','created_at'=>'2026-09-12 10:00:00.123456'];
        $db->table('person_contacts')->insert($contact);$db->table('person_contacts')->insert($contact);
        $stored=$db->table('person_contacts')->first();
        $this->assertSame($phone,openssl_decrypt($stored->value_ciphertext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag));
        $this->assertSame(2,$db->table('person_contacts')->count());
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_coordinates_are_paired_and_bounded(): void
    {
        $db=self::$capsule->getConnection();
        $address=$db->table('addresses')->insertGetId(['country_code'=>'AGO','line1_ciphertext'=>'synthetic ciphertext',
            'key_version'=>1,'created_at'=>'2026-09-12 10:00:00.123456']);
        $location=['public_id'=>(string)Str::ulid(),'address_id'=>$address,'name'=>'Synthetic local',
            'public_visibility'=>'PRIVATE','status'=>'ACTIVE','created_at'=>'2026-09-12 10:00:00.123456'];
        $id=$db->table('physical_locations')->insertGetId($location);
        $db->table('physical_locations')->where('id',$id)->update(['latitude'=>'-90.000000','longitude'=>'180.000000']);
        $this->rejects(fn()=>$db->table('physical_locations')->where('id',$id)->update(['latitude'=>'90.000001']),3819);
        $this->rejects(fn()=>$db->table('physical_locations')->where('id',$id)->update(['longitude'=>'-180.000001']),3819);
        $this->rejects(fn()=>$db->table('physical_locations')->where('id',$id)->update(['longitude'=>null]),3819);
        $this->rejects(fn()=>$db->table('physical_locations')->where('id',$id)->update(['public_visibility'=>'PUBLIC']),3819);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_all_approved_territorial_parent_child_states_can_be_persisted(): void
    {
        $db=self::$capsule->getConnection();
        $rules=json_decode(file_get_contents(self::$root.'/docs/database/unit_parent_rules.json'),true,512,JSON_THROW_ON_ERROR);
        $types=[];
        foreach(array_unique(array_merge(...$rules['allowed_parent_child_pairs'])) as $code)$types[$code]=$this->lookup('organizational_unit_types',$code);
        foreach($rules['allowed_parent_child_pairs'] as [$parent,$child])$db->table('unit_parent_rules')->insert([
            'child_type_id'=>$types[$child],'parent_type_id'=>$types[$parent],'created_at'=>'2026-09-12 10:00:00.123456']);
        $areaType=$this->lookup('territorial_area_types','TEST_MUNICIPALITY');
        $area=$db->table('territorial_areas')->insertGetId(['area_type_id'=>$areaType,'code'=>'TEST_AREA','name'=>'Synthetic territory',
            'status'=>'ACTIVE','created_at'=>'2026-09-12 10:00:00.123456']);
        $add=static fn(string $type,?int $parent,string $code)=>$db->table('organizational_units')->insertGetId([
            'public_id'=>(string)Str::ulid(),'unit_type_id'=>$types[$type],'parent_id'=>$parent,'municipality_id'=>$area,
            'code'=>$code,'name'=>'Synthetic '.$type,'status'=>'DRAFT','created_at'=>'2026-09-12 10:00:00.123456']);
        $general=$add('GENERAL_DIRECTION',null,'TEST_G');$regional=$add('REGIONAL_DIRECTION',$general,'TEST_R');
        $provincial=$add('PROVINCIAL_DIRECTION',$regional,'TEST_P');$municipal=$add('MUNICIPAL_DIRECTION',$provincial,'TEST_M1');
        $gc=$add('GENERAL_CENTER',$municipal,'TEST_GC');$center=$add('CENTER',$gc,'TEST_C1');
        $add('CONGREGATION',$center,'TEST_CO1');
        $municipal2=$add('MUNICIPAL_DIRECTION',$provincial,'TEST_M2');$center2=$add('CENTER',$municipal2,'TEST_C2');
        $add('CONGREGATION',$center2,'TEST_CO2');
        $this->assertSame(10,$db->table('organizational_units')->count());
        $this->rejects(fn()=>$db->table('organizational_units')->where('id',$center)->update(['parent_id'=>99999999]),1452);
        $this->rejects(fn()=>$db->table('organizational_units')->where('id',$center)->update(['status'=>'INCOMPLETE']),3819);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_personal_documents_keep_civil_dates_and_non_unique_candidate_index(): void
    {
        $db=self::$capsule->getConnection();$person=$this->person('Synthetic document');
        $type=$this->lookup('identity_document_types','TEST_DOCUMENT');
        $document=['person_id'=>$person,'document_type_id'=>$type,'issuer_country'=>'AGO',
            'number_ciphertext'=>random_bytes(40),'number_blind_index'=>random_bytes(32),'key_version'=>1,
            'issued_on'=>'2026-01-02','expires_on'=>'2030-01-02','verification_status'=>'TEST_PENDING',
            'created_at'=>'2026-09-12 10:00:00.123456'];
        $db->table('person_documents')->insert($document);$db->table('person_documents')->insert($document);
        $stored=$db->table('person_documents')->first();
        $this->assertSame('2026-01-02',$stored->issued_on);
        $this->assertSame('2030-01-02',$stored->expires_on);
        $this->assertSame($document['number_blind_index'],$stored->number_blind_index);
        $this->assertSame(2,$db->table('person_documents')->count());
        $this->rejects(fn()=>$db->table('person_documents')->insert(array_replace($document,['file_id'=>99999999])),1452);
    }

    /** @depends test_migrate_rollback_and_remigrate */
    public function test_location_change_preserves_unit_identity_and_period_history(): void
    {
        $db=self::$capsule->getConnection();$type=$this->lookup('organizational_unit_types','TEST_UNIT');
        $public=(string)Str::ulid();
        $unit=$db->table('organizational_units')->insertGetId(['public_id'=>$public,'unit_type_id'=>$type,
            'code'=>'TEST_MOVING_UNIT','name'=>'Synthetic moving unit','status'=>'DRAFT','created_at'=>'2026-09-12 10:00:00.123456']);
        $occupation=$this->lookup('occupation_types','TEST_OCCUPATION');$locations=[];
        foreach([1,2] as $i){
            $address=$db->table('addresses')->insertGetId(['country_code'=>'AGO','line1_ciphertext'=>'synthetic address '.$i,
                'key_version'=>1,'created_at'=>'2026-09-12 10:00:00.123456']);
            $locations[]=$db->table('physical_locations')->insertGetId(['public_id'=>(string)Str::ulid(),'address_id'=>$address,
                'name'=>'Synthetic site '.$i,'public_visibility'=>'PRIVATE','status'=>'TEST_ACTIVE','created_at'=>'2026-09-12 10:00:00.123456']);
        }
        $first=$db->table('unit_location_links')->insertGetId(['unit_id'=>$unit,'location_id'=>$locations[0],
            'occupation_type_id'=>$occupation,'is_primary'=>1,'status'=>'ENDED','starts_at'=>'2026-01-01 00:00:00.000000',
            'ends_at'=>'2026-09-01 00:00:00.000000','created_at'=>'2026-09-12 10:00:00.123456']);
        $db->table('unit_location_links')->insert(['unit_id'=>$unit,'location_id'=>$locations[1],
            'occupation_type_id'=>$occupation,'is_primary'=>1,'status'=>'ACTIVE','starts_at'=>'2026-09-01 00:00:00.000000',
            'created_at'=>'2026-09-12 10:00:00.123456']);
        $this->assertSame($public,$db->table('organizational_units')->where('id',$unit)->value('public_id'));
        $this->assertSame(2,$db->table('unit_location_links')->where('unit_id',$unit)->count());
        $this->assertSame($locations[0],(int)$db->table('unit_location_links')->where('id',$first)->value('location_id'));
    }

    /** @dataProvider applicationRules */
    public function test_application_enforced_rule_is_not_a_database_guarantee(string $rule): void
    {
        $this->markTestSkipped('NOT YET EXECUTABLE — APPLICATION LAYER WAVE: '.$rule);
    }

    public function applicationRules(): array
    {
        return [['self-parent'],['cycle'],['invalid parent/child type'],['second non-CLOSED General Center'],['ACTIVE municipality minimum Center']];
    }

    public static function tearDownAfterClass(): void
    {
        if(self::$ready && getenv('WAVE1_KEEP_SCHEMA')!=='1')self::$migrator->rollback(self::$paths);
        DB::clearResolvedInstances();Schema::clearResolvedInstances();
    }
}
