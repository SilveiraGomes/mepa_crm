from pathlib import Path
import subprocess,json,time
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit';c='mepa-wave4-m11-independent-mysql';rows=[]
q="SELECT UTC_TIMESTAMP(6),p.DB,t.trx_state,LEFT(t.trx_query,95),r.OBJECT_NAME,r.INDEX_NAME,r.LOCK_MODE,r.LOCK_DATA,b.INDEX_NAME,b.LOCK_MODE,b.LOCK_DATA FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks r ON r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.data_locks b ON b.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID LEFT JOIN information_schema.innodb_trx t ON t.trx_id=w.REQUESTING_ENGINE_TRANSACTION_ID LEFT JOIN information_schema.processlist p ON p.ID=t.trx_mysql_thread_id WHERE r.OBJECT_SCHEMA LIKE 'mepa_%test_m11%';"
for i in range(800):
 r=subprocess.run(['docker','exec',c,'mysql','-uroot','-N','--batch','-e',q],capture_output=True,text=True)
 if r.stdout:rows.append({'sample':i,'sql_output':r.stdout,'exit':r.returncode});(out/'lock_wait_details.json').write_text(json.dumps(rows,indent=2))
 if (out/'isolation_qualification.json').exists():break
 time.sleep(2)
print('lock_detail_samples',len(rows),flush=True)
