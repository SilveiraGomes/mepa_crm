<?php
// Read-only preflight. No CREATE/ALTER/INSERT/UPDATE/DELETE or config writes.
declare(strict_types=1);
function capabilityBaseline(string $version, string $comment=''): array {
    $family=stripos($version,'MariaDB')!==false?'MariaDB':'MySQL';
    preg_match('/\d+\.\d+\.\d+/', $version,$m);
    $numeric=$m[0]??'0.0.0';
    $minimum=$family==='MariaDB'?'10.11.2':'8.0.16';
    $supported=version_compare($numeric,$minimum,'>=') && !preg_match('/TiDB|Cockroach|Vitess/i',$version.' '.$comment);
    return compact('family','numeric','minimum','supported');
}
if (realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__) return;
if (in_array('--self-test',$argv,true)) {
    foreach ([['5.7.44',false],['8.0.15',false],['8.0.16',true],['8.4.0',true],['10.4.32-MariaDB',false],['10.11.2-MariaDB',true],['11.4.0-MariaDB',true],['garbage',false]] as [$v,$expected]) {
        if(capabilityBaseline($v)['supported']!==$expected) {fwrite(STDERR,'Version classification failed');exit(1);}
    }
    echo json_encode(['version_cases'=>8,'errors'=>0]),PHP_EOL;exit(0);
}
$dsn=getenv('DB_CAPABILITIES_DSN');
if (!$dsn || !str_starts_with($dsn,'mysql:')) {fwrite(STDERR,"Set DB_CAPABILITIES_DSN explicitly (mysql PDO DSN); no .env is loaded.\n");exit(2);}
try {
    $pdo=new PDO($dsn,getenv('DB_CAPABILITIES_USER')?:'',getenv('DB_CAPABILITIES_PASSWORD')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $vars=$pdo->query('SELECT VERSION() AS version, @@version_comment AS comment, @@character_set_server AS charset, @@collation_server AS collation, @@foreign_key_checks AS fk_checks, @@sql_mode AS sql_mode, DATABASE() AS db')->fetch(PDO::FETCH_ASSOC);
    $baseline=capabilityBaseline($vars['version'],$vars['comment']);$fail=[];
    if(!$baseline['supported'])$fail[]='ENGINE_VERSION_BELOW_BASELINE_OR_UNSUPPORTED';
    $innodb=null;foreach($pdo->query('SHOW ENGINES') as $engine)if(strcasecmp($engine['Engine'],'InnoDB')===0)$innodb=$engine;
    if(!$innodb || !in_array(strtoupper($innodb['Support']),['YES','DEFAULT'],true))$fail[]='INNODB_UNAVAILABLE';
    if(($innodb['Transactions']??'NO')!=='YES'||($innodb['Savepoints']??'NO')!=='YES')$fail[]='TRANSACTIONS_UNAVAILABLE';
    if((int)$vars['fk_checks']!==1)$fail[]='FOREIGN_KEY_CHECKS_DISABLED';
    if($vars['charset']!=='utf8mb4')$fail[]='SERVER_CHARSET_NOT_UTF8MB4';
    if(!str_contains($vars['sql_mode'],'STRICT_TRANS_TABLES')&&!str_contains($vars['sql_mode'],'STRICT_ALL_TABLES'))$fail[]='STRICT_MODE_REQUIRED';
    $checkStatus='VERSION_SUPPORTED_NOT_EMPIRICALLY_PROBED';
    if($baseline['family']==='MariaDB') {
        $enabled=$pdo->query('SELECT @@check_constraint_checks')->fetchColumn();
        if((int)$enabled!==1){$fail[]='CHECK_CONSTRAINT_CHECKS_DISABLED';$checkStatus='DISABLED';}
    }
    if(!$baseline['supported'])$checkStatus='UNSUPPORTED_PRODUCTION_BASELINE';
    $portableCollation=$pdo->query("SHOW COLLATION WHERE Collation='utf8mb4_unicode_ci'")->fetch(PDO::FETCH_ASSOC);
    if(!$portableCollation)$fail[]='PORTABLE_COLLATION_UNAVAILABLE';
    $tables=[];
    if($vars['db']){
        $q=$pdo->prepare('SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=\'BASE TABLE\'');$q->execute([$vars['db']]);
        foreach($q as $row){$tables[]=$row;if($row['ENGINE']!=='InnoDB')$fail[]='TABLE_NOT_INNODB:'.$row['TABLE_NAME'];if(!str_starts_with($row['TABLE_COLLATION']??'','utf8mb4_'))$fail[]='TABLE_NOT_UTF8MB4:'.$row['TABLE_NAME'];}
        if($baseline['family']==='MySQL' && $baseline['supported']){
            $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? AND CONSTRAINT_TYPE='CHECK' AND ENFORCED='NO'");$q->execute([$vars['db']]);if((int)$q->fetchColumn()>0)$fail[]='NOT_ENFORCED_CHECK_EXISTS';
        }
    }
    echo json_encode(['status'=>$fail?'DEPLOYMENT_BLOCKER':'READ_ONLY_PREFLIGHT_PASS','engine'=>$baseline,'server'=>$vars,'innodb'=>$innodb,'check_enforcement'=>$checkStatus,'empirical_qualification'=>'REQUIRED_IN_ISOLATED_DATABASE_BEFORE_DEPLOYMENT','tables_examined'=>count($tables),'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit($fail?1:0);
} catch(Throwable $e){fwrite(STDERR,"Capability diagnosis failed; SQLSTATE/error code: ".$e->getCode().". Credentials and connection details suppressed.\n");exit(2);}