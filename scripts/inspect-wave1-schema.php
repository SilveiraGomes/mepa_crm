<?php

declare(strict_types=1);

// No .env, application boot, data SELECTs or credential discovery.
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/docs/database/physical/wave1_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (in_array('--capture', $argv, true)) {
    require $root.'/apps/api/vendor/autoload.php';
    $recorder = new class {
        public array $sql = [];
        public function getDriverName(): string { return 'mysql'; }
        public function statement(string $sql): bool { $this->sql[] = $sql; return true; }
    };
    Illuminate\Support\Facades\DB::swap($recorder);
    $migrations = [];
    foreach ($manifest['migrations'] as $entry) {
        $migration = require $root.'/apps/api/database/migrations/'.$entry['file'];
        $migration->up();
        $migrations[] = $migration;
    }
    $up = $recorder->sql;
    $recorder->sql = [];
    foreach (array_reverse($migrations) as $migration) $migration->down();
    echo json_encode(['mode'=>'STATIC_CAPTURE_NOT_EXECUTION', 'up'=>$up, 'down'=>$recorder->sql], JSON_PRETTY_PRINT), PHP_EOL;
    exit(0);
}

try {
    $dsn = getenv('WAVE1_DSN');
    if (!$dsn || !str_starts_with($dsn, 'mysql:') || !str_contains($dsn, 'dbname=')) {
        throw new RuntimeException('Explicit WAVE1_DSN with database is required');
    }
    $pdo = new PDO($dsn, getenv('WAVE1_USER') ?: '', getenv('WAVE1_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    require __DIR__.'/check-database-capabilities.php';
    $baseline = capabilityBaseline($version);
    if (!$baseline['supported'] || $baseline['family'] !== 'MySQL') throw new RuntimeException('Compatible MySQL 8.x is required');
    $pdo->exec("SET time_zone = '+00:00'");
    $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $query = static function (string $sql, array $parameters) use ($pdo): array {
        $stmt = $pdo->prepare($sql); $stmt->execute($parameters); return $stmt->fetchAll();
    };
    $tables = [];
    $snapshot = ['-- Schema-only snapshot from authorized isolated MySQL; no data.'];
    foreach ($manifest['tables'] as $name) {
        $row = $query('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$schema,$name])[0] ?? null;
        if (!$row) continue;
        $table = ['name'=>$name,'engine'=>$row['ENGINE'],'charset'=>explode('_',$row['TABLE_COLLATION'])[0],
            'collation'=>$row['TABLE_COLLATION'],'columns'=>[],'primary'=>[],'indexes'=>[],'foreign_keys'=>[],'checks'=>[]];
        foreach ($query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$schema,$name]) as $c) {
            $type = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/i','$1',strtolower($c['COLUMN_TYPE']));
            $table['columns'][] = ['name'=>$c['COLUMN_NAME'],'type'=>$type,'nullable'=>$c['IS_NULLABLE']==='YES',
                'default'=>$c['COLUMN_DEFAULT']===null ? null : (string)$c['COLUMN_DEFAULT'],
                'auto_increment'=>str_contains($c['EXTRA'],'auto_increment'),'charset'=>$c['CHARACTER_SET_NAME'],'collation'=>$c['COLLATION_NAME']];
        }
        $indexes = [];
        foreach ($query('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$schema,$name]) as $i) {
            if ($i['INDEX_NAME']==='PRIMARY') { $table['primary'][]=$i['COLUMN_NAME']; continue; }
            $indexes[$i['INDEX_NAME']]['name']=$i['INDEX_NAME'];
            $indexes[$i['INDEX_NAME']]['unique']=(int)$i['NON_UNIQUE']===0;
            $indexes[$i['INDEX_NAME']]['columns'][]=$i['COLUMN_NAME'];
            if ($i['SUB_PART']!==null) $indexes[$i['INDEX_NAME']]['prefix_length']=(int)$i['SUB_PART'];
        }
        $table['indexes']=array_values($indexes);
        $fks=[];
        foreach ($query('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=? AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION',[$schema,$name]) as $f) {
            $key=$f['CONSTRAINT_NAME'];
            $fks[$key]['name']=$key; $fks[$key]['columns'][]=$f['COLUMN_NAME'];
            $fks[$key]['target_table']=$f['REFERENCED_TABLE_NAME']; $fks[$key]['target_columns'][]=$f['REFERENCED_COLUMN_NAME'];
            $fks[$key]['on_delete']=$f['DELETE_RULE']; $fks[$key]['on_update']=$f['UPDATE_RULE'];
        }
        $table['foreign_keys']=array_values($fks);
        foreach ($query("SELECT c.CONSTRAINT_NAME,c.CHECK_CLAUSE,t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA=? AND t.TABLE_NAME=? AND t.CONSTRAINT_TYPE='CHECK'",[$schema,$name]) as $c) {
            $table['checks'][]=['name'=>$c['CONSTRAINT_NAME'],'expression'=>$c['CHECK_CLAUSE'],'enforced'=>$c['ENFORCED']==='YES'];
        }
        $tables[]=$table;
        $create=$pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch();
        $snapshot[]=preg_replace('/ AUTO_INCREMENT=\d+/','',$create['Create Table']).';';
    }
    echo json_encode(['mode'=>'INFORMATION_SCHEMA','engine'=>$version,'tables'=>$tables,'schema_sql'=>implode("\n\n",$snapshot)."\n"],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Wave 1 inspection blocked; error code: '.$e->getCode().'. No credentials or connection details emitted.'.PHP_EOL);
    exit(2);
}
