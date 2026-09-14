from pathlib import Path
import subprocess,tempfile,json
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit';d=Path(tempfile.mkdtemp(prefix='mepa_m11_crash_collect_'));r=subprocess.run(['php',str(root/'scripts/audit-wave4-m11/liveness.php'),str(d),'crash'],cwd=root,capture_output=True,text=True,timeout=15);rec={'exit':r.returncode,'stdout':r.stdout,'stderr':r.stderr};(out/'worker_crash_collection.json').write_text(json.dumps(rec,indent=2));print(json.dumps(rec),flush=True)
for p in d.iterdir():p.unlink()
d.rmdir()
