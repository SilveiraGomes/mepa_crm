from pathlib import Path
import os,secrets,subprocess,json,tempfile,time,shutil
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit';password=secrets.token_urlsafe(32);schema='mepa_wave3_test_m11_wavethreedomaintest'
r=subprocess.run(['docker','exec','mepa-wave4-m11-independent-mysql','mysql','-uroot','-e',"CREATE USER 'm11probe'@'%' IDENTIFIED BY '"+password+"'; GRANT SELECT ON "+schema+".* TO 'm11probe'@'%';"],capture_output=True,text=True);assert r.returncode==0
# A denied worker still signals done; real exit/payload must reject success.
env={**os.environ,'WAVE3_DSN':f'mysql:host=127.0.0.1;port=33114;dbname={schema}','WAVE3_USER':'m11probe','WAVE3_PASSWORD':password,'WAVE3_ALLOW_SYNTHETIC':'1'}
d=Path(tempfile.mkdtemp(prefix='mepa_m11_crash_probe_'));(d/'release').touch();job={'barrier':str(d),'worker':0,'fixture':{k:0 for k in ['actor','auth','device','event','session','person']},'token':'SYNTHETIC_INVALID','key':'SYNTHETIC_NEGATIVE'}
r=subprocess.run(['php',str(root/'scripts/wave3-checkin-worker.php')],cwd=root,env=env,input=json.dumps(job),capture_output=True,text=True,timeout=40)
rec={'exit':r.returncode,'done_exists':(d/'done_0').exists(),'ready_exists':(d/'ready_0').exists(),'stdout':r.stdout,'stderr':r.stderr,'collector_would_accept':r.returncode==0 and not r.stderr.strip(),'namespace':d.name};(out/'worker_failure_probe.json').write_text(json.dumps(rec,indent=2));print(json.dumps(rec),flush=True)
for p in d.iterdir():p.unlink()
d.rmdir()
