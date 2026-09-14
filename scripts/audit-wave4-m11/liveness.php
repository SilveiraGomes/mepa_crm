<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/apps/api/vendor/autoload.php';
require dirname(__DIR__,2).'/apps/api/tests/Database/WaveThreeCheckinConcurrencyTest.php';
$dir=$argv[1];
$code=($argv[2]??'')==='crash'?'touch($argv[1]."/done_0");echo "{\"worker_error\":\"UNEXPECTED\"}\n";exit(3);':'touch($argv[1]."/done_0");sleep(120);';
$p=proc_open([PHP_BINARY,'-r',$code,$dir],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
fclose($pipes[0]);$status=proc_get_status($p);file_put_contents($dir.'/pid',(string)$status['pid']);
$test=new Tests\Database\WaveThreeCheckinConcurrencyTest('test_same_person_different_sessions_do_not_share_a_checkin_lock');
$m=new ReflectionMethod($test,'collect');$m->setAccessible(true);try{$m->invoke($test,[[$p,$pipes[1],$pipes[2]]],$dir);echo json_encode(['accepted'=>true]);}catch(Throwable $e){echo json_encode(['accepted'=>false,'assertion'=>get_class($e),'message'=>$e->getMessage(),'done_exists'=>file_exists($dir.'/done_0')]);}
