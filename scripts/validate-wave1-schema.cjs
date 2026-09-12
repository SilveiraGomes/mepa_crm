'use strict';
const fs=require('node:fs');
const crypto=require('node:crypto');
const path=require('node:path');
const {spawnSync}=require('node:child_process');
const {root,catalog,names,deferred,orderedTables,model}=require('./lib/wave1-catalog.cjs');
const {parseCreate,compare}=require('./lib/wave1-schema.cjs');
const staticMode=process.argv.includes('--static');
const allowDeferred=process.argv.includes('--allow-deferred');
const capture=spawnSync(process.env.PHP_BIN||'php',[path.join(root,'scripts/inspect-wave1-schema.php'),...(staticMode?['--capture']:[])],{cwd:root,encoding:'utf8',env:process.env});
if(capture.status!==0){process.stderr.write(capture.stderr||'Schema inspection unavailable\n');process.exit(2);}
let data;
try {data=JSON.parse(capture.stdout);}catch(e){console.error('Invalid inspection JSON');process.exit(2);}
const inspectionIndex=process.argv.indexOf('--inspection-output');
if(inspectionIndex>=0 && !staticMode)fs.writeFileSync(path.resolve(process.argv[inspectionIndex+1]),JSON.stringify(data,null,2)+'\n');
const tables=staticMode?data.up.map(parseCreate):data.tables;
const errors=compare(orderedTables().map(t=>model(t,allowDeferred)),tables);
const strictErrors=compare(orderedTables().map(t=>model(t)),tables);
const manifest=JSON.parse(fs.readFileSync(path.join(root,'docs/database/physical/wave1_manifest.json'),'utf8'));
if(JSON.stringify(manifest.tables)!==JSON.stringify(orderedTables().map(t=>t.name)))errors.push({path:'manifest.tables',error:'Wave scope/order drift'});
const catalogHash=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'docs/database/model_catalog.json'))).digest('hex');
if(manifest.catalog_sha256!==catalogHash)errors.push({path:'manifest.catalog_sha256',error:'Approved catalogue changed since generation'});
const files=fs.readdirSync(path.join(root,'apps/api/database/migrations')).filter(n=>n.includes('_wave1_')).sort();
if(JSON.stringify(files)!==JSON.stringify(manifest.migrations.map(m=>m.file).sort()))errors.push({path:'migrations',error:'Missing or unregistered Wave 1 migration'});
const waves=JSON.parse(fs.readFileSync(path.join(root,'docs/database/physical/migration_waves.json'),'utf8'));
const all=waves.waves.flatMap(w=>w.tables);
if(all.length!==199||new Set(all).size!==199||catalog.tables.some(t=>!all.includes(t.name)))errors.push({path:'waves',error:'199-table partition drift'});
const assigned=Object.fromEntries(waves.waves.flatMap(w=>w.tables.map(t=>[t,w.wave])));
for(const t of catalog.tables)for(const c of t.columns.filter(c=>c.fk))if(assigned[c.fk]>assigned[t.name]
  && !(t.name==='files'&&c.name==='created_by')&&!deferred.some(d=>d.table===t.name&&d.column===c.name))errors.push({path:t.name+'.'+c.name,error:'Unplanned cross-wave dependency'});
if(staticMode) {
  if(data.up.length!==names.length||data.down.length!==names.length)errors.push({path:'migration_capture',error:'Statement count drift'});
  const installed=new Set(['users']);
  for(const t of tables){for(const f of t.foreign_keys)if(f.target_table!==t.name&&!installed.has(f.target_table))errors.push({path:f.name,error:'Target created after FK'});installed.add(t.name);}
  for(const sql of data.down){const m=/^DROP TABLE `(\w+)`$/.exec(sql);if(!m){errors.push({path:'down',error:'Unsupported rollback'});continue;}
    for(const t of tables.filter(t=>installed.has(t.name)&&t.name!==m[1]))if(t.foreign_keys.some(f=>f.target_table===m[1]))errors.push({path:'down.'+m[1],error:'Referenced by surviving table '+t.name});installed.delete(m[1]);}
  if(installed.size!==1||!installed.has('users'))errors.push({path:'down',error:'Rollback scope mismatch'});
}
const report={mode:data.mode,engine:data.engine||null,physical_execution:!staticMode,
  tables:tables.length,columns:tables.reduce((n,t)=>n+t.columns.length,0),foreign_keys:tables.reduce((n,t)=>n+t.foreign_keys.length,0),
  checks:tables.reduce((n,t)=>n+t.checks.length,0),status:errors.length?'DRIFT_DETECTED':allowDeferred?'STAGED_PARITY_ONLY':'STRICT_PARITY_PASS',
  errors,strict_errors:strictErrors,deferred_foreign_keys:deferred};
const outIndex=process.argv.indexOf('--output');
if(outIndex>=0)fs.writeFileSync(path.resolve(process.argv[outIndex+1]),JSON.stringify(report,null,2)+'\n');
const snapshotIndex=process.argv.indexOf('--snapshot');
if(snapshotIndex>=0) {
  if(staticMode||strictErrors.length||errors.length){console.error('Snapshot refused: requires physical strict parity');process.exit(1);}
  fs.writeFileSync(path.resolve(process.argv[snapshotIndex+1]),data.schema_sql);
}
console.log(JSON.stringify(report,null,2));
process.exit(errors.length?1:0);
