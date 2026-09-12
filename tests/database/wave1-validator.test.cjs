'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const path=require('node:path');
const {root,orderedTables,model}=require('../../scripts/lib/wave1-catalog.cjs');
const {parseCreate,compare,expressionAst}=require('../../scripts/lib/wave1-schema.cjs');
const result=spawnSync(process.env.PHP_BIN||'php',[path.join(root,'scripts/inspect-wave1-schema.php'),'--capture'],{encoding:'utf8',cwd:root});
assert.equal(result.status,0,result.stderr);
const capture=JSON.parse(result.stdout);
const actual=capture.up.map(parseCreate),expected=orderedTables().map(t=>model(t,true));
test('executed migration capture matches staged catalogue',()=>assert.deepEqual(compare(expected,actual),[]));
test('strict catalogue exposes the deferred departmental FK',()=>{
  const errors=compare(orderedTables().map(t=>model(t)),actual);
  assert.equal(errors.length,1);assert.equal(errors[0].path,'files.fk_files_owner_department_id');
});
for(const [name,mutate] of [
  ['signed foreign key',a=>{a.find(t=>t.name==='people').columns.find(c=>c.name==='status_id').type='bigint';}],
  ['nullable public ID',a=>{a.find(t=>t.name==='people').columns.find(c=>c.name==='public_id').nullable=true;}],
  ['cascade action',a=>{a.find(t=>t.name==='people').foreign_keys[0].on_delete='CASCADE';}],
  ['public ID instead of internal target',a=>{a.find(t=>t.name==='people').foreign_keys[0].target_columns=['public_id'];}],
  ['removed unique public ID',a=>{const t=a.find(t=>t.name==='people');t.indexes=t.indexes.filter(i=>i.name!=='uq_people_public_id');}],
  ['disabled CHECK',a=>{a.find(t=>t.checks.length).checks[0].enforced=false;}],
  ['changed default',a=>{a[0].columns.find(c=>c.name==='lock_version').default='1';}],
  ['wrong table collation',a=>{a[0].collation='utf8mb4_general_ci';}],
  ['missing table',a=>{a.pop();}],
  ['extra business column',a=>{a.find(t=>t.name==='people').columns.push({name:'membership_number'});}],
])test('validator rejects '+name,()=>{const a=structuredClone(actual);mutate(a);assert.ok(compare(expected,a).length);});
test('CHECK AST tolerates MySQL parentheses without losing boolean grouping',()=>{
  assert.deepEqual(expressionAst('`ends_at` IS NULL OR `ends_at` > `starts_at`'),expressionAst('((`ends_at` is null) or (`ends_at` > `starts_at`))'));
  assert.notDeepEqual(expressionAst('(a = 1 OR b = 1) AND c = 1'),expressionAst('a = 1 OR (b = 1 AND c = 1)'));
  assert.deepEqual(expressionAst("`status` IN ('DRAFT','ACTIVE','CLOSED')"),expressionAst("(`status` in (_utf8mb4'DRAFT',_utf8mb4'ACTIVE',_utf8mb4'CLOSED'))"));
});
test('capture includes reverse-order down statements for Wave 1 only',()=>assert.deepEqual(capture.down,actual.slice().reverse().map(t=>'DROP TABLE `'+t.name+'`')));

test('CHECK AST decodes the escaped literals returned by MySQL 8.4 information_schema',()=>{
  const actual="(`status` in (_utf8mb4\\'DRAFT\\',_utf8mb4\\'ACTIVE\\',_utf8mb4\\'CLOSED\\'))";
  assert.deepEqual(expressionAst("`status` IN ('DRAFT','ACTIVE','CLOSED')"),expressionAst(actual));
  assert.throws(()=>expressionAst("status = \\'O\\'Brien\\'"));
});
