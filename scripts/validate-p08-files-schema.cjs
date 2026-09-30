// P0.8 schema parity (ADR 0019): INFORMATION_SCHEMA of an isolated Wave 5 pool vs docs/database/model_catalog.json,
// in both directions (missing AND unexpected), for the seven Documents/Files tables: columns (nullability + base type),
// indexes and foreign keys; the files CHECK constraints (only the original status CHECK, no classification CHECK:
// the vocabulary lives in the application, ADR 0019 "Schema delta"); 0 schema changes; and the 17 controlled rows
// (9 FILES permissions + 8 legal_document_types). Env: P08_DSN (mysql:host=..;port=..;dbname=mepa_wave5_test_*),
// P08_USER, P08_PASSWORD.
const { execFileSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const outputIndex = process.argv.indexOf('--output')
const output = outputIndex >= 0 ? process.argv[outputIndex + 1] : null
const selectedTables = ['files', 'legal_document_types', 'legal_documents', 'document_versions', 'person_documents', 'person_files', 'event_documents']
function mysqlRows(sql) {
  const mysql = process.env.MYSQL_BIN || 'C:\\wamp64\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe'
  const parts = Object.fromEntries((process.env.P08_DSN || '').replace(/^mysql:/, '').split(';').filter(Boolean).map((item) => item.split('=')))
  if (!parts.dbname || !/^mepa_wave5_test_[a-z0-9_]+$/.test(parts.dbname)) throw new Error('P08_DSN must target an isolated Wave 5 test database')
  const args = ['--user=' + (process.env.P08_USER || 'root')]
  if (process.env.P08_PASSWORD) args.push('--password=' + process.env.P08_PASSWORD)
  args.push('--host=' + (parts.host || '127.0.0.1'), '--port=' + (parts.port || 3306), '--database=' + parts.dbname, '--batch', '--skip-column-names', '--execute=' + sql)
  const raw = execFileSync(mysql, args, { encoding: 'utf8' })
  return raw.trim().split(/\r?\n/).filter(Boolean).map((line) => line.split('\t'))
}

const baseType = (type) => type.replace(/\s+CHARACTER SET.*$/i, '').trim().toLowerCase()

let status = 'PASS'
let details = {}
try {
  const catalog = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'), 'utf8'))
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/physical/p08_files_delta_manifest.json'), 'utf8'))
  const tableList = selectedTables.map((name) => `'${name}'`).join(',')
  const columns = mysqlRows(`SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${tableList}) ORDER BY TABLE_NAME,ORDINAL_POSITION`)
  const indexes = mysqlRows(`SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${tableList}) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX`)
  const fks = mysqlRows(`SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${tableList}) AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,COLUMN_NAME`)
  const actualColumns = new Set(columns.map(([table, column, nullable, type]) => `${table}:${column}:${nullable}:${type.toLowerCase()}`))
  const actualIndexes = new Set(indexes.map(([table, index, column]) => `${table}:${index}:${column}`))
  const actualFks = new Set(fks.map(([table, column, target, targetColumn]) => `${table}:${column}:${target}:${targetColumn}`))
  const missing = []
  const expectedColumns = new Set()
  const expectedIndexes = new Set()
  const expectedFks = new Set()
  for (const tableName of selectedTables) {
    const table = catalog.tables.find((candidate) => candidate.name === tableName)
    if (!table) { missing.push(`catalog-table:${tableName}`); continue }
    for (const column of table.columns) {
      expectedColumns.add(`${tableName}:${column.name}:${column.nullable ? 'YES' : 'NO'}:${baseType(column.type)}`)
      if (column.pk) expectedIndexes.add(`${tableName}:PRIMARY:${column.name}`)
      for (const index of [...(column.index || []), ...(column.unique || [])]) expectedIndexes.add(`${tableName}:${index}:${column.name}`)
      if (column.target) {
        const [targetTable, targetColumn] = column.target.split('.')
        expectedFks.add(`${tableName}:${column.name}:${targetTable}:${targetColumn}`)
      }
    }
  }
  for (const value of expectedColumns) if (!actualColumns.has(value)) missing.push(`column:${value}`)
  for (const value of expectedIndexes) if (!actualIndexes.has(value)) missing.push(`index:${value}`)
  for (const value of expectedFks) if (!actualFks.has(value)) missing.push(`fk:${value}`)
  const unexpected = [
    ...[...actualColumns].filter((value) => !expectedColumns.has(value)).map((value) => `column:${value}`),
    ...[...actualIndexes].filter((value) => !expectedIndexes.has(value)).map((value) => `index:${value}`),
    ...[...actualFks].filter((value) => !expectedFks.has(value)).map((value) => `fk:${value}`),
  ]
  const fileChecks = mysqlRows("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='files' AND CONSTRAINT_TYPE='CHECK' ORDER BY CONSTRAINT_NAME").map(([value]) => value)
  const expectedControlled = manifest.controlled_data.map((value) => value.replace(/^permissions:/, 'permission:').replace(/^legal_document_types:/, 'document_type:')).sort()
  const controlled = mysqlRows("SELECT CONCAT('document_type:',code) FROM legal_document_types WHERE is_active=1 AND code IN ('MINUTES','RESOLUTION','APPOINTMENT','CORRESPONDENCE','CONTRACT','PROPERTY_TITLE','CONSENT','OTHER') UNION ALL SELECT CONCAT('permission:',code) FROM permissions WHERE data_type='FILES' AND action=code").map(([value]) => value).sort()
  const controlledOk = JSON.stringify(controlled) === JSON.stringify(expectedControlled) && controlled.length === 17
  const filesChecksOk = JSON.stringify(fileChecks) === JSON.stringify(['ck_files_status'])
  if (!controlledOk || !filesChecksOk || missing.length || unexpected.length || manifest.schema_changes.length !== 0) status = 'FAIL'
  details = {
    database: mysqlRows('SELECT DATABASE()')[0][0],
    comparison: 'BIDIRECTIONAL',
    compared_tables: selectedTables.length,
    compared_columns: columns.length,
    compared_indexes: indexes.length,
    compared_foreign_keys: fks.length,
    missing_or_mismatched: missing,
    unexpected,
    files_check_constraints: fileChecks,
    controlled_rows: controlled,
    controlled_rows_expected: expectedControlled.length,
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
