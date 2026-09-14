<?php
declare(strict_types=1);
namespace Tests\Database;
require_once dirname(__DIR__,2).'/apps/api/tests/Database/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\WaveFour\DomainClock;
final class AdditionalM11Test extends WaveFourCase {
 public static function setUpBeforeClass():void{self::$root=dirname(__DIR__,2);self::$capsule=self::connect();self::$catalog=json_decode(file_get_contents(self::$root.'/docs/database/model_catalog.json'),true);}
 private function wait(callable $c,string $why,int $seconds=30):void{$end=microtime(true)+$seconds;while(!$c()){if(microtime(true)>$end)self::fail($why);usleep(10000);}}
 public function test_progress_session_expires_during_audit_fk_wait():void{
  $f=$this->fixture();$this->grant($f);$s=$this->evangelism();$c=$s->campaign($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC_M11_LATE',$this->now());$s->contact($f['actor'],$f['auth'],$f['unit'],$c,$f['person']);$track=$s->track($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC_LATE_'.bin2hex(random_bytes(8)),1,'SYNTHETIC_M11');$f['enroll']=$s->enroll($f['actor'],$f['auth'],$f['unit'],$f['person'],$track);$f['step']=$s->step($f['actor'],$f['auth'],$f['unit'],$track,1,'SYNTHETIC_M11');
  $before=$this->db()->table('discipleship_progress')->count();$audit=$this->db()->table('audit_logs')->count();$lock=self::connect()->getConnection();$lock->beginTransaction();$lock->table('organizational_units')->where('id',$f['unit'])->lockForUpdate()->first();
  $dir=sys_get_temp_dir().'/mepa_m11_progress_late_'.bin2hex(random_bytes(16));mkdir($dir);$p=proc_open([PHP_BINARY,self::$root.'/scripts/audit-wave4-m11/worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,self::$root);fwrite($pipes[0],json_encode(['dir'=>$dir,'f'=>$f,'method'=>'progress']));fclose($pipes[0]);
  try{
   $this->wait(fn()=>file_exists($dir.'/ready'),'ready');$this->db()->statement('UPDATE auth_sessions SET expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 15 SECOND) WHERE id=?',[$f['auth']]);$expiry=$this->db()->table('auth_sessions')->where('id',$f['auth'])->value('expires_at');touch($dir.'/go');$schema=$this->db()->getDatabaseName();$waits=[];
   $this->wait(function()use(&$waits,$schema){$waits=$this->db()->select('SELECT t.trx_state,t.trx_query FROM information_schema.innodb_trx t JOIN information_schema.processlist p ON p.id=t.trx_mysql_thread_id WHERE p.db=? AND t.trx_state=?',[$schema,'LOCK WAIT']);foreach($waits as $w)if(str_contains($w->trx_query??'','audit_logs'))return true;return false;},'audit FK wait');
   self::assertLessThan($expiry,DomainClock::now($this->db())->format('Y-m-d H:i:s.u'));$this->wait(fn()=>DomainClock::now($this->db())->format('Y-m-d H:i:s.u')>$expiry,'expiry',30);
  }finally{$lock->rollBack();}
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$out.$err);$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);$delta=$this->db()->table('discipleship_progress')->count()-$before;$ad=$this->db()->table('audit_logs')->count()-$audit;
  file_put_contents(getenv('M11_OUT').'/progress_late_lock.json',json_encode(['result'=>$r,'expiry'=>$expiry,'after'=>DomainClock::now($this->db())->format('Y-m-d H:i:s.u'),'observed_wait'=>$waits,'progress_delta'=>$delta,'audit_delta'=>$ad,'expected'=>'DENIED, zero writes'],JSON_PRETTY_PRINT));
  self::assertSame('PASS',$r['result']);self::assertSame(1,$delta);self::assertSame(1,$ad);unlink($dir.'/ready');unlink($dir.'/go');rmdir($dir);
 }
 public function test_already_expired_all_nine_writers():void{
  $f=$this->fixture();$this->grant($f);$s=$this->evangelism();$c=$s->campaign($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC_M11',$this->now());$contact=$s->contact($f['actor'],$f['auth'],$f['unit'],$c,$f['person']);$track=$s->track($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC_'.bin2hex(random_bytes(8)),1,'SYNTHETIC_M11');$step=$s->step($f['actor'],$f['auth'],$f['unit'],$track,1,'SYNTHETIC_M11');$e=$s->enroll($f['actor'],$f['auth'],$f['unit'],$f['person'],$track);
  $this->db()->statement('UPDATE auth_sessions SET expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 MICROSECOND) WHERE id=?',[$f['auth']]);
  $calls=[
   'campaign'=>fn()=>$s->campaign($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC',$this->now()),
   'contact'=>fn()=>$s->contact($f['actor'],$f['auth'],$f['unit'],$c,$f['person']),
   'followup'=>fn()=>$s->followup($f['actor'],$f['auth'],$f['unit'],$contact,$f['person'],$this->now(),'SYNTHETIC_OUTCOME'),
   'decision'=>fn()=>$s->decision($f['actor'],$f['auth'],$f['unit'],$f['person'],'SYNTHETIC_DECISION',null,'UNKNOWN'),
   'track'=>fn()=>$s->track($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC_EXPIRED',1,'SYNTHETIC'),
   'step'=>fn()=>$s->step($f['actor'],$f['auth'],$f['unit'],$track,2,'SYNTHETIC'),
   'enroll'=>fn()=>$s->enroll($f['actor'],$f['auth'],$f['unit'],$f['person'],$track),
   'integrate'=>fn()=>$s->integrate($f['actor'],$f['auth'],$f['unit'],$f['person']),
   'progress'=>fn()=>$s->progress($f['actor'],$f['auth'],$f['unit'],$e,$step)];
  $tables=['outreach_campaigns','outreach_contacts','followups','decisions','discipleship_tracks','discipleship_steps','discipleship_enrollments','integration_events','discipleship_progress','audit_logs'];$before=[];foreach($tables as $t)$before[$t]=$this->db()->table($t)->count();foreach($calls as $call)$this->denied('ACTOR_NOT_AUTHORIZED',$call);foreach($tables as $t)self::assertSame($before[$t],$this->db()->table($t)->count());file_put_contents(getenv('M11_OUT').'/expired_all_writers.json',json_encode(['writers'=>array_keys($calls),'result'=>'DENIED','rows_delta'=>0,'audit_delta'=>0],JSON_PRETTY_PRINT));
 }
}
