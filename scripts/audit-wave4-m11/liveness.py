from pathlib import Path
import subprocess,tempfile,time,json
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit';p=root/'scripts/audit-wave4-m11/liveness.php';p.write_text(p.read_text(encoding='utf-8-sig'),encoding='utf8');d=Path(tempfile.mkdtemp(prefix='mepa_m11_liveness_'));proc=subprocess.Popen(['php',str(p),str(d)],cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);start=time.time()
try:
 stdout,stderr=proc.communicate(timeout=6);rec={'outer_timeout':False,'exit':proc.returncode,'stdout':stdout,'stderr':stderr}
except subprocess.TimeoutExpired:
 rec={'outer_timeout':True,'collector_still_running':proc.poll() is None,'done_exists':(d/'done_0').exists(),'seconds':time.time()-start,'original_method':'WaveThreeCheckinConcurrencyTest::collect via reflection, no code modification','worker':'controlled sleeping worker after done','result':'unbounded collection confirmed; requires external timeout'}
 # Terminate only the process tree explicitly created by this probe.
 subprocess.run(['taskkill','/PID',str(proc.pid),'/T','/F'],capture_output=True);stdout,stderr=proc.communicate(timeout=10);rec['stderr']=stderr
(out/'process_liveness_probe.json').write_text(json.dumps(rec,indent=2));print(json.dumps(rec),flush=True)
for p in d.iterdir():p.unlink()
d.rmdir()
