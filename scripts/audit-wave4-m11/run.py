from pathlib import Path
import os,json,subprocess,time,secrets,hashlib,concurrent.futures,threading
ROOT=Path(__file__).resolve().parents[2];OUT=ROOT/'docs/database/physical/wave4_m11_audit';CONTAINER='mepa-wave4-m11-independent-mysql'
def sql(q):
 r=subprocess.run(['docker','exec',CONTAINER,'mysql','-uroot','-N','--batch','-e',q],capture_output=True,text=True)
 if r.returncode:raise RuntimeError(r.stderr)
 return r.stdout
for i in range(60):
 try:
  sql('SELECT 1');break
 except Exception:time.sleep(1)
password=secrets.token_urlsafe(32)
sql("CREATE USER 'm11audit'@'%' IDENTIFIED BY '"+password+"'; GRANT PROCESS, SELECT ON *.* TO 'm11audit'@'%';")
meta=json.loads(subprocess.check_output(['docker','inspect',CONTAINER],text=True))[0]
(OUT/'environment.json').write_text(json.dumps({'container':CONTAINER,'id':meta['Id'],'created':meta['Created'],'image':meta['Image'],'mounts':meta['Mounts'],'port':33114,'server':sql('SELECT VERSION(),@@sql_mode,@@transaction_isolation,@@foreign_key_checks,@@system_time_zone;'),'executor_reused':False},indent=2))
protected={p:p.read_bytes() for d in ['apps/api/app','apps/api/database/migrations','apps/api/tests','docs/adr','docs/reviews','docs/database','scripts'] for p in (ROOT/d).rglob('*') if p.is_file() and 'wave4_m11_audit' not in p.parts and 'audit-wave4-m11' not in p.parts}
(OUT/'source_baseline.json').write_text(json.dumps({str(p.relative_to(ROOT)):hashlib.sha256(b).hexdigest() for p,b in protected.items()},indent=2))
(OUT/'git_diff.txt').write_text(subprocess.check_output(['git','diff','--','apps/api/app/Domain/Events/CheckinService.php','apps/api/tests/Database/WaveThreeCheckinConcurrencyTest.php','scripts/wave3-checkin-worker.php'],cwd=ROOT,text=True))
results=[];guard=threading.Lock()
def env_for(prefix,schema):
 env=os.environ.copy();env.update({prefix+'_DSN':f'mysql:host=127.0.0.1;port=33114;dbname={schema}',prefix+'_USER':'m11audit',prefix+'_PASSWORD':password,prefix+'_ALLOW_SYNTHETIC':'1','WAVE1_KEEP_SCHEMA':'1','WAVE2_KEEP_SCHEMA':'1','M11_OUT':str(OUT)})
 return env
def run(label,suite,prefix='WAVE4',filter=None):
 schema='mepa_'+('wave3' if prefix=='WAVE3' else 'wave4')+'_test_m11_'+label.lower();sql(f"CREATE DATABASE {schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON {schema}.* TO 'm11audit'@'%';")
 env=env_for(prefix,schema)
 path='tests/Database/'+suite+'.php' if suite!='IndependentM11Test' else '../../scripts/audit-wave4-m11/IndependentM11Test.php'
 cmd=['php','vendor/phpunit/phpunit/phpunit',path,'--log-junit',str(OUT/(label+'.xml'))]
 if filter:cmd+=['--filter',filter]
 start=time.time()
 try:
  r=subprocess.run(cmd,cwd=ROOT/'apps/api',env=env,capture_output=True,text=True,timeout=240);status=r.returncode;log=r.stdout+r.stderr
 except subprocess.TimeoutExpired as e:status=124;log='OUTER_HARNESS_TIMEOUT '+str(e.stdout)
 (OUT/(label+'.log')).write_text(log,encoding='utf8')
 rec={'label':label,'suite':suite,'schema':schema,'exit':status,'seconds':round(time.time()-start,2),'filter':filter}
 with guard:
  results.append(rec);(OUT/'runs.json').write_text(json.dumps(results,indent=2));print(json.dumps(rec),flush=True)
 return rec,env
# preflight on virgin schema before any DDL
schema='mepa_wave4_test_m11_preflight';sql(f"CREATE DATABASE {schema}; GRANT ALL ON {schema}.* TO 'm11audit'@'%';")
env=env_for('WAVE4',schema);env.update({'DB_CAPABILITIES_DSN':env['WAVE4_DSN'],'DB_CAPABILITIES_USER':'m11audit','DB_CAPABILITIES_PASSWORD':password})
r=subprocess.run(['php','scripts/check-database-capabilities.php'],cwd=ROOT,env=env,capture_output=True,text=True);(OUT/'preflight.json').write_text(r.stdout);print('Preflight',r.returncode,flush=True)
for suite in ['IndependentM11Test','WaveFourChildCheckinBoundaryTest','WaveFourChildrenSafetyTest','WaveFourCheckoutConcurrencyTest','WaveFourDiscipleshipScopeTest','WaveFourEvangelismTest','WaveFourTemporalAuthorizationTest','WaveFourPhysicalTest']:
 rec,env=run(suite,suite)
 if suite=='WaveFourPhysicalTest':
  r=subprocess.run(['node','scripts/validate-wave4-schema.cjs','--output',str(OUT/'strict_parity.json'),'--inspection-output',str(OUT/'physical_inspection.json')],cwd=ROOT,env=env,capture_output=True,text=True);(OUT/'strict_parity.log').write_text(r.stdout+r.stderr)
run('WaveThreeDomainTest','WaveThreeDomainTest','WAVE3')
run('WaveThreeCheckinConcurrencyTest','WaveThreeCheckinConcurrencyTest','WAVE3')
f='test_same_person_different_sessions_do_not_share_a_checkin_lock'
for i in range(1,31):run('isolated_'+str(i),'WaveThreeCheckinConcurrencyTest','WAVE3',f)
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
 list(pool.map(lambda i:run('parallel_'+str(i),'WaveThreeCheckinConcurrencyTest','WAVE3',f),[1,2]))
# Relevant concurrent 10-worker child suite alongside each corrected-test run.
for i in range(1,11):
 with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
  a=pool.submit(run,'loaded_'+str(i),'WaveThreeCheckinConcurrencyTest','WAVE3',f)
  b=pool.submit(run,'load_child_'+str(i),'WaveFourChildCheckinBoundaryTest','WAVE4','test_concurrent');a.result();b.result()
# Preserve earlier evidence byte for byte; independent copies retain generated results.
for p,b in protected.items():
 if p.read_bytes()!=b:
  if p.parent==ROOT/'docs/database/physical':
   (OUT/('generated_'+p.name)).write_bytes(p.read_bytes());p.write_bytes(b)
changes=[str(p.relative_to(ROOT)) for p,b in protected.items() if p.read_bytes()!=b]
(OUT/'source_preservation.json').write_text(json.dumps({'changed':changes,'status':'PASS' if not changes else 'FAIL','files':len(protected)},indent=2))
print('FINISHED',flush=True)
