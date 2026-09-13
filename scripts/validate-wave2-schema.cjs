'use strict';
const fs=require('node:fs');
const crypto=require('node:crypto');
const path=require('node:path');
const {spawnSync}=require('node:child_process');
const wave1=require('./lib/wave1-catalog.cjs');
const wave2=require('./lib/wave2-catalog.cjs');
const {root,catalog}=wave2;
const {parseCreate,compare}=require('./lib/wave1-schema.cjs');
const staticMode=process.argv.includes('--static');
const capture=spawnSync(process.env.PHP_BIN||'php',[path.join(root,'scripts/inspect-wave2-schema.php'),...(staticMode?['--capture']:[])],{cwd:root,encoding:'utf8',env:process.env});
if(capture.status!==0){process.stderr.write(capture.stderr||'Schema inspection unavailable\n');process.exit(2);}
let data;
try {data=JSON.parse(capture.stdout);}catch(e){console.error('Invalid inspection JSON');process.exit(2);}
const inspectionIndex=process.argv.indexOf('--inspection-output');
if(inspectionIndex>=0 && !staticMode)fs.writeFileSync(path.resolve(process.argv[inspectionIndex+1]),JSON.stringify(data,null,2)+'\n');

const wave1Tables=wave1.orderedTables();
const wave2Tables=wave2.orderedTables();
// Wave 2's 'users' migration ALTERs scaffolding instead of CREATE-ing a table, and the W1-F01
// migration only ADDs a FOREIGN KEY - neither emits a `CREATE TABLE` statement. In static mode
// the CREATE-only snapshot cannot show either, so 'files' is compared in its pre-materialization
// (staged) shape there, and both are checked separately against the captured ALTERs instead.
const expected=[...wave1Tables.map(t=>wave1.model(t,staticMode)),...wave2Tables.filter(t=>!staticMode||t.name!=='users').map(t=>wave2.model(t))];
const errors=[];
let alterStatements=[];
if(staticMode) {
  const createSql=data.up.filter(s=>s.startsWith('CREATE TABLE '));
  alterStatements=data.up.filter(s=>!s.startsWith('CREATE TABLE '));
  errors.push(...compare(expected,createSql.map(parseCreate)));
  const expectedCreateCount=wave1Tables.length+wave2Tables.length-1;
  if(createSql.length!==expectedCreateCount)errors.push({path:'migration_capture',error:'CREATE TABLE count drift'});
  const expectedAlterCount=1/*W1-F01*/+7/*users: 1 column ALTER + 3 unique keys + 1 FK + 2 CHECKs*/;
  if(alterStatements.length!==expectedAlterCount)errors.push({path:'migration_capture',error:'ALTER statement count drift'});
  if(!alterStatements.some(s=>/ALTER TABLE `files`[\s\S]*ADD CONSTRAINT `fk_files_owner_department_id` FOREIGN KEY \(`owner_department_id`\)[\s\S]*REFERENCES `department_instances` \(`id`\) ON DELETE RESTRICT ON UPDATE RESTRICT/.test(s)))
    errors.push({path:'files.fk_files_owner_department_id',error:'W1-F01 materialization ALTER not captured'});
  if(!alterStatements.some(s=>/ALTER TABLE `users`[\s\S]*ADD COLUMN `person_id`/.test(s)))errors.push({path:'users',error:'users adaptation ALTER not captured'});
} else {
  errors.push(...compare(expected,data.tables));
}
const tables=staticMode?data.up.filter(s=>s.startsWith('CREATE TABLE ')).map(parseCreate):data.tables;

const manifest=JSON.parse(fs.readFileSync(path.join(root,'docs/database/physical/wave2_manifest.json'),'utf8'));
// 'users' is deliberately adapted last (never DROP+CREATE, ALTER only after every new
// table exists) regardless of where the FK topological sort would otherwise place it.
const appliedOrder=[...wave2Tables.filter(t=>t.name!=='users').map(t=>t.name),'users'];
if(JSON.stringify(manifest.tables)!==JSON.stringify(appliedOrder))errors.push({path:'manifest.tables',error:'Wave 2 scope/order drift'});
const catalogHash=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'docs/database/model_catalog.json'))).digest('hex');
if(manifest.catalog_sha256!==catalogHash)errors.push({path:'manifest.catalog_sha256',error:'Approved catalogue changed since generation'});
const files=fs.readdirSync(path.join(root,'apps/api/database/migrations')).filter(n=>n.includes('_wave2_')).sort();
if(JSON.stringify(files)!==JSON.stringify([...manifest.migrations].sort()))errors.push({path:'migrations',error:'Missing or unregistered Wave 2 migration'});

const waves=JSON.parse(fs.readFileSync(path.join(root,'docs/database/physical/migration_waves.json'),'utf8'));
const all=waves.waves.flatMap(w=>w.tables);
if(all.length!==199||new Set(all).size!==199||catalog.tables.some(t=>!all.includes(t.name)))errors.push({path:'waves',error:'199-table partition drift'});
const assigned=Object.fromEntries(waves.waves.flatMap(w=>w.tables.map(t=>[t,w.wave])));
for(const t of catalog.tables)for(const c of t.columns.filter(c=>c.fk))if(assigned[c.fk]>assigned[t.name]
  && !(t.name==='files'&&c.name==='created_by')&&!wave1.deferred.some(d=>d.table===t.name&&d.column===c.name))errors.push({path:t.name+'.'+c.name,error:'Unplanned cross-wave dependency'});

// W1-F01 must now be MATERIALIZED: files.owner_department_id -> department_instances.id, RESTRICT/RESTRICT.
// Only checkable physically (information_schema) - static mode verified the ALTER text above.
let w1f01Ok=staticMode;
if(!staticMode) {
  const filesActual=tables.find(t=>t.name==='files');
  const w1f01Fk=filesActual?.foreign_keys.find(f=>f.name==='fk_files_owner_department_id');
  w1f01Ok=!!w1f01Fk && w1f01Fk.target_table==='department_instances' && w1f01Fk.on_delete==='RESTRICT' && w1f01Fk.on_update==='RESTRICT';
  if(!w1f01Ok)errors.push({path:'files.fk_files_owner_department_id',error:'W1-F01 not materialized as RESTRICT/RESTRICT'});
}

// Global CASCADE audit: zero non-RESTRICT delete/update rule anywhere in the inspected union.
const nonRestrict=tables.flatMap(t=>t.foreign_keys.filter(f=>f.on_delete!=='RESTRICT'||f.on_update!=='RESTRICT').map(f=>({table:t.name,fk:f.name,on_delete:f.on_delete,on_update:f.on_update})));
for(const n of nonRestrict)errors.push({path:n.table+'.'+n.fk,error:'Non-RESTRICT FK action: '+n.on_delete+'/'+n.on_update});

const report={mode:data.mode,engine:data.engine||null,physical_execution:!staticMode,
  tables:tables.length,columns:tables.reduce((n,t)=>n+t.columns.length,0),foreign_keys:tables.reduce((n,t)=>n+t.foreign_keys.length,0),
  checks:tables.reduce((n,t)=>n+t.checks.length,0),cascade_count:nonRestrict.length,
  w1_f01:{status:'MATERIALIZED',verified:w1f01Ok},
  status:errors.length?'DRIFT_DETECTED':'STRICT_PARITY_PASS',errors};
const outIndex=process.argv.indexOf('--output');
if(outIndex>=0)fs.writeFileSync(path.resolve(process.argv[outIndex+1]),JSON.stringify(report,null,2)+'\n');
console.log(JSON.stringify(report,null,2));
process.exit(errors.length?1:0);
