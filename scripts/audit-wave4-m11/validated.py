from pathlib import Path
import os,subprocess,secrets
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit';p=root/'scripts/audit-wave4-m11/IndependentM11ValidatedTest.php';p.write_text(p.read_text(encoding='utf-8-sig'),encoding='utf8');password=secrets.token_urlsafe(32)
r=subprocess.run(['docker','exec','mepa-wave4-m11-independent-mysql','mysql','-uroot','-e',"CREATE USER 'm11validated'@'%' IDENTIFIED BY '"+password+"'; GRANT PROCESS,SELECT ON *.* TO 'm11validated'@'%'; GRANT ALL ON mepa_wave4_test_m11_ownv2.* TO 'm11validated'@'%';"],capture_output=True,text=True);assert r.returncode==0
# Reuse only this audit's own prepared synthetic schema; no executor state.
env={**os.environ,'WAVE4_DSN':'mysql:host=127.0.0.1;port=33114;dbname=mepa_wave4_test_m11_ownv2','WAVE4_USER':'m11validated','WAVE4_PASSWORD':password,'WAVE4_ALLOW_SYNTHETIC':'1','M11_OUT':str(out)}
r=subprocess.run(['php','vendor/phpunit/phpunit/phpunit',str(p),'--log-junit',str(out/'IndependentM11Validated.xml')],cwd=root/'apps/api',env=env,capture_output=True,text=True,timeout=120);(out/'IndependentM11Validated.log').write_text(r.stdout+r.stderr,encoding='utf8');print(r.returncode,r.stdout,r.stderr,flush=True)
