from pathlib import Path
import subprocess,os,secrets,json,time
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit'
while not (out/'source_preservation.json').exists():time.sleep(2)
runs=json.loads((out/'runs.json').read_text());aux_end=(out/'IndependentM11Final.log').stat().st_mtime
excluded=[r['label'] for r in runs if r['label'].startswith('isolated_') and (out/(r['label']+'.xml')).stat().st_mtime-r['seconds']<aux_end]
password=secrets.token_urlsafe(32);c='mepa-wave4-m11-independent-mysql'
def sql(q):
 r=subprocess.run(['docker','exec',c,'mysql','-uroot','-e',q],capture_output=True,text=True);assert r.returncode==0,r.stderr
sql("CREATE USER 'm11repeat'@'%' IDENTIFIED BY '"+password+"';")
protected={p:p.read_bytes() for p in (root/'docs/database/physical').glob('*') if p.is_file()};records=[]
for i in range(1,len(excluded)+1):
 schema='mepa_wave3_test_m11_extra_isolated_'+str(i);sql(f"CREATE DATABASE {schema}; GRANT ALL ON {schema}.* TO 'm11repeat'@'%';")
 env={**os.environ,'WAVE3_DSN':f'mysql:host=127.0.0.1;port=33114;dbname={schema}','WAVE3_USER':'m11repeat','WAVE3_PASSWORD':password,'WAVE3_ALLOW_SYNTHETIC':'1'};label='extra_isolated_'+str(i);start=time.time()
 r=subprocess.run(['php','vendor/phpunit/phpunit/phpunit','tests/Database/WaveThreeCheckinConcurrencyTest.php','--filter','test_same_person_different_sessions_do_not_share_a_checkin_lock','--log-junit',str(out/(label+'.xml'))],cwd=root/'apps/api',env=env,capture_output=True,text=True,timeout=240);(out/(label+'.log')).write_text(r.stdout+r.stderr,encoding='utf8');rec={'label':label,'exit':r.returncode,'seconds':time.time()-start,'schema':schema,'start':start,'end':time.time()};records.append(rec);print(json.dumps(rec),flush=True)
for p,b in protected.items():
 if p.read_bytes()!=b:(out/('extra_generated_'+p.name)).write_bytes(p.read_bytes());p.write_bytes(b)
(out/'isolation_qualification.json').write_text(json.dumps({'reason':'Early filtered runs overlapped development of independent probes; not counted as isolated. No failures removed and no retry of a failed repetition.','excluded_from_isolated':excluded,'aux_end':aux_end,'additional_runs':records,'qualified_isolated':[r['label'] for r in runs if r['label'].startswith('isolated_') and r['label'] not in excluded]+[r['label'] for r in records]},indent=2));print('ISOLATION_FINISHED',flush=True)
