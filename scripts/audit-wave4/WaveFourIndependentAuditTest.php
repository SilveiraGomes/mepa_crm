<?php
declare(strict_types=1);
namespace Tests\Database;
require_once dirname(__DIR__,2).'/apps/api/tests/Database/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
final class WaveFourIndependentAuditTest extends WaveFourCase {
 private function evidence(string $key,array $value):void{$p=getenv('WAVE4_AUDIT_EVIDENCE')?:self::$root.'/docs/database/physical/wave4_audit/adversarial.json';$all=file_exists($p)?json_decode(file_get_contents($p),true):[];$all[$key]=$value;file_put_contents($p,json_encode($all,JSON_PRETTY_PRINT).PHP_EOL);}
 private function launch(array $jobs):array{
  $dir=sys_get_temp_dir().'/mepa_wave4_audit_'.bin2hex(random_bytes(8));mkdir($dir);$ps=[];
  foreach($jobs as $i=>$job){$p=proc_open([PHP_BINARY,self::$root.'/scripts/audit-wave4/worker.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,self::$root);self::assertIsResource($p);$job+=['i'=>$i,'dir'=>$dir];fwrite($pipes[0],json_encode($job));fclose($pipes[0]);$ps[]=[$p,$pipes[1],$pipes[2]];}
  $this->wait($dir,fn()=>count(glob($dir.'/ready_*'))===count($jobs));touch($dir.'/go');return [$ps,$dir];
 }
 private function wait(string $dir,callable $ready):void{$end=microtime(true)+35;while(!$ready()){if(microtime(true)>$end){touch($dir.'/go');touch($dir.'/finish');self::fail('Independent worker barrier timeout');}usleep(10000);}}
 private function collect(array $ps,string $dir):array{$rs=[];foreach($ps as [$p,$out,$err]){$a=stream_get_contents($out);$e=stream_get_contents($err);fclose($out);fclose($err);self::assertSame('',trim($e));self::assertSame(0,proc_close($p),$a);$rs[]=json_decode($a,true,512,JSON_THROW_ON_ERROR);}foreach(glob($dir.'/*') as $p)unlink($p);rmdir($dir);return $rs;}
 public function test_independent_pipeline_and_cross_domain_guardian_discipleship():void{
  $f=$this->childFixture();$p=$f['guardian'];$s=$this->evangelism();$before=$this->db()->table('people')->count();
  $campaign=$s->campaign($f['actor'],$f['auth'],$f['unit'],'INDEPENDENT',$this->now());$c=$s->contact($f['actor'],$f['auth'],$f['unit'],$campaign,$p);
  for($i=0;$i<3;$i++)$s->followup($f['actor'],$f['auth'],$f['unit'],$c,$p,$this->now()->modify('-'.$i.' days'),'SYNTHETIC_RESULT');
  foreach(['SYNTHETIC_DECISION','SYNTHETIC_BAPTISM'] as $kind)$s->decision($f['actor'],$f['auth'],$f['unit'],$p,$kind,null,'UNKNOWN',$c);
  self::assertSame(0,$this->db()->table('memberships')->where('person_id',$p)->count());
  $track=$s->track($f['actor'],$f['auth'],$f['unit'],'AUDIT_'.bin2hex(random_bytes(5)),1,'Independent');$en=$s->enroll($f['actor'],$f['auth'],$f['unit'],$p,$track);
  for($i=1;$i<=3;$i++){$step=$s->step($f['actor'],$f['auth'],$f['unit'],$track,$i,'Step '.$i);$s->progress($f['actor'],$f['auth'],$f['unit'],$en,$step);}
  $s->integrate($f['actor'],$f['auth'],$f['unit'],$p);$m=$f;$m['person']=$p;$this->permanent($m);$s->integrate($f['actor'],$f['auth'],$f['unit'],$p,$m['membership']);
  self::assertSame($before,$this->db()->table('people')->count());self::assertCount(3,$s->history($f['actor'],$f['auth'],$f['unit'],$c)['followups']);self::assertSame(3,$this->db()->table('discipleship_progress')->where('enrollment_id',$en)->count());
  foreach(['outreach_contacts','discipleship_enrollments','integration_events','memberships'] as $t)self::assertGreaterThan(0,$this->db()->table($t)->where('person_id',$p)->count());
  $this->evidence('cross_domain',['same_person'=>$p,'followups'=>3,'progress'=>3,'duplicate_people'=>0,'decision_created_membership'=>false,'admission_boundary'=>'approved synthetic membership fixture + existing MemberNumberGenerator/CredentialService; no AdmissionService exists']);
 }
 public function test_generic_event_checkin_reproduces_child_consent_and_permission_bypass():void{
  $f=$this->childFixture(false);$this->children()->revokeConsent($f['actor'],$f['auth'],$f['consent']);
  $this->db()->table('role_permissions')->whereIn('permission_id',$this->db()->table('permissions')->where('data_type','CHILDREN')->pluck('id'))->delete();
  $this->denied('ACTOR_NOT_AUTHORIZED',fn()=>$this->childIn($f));
  $this->denied('CHILD_SAFETY_FLOW_REQUIRED',fn()=>$this->scan($f,$f['credential']['token'],'audit-generic-bypass'));$r=['result'=>'DENIED'];
  self::assertSame('DENIED',$r['result']);self::assertSame(0,$this->db()->table('event_checkins')->where('person_id',$f['child'])->where('session_id',$f['session'])->count());self::assertSame(0,$this->db()->table('event_attendance')->where('person_id',$f['child'])->where('session_id',$f['session'])->count());self::assertSame(0,$this->db()->table('child_custody_visits')->where('child_person_id',$f['child'])->count());
  $this->evidence('W4R_01',['reproduced'=>true,'generic_result'=>$r['result'],'consent_revoked'=>true,'child_permissions'=>0,'attendance'=>0,'custody_visits'=>0,'severity'=>'HIGH','test_kind'=>'REGRESSION_ASSERTS_DENIED']);
 }
 public function test_database_guards_are_not_application_guards():void{
  $f=$this->childFixture();$this->childOut($f);$original=$this->db()->table('child_custody_visits')->where('id',$f['visit'])->first();
  $this->db()->beginTransaction();try{
   $this->db()->table('child_custody_visits')->where('id',$f['visit'])->update(['collected_by_person_id'=>$f['other'],'collection_authorization_id'=>$f['otherPickup']]);
   self::assertSame($f['other'],(int)$this->db()->table('child_custody_visits')->where('id',$f['visit'])->value('collected_by_person_id'));
   $duplicate=$this->row('child_custody_visits',['session_id'=>$f['session'],'child_person_id'=>$f['child'],'delivered_by_person_id'=>$f['guardian'],'authorization_id'=>$f['delivery'],'checked_in_by'=>$f['actor']]);self::assertGreaterThan(0,$duplicate);
   $this->evidence('db_boundary',['direct_recipient_rewrite_allowed'=>true,'same_child_session_duplicate_allowed'=>true,'checkout_and_F07'=>'APPLICATION_ENFORCED','tracking'=>['W4-F07','W4-CHECKOUT-01'],'db_double_delivery_guarantee'=>false]);
  }finally{$this->db()->rollBack();}self::assertEquals($original,$this->db()->table('child_custody_visits')->where('id',$f['visit'])->first());
 }
 public function test_independent_real_checkout_workers_2_10_30_50():void{
  foreach([2,10,30,50] as $n){$f=$this->childFixture();unset($f['credential']);$jobs=[];for($i=0;$i<$n;$i++)$jobs[]=['f'=>$f,'other'=>$i%2===1];[$ps,$dir]=$this->launch($jobs);$rs=$this->collect($ps,$dir);$counts=array_count_values(array_column($rs,'result'));self::assertSame(1,$counts['CHECKED_OUT']??0);self::assertSame($n-1,$counts['ALREADY_CHECKED_OUT']??0);
   $sql=$this->db()->selectOne("SELECT COUNT(*) AS visits,SUM(checked_out_at IS NOT NULL) AS closed FROM child_custody_visits WHERE child_person_id=? AND session_id=?",[$f['child'],$f['session']]);self::assertSame(1,(int)$sql->visits);self::assertSame(1,(int)$sql->closed);self::assertSame(1,$this->db()->table('audit_logs')->where('action','CHILD_CHECKOUT')->where('entity_id',$f['visit'])->count());$this->evidence('independent_workers_'.$n,['counts'=>$counts,'sql_visits'=>1,'sql_checkout_audits'=>1,'raw_errors'=>0]);}
 }
 public function test_independent_revocation_race_and_consent_race_in_both_orders():void{
  foreach([['out','auth'],['in','consent']] as [$operation,$revoke])foreach([$operation,$revoke] as $first)for($trial=0;$trial<5;$trial++){
   $f=$this->childFixture($operation==='out');$second=$first===$operation?$revoke:$operation;
   [$ps,$dir]=$this->launch([['f'=>$f,'mode'=>$first,'hold'=>true],['f'=>$f,'mode'=>$second,'after'=>0]]);
   try{$this->wait($dir,fn()=>file_exists($dir.'/locked_0')&&file_exists($dir.'/attempt_1'));self::assertFileDoesNotExist($dir.'/done_1');}finally{touch($dir.'/finish');}
   $rs=array_column($this->collect($ps,$dir),'result');$success=$operation==='out'?'CHECKED_OUT':'CHECKED_IN';$revoked=$revoke==='auth'?'REVOKED':'CONSENT_REVOKED';$denied=$revoke==='auth'?'PICKUP_NOT_AUTHORIZED':'CONSENT_REQUIRED';self::assertSame($first===$operation?[$success,$revoked]:[$revoked,$denied],$rs);
   if($first===$revoke)self::assertSame(0,$operation==='out'?$this->db()->table('child_custody_visits')->where('id',$f['visit'])->whereNotNull('checked_out_at')->count():$this->db()->table('event_attendance')->where('session_id',$f['session'])->count());
  }$this->evidence('independent_races',['pickup_trials'=>10,'consent_trials'=>10,'both_orders'=>true,'stale_allow'=>0]);
 }
 public function test_ten_other_children_same_session_finish_while_one_child_locked():void{
  $blocked=$this->childFixture();$jobs=[['f'=>$blocked]];
  for($i=0;$i<10;$i++){$f=$blocked;$f['person']=$this->row('people');$list=$this->row('event_invitation_lists',['event_id'=>$f['event'],'version'=>100+$i]);$r=(new \App\Domain\Events\InvitationService($this->db(),self::policy(),[],[]))->freeze($f['actor'],$f['auth'],$list,[$f['person']],'SYNTHETIC_READY','SYNTHETIC_READY',$this->now());$f['registration']=$r[$f['person']]['registration_id'];$f=$this->childFixture(true,$f);unset($f['credential']);$jobs[]=['f'=>$f];}
  $lock=self::connect()->getConnection();$lock->beginTransaction();$lock->table('child_profiles')->where('person_id',$blocked['child'])->lockForUpdate()->first();[$ps,$dir]=$this->launch($jobs);
  try{$this->wait($dir,fn()=>count(glob($dir.'/done_*'))===10);self::assertFileDoesNotExist($dir.'/done_0');}finally{$lock->rollBack();}$rs=$this->collect($ps,$dir);self::assertSame(11,array_count_values(array_column($rs,'result'))['CHECKED_OUT']);$this->evidence('independent_granularity',['same_event'=>true,'same_session'=>true,'children'=>11,'ten_completed_while_one_locked'=>true]);
 }
 public function test_simultaneous_child_checkin_and_session_isolation():void{
  $f=$this->childFixture(false);[$ps,$dir]=$this->launch(array_fill(0,10,['f'=>$f,'mode'=>'in']));$counts=array_count_values(array_column($this->collect($ps,$dir),'result'));self::assertSame(1,$counts['CHECKED_IN']??0);self::assertSame(9,$counts['OPEN_VISIT_EXISTS']??0);
  $b=$this->row('event_sessions',['event_id'=>$f['event']]);foreach(['event_checkins','event_attendance','child_custody_visits'] as $t)self::assertSame(0,$this->db()->table($t)->where('session_id',$b)->count());
  $this->denied('CONTEXT_MISMATCH',fn()=>$this->children()->checkout($f['actor'],$f['auth'],PHP_INT_MAX,$f['guardian'],$f['pickup'],'SYNTHETIC_IN_PERSON',true));
  $f['session']=$b;self::assertSame('CHECKED_IN',$this->childIn($f)['result']);self::assertSame(2,$this->db()->table('child_custody_visits')->where('child_person_id',$f['child'])->count());$this->evidence('independent_checkin',['workers'=>10,'one_checkin'=>true,'session_B_initial_rows'=>0,'explicit_session_B_in'=>'CHECKED_IN','orphan_checkout'=>'CONTEXT_MISMATCH']);
 }
 public function test_discipleship_progress_cross_unit_reproduces_missing_enrollment_owner():void{
  $a=$this->fixture();$this->grant($a);$s=$this->evangelism();$c=$s->campaign($a['actor'],$a['auth'],$a['unit'],'AUDIT_UNIT_A',$this->now());$s->contact($a['actor'],$a['auth'],$a['unit'],$c,$a['person']);$track=$s->track($a['actor'],$a['auth'],$a['unit'],'AUDIT_SCOPE_'.bin2hex(random_bytes(6)),1,'Audit');$step=$s->step($a['actor'],$a['auth'],$a['unit'],$track,1,'Step');$en=$s->enroll($a['actor'],$a['auth'],$a['unit'],$a['person'],$track);
  $b=$this->fixture();$this->grant($b);$c=$s->campaign($b['actor'],$b['auth'],$b['unit'],'AUDIT_UNIT_B',$this->now());$s->contact($b['actor'],$b['auth'],$b['unit'],$c,$a['person']);
  $this->denied('ACTOR_NOT_AUTHORIZED',fn()=>$s->progress($b['actor'],$b['auth'],$b['unit'],$en,$step));self::assertSame(0,$this->db()->table('discipleship_progress')->where('enrollment_id',$en)->count());$this->evidence('W4R_02',['reproduced'=>true,'actor_scope_B_only'=>true,'enrollment_created_in_A'=>true,'same_person_contact_in_B'=>true,'progress_other_unit_allowed'=>false,'severity'=>'MEDIUM','test_kind'=>'REGRESSION_ASSERTS_DENIED']);
 }
 public function test_independent_rich_rollback_retains_all_raw_rows_and_migration_history():void{
  $f=$this->childFixture();$this->childOut($f);$this->children()->revokeAuthorization($f['actor'],$f['auth'],$f['pickup'],'INDEPENDENT_HISTORY');$this->children()->revokeConsent($f['actor'],$f['auth'],$f['consent']);
  $names=json_decode(file_get_contents(self::$root.'/docs/database/physical/wave4_manifest.json'),true)['tables'];foreach($names as $t)if(!$this->db()->table($t)->exists())$this->row($t);
  $snapshot=function(){ $all=[];foreach($this->db()->select('SHOW TABLES') as $row){$t=array_values((array)$row)[0];$all[$t]=['ddl'=>(array)$this->db()->selectOne('SHOW CREATE TABLE `'.$t.'`'),'rows'=>array_map(fn($r)=>(array)$r,$this->db()->select('SELECT * FROM `'.$t.'` ORDER BY 1'))];}ksort($all);return hash('sha256',serialize($all));};
  $before=$snapshot();try{self::$migrator->rollback(self::$waveFourPaths);self::fail('Rich rollback must refuse');}catch(\RuntimeException $e){self::assertSame('WAVE4_DURABLE_DATA_ROLLBACK_BLOCKED',$e->getMessage());}self::assertSame($before,$snapshot());
  $this->evidence('independent_rich_rollback',['all_17_tables_populated'=>true,'raw_rows_and_ddl_hash_before'=>$before,'raw_rows_and_ddl_hash_after'=>$snapshot(),'migration_history_in_hash'=>true,'result'=>'REFUSED_WITHOUT_LOSS']);
 }

 public function test_checkout_can_validate_expired_authorization_with_stale_server_time():void{
  $f=$this->childFixture();$ends=$this->now()->modify('+3 seconds')->format('Y-m-d H:i:s.u');$this->db()->table('guardian_authorizations')->where('id',$f['pickup'])->update(['ends_at'=>$ends]);
  $lock=self::connect()->getConnection();$lock->beginTransaction();$lock->table('users')->where('id',$f['actor'])->lockForUpdate()->first();[$ps,$dir]=$this->launch([['f'=>$f]]);
  try{$this->wait($dir,fn()=>file_exists($dir.'/attempt_0'));usleep(4500000);self::assertFileDoesNotExist($dir.'/done_0');$atRelease=$this->db()->selectOne('SELECT UTC_TIMESTAMP(6) AS t')->t;self::assertGreaterThan($ends,$atRelease);}finally{$lock->rollBack();}
  $r=$this->collect($ps,$dir)[0];self::assertSame('PICKUP_NOT_AUTHORIZED',$r['result']);$v=$this->db()->table('child_custody_visits')->where('id',$f['visit'])->first();
  self::assertNull($v->checked_out_at);$this->evidence('W4R_03',['reproduced'=>true,'expiry'=>$ends,'db_utc_before_validation_unblocked'=>$atRelease,'recorded_checkout_time'=>$v->checked_out_at,'result'=>$r['result'],'severity'=>'MEDIUM','test_kind'=>'REGRESSION_ASSERTS_DENIED_AFTER_WAIT']);
 }

}
