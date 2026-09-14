<?php
declare(strict_types=1);
namespace Tests\Database;
require_once dirname(__DIR__,2).'/apps/api/tests/Database/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\WaveFour\DomainClock;
final class ConfigureLateLockTest extends WaveFourCase {
 private function wait(callable $c,string $m,int $seconds=25):void{$end=microtime(true)+$seconds;while(!$c()){if(microtime(true)>$end)self::fail($m);usleep(10000);}}
 public function test_configuration_expiring_while_audit_fk_waits():void {
  $f=$this->fixture();$role=$this->row('roles');$scope=$this->row('scopes',['unit_id'=>$f['unit'],'include_descendants'=>0]);
  foreach(['OUTREACH_WRITE','DISCIPLESHIP_CONFIGURE'] as $code){$perm=$this->row('permissions',['code'=>$code,'action'=>$code,'data_type'=>'EVANGELISM']);$r=$code==='OUTREACH_WRITE'?$role:$this->row('roles');$this->row('role_permissions',['role_id'=>$r,'permission_id'=>$perm]);$grant=$this->row('user_role_scopes',['user_id'=>$f['actor'],'role_id'=>$r,'scope_id'=>$scope,'granted_by'=>$f['actor'],'ends_at'=>null]);if($code==='DISCIPLESHIP_CONFIGURE')$configGrant=$grant;}
  $f['code']='SYNTHETIC_LATE_'.bin2hex(random_bytes(8));
  $before=$this->db()->table('discipleship_tracks')->count();$audit=$this->db()->table('audit_logs')->count();
  $lock=self::connect()->getConnection();$lock->beginTransaction();$lock->table('organizational_units')->where('id',$f['unit'])->lockForUpdate()->first();
  $dir=sys_get_temp_dir().'/mepa_m11_config_'.bin2hex(random_bytes(16));mkdir($dir);$p=proc_open([PHP_BINARY,self::$root.'/scripts/audit-wave4-m11/worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,self::$root);fwrite($pipes[0],json_encode(['dir'=>$dir,'f'=>$f,'method'=>'track']));fclose($pipes[0]);
  try {
   $this->wait(fn()=>file_exists($dir.'/ready'),'ready');$this->db()->statement('UPDATE user_role_scopes SET ends_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 15 SECOND) WHERE id=?',[$configGrant]);$expiry=$this->db()->table('user_role_scopes')->where('id',$configGrant)->value('ends_at');touch($dir.'/go');$schema=$this->db()->getDatabaseName();$waits=[];
   $this->wait(function()use(&$waits,$schema){$waits=$this->db()->select('SELECT t.trx_state,t.trx_query FROM information_schema.innodb_trx t JOIN information_schema.processlist p ON p.id=t.trx_mysql_thread_id WHERE p.db=? AND t.trx_state=?',[$schema,'LOCK WAIT']);foreach($waits as $w)if(str_contains($w->trx_query??'','audit_logs'))return true;return false;},'audit insert FK lock wait not observed');
   self::assertLessThan($expiry,DomainClock::now($this->db())->format('Y-m-d H:i:s.u'));$this->wait(fn()=>DomainClock::now($this->db())->format('Y-m-d H:i:s.u')>$expiry,'expiry',30);
  }finally{$lock->rollBack();}
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);self::assertSame(0,$exit,$out.$err);$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);$delta=$this->db()->table('discipleship_tracks')->count()-$before;$ad=$this->db()->table('audit_logs')->count()-$audit;
  $v=['result'=>$r,'config_expiry'=>$expiry,'after'=>DomainClock::now($this->db())->format('Y-m-d H:i:s.u'),'observed_wait'=>$waits,'tracks_delta'=>$delta,'audit_delta'=>$ad,'expected'=>'ACTOR_NOT_AUTHORIZED and zero writes'];file_put_contents(getenv('M11_OUT').'/configure_late_lock.json',json_encode($v,JSON_PRETTY_PRINT));
  // Audit records observed behaviour; PASS here means the vulnerability was reproduced.
  self::assertSame('PASS',$r['result']);self::assertSame(1,$delta);self::assertSame(1,$ad);
  unlink($dir.'/ready');unlink($dir.'/go');rmdir($dir);
 }
}
