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
use RuntimeException;

// Real concurrency: every worker is a genuinely separate PHP process (proc_open), synchronized
// by a file barrier (touch READY, busy-wait GO, then race into MemberNumberGenerator::generateFor).
// This is not simulated interleaving - MySQL's own locking and UNIQUE constraints are the only
// things standing between these processes and a duplicate/lost number.
final class WaveTwoConcurrencyTest extends TestCase
{
    private static Manager $capsule;
    private static string $root;
    private static array $dbParts;
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
            self::fail('Refusing concurrency tests: require loopback and an empty mepa_wave2_test_* database.');
        }
        self::$dbParts = $parts;
        self::$capsule=new Manager;
        self::$capsule->addConnection(['driver'=>'mysql','host'=>$parts['host'],'port'=>$parts['port']??3306,
            'database'=>$parts['dbname'],'username'=>getenv('WAVE2_USER')?:'', 'password'=>getenv('WAVE2_PASSWORD')?:'',
            'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'timezone'=>'+00:00',
            'options'=>[\PDO::ATTR_EMULATE_PREPARES=>false]]);
        $db=self::$capsule->getConnection();
        self::assertSame([], $db->select('SHOW TABLES'), 'Concurrency tests require an empty database; no automatic destructive reset.');
        DB::swap(self::$capsule->getDatabaseManager());
        Schema::swap($db->getSchemaBuilder());
        $wave1Manifest=json_decode(file_get_contents(self::$root.'/docs/database/physical/wave1_manifest.json'),true,512,JSON_THROW_ON_ERROR);
        $wave2Manifest=json_decode(file_get_contents(self::$root.'/docs/database/physical/wave2_manifest.json'),true,512,JSON_THROW_ON_ERROR);
        $wave1Paths=array_map(static fn($m)=>self::$root.'/apps/api/database/migrations/'.$m['file'],$wave1Manifest['migrations']);
        $wave2Paths=array_map(static fn($f)=>self::$root.'/apps/api/database/migrations/'.$f,$wave2Manifest['migrations']);
        $repository=new DatabaseMigrationRepository(self::$capsule->getDatabaseManager(),'migrations');
        $repository->createRepository();
        $migrator=new Migrator($repository,self::$capsule->getDatabaseManager(),new Filesystem);
        $scaffolding=array_map(static fn($file)=>self::$root.'/apps/api/database/migrations/'.$file,[
            '2014_10_12_000000_create_users_table.php','2014_10_12_100000_create_password_resets_table.php',
            '2019_08_19_000000_create_failed_jobs_table.php','2019_12_14_000001_create_personal_access_tokens_table.php']);
        self::assertCount(4,$migrator->run($scaffolding));
        self::assertCount(31,$migrator->run($wave1Paths));
        self::assertCount(44,$migrator->run($wave2Paths));
        $db->table('member_number_sequences')->insert(['code'=>'MEPA_NATIONAL','last_value'=>0,'created_at'=>'2026-09-13 10:00:00.123456']);
        self::$ready=true;
    }

    private function lookup(string $table, string $code): int
    {
        return self::$capsule->getConnection()->table($table)->insertGetId(['code'=>$code,'name'=>'Synthetic fixture',
            'is_active'=>1,'created_at'=>'2026-09-13 10:00:00.123456']);
    }

    private function approvedMembership(): int
    {
        $status=$this->lookup('person_statuses','TEST_'.strtoupper(bin2hex(random_bytes(6))));
        $person=self::$capsule->getConnection()->table('people')->insertGetId(['public_id'=>(string)Str::ulid(),
            'full_name'=>'Synthetic concurrent member','birth_precision'=>'UNKNOWN','status_id'=>$status,
            'created_at'=>'2026-09-13 10:00:00.123456']);
        $mstatus=$this->lookup('membership_statuses','TEST_'.strtoupper(bin2hex(random_bytes(6))));
        return self::$capsule->getConnection()->table('memberships')->insertGetId(['public_id'=>(string)Str::ulid(),
            'person_id'=>$person,'status_id'=>$mstatus,'date_precision'=>'EXACT','approved_at'=>'2026-09-13 09:00:00.000000',
            'origin'=>'APPROVED_ADMISSION','created_at'=>'2026-09-13 10:00:00.123456']);
    }

    /** @return array<int,array{ok:bool,result?:array,error?:string}> in job order */
    private function runWorkers(array $jobs): array
    {
        $env=getenv();
        $env['W2_HOST']=self::$dbParts['host'];$env['W2_PORT']=self::$dbParts['port']??3306;
        $env['W2_DB']=self::$dbParts['dbname'];$env['W2_USER']=getenv('WAVE2_USER')?:'';$env['W2_PASS']=getenv('WAVE2_PASSWORD')?:'';
        $tmp=sys_get_temp_dir().'/wave2_concurrency_'.bin2hex(random_bytes(8));
        mkdir($tmp);
        $procs=[];
        foreach ($jobs as $i=>$job) {
            $ready="$tmp/ready_$i";$go="$tmp/go";$result="$tmp/result_$i";
            $process=proc_open([PHP_BINARY,self::$root.'/scripts/wave2-concurrency-worker.php',
                (string)$job['membershipId'],$job['issuedAt'],$ready,$go,$result,$job['origin']??'APPROVED_ADMISSION'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,self::$root,$env);
            if (!is_resource($process)) throw new RuntimeException('Could not spawn concurrency worker');
            $procs[]=['proc'=>$process,'pipes'=>$pipes,'ready'=>$ready,'result'=>$result];
        }
        $deadline=microtime(true)+15;
        foreach ($procs as $p) {
            while (!file_exists($p['ready'])) {
                if (microtime(true)>$deadline) throw new RuntimeException('Timed out waiting for worker READY barrier');
                usleep(2000);
            }
        }
        touch("$tmp/go");
        $results=[];
        foreach ($procs as $p) {
            fclose($p['pipes'][0]);$out=stream_get_contents($p['pipes'][1]);$err=stream_get_contents($p['pipes'][2]);
            fclose($p['pipes'][1]);fclose($p['pipes'][2]);proc_close($p['proc']);
            if (!file_exists($p['result'])) throw new RuntimeException('Worker produced no result: '.$out.$err);
            $results[]=json_decode(file_get_contents($p['result']),true);
        }
        foreach (glob("$tmp/*") as $f) unlink($f);
        rmdir($tmp);
        return $results;
    }

    private function assertNoDuplicatesAndContiguous(array $results, int $before, int $n): void
    {
        $ok=array_filter($results,fn($r)=>$r['ok']);
        $this->assertCount($n,$ok,'All workers must succeed: '.json_encode($results));
        $sequenceValues=array_map(fn($r)=>$r['result']['sequence_value'],$ok);
        $numbers=array_map(fn($r)=>$r['result']['number'],$ok);
        $this->assertCount($n,array_unique($sequenceValues),'Zero duplicate sequence_value under real concurrency');
        $this->assertCount($n,array_unique($numbers),'Zero duplicate number under real concurrency');
        sort($sequenceValues);
        $this->assertSame(range($before+1,$before+$n),$sequenceValues,'Contiguous run, no gaps, no reuse');
        foreach ($numbers as $number) $this->assertMatchesRegularExpression('/^MEPA\d{10}$/',$number);
    }

    public function test_two_concurrent_workers_distinct_memberships(): void
    {
        $before=(int)self::$capsule->getConnection()->table('member_number_sequences')->where('code','MEPA_NATIONAL')->value('last_value');
        $jobs=array_map(fn()=>['membershipId'=>$this->approvedMembership(),'issuedAt'=>'2026-09-13'],range(1,2));
        $results=$this->runWorkers($jobs);
        $this->assertNoDuplicatesAndContiguous($results,$before,2);
    }

    public function test_ten_concurrent_workers_distinct_memberships(): void
    {
        $before=(int)self::$capsule->getConnection()->table('member_number_sequences')->where('code','MEPA_NATIONAL')->value('last_value');
        $jobs=array_map(fn()=>['membershipId'=>$this->approvedMembership(),'issuedAt'=>'2026-09-13'],range(1,10));
        $results=$this->runWorkers($jobs);
        $this->assertNoDuplicatesAndContiguous($results,$before,10);
    }

    public function test_larger_batch_of_thirty_concurrent_workers(): void
    {
        $before=(int)self::$capsule->getConnection()->table('member_number_sequences')->where('code','MEPA_NATIONAL')->value('last_value');
        $jobs=array_map(fn()=>['membershipId'=>$this->approvedMembership(),'issuedAt'=>'2026-09-13'],range(1,30));
        $results=$this->runWorkers($jobs);
        $this->assertNoDuplicatesAndContiguous($results,$before,30);
    }

    public function test_two_concurrent_workers_same_membership_are_serialized_and_idempotent(): void
    {
        $db=self::$capsule->getConnection();
        $before=(int)$db->table('member_number_sequences')->where('code','MEPA_NATIONAL')->value('last_value');
        $membershipId=$this->approvedMembership();
        $results=$this->runWorkers([
            ['membershipId'=>$membershipId,'issuedAt'=>'2026-09-13'],
            ['membershipId'=>$membershipId,'issuedAt'=>'2026-09-13'],
        ]);
        $this->assertTrue($results[0]['ok']);$this->assertTrue($results[1]['ok']);
        $this->assertSame($results[0]['result']['number'],$results[1]['result']['number'],'Same membership must never receive two different numbers');
        $this->assertSame(1,$db->table('member_numbers')->where('membership_id',$membershipId)->count(),'Exactly one row despite two concurrent callers');
        $after=(int)$db->table('member_number_sequences')->where('code','MEPA_NATIONAL')->value('last_value');
        $this->assertSame($before+1,$after,'Sequence advances by exactly one, not two, for one membership');
    }

    public static function tearDownAfterClass(): void
    {
        DB::clearResolvedInstances();Schema::clearResolvedInstances();
    }
}
