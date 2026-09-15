from pathlib import Path
import json,subprocess,hashlib,re
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_audit'
catalog=json.loads((root/'docs/database/model_catalog.json').read_text(encoding='utf8'))
waves=json.loads((root/'docs/database/physical/migration_waves.json').read_text(encoding='utf8'))
names=next(w['tables'] for w in waves['waves'] if w['wave']==4)
schema='mepa_wave4_test_independent_physical'
def q(sql):
 r=subprocess.run(['docker','exec','mepa-wave4-audit-mysql','mysql','-uroot','-N','--batch','--raw','-e',sql],capture_output=True,text=True,check=True)
 return [line.split('\t') for line in r.stdout.splitlines()]
columns=q("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='"+schema+"' ORDER BY TABLE_NAME,ORDINAL_POSITION")
fks=q("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='"+schema+"' AND REFERENCED_TABLE_NAME IS NOT NULL")
tables=q("SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='"+schema+"'")
constraints=q("SELECT TABLE_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='"+schema+"'")
rules=q("SELECT TABLE_NAME,DELETE_RULE,UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='"+schema+"'")
errors=[]
for t in catalog['tables']:
 if t['name'] not in names:continue
 actual=[c for c in columns if c[0]==t['name']]
 if [c[1] for c in actual]!=[c['name'] for c in t['columns']]:errors.append(t['name']+' column topology')
 for c in t['columns']:
  a=next((a for a in actual if a[1]==c['name']),None)
  if not a:continue
  if a[2]!=c['type'].lower().split(' character set ')[0] or (c['nullable']!=(a[3]=='YES')):errors.append(t['name']+'.'+c['name']+' type/null drift')
  if c.get('pk') and (a[2]!='bigint unsigned' or a[4]!='auto_increment'):errors.append(t['name']+' PK')
  if c.get('fk') and (a[2]!='bigint unsigned' or [t['name'],c['name'],c['fk'],'id'] not in fks):errors.append(t['name']+'.'+c['name']+' FK')
  if c['name']=='public_id' and (a[2]!='char(26)' or a[5:7]!=['ascii','ascii_bin']):errors.append(t['name']+' public_id')
  if c['name']=='code' and a[6]!='utf8mb4_bin':errors.append(t['name']+' code collation')
 for a in tables:
  if a[0]==t['name'] and a[1:]!=['InnoDB','utf8mb4_unicode_ci']:errors.append(t['name']+' engine/collation')
for f in fks:
 if f[0] in names and f[3]!='id':errors.append('FK structural non-id')
for a in rules:
 if a[0] in names and a[1:]!=['RESTRICT','RESTRICT']:errors.append(a[0]+' FK rule')
def counts(scope):return {'tables':len([t for t in tables if t[0] in scope]),'columns':len([c for c in columns if c[0] in scope]),'foreign_keys':len([f for f in fks if f[0] in scope]),'checks':len([c for c in constraints if c[0] in scope and c[1]=='CHECK']),'unique':len([c for c in constraints if c[0] in scope and c[1]=='UNIQUE']),'public_id':len([c for c in columns if c[0] in scope and c[1]=='public_id']),'cascade':len([r for r in rules if r[0] in scope and 'CASCADE' in r[1:]])}
physical=[t[0] for t in tables];recognized=[n for n in physical if n not in ['migrations','password_resets','failed_jobs','personal_access_tokens']]
result={'status':'PASS' if not errors else 'FAIL','method':'Independent raw SQL/catalogue comparison; no wave4 model library imported; full strict validator separately reexecuted','wave4':counts(names),'catalogued':counts(recognized),'physical_including_scaffolding':counts(physical),'errors':errors}
(out/'independent_sql_counts.json').write_text(json.dumps(result,indent=2),encoding='utf8');print(json.dumps(result,indent=2))
