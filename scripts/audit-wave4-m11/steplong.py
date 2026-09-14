from pathlib import Path
import os,subprocess,secrets
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit';p=root/'scripts/audit-wave4-m11/StepLongLeaseTest.php';p.write_text(p.read_text(encoding='utf-8-sig'),encoding='utf8');password=secrets.token_urlsafe(32)
r=subprocess.run(['docker','exec','mepa-wave4-m11-independent-mysql','mysql','-uroot','-e',"CREATE USER 'm11steplong'@'%' IDENTIFIED BY '"+password+"'; GRANT PROCESS,SELECT ON *.* TO 'm11steplong'@'%'; GRANT ALL ON mepa_wave4_test_m11_ownv3.* TO 'm11steplong'@'%';"],capture_output=True,text=True);assert r.returncode==0
# Reuse only this audit's own prepared synthetic schema; no executor state.
env={**os.environ,'WAVE4_DSN':'mysql:host=127.0.0.1;port=33114;dbname=mepa_wave4_test_m11_ownv3','WAVE4_USER':'m11steplong','WAVE4_PASSWORD':password,'WAVE4_ALLOW_SYNTHETIC':'1','M11_OUT':str(out)}
r=subprocess.run(['php','vendor/phpunit/phpunit/phpunit',str(p),'--log-junit',str(out/'StepLongLease.xml'),'--filter','test_independent_step_expires_after_real_lock_wait'],cwd=root/'apps/api',env=env,capture_output=True,text=True,timeout=180);(out/'StepLongLease.log').write_text(r.stdout+r.stderr,encoding='utf8');print(r.returncode,r.stdout,r.stderr,flush=True)

with (out/'IndependentM11Final.log').open('a',encoding='utf8') as f:f.write('\nSupplemental StepLongLease completed; see StepLongLease.log.\n')
