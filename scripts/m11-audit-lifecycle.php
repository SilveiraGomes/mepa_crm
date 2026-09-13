<?php

declare(strict_types=1);

// Independent M1.1 audit: own PDO snapshots/fixtures, no application boot or .env.
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$root = dirname(__DIR__);
require $root . '/apps/api/vendor/autoload.php';
$dsn = getenv('M11R_DSN') ?: '';
if (getenv('M11R_ALLOW_SYNTHETIC') !== '1'
    || !preg_match('/^mysql:host=127\.0\.0\.1;port=33097;dbname=(mepa_m11r_test_[a-z0-9_]+)$/D', $dsn, $match)) {
    throw new RuntimeException('Refusing audit: explicit independent loopback synthetic schema required.');
}
$checks = [];
function verify(bool $condition, string $label): void {
    global $checks;
    $checks[] = ['check' => $label, 'pass' => $condition];
    if (!$condition) throw new RuntimeException('AUDIT FAILED: ' . $label);
}
$manager = new Manager;
$manager->addConnection(['driver'=>'mysql','host'=>'127.0.0.1','port'=>33097,'database'=>$match[1],
    'username'=>getenv('M11R_USER') ?: '', 'password'=>getenv('M11R_PASSWORD') ?: '',
    'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','strict'=>true,'timezone'=>'+00:00',
    'options'=>[PDO::ATTR_EMULATE_PREPARES=>false]]);
$db = $manager->getConnection();
$pdo = $db->getPdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
verify($pdo->query('SHOW TABLES')->fetchAll() === [], 'independent schema initially empty');
DB::swap($manager->getDatabaseManager()); Schema::swap($db->getSchemaBuilder());
$repository = new DatabaseMigrationRepository($manager->getDatabaseManager(), 'migrations');
$repository->createRepository();
$migrator = new Migrator($repository, $manager->getDatabaseManager(), new Filesystem);
$base = $root . '/apps/api/database/migrations/';
$scaffold = ['2014_10_12_000000_create_users_table.php','2014_10_12_100000_create_password_resets_table.php',
    '2019_08_19_000000_create_failed_jobs_table.php','2019_12_14_000001_create_personal_access_tokens_table.php'];
verify(count($migrator->run(array_map(fn($file)=>$base.$file,$scaffold)))===4, 'scaffolding applies');
$w1=json_decode(file_get_contents($root.'/docs/database/physical/wave1_manifest.json'),true,512,JSON_THROW_ON_ERROR);
$w2=json_decode(file_get_contents($root.'/docs/database/physical/wave2_manifest.json'),true,512,JSON_THROW_ON_ERROR);
verify(count($migrator->run(array_map(fn($m)=>$base.$m['file'],$w1['migrations'])))===31, 'Wave 1 applies');
verify(count($migrator->run(array_map(fn($file)=>$base.$file,$w2['migrations'])))===44, 'Wave 2 applies');
$domainPath=$base.'2026_09_14_000000_wave2m1_add_transfers_closed_at.php';
$guardPath=$base.'2026_09_14_000001_wave2m1_add_transfers_open_guard.php';
verify(count($migrator->run([$domainPath]))===1, 'durable column before guard');
verify(count($migrator->run([$guardPath]))===1, 'technical guard applies');
function state(PDO $pdo, bool $guard): array {
    $columns=$pdo->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transfers' AND COLUMN_NAME IN ('closed_at','open_flag') ORDER BY ORDINAL_POSITION")->fetchAll();
    $index=$pdo->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transfers' AND INDEX_NAME='uq_transfers_membership_open' ORDER BY SEQ_IN_INDEX")->fetchAll();
    verify(array_column($columns,'COLUMN_NAME')===($guard?['closed_at','open_flag']:['closed_at']), 'state columns '.$guard);
    verify($columns[0]['COLUMN_TYPE']==='datetime(6)' && $columns[0]['IS_NULLABLE']==='YES', 'durable microsecond datetime');
    if ($guard) {
        verify($columns[1]['EXTRA']==='STORED GENERATED' && $columns[1]['IS_NULLABLE']==='YES', 'nullable generated flag');
        $expression=strtolower(preg_replace('/[`\s()]/','',$columns[1]['GENERATION_EXPRESSION']));
        verify($expression==='ifclosed_atisnull,1,null', 'generated expression depends only on closed_at');
        verify(array_column($index,'COLUMN_NAME')===['membership_id','open_flag'], 'unique column order');
        verify(array_unique(array_column($index,'NON_UNIQUE'))===[0], 'unique physical enforcement');
    } else verify($index===[], 'guard unique absent');
    return ['show_create'=>$pdo->query('SHOW CREATE TABLE transfers')->fetch(PDO::FETCH_NUM)[1], 'columns'=>$columns,'unique'=>$index];
}
function snapshot(PDO $pdo): array {
    $rows=$pdo->query('SELECT * FROM transfers ORDER BY id')->fetchAll();
    foreach ($rows as &$row) { unset($row['open_flag']); ksort($row); } unset($row);
    $serialized=json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    return ['rows'=>$rows,'serialized'=>$serialized,'checksum_sha256'=>hash('sha256',$serialized)];
}
function insert(PDO $pdo,string $table,array $data): int {
    $sql='INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_fill(0,count($data),'?')).')';
    $pdo->prepare($sql)->execute(array_values($data)); return (int)$pdo->lastInsertId();
}
function publicId(): string { static $i=0; return '01'.str_pad((string)++$i,24,'0',STR_PAD_LEFT); }
$domain=require $domainPath;
$emptyA=state($pdo,true); $emptyBefore=snapshot($pdo);
$domain->down(); $domain->up();
verify(snapshot($pdo)===$emptyBefore,'empty durable rollback/adoption preserves data');
verify(count($migrator->rollback([$guardPath],['step'=>1]))===1,'empty exact guard rollback');
$emptyB=state($pdo,false);
verify(snapshot($pdo)===$emptyBefore,'empty guard rollback preserves data');
verify(count($migrator->run([$guardPath]))===1,'empty guard reapply');
$time='2026-09-13 15:16:17.111222';
$lookup=fn($table,$code)=>insert($pdo,$table,['code'=>$code,'name'=>'Independent synthetic fixture','is_active'=>1,'created_at'=>$time]);
$type=$lookup('organizational_unit_types','AUDIT_M11');
$units=[];
foreach (['ORIGIN','DESTINATION'] as $code) $units[]=insert($pdo,'organizational_units',['public_id'=>publicId(),'unit_type_id'=>$type,'code'=>'M11_'.$code,'name'=>$code,'status'=>'ACTIVE','created_at'=>$time]);
$ps=$lookup('person_statuses','M11_PERSON');
$person=insert($pdo,'people',['public_id'=>publicId(),'full_name'=>'Independent synthetic person','birth_precision'=>'UNKNOWN','status_id'=>$ps,'created_at'=>$time]);
$ms=$lookup('membership_statuses','M11_MEMBER');
$membership=insert($pdo,'memberships',['public_id'=>publicId(),'person_id'=>$person,'status_id'=>$ms,'date_precision'=>'EXACT','origin'=>'APPROVED_ADMISSION','created_at'=>$time]);
$user=insert($pdo,'users',['public_id'=>publicId(),'account_kind'=>'SERVICE','login'=>'m11_audit_synthetic','password_hash'=>'synthetic-noncredential','status'=>'ACTIVE','mfa_required'=>0,'created_at'=>$time]);
$wf=insert($pdo,'workflows',['code'=>'M11_AUDIT','version'=>1,'name'=>'Independent audit','status'=>'ACTIVE','created_at'=>$time]);
$instance=insert($pdo,'workflow_instances',['public_id'=>publicId(),'workflow_id'=>$wf,'unit_id'=>$units[0],'requested_by'=>$user,'status'=>'SYNTHETIC','created_at'=>$time]);
$dates=['2026-01-02 03:04:05.123456','2026-02-03 04:05:06.987654',null];
$ids=[];
foreach ($dates as $i=>$closed) $ids[]=insert($pdo,'transfers',['public_id'=>publicId(),'membership_id'=>$membership,'origin_unit_id'=>$units[0],'destination_unit_id'=>$units[1],
    'requested_at'=>'2026-01-01 00:00:00.100001','effective_at'=>$i===0?$closed:null,'closed_at'=>$closed,
    'status'=>['AUDIT_ALPHA','AUDIT_BETA','AUDIT_GAMMA'][$i],'workflow_instance_id'=>$instance,'source_document_id'=>null,'created_at'=>$time,'lock_version'=>$i+4]);
$before=snapshot($pdo); $stateA=state($pdo,true);
$flags=$pdo->query('SELECT id,open_flag FROM transfers ORDER BY id')->fetchAll();
verify(array_column($flags,'open_flag')===[null,null,1],'A/B/C generated flags');
$membershipBefore=$pdo->query('SELECT * FROM memberships ORDER BY id')->fetchAll();
verify(count($migrator->rollback([$guardPath],['step'=>1]))===1,'populated exact guard rollback');
$stateB=state($pdo,false); $afterRollback=snapshot($pdo);
verify($before===$afterRollback,'all rows and deterministic checksum identical after rollback');
verify(array_column($afterRollback['rows'],'closed_at')===$dates,'microseconds and null unchanged');
$domain->down(); $domain->up();
verify(snapshot($pdo)===$before,'populated durable rollback/adoption preserves history');
verify(count($migrator->run([$guardPath]))===1,'valid populated reapply');
$stateC=state($pdo,true); $afterReapply=snapshot($pdo);
verify($afterReapply===$before,'all rows and checksum identical after reapply');
verify($pdo->query('SELECT * FROM memberships ORDER BY id')->fetchAll()===$membershipBefore,'membership unchanged');
verify($pdo->query('SELECT id,open_flag FROM transfers ORDER BY id')->fetchAll()===$flags,'flags restored');
verify(count($migrator->rollback([$guardPath],['step'=>1]))===1,'second exact guard rollback');
$d=insert($pdo,'transfers',['public_id'=>publicId(),'membership_id'=>$membership,'origin_unit_id'=>$units[0],'destination_unit_id'=>$units[1],
    'requested_at'=>$time,'effective_at'=>null,'closed_at'=>null,'status'=>'AUDIT_DELTA','workflow_instance_id'=>$instance,'created_at'=>$time,'lock_version'=>19]);
$invalidBefore=snapshot($pdo);
$db->enableQueryLog(); $db->flushQueryLog(); $invalidError=null;
try { $migrator->run([$guardPath]); } catch (RuntimeException $error) { $invalidError=$error->getMessage(); }
$precheckQueries=$db->getQueryLog(); $db->disableQueryLog();
verify(str_starts_with($invalidError??'','TRANSFER_OPEN_GUARD_INVALID_DATA:'),'explicit understandable precheck error');
foreach ($precheckQueries as $query) verify(!preg_match('/^\s*(ALTER|UPDATE|DELETE|INSERT)\b/i',$query['query']), 'no mutation during invalid precheck');
$invalidAfter=snapshot($pdo); $invalidState=state($pdo,false);
verify($invalidBefore===$invalidAfter,'invalid C/D rows and checksum unchanged');
verify(!in_array(basename($guardPath,'.php'),$repository->getRan(),true),'failed guard not marked applied');
// Exercise repository removal/reapplication of the durable migration, not only direct methods.
verify(count($migrator->rollback([$domainPath],['step'=>1]))===1,'durable migration rollback through Migrator');
verify(snapshot($pdo)===$invalidBefore,'Migrator durable rollback retains populated history');
verify(count($migrator->run([$domainPath]))===1,'durable migration reapply adopts existing history');
verify(snapshot($pdo)===$invalidBefore,'durable reapply retains invalid dataset without repairing it');
// Historical destructive migration is only executed against a separate disposable minimal table.
$legacyManager=new Manager;
$legacyManager->addConnection(['driver'=>'mysql','host'=>'127.0.0.1','port'=>33097,'database'=>'mepa_m11r_test_legacy_final',
    'username'=>getenv('M11R_USER')?:'','password'=>getenv('M11R_PASSWORD')?:'', 'charset'=>'utf8mb4','strict'=>true]);
$legacy=$legacyManager->getConnection();
verify($legacy->select('SHOW TABLES')===[],'separate historical reproduction schema empty');
DB::swap($legacyManager->getDatabaseManager()); Schema::swap($legacy->getSchemaBuilder());
$legacy->statement('CREATE TABLE transfers (id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,membership_id BIGINT UNSIGNED NOT NULL,effective_at DATETIME(6) NULL,status VARCHAR(64) NOT NULL) ENGINE=InnoDB');
$previous=require $root.'/docs/database/physical/m11r_previous_guard.php.txt';
$previous->up();
foreach ($dates as $i=>$closed) $legacy->table('transfers')->insert(['membership_id'=>1,'effective_at'=>$i===0?$closed:null,'closed_at'=>$closed,'status'=>'HISTORY_'.$i]);
$legacyBefore=$legacy->select('SELECT * FROM transfers ORDER BY id');
$domain->up(); // adoption on an installation where historical M1 already owns the column
verify(json_encode($legacy->select('SELECT * FROM transfers ORDER BY id'))===json_encode($legacyBefore),'existing historical M1 adoption is nonmutating');
$previous->down();
verify(!$legacy->getSchemaBuilder()->hasColumn('transfers','closed_at'),'historical rollback really deletes closed_at');
$legacyAfterRollback=$legacy->select('SELECT * FROM transfers ORDER BY id');
$legacyError=null;
try { $previous->up(); } catch (\Illuminate\Database\QueryException $error) { $legacyError=['code'=>(int)$error->errorInfo[1],'message'=>$error->getMessage()]; }
verify(($legacyError['code']??null)===1062,'historical reapply really fails duplicate-key');
verify(array_column($legacy->select('SELECT * FROM transfers ORDER BY id'),'closed_at')===[null,null,null],'historical reapply loses open/closed distinction');
DB::swap($manager->getDatabaseManager()); Schema::swap($db->getSchemaBuilder());
$evidence=['mysql'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'database'=>$match[1],
    'states'=>['A'=>$stateA,'B'=>$stateB,'C'=>$stateC],'before'=>$before,'after_rollback'=>$afterRollback,'after_reapply'=>$afterReapply,
    'flags'=>$flags,'invalid'=>['before'=>$invalidBefore,'after'=>$invalidAfter,'error'=>$invalidError,'state'=>$invalidState,'queries'=>$precheckQueries],
    'durable_migrator_rollback_reapply'=>'PASS','empty'=>['before'=>$emptyBefore,'A'=>$emptyA,'B'=>$emptyB],
    'historical'=>['source_commit'=>'7f4e400','source_sha256'=>hash_file('sha256',$root.'/docs/database/physical/m11r_previous_guard.php.txt'),'before'=>$legacyBefore,'after_rollback'=>$legacyAfterRollback,'reapply_error'=>$legacyError],
    'checks'=>$checks,'result'=>'PASS'];
file_put_contents($root.'/docs/database/physical/m11r_independent_lifecycle_evidence.json',json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
echo 'Independent lifecycle audit PASS: '.count($checks).' checks; checksum '.$before['checksum_sha256'].PHP_EOL;
