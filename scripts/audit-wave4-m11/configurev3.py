from pathlib import Path
import subprocess,json,os,secrets,time
ROOT=Path(__file__).resolve().parents[2];OUT=ROOT/'docs/database/physical/wave4_m11_audit';c='mepa-wave4-m11-independent-mysql';password=secrets.token_urlsafe(32);schema='mepa_wave4_test_m11_configv3'
r=subprocess.run(['docker','exec',c,'mysql','-uroot','-e',"CREATE USER 'm11configv3'@'%' IDENTIFIED BY '"+password+"'; GRANT PROCESS, SELECT ON *.* TO 'm11configv3'@'%'; CREATE DATABASE "+schema+"; GRANT ALL ON "+schema+".* TO 'm11configv3'@'%';"],capture_output=True,text=True);assert r.returncode==0,r.stderr
env={**os.environ,'WAVE4_DSN':f'mysql:host=127.0.0.1;port=33114;dbname={schema}','WAVE4_USER':'m11configv3','WAVE4_PASSWORD':password,'WAVE4_ALLOW_SYNTHETIC':'1','M11_OUT':str(OUT)}
r=subprocess.run(['php','vendor/phpunit/phpunit/phpunit','../../scripts/audit-wave4-m11/ConfigureLateLockTest.php','--log-junit',str(OUT/'ConfigureLateLockV3.xml')],cwd=ROOT/'apps/api',env=env,capture_output=True,text=True,timeout=300);(OUT/'ConfigureLateLockV3.log').write_text(r.stdout+r.stderr,encoding='utf8');print(r.returncode,r.stdout,r.stderr,flush=True)
