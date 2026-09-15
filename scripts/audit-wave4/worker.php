<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/apps/api/vendor/autoload.php';
require dirname(__DIR__,2).'/apps/api/tests/Database/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\WaveFour\ChildrenService;
$job=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$db=WaveFourCase::connect()->getConnection();
$s=new ChildrenService($db,WaveFourCase::domainPolicy(),WaveFourCase::policy());
$f=$job['f'];$dir=$job['dir'];$i=$job['i'];
$wait=function($file){$until=microtime(true)+45;while(!file_exists($file)){if(microtime(true)>$until)throw new RuntimeException('audit barrier timeout');usleep(10000);}};
touch($dir.'/ready_'.$i);$wait($dir.'/go');
if(isset($job['after']))$wait($dir.'/locked_'.$job['after']);
if(!empty($job['hold'])){$db->beginTransaction();$db->table('child_profiles')->where('person_id',$f['child'])->lockForUpdate()->first();touch($dir.'/locked_'.$i);$wait($dir.'/finish');}
touch($dir.'/attempt_'.$i);
try{
 switch($job['mode']??'out'){
 case 'auth':$s->revokeAuthorization($f['actor'],$f['auth'],$f['pickup'],'INDEPENDENT_RACE');$r=['result'=>'REVOKED'];break;
 case 'consent':$s->revokeConsent($f['actor'],$f['auth'],$f['consent']);$r=['result'=>'CONSENT_REVOKED'];break;
 case 'in':$r=$s->checkin($f['actor'],$f['auth'],$f['device'],$f['event'],$f['session'],$f['child'],$f['guardian'],$f['delivery'],$f['credential']['token'],'audit-in-'.$i,'SYNTHETIC_IN_PERSON',true);break;
 default:$other=$job['other']??false;$r=$s->checkout($f['actor'],$f['auth'],$f['visit'],$other?$f['other']:$f['guardian'],$other?$f['otherPickup']:$f['pickup'],'SYNTHETIC_IN_PERSON',true);
 }
 if(!empty($job['hold']))$db->commit();
}catch(\App\Domain\WaveFour\DomainError|\App\Domain\Events\EventError $e){if(!empty($job['hold']))$db->rollBack();$r=['result'=>$e->reason];}
echo json_encode($r,JSON_THROW_ON_ERROR);touch($dir.'/done_'.$i);
