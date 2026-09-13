'use strict';
const w2=require('./wave2-catalog.cjs');
const w1=require('./wave1-catalog.cjs');
const fs=require('node:fs');
const waves=JSON.parse(fs.readFileSync(w2.root+'/docs/database/physical/migration_waves.json','utf8'));
const names=waves.waves.find(w=>w.wave===3).tables;
const support=['audit_logs'];
function model(t){const m=w2.model(t); const add=(suffix,expression)=>m.checks.push({name:`ck_${t.name}_${suffix}`,expression,source:'04_database_constraints: approved row invariant'});
for(const c of t.columns)if(c.name==='version')add('version','`version` >= 1');
if(['events','event_sessions'].includes(t.name))add('period','`ends_at` > `starts_at`');
if(t.name==='credential_types')add('validity','`default_validity_days` IS NULL OR `default_validity_days` > 0');
if(t.name==='invitation_criteria')add('target', ['position_id','class_id','body_id','unit_id','department_id'].map(target=>'('+['position_id','class_id','body_id','unit_id','department_id'].map(c=>'`'+c+'` IS '+(c===target?'NOT ':'')+'NULL').join(' AND ')+')').join(' OR '));
return m;}
function orderedTables(){const p=w2.catalog.tables.filter(t=>[...names,...support].includes(t.name));const done=new Set([...w1.names,...w2.names]);const out=[];while(p.length){const i=p.findIndex(t=>t.columns.every(c=>!c.fk||c.fk===t.name||done.has(c.fk)));if(i<0)throw Error('Unresolved Wave 3 dependency');const[t]=p.splice(i,1);out.push(t);done.add(t.name);}return out;}
module.exports={...w2,names,support,model,orderedTables};
