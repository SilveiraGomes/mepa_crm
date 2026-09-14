from pathlib import Path
import subprocess,json,time
out=Path('docs/database/physical/wave4_m11_audit');q="SELECT UTC_TIMESTAMP(6),r.OBJECT_SCHEMA,r.OBJECT_NAME,r.INDEX_NAME,r.LOCK_MODE,r.LOCK_DATA,LEFT(rt.trx_query,130),b.INDEX_NAME,b.LOCK_MODE,b.LOCK_DATA,bt.trx_state,LEFT(bt.trx_query,160) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks r ON r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.data_locks b ON b.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID LEFT JOIN information_schema.innodb_trx rt ON rt.trx_id=w.REQUESTING_ENGINE_TRANSACTION_ID LEFT JOIN information_schema.innodb_trx bt ON bt.trx_id=w.BLOCKING_ENGINE_TRANSACTION_ID WHERE r.OBJECT_SCHEMA LIKE 'mepa_%test_m11%';";rows=[]
for i in range(400):
 r=subprocess.run(['docker','exec','mepa-wave4-m11-independent-mysql','mysql','-uroot','-N','--batch','-e',q],capture_output=True,text=True)
 if r.stdout:rows.append({'sample':i,'output':r.stdout,'exit':r.returncode});(out/'blocker_traces.json').write_text(json.dumps(rows,indent=2))
 if (out/'isolation_qualification.json').exists():break
 time.sleep(3)
print('trace samples',len(rows),flush=True)
