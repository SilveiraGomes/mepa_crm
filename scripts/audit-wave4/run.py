from pathlib import Path
import json,hashlib,subprocess,os,secrets,time,xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[2]
OUT=ROOT/'docs/database/physical/wave4_audit'
OUT.mkdir(exist_ok=True)
CONTAINER='mepa-wave4-audit-mysql'
def docker(sql):
 r=subprocess.run(['docker','exec',CONTAINER,'mysql','-uroot','--batch','--raw','-e',sql],capture_output=True,text=True)
 if r.returncode: raise RuntimeError(r.stderr)
 return r.stdout
password=secrets.token_urlsafe(36)
docker("CREATE USER IF NOT EXISTS 'wave4audit'@'172.17.%' IDENTIFIED BY '"+password+"'; ALTER USER 'wave4audit'@'172.17.%' IDENTIFIED BY '"+password+"';")
base=os.environ.copy()
suites=[('WaveOnePhysicalTest','WAVE1','mepa_wave1_test_independent'),('WaveTwoPhysicalTest','WAVE2','mepa_wave2_test_independent'),('WaveTwoConcurrencyTest','WAVE2','mepa_wave2_test_independent_number'),('WaveTwoIndependentReconciliationTest','WAVE2F','mepa_wave2f_test_independent'),('WaveTwoTransferConcurrencyTest','WAVE2M1','mepa_wave2m1_test_independent'),('WaveTwoM1IndependentAuditTest','M1AUDIT','mepa_m1audit_test_independent'),('WaveTwoTransferMigrationSafetyTest','M1SAFETY','mepa_m1safety_test_independent'),('WaveThreePhysicalTest','WAVE3','mepa_wave3_test_independent'),('WaveThreeDomainTest','WAVE3','mepa_wave3_test_independent_domain'),('WaveThreeCheckinConcurrencyTest','WAVE3','mepa_wave3_test_independent_concurrency'),('WaveFourPhysicalTest','WAVE4','mepa_wave4_test_independent_physical'),('WaveFourEvangelismTest','WAVE4','mepa_wave4_test_independent_evangelism'),('WaveFourChildrenSafetyTest','WAVE4','mepa_wave4_test_independent_children'),('WaveFourCheckoutConcurrencyTest','WAVE4','mepa_wave4_test_independent_concurrency'),('WaveFourIndependentAuditTest','WAVE4','mepa_wave4_test_independent_adversarial')]
protected={p:p.read_bytes() for folder in ['apps/api/app','apps/api/database/migrations','apps/api/tests/Database','docs/adr','docs/database','docs/reviews'] for p in (ROOT/folder).rglob('*') if p.is_file() and 'wave4_audit' not in p.parts and not p.name.startswith('P0.3.4_wave4_audit') and not p.name.startswith('P0.3.4_wave4_gate')}
if not (OUT/'source_baseline.json').exists(): (OUT/'source_baseline.json').write_text(json.dumps({str(p.relative_to(ROOT)):hashlib.sha256(b).hexdigest() for p,b in protected.items()},indent=2),encoding='utf8')
meta=json.loads(subprocess.check_output(['docker','inspect',CONTAINER],text=True))[0]
(OUT/'environment.json').write_text(json.dumps({'container':CONTAINER,'id':meta['Id'],'image':meta['Image'],'created':meta['Created'],'mounts':meta['Mounts'],'port':33104,'sql':docker('SELECT VERSION(),@@sql_mode,@@foreign_key_checks,@@transaction_isolation;'),'executor_reused':False,'password_recorded':False},indent=2),encoding='utf8')
results=json.loads((OUT/'suite_runs.json').read_text()) if (OUT/'suite_runs.json').exists() else []
for suite,prefix,schema in suites[len(results) :]:
 docker(f"CREATE DATABASE {schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON {schema}.* TO 'wave4audit'@'172.17.%';")
 env=base.copy()
 env.update({prefix+'_DSN':f'mysql:host=127.0.0.1;port=33104;dbname={schema}',prefix+'_USER':'wave4audit',prefix+'_PASSWORD':password,prefix+'_ALLOW_SYNTHETIC':'1','WAVE1_KEEP_SCHEMA':'1','WAVE2_KEEP_SCHEMA':'1'})
 before={p:p.read_bytes() for p in (ROOT/'docs/database/physical').glob('*') if p.is_file()}
 start=time.time()
 path='tests/Database/'+suite+'.php' if suite!='WaveFourIndependentAuditTest' else '../../scripts/audit-wave4/WaveFourIndependentAuditTest.php'
 r=subprocess.run(['php','vendor/phpunit/phpunit/phpunit',path,'--log-junit',str(OUT/(suite+'.xml'))],cwd=ROOT/'apps/api',env=env,capture_output=True,text=True)
 (OUT/(suite+'.log')).write_text(r.stdout+r.stderr,encoding='utf8')
 for p in (ROOT/'docs/database/physical').glob('*'):
  if p.is_file() and (p not in before or p.read_bytes()!=before[p]):
   (OUT/(suite+'__'+p.name)).write_bytes(p.read_bytes())
   if p in before:p.write_bytes(before[p])
   else:p.unlink()
 results.append({'suite':suite,'schema':schema,'exit':r.returncode,'seconds':round(time.time()-start,2)})
 print(json.dumps(results[-1]),flush=True)
 (OUT/'suite_runs.json').write_text(json.dumps(results,indent=2),encoding='utf8')
 if suite=='WaveFourPhysicalTest':
  v=subprocess.run(['node','scripts/validate-wave4-schema.cjs','--output',str(OUT/'strict_parity.json'),'--inspection-output',str(OUT/'physical_inspection.json')],cwd=ROOT,env=env,capture_output=True,text=True)
  (OUT/'strict_parity.log').write_text(v.stdout+v.stderr,encoding='utf8')
  (OUT/'counts_raw.tsv').write_text(docker(f"SELECT COUNT(*) AS tables FROM information_schema.TABLES WHERE TABLE_SCHEMA='{schema}'; SELECT COUNT(*) AS columns FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{schema}'; SELECT COUNT(*) AS fk FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='{schema}'; SELECT COUNT(*) AS checks FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='{schema}' AND CONSTRAINT_TYPE='CHECK'; SELECT COUNT(*) AS unique_keys FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='{schema}' AND CONSTRAINT_TYPE='UNIQUE'; SELECT COUNT(*) AS cascade_count FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='{schema}' AND (DELETE_RULE='CASCADE' OR UPDATE_RULE='CASCADE');"),encoding='utf8')
# never store password or environment
initial=json.loads((OUT/'source_baseline.json').read_text()); changes=[p for p,h in initial.items() if not (ROOT/p).exists() or hashlib.sha256((ROOT/p).read_bytes()).hexdigest()!=h]
(OUT/'source_preservation.json').write_text(json.dumps({'changed':changes,'files':len(initial),'status':'PASS' if not changes else 'FAIL'},indent=2),encoding='utf8')
