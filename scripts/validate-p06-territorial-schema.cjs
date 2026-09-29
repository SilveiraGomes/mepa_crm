const { execFileSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const outputIndex = process.argv.indexOf('--output')
const output = outputIndex >= 0 ? process.argv[outputIndex + 1] : null
const selectedTables = [
  'organizational_units', 'organizational_unit_types', 'unit_parent_rules', 'unit_parent_periods',
  'organizational_structure_lock', 'permissions', 'audit_logs', 'user_role_scopes', 'scopes',
  'person_unit_contexts', 'unit_location_links',
]

function mysqlRows(sql) {
  const mysql = 'C:\\wamp64\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe'
  const parts = Object.fromEntries((process.env.P06_DSN || '').replace(/^mysql:/, '').split(';').filter(Boolean).map((item) => item.split('=')))
  if (!parts.dbname || !/^mepa_wave5_test_[a-z0-9_]+$/.test(parts.dbname)) throw new Error('P06_DSN must target an isolated Wave 5 database')
  const args = ['--user=' + (process.env.P06_USER || 'root')]
  if (process.env.P06_PASSWORD) args.push('--password=' + process.env.P06_PASSWORD)
  args.push('--host=' + (parts.host || '127.0.0.1'), '--port=' + (parts.port || 3306), '--database=' + parts.dbname, '--batch', '--skip-column-names', '--execute=' + sql)
  const raw = execFileSync(mysql, args, { encoding: 'utf8' })
  return raw.trim().split(/\r?\n/).filter(Boolean).map((line) => line.split('\t'))
}

let status = 'PASS'
let details = {}
try {
  const catalog = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'), 'utf8'))
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/physical/p06_territorial_delta_manifest.json'), 'utf8'))
  const tableList = selectedTables.map((name) => `'${name}'`).join(',')
  const columns = mysqlRows(`SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${tableList}) ORDER BY TABLE_NAME,ORDINAL_POSITION`)
  const indexes = mysqlRows(`SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${tableList}) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX`)
  const fks = mysqlRows(`SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${tableList}) AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,COLUMN_NAME`)
  const actualColumns = new Set(columns.map(([table, column, nullable]) => `${table}:${column}:${nullable}`))
  const actualIndexes = new Set(indexes.map(([table, index, column]) => `${table}:${index}:${column}`))
  const actualFks = new Set(fks.map(([table, column, target, targetColumn]) => `${table}:${column}:${target}:${targetColumn}`))
  const missing = []
  const migrationOnly = {
    person_unit_contexts: { columns: [
      {name:'id',nullable:false},{name:'person_id',nullable:false,target:'people.id',index:['ix_person_unit_contexts_person_id_status_starts_at']},
      {name:'unit_id',nullable:false,target:'organizational_units.id',index:['ix_person_unit_contexts_unit_id_context_kind_status_starts_at']},
      {name:'context_kind',nullable:false,index:['ix_person_unit_contexts_unit_id_context_kind_status_starts_at']},{name:'status',nullable:false,index:['ix_person_unit_contexts_person_id_status_starts_at','ix_person_unit_contexts_unit_id_context_kind_status_starts_at']},
      {name:'starts_at',nullable:false,index:['ix_person_unit_contexts_person_id_status_starts_at','ix_person_unit_contexts_unit_id_context_kind_status_starts_at']},{name:'ends_at',nullable:true},{name:'reason',nullable:true},
      {name:'source_document_id',nullable:true,target:'legal_documents.id',index:['ix_person_unit_contexts_source_document_id']},{name:'created_at',nullable:false},{name:'lock_version',nullable:false},
    ]},
  }
  for (const tableName of selectedTables) {
    const table = catalog.tables.find((candidate) => candidate.name === tableName) || migrationOnly[tableName]
    if (!table) { missing.push(`catalog-table:${tableName}`); continue }
    for (const column of table.columns) {
      const nullable = column.nullable ? 'YES' : 'NO'
      if (!actualColumns.has(`${tableName}:${column.name}:${nullable}`)) missing.push(`column:${tableName}.${column.name}:${nullable}`)
      for (const index of column.index || []) if (!actualIndexes.has(`${tableName}:${index}:${column.name}`)) missing.push(`index:${tableName}.${index}.${column.name}`)
      if (column.target) {
        const [targetTable, targetColumn] = column.target.split('.')
        if (!actualFks.has(`${tableName}:${column.name}:${targetTable}:${targetColumn}`)) missing.push(`fk:${tableName}.${column.name}->${column.target}`)
      }
    }
  }
  const controlled = mysqlRows("SELECT CONCAT('permission:',code) FROM permissions WHERE code IN ('TERRITORIAL_VIEW','TERRITORIAL_MANAGE','TERRITORIAL_MOVE','TERRITORIAL_LIFECYCLE') UNION ALL SELECT CONCAT('lock:',code) FROM organizational_structure_lock WHERE code='NATIONAL_TREE'").map(([value]) => value)
  if (controlled.length !== 5 || missing.length || manifest.schema_changes.length !== 0) status = 'FAIL'
  details = {
    database: mysqlRows('SELECT DATABASE()')[0][0],
    compared_tables: selectedTables.length,
    compared_columns: columns.length,
    compared_indexes: indexes.length,
    compared_foreign_keys: fks.length,
    missing_or_mismatched: missing,
    controlled_rows: controlled,
    manifest_schema_changes: manifest.schema_changes,
  }
} catch (error) {
  status = 'FAIL'
  details = { error: String(error.message || error) }
}

const result = { status, mode: 'INFORMATION_SCHEMA_VS_MODEL_CATALOG_AND_CONTROLLED_DATA', schema_changes: 0, details }
const json = JSON.stringify(result, null, 2)
if (output) fs.writeFileSync(path.resolve(root, output), json + '\n')
console.log(json)
process.exit(status === 'PASS' ? 0 : 1)
