from pathlib import Path
import subprocess,json,time
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit';c='mepa-wave4-m11-independent-mysql';records=[]
for i in range(500):
 r=subprocess.run(['docker','exec',c,'mysql','-uroot','-N','--batch','-e',"SELECT NOW(6),t.trx_id,t.trx_state,t.trx_wait_started,LEFT(t.trx_query,180),p.DB FROM information_schema.innodb_trx t JOIN information_schema.processlist p ON p.ID=t.trx_mysql_thread_id WHERE p.USER LIKE 'm11%'; SELECT NOW(6),COUNT(*) FROM performance_schema.data_lock_waits; SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_deadlocks','Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_row_lock_current_waits');"],capture_output=True,text=True)
 barriers=[p.name for p in Path(__import__('os').environ['TEMP']).glob('mepa_wave3_barrier_*')]
 records.append({'sample':i,'output':r.stdout,'exit':r.returncode,'barriers':barriers})
 (out/'innodb_observations.json').write_text(json.dumps(records,indent=2))
 if (out/'source_preservation.json').exists():break
 time.sleep(3)
print('observations',len(records),flush=True)
