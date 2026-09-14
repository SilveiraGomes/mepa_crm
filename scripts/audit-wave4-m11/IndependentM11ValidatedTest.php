<?php
declare(strict_types=1);
namespace Tests\Database;
require_once dirname(__DIR__,2).'/apps/api/tests/Database/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\WaveFour\DomainClock;
final class IndependentM11ValidatedTest extends WaveFourCase {
 public static function setUpBeforeClass():void{self::$root=dirname(__DIR__,2);self::$capsule=self::connect();self::$catalog=json_decode(file_get_contents(self::$root.'/docs/database/model_catalog.json'),true);}
 private array $evidence=[];
 private function save(string $name,array $v):void{$this->evidence[$name]=$v;file_put_contents(getenv('M11_OUT').'/validated_'.$name.'.json',json_encode($v,JSON_PRETTY_PRINT));}
 private function setupFlow():array{
  $f=$this->fixture();$this->grant($f);$s=$this->evangelism();
  $f['campaign']=$s->campaign($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC_M11',$this->now());
  $f['target']=$this->row('people');$f['track']=$s->track($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC_'.bin2hex(random_bytes(8)),1,'SYNTHETIC_M11');
  return $f;
 }
 private function wait(callable $condition,string $why,int $seconds=25):void{$end=microtime(true)+$seconds;while(!$condition()){if(microtime(true)>$end)self::fail($why);usleep(10000);}}
 private function race(string $method,bool $expire):void{
  $f=$this->setupFlow();$table='people';$id=$f['target'];$written='outreach_contacts';
  if($method==='step'){$table='discipleship_tracks';$id=$f['track'];$written='discipleship_steps';}
  if($method==='progress'){
   $s=$this->evangelism();$s->contact($f['actor'],$f['auth'],$f['unit'],$f['campaign'],$f['target']);
   $f['enroll']=$s->enroll($f['actor'],$f['auth'],$f['unit'],$f['target'],$f['track']);
   $f['step']=$s->step($f['actor'],$f['auth'],$f['unit'],$f['track'],1,'SYNTHETIC_M11');$table='discipleship_steps';$id=$f['step'];$written='discipleship_progress';
  }
  $before=$this->db()->table($written)->count();$audit=$this->db()->table('audit_logs')->count();
  $lock=self::connect()->getConnection();$lock->beginTransaction();$lock->table($table)->where('id',$id)->lockForUpdate()->first();
  $dir=sys_get_temp_dir().'/mepa_m11_independent_'.bin2hex(random_bytes(16));mkdir($dir);
  $p=proc_open([PHP_BINARY,self::$root.'/scripts/audit-wave4-m11/worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,self::$root);self::assertIsResource($p);
  fwrite($pipes[0],json_encode(['dir'=>$dir,'f'=>$f,'method'=>$method]));fclose($pipes[0]);
  try{
   $this->wait(fn()=>file_exists($dir.'/ready'),'worker readiness');
   $this->db()->statement('UPDATE auth_sessions SET expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL '.($expire?'15':'120').' SECOND) WHERE id=?',[$f['auth']]);
   $expiry=$this->db()->table('auth_sessions')->where('id',$f['auth'])->value('expires_at');touch($dir.'/go');
   $schema=$this->db()->getDatabaseName();$waitRows=[];
   $this->wait(function()use(&$waitRows,$table,$schema){$waitRows=$this->db()->select('SELECT t.trx_id,t.trx_state,t.trx_query,t.trx_wait_started FROM information_schema.innodb_trx t JOIN information_schema.processlist p ON p.id=t.trx_mysql_thread_id WHERE p.db=? AND t.trx_state=?',[$schema,'LOCK WAIT']);foreach($waitRows as $r)if(str_contains($r->trx_query??'',$table))return true;return false;},'real target LOCK WAIT not observed');
   // Reaching this target lock proves provisional authorization passed.
   if($expire)$this->wait(fn()=>DomainClock::now($this->db())->format('Y-m-d H:i:s.u')>$expiry,'real expiry',30);
  }finally{$lock->rollBack();}
  stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$out='';$err='';$exit=null;
  $this->wait(function()use($p,$pipes,&$out,&$err,&$exit){$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($p);if(!$status['running']){$exit=$status['exitcode'];return true;}return false;},'worker completion');
  $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);
  self::assertSame(0,$exit,$out.$err);self::assertSame('',$err);$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);
  self::assertSame($expire?'ACTOR_NOT_AUTHORIZED':'PASS',$r['result']);self::assertSame(0,$r['transaction_level']); self::assertLessThan($expiry,$r['started'],'worker starts while session valid');
  $delta=$this->db()->table($written)->count()-$before;$auditDelta=$this->db()->table('audit_logs')->count()-$audit;
  self::assertSame($expire?0:1,$delta);self::assertSame($expire?0:1,$auditDelta);
  if($method==='contact'&&$expire){self::assertCount(2,$r['insert_statements'],'business insert and success audit executed before final failure');}
  $this->save($method.'_'.($expire?'expired':'valid'),['result'=>$r,'sql_delta'=>$delta,'audit_delta'=>$auditDelta,'expiry'=>$expiry,'observed_lock_wait'=>$waitRows,'barrier'=>basename($dir)]);
  unlink($dir.'/ready');unlink($dir.'/go');rmdir($dir);
 }
 public function test_independent_contact_expires_after_real_lock_wait():void{$this->race('contact',true);}
 public function test_independent_step_expires_after_real_lock_wait():void{$this->race('step',true);}
 public function test_independent_progress_expires_after_later_step_lock():void{$this->race('progress',true);}
 public function test_independent_valid_contact_after_real_lock_wait():void{$this->race('contact',false);}
 public function test_scope_provenance_adversarial():void{
  $f=$this->setupFlow();$s=$this->evangelism();$s->contact($f['actor'],$f['auth'],$f['unit'],$f['campaign'],$f['target']);$e=$s->enroll($f['actor'],$f['auth'],$f['unit'],$f['target'],$f['track']);$step=$s->step($f['actor'],$f['auth'],$f['unit'],$f['track'],1,'SYNTHETIC_M11');
  $b=$this->fixture();$this->grant($b);$c=$s->campaign($b['actor'],$b['auth'],$b['unit'],'SYNTHETIC_OTHER',$this->now());$s->contact($b['actor'],$b['auth'],$b['unit'],$c,$f['target']);
  $this->denied('ACTOR_NOT_AUTHORIZED',fn()=>$s->progress($b['actor'],$b['auth'],$b['unit'],$e,$step));
  $a=(array)$this->db()->table('audit_logs')->where('entity_type','discipleship_enrollments')->where('entity_id',$e)->first();unset($a['id']);
  $this->db()->table('audit_logs')->where('entity_type','discipleship_enrollments')->where('entity_id',$e)->delete();
  $this->denied('ENROLLMENT_SCOPE_UNRESOLVED',fn()=>$s->progress($f['actor'],$f['auth'],$f['unit'],$e,$step));
  $this->db()->table('audit_logs')->insert($a);$fake=$a;$fake['unit_id']=$b['unit'];$fake['entity_type']='outreach_contacts';$this->db()->table('audit_logs')->insert($fake);
  self::assertGreaterThan(0,$s->progress($f['actor'],$f['auth'],$f['unit'],$e,$step));
  $fake['entity_type']='discipleship_enrollments';$this->db()->table('audit_logs')->insert($fake);
  $this->denied('ENROLLMENT_SCOPE_UNRESOLVED',fn()=>$s->progress($f['actor'],$f['auth'],$f['unit'],$e,$step));
  $this->save('scope',['cross_unit'=>'DENIED','missing'=>'DENIED','unrelated'=>'IGNORED','same_scope'=>'PASS','ambiguous'=>'DENIED']);
 }
 public function test_clock_and_caller_dates():void{
  $f=$this->setupFlow();$s=$this->evangelism();$this->db()->statement('UPDATE auth_sessions SET expires_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 MICROSECOND) WHERE id=?',[$f['auth']]);
  foreach(['1900-01-01','2999-01-01'] as $date)$this->denied('ACTOR_NOT_AUTHORIZED',fn()=>$s->campaign($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC_DATE',new \DateTimeImmutable($date)));
  $this->db()->statement('UPDATE auth_sessions SET expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 60 SECOND) WHERE id=?',[$f['auth']]);self::assertGreaterThan(0,$s->campaign($f['actor'],$f['auth'],$f['unit'],'SYNTHETIC_FUTURE',new \DateTimeImmutable('2999-01-01')));
  $now=DomainClock::now($this->db());self::assertSame('UTC',$now->getTimezone()->getName());self::assertMatchesRegularExpression('/\.\d{6}$/',$now->format('Y-m-d H:i:s.u'));
  foreach((new \ReflectionClass($s))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m)foreach($m->getParameters() as $p)self::assertNotContains(strtolower($p->getName()),['now','clock','timestamp']);
  $this->save('clock',['utc'=>$now->format('Y-m-d H:i:s.u'),'one_microsecond_past'=>'DENIED','future'=>'PASS','caller_old_future_dates'=>'DENIED_WHEN_EXPIRED']);
 }
}
