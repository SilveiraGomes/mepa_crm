'use strict';
const w3 = require('./wave3-catalog.cjs');
const w2 = require('./wave2-catalog.cjs');
const w1 = require('./wave1-catalog.cjs');
const fs = require('node:fs');
const waves = JSON.parse(fs.readFileSync(w3.root+'/docs/database/physical/migration_waves.json','utf8'));
const names = waves.waves.find(w => w.wave === 4).tables;
function model(t) {
  const m = w2.model(t);
  const add = (suffix,expression,source) => m.checks.push({name:`ck_${t.name}_${suffix}`,expression,source});
  for (const c of t.columns) if(c.name === 'version') add('version','`version` >= 1','04_database_constraints: version >=1');
  return m;
}
function orderedTables() {
  const pending = w3.catalog.tables.filter(t=>names.includes(t.name));
  const done = new Set([...w1.names,...w2.names,...w3.names,...w3.support]);
  const out=[];
  while(pending.length) {
    const i=pending.findIndex(t=>t.columns.every(c=>!c.fk||done.has(c.fk)));
    if(i<0) throw Error('Unresolved Wave 4 dependency');
    const [t]=pending.splice(i,1); out.push(t); done.add(t.name);
  }
  return out;
}
module.exports={...w3,names,support:[],model,orderedTables};
