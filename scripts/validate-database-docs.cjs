const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'..');
const read=p=>fs.readFileSync(path.join(root,p),'utf8').replace(/^\uFEFF/,'');
const exists=p=>fs.existsSync(path.join(root,p));
const m=JSON.parse(read('docs/database/model_catalog.json'));
const d=read('docs/database/02_data_dictionary.md'),erd=read('docs/diagrams/mepa_erd_master.mmd');
const errors=[];let fks=0,columns=0;
if(new Set(m.tables.map(t=>t.name)).size!==m.tables.length)errors.push('Duplicate table');
for(const t of m.tables){
 for(const prop of ['name','purpose','domain','owner','retention','volume','sensitivity'])if(!t[prop])errors.push(`${t.name}: missing ${prop}`);
 const section=d.split('## '+t.name+'\n')[1]?.split('\n## ')[0];
 if(!section)errors.push('Missing dictionary section '+t.name);
 const names=t.columns.map(c=>c.name);if(new Set(names).size!==names.length)errors.push('Duplicate columns '+t.name);
 for(const c of t.columns){columns++;
  for(const prop of ['name','type','nullable','default','pk','unique','index','description','example','sensitivity'])if(c[prop]===undefined)errors.push(`${t.name}.${c.name}: missing ${prop}`);
  const row=section?.split('\n').find(l=>l.startsWith('| '+c.name+' |'));
  if(!row)errors.push('Missing dictionary column '+t.name+'.'+c.name);
  else {const cells=row.split('|').map(x=>x.trim());if(cells[2]!==c.type||cells[10]!==c.example||cells[11]!==c.sensitivity)errors.push('Dictionary/catalog mismatch '+t.name+'.'+c.name);}
  if(c.fk){fks++;if(!m.tables.some(x=>x.name===c.fk))errors.push('FK target absent '+c.fk);if(!erd.includes(t.name+' : "'+c.name+'"'))errors.push('Missing ERD relation '+t.name+'.'+c.name);if(!c.cardinality||c.on_delete!=='RESTRICT'||c.on_update!=='RESTRICT')errors.push('FK policy '+t.name+'.'+c.name);if(c.type!=='BIGINT UNSIGNED')errors.push('Non-BIGINT FK '+t.name+'.'+c.name);}
  if(c.name==='reason'&&c.type!=='TEXT')errors.push('reason type '+t.name);
  if(/FLOAT|DOUBLE/.test(c.type))errors.push('Non-decimal numeric type '+t.name+'.'+c.name);
  for(const name of [...c.unique,...c.index])if(name.length>64)errors.push('Index name >64 '+name);
 }
 for(const key of [...t.unique,...t.indexes])for(const col of key)if(!names.includes(col))errors.push('Missing indexed column '+t.name+'.'+col);
}
if((erd.match(/ : "/g)||[]).length!==fks)errors.push('ERD relation count mismatch');
for(const p of fs.readdirSync(path.join(root,'docs/database')).filter(n=>n.endsWith('.md'))){for(const match of read('docs/database/'+p).matchAll(/\]\(([^)]+)\)/g)){if(!/^(https?:|#)/.test(match[1])&&!fs.existsSync(path.resolve(root,'docs/database',match[1])))errors.push('Broken link '+p+': '+match[1]);}}
for(const name of ['01_database_principles','02_data_dictionary','03_erd_master','04_database_constraints','05_indexing_strategy','06_history_and_temporal_data','07_data_classification','08_migration_strategy','09_open_database_decisions','10_p02_audit_report'])if(!exists('docs/database/'+name+'.md'))errors.push('Missing deliverable '+name);
if(!exists('mepa_crm_v1.1.0.md')||!exists('mepa_crm_v1.1.1.md'))errors.push('Missing preserved/canonical baseline');
const canonical=read('mepa_crm_v1.1.1.md');if(canonical.includes('Centro Normal'))errors.push('Residual terminology');
if(!canonical.includes('Centro Geral opcional'))errors.push('Missing optional Centro Geral');

// P0.2-F checks strengthen deliverable consistency; earlier checks remain intact.
for(const p of ['docs/reviews/P0.2_reconciliation.md','docs/reviews/P0.2_open_decisions_matrix.md','docs/database/10_database_engine_compatibility.md','docs/database/11_financial_invariants_test_plan.md','docs/database/unit_parent_rules.json','scripts/check-database-capabilities.php','tests/architecture/run.php','tests/architecture/run-isolated.ps1'])if(!exists(p))errors.push('Missing reconciliation deliverable '+p);
const reconciliation=exists('docs/reviews/P0.2_reconciliation.md')?read('docs/reviews/P0.2_reconciliation.md'):'';
const decisions=exists('docs/reviews/P0.2_open_decisions_matrix.md')?read('docs/reviews/P0.2_open_decisions_matrix.md'):'';
for(let n=1;n<=11;n++){
 const id='F'+String(n).padStart(2,'0');const rows=reconciliation.split('\n').filter(l=>l.startsWith('| '+id+' |'));
 if(rows.length!==1)errors.push('Finding matrix cardinality '+id);
 else if(!/\| (RESOLVED|ACCEPTED_RISK|BLOCKED|NOT_APPLICABLE)(?:\s|\|)/.test(rows[0]))errors.push('Finding state '+id);
}
for(let n=1;n<=12;n++){const id='D-'+String(n).padStart(2,'0');if(decisions.split('\n').filter(l=>l.startsWith('| '+id+' |')).length!==1)errors.push('Decision matrix cardinality '+id);}
const ct=m.tables.find(t=>t.name==='credential_types');for(const name of ['requires_member_number','default_validity_days','requires_formal_approval'])if(!ct?.columns.some(c=>c.name===name))errors.push('Missing credential configuration '+name);
for(const name of ['organizational_posts','department_posts'])if(!m.tables.find(t=>t.name===name)?.columns.find(c=>c.name==='occupancy_status')?.description.includes('Cache de interface'))errors.push('Occupancy cache contract '+name);
if(exists('docs/database/unit_parent_rules.json')){
 try{const rules=JSON.parse(read('docs/database/unit_parent_rules.json'));const pairs=rules.allowed_parent_child_pairs;
 if(!Array.isArray(pairs)||pairs.length!==7||new Set(pairs.map(p=>JSON.stringify(p))).size!==7)errors.push('Canonical parent pairs cardinality');
 if(!pairs?.every(p=>Array.isArray(p)&&p.length===2&&p[0]!==p[1]))errors.push('Invalid parent pair');
 if(rules.general_center_optional!==true||rules.active_municipality_min_active_centers!==1||rules.municipality_max_non_closed_general_centers!==1)errors.push('Canonical hierarchy invariant');
 }catch(e){errors.push('Parent rules JSON invalid');}
}
if(!m.physical_candidates?.organizational_units||m.physical_candidates.status!=='PROPOSED_NOT_PRODUCTION_DDL')errors.push('Missing explicit physical candidate status');
for(const p of ['P0.2_reconciliation.md','P0.2_open_decisions_matrix.md'])if(exists('docs/reviews/'+p)){for(const match of read('docs/reviews/'+p).matchAll(/\]\(([^)]+)\)/g))if(!/^(https?:|#)/.test(match[1])&&!fs.existsSync(path.resolve(root,'docs/reviews',match[1])))errors.push('Broken reconciliation link '+p+': '+match[1]);}

// P0.2-D01 checks: PK/FK identifier strategy, per-table public_id classification, no member_number/public_id misuse.
const PUBLIC_ID_TYPE='CHAR(26) CHARACTER SET ascii COLLATE ascii_bin';
if(m.identifier_strategy?.status!=='ACCEPTED'||m.identifier_strategy?.decision!=='D-01 RESOLVED')errors.push('Identifier strategy not ACCEPTED/RESOLVED');
if(!/Status:\*\*\s*Accepted/.test(read('docs/adr/0009-internal-pk-public-id.md')))errors.push('ADR 0009 not Accepted');
if(!/\|\s*D-01\s*—\s*RESOLVED\s*\|/.test(read('docs/database/09_open_database_decisions.md')))errors.push('D-01 not RESOLVED in open decisions');
if(!/\|\s*D-01\s*\|.*\|\s*RESOLVED\s*\|/.test(read('docs/reviews/P0.2_open_decisions_matrix.md')))errors.push('D-01 not RESOLVED in decisions matrix');
let publicRequired=0,publicNotRequired=0;
for(const t of m.tables){
 const ident=t.identifiers;
 if(!ident){errors.push('Missing identifiers block '+t.name);continue;}
 for(const prop of ['internal_pk','internal_pk_type','public_id_required','public_id_reason','immutable','decision'])if(ident[prop]===undefined)errors.push(`${t.name}.identifiers: missing ${prop}`);
 if(ident.internal_pk_type!=='BIGINT UNSIGNED AUTO_INCREMENT')errors.push('Non-standard internal PK type '+t.name);
 const section=d.split('## '+t.name+'\n')[1]?.split('\n## ')[0]||'';
 const pubRow=section.split('\n').find(l=>l.startsWith('| public_id |'));
 if(ident.public_id_required===true){
  publicRequired++;
  if(ident.public_id_type!==PUBLIC_ID_TYPE)errors.push('public_id type mismatch '+t.name);
  if(!pubRow)errors.push('public_id_required=true but column missing in dictionary '+t.name);
  else{
   const cells=pubRow.split('|').map(x=>x.trim());
   if(cells[2]!==PUBLIC_ID_TYPE)errors.push('public_id column type mismatch '+t.name);
   if(cells[3]!=='nao'&&cells[3].toLowerCase()!=='não')errors.push('public_id nullable '+t.name);
   if(!cells[7]||cells[7]==='—'||!cells[7].startsWith('uq_'))errors.push('public_id missing UNIQUE '+t.name);
   if(cells[8]&&cells[8]!=='—')errors.push('public_id has redundant INDEX '+t.name);
  }
 } else if(ident.public_id_required===false){
  publicNotRequired++;
  if(pubRow)errors.push('public_id_required=false but column present in dictionary '+t.name);
 } else errors.push('public_id_required not boolean '+t.name);
 for(const c of t.columns){
  if(c.name==='member_number'&&(c.pk||t.name!=='member_numbers'&&t.name!=='legacy_member_numbers'))errors.push('member_number misused as structural key '+t.name);
  if(c.fk&&/\.public_id$/.test(c.fk))errors.push('FK targets public_id instead of id '+t.name+'.'+c.name);
  if(c.pk&&c.type!=='BIGINT UNSIGNED')errors.push('Non-BIGINT PK '+t.name);
  if(/\bUUID\b/i.test(c.type))errors.push('UUID column type present '+t.name+'.'+c.name);
 }
}
if(publicRequired!==54)errors.push('public_id_required=true count drifted from 54: '+publicRequired);
if(publicNotRequired!==145)errors.push('public_id_required=false count drifted from 145: '+publicNotRequired);

console.log(JSON.stringify({tables:m.tables.length,columns,fks,master_relations:(erd.match(/ : "/g)||[]).length,local_links:'checked',identifier_strategy:m.identifier_strategy?.status,public_id_entities:publicRequired,errors},null,2));
if(errors.length)process.exitCode=1;