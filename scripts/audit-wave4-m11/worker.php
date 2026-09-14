<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/apps/api/vendor/autoload.php';
require dirname(__DIR__,2).'/apps/api/tests/Database/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\WaveFour\DomainError;
try {
 $j=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
 $db=WaveFourCase::connect()->getConnection();
$db->setEventDispatcher(new Illuminate\Events\Dispatcher()); $seen=[];
 $db->listen(function($q)use(&$seen){$s=strtolower($q->sql);if(str_starts_with($s,'insert'))$seen[]=$q->sql;});
 $s=new App\Domain\WaveFour\EvangelismService($db,WaveFourCase::domainPolicy(),WaveFourCase::policy());$f=$j['f'];
 touch($j['dir'].'/ready');
 $deadline=microtime(true)+40;
 while(!file_exists($j['dir'].'/go')){if(microtime(true)>$deadline)throw new RuntimeException('barrier timeout');usleep(10000);}
 $started=App\Domain\WaveFour\DomainClock::now($db)->format('Y-m-d H:i:s.u');
 try {
  $id=match($j['method']){
   'contact'=>$s->contact($f['actor'],$f['auth'],$f['unit'],$f['campaign'],$f['target']),
   'track'=>$s->track($f['actor'],$f['auth'],$f['unit'],$f['code'],1,'SYNTHETIC_M11'),
   'step'=>$s->step($f['actor'],$f['auth'],$f['unit'],$f['track'],1,'SYNTHETIC_M11_STEP'),
   'progress'=>$s->progress($f['actor'],$f['auth'],$f['unit'],$f['enroll'],$f['step']),
  };$result=['result'=>'PASS','id'=>$id];
 }catch(DomainError $e){$result=['result'=>$e->reason];}
 echo json_encode($result+['started'=>$started,'insert_statements'=>$seen,'transaction_level'=>$db->transactionLevel()]),PHP_EOL;
}catch(Throwable $e){echo json_encode(['error'=>get_class($e),'message'=>$e->getMessage()]),PHP_EOL;exit(3);}
