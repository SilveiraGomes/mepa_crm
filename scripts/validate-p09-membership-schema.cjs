// P0.9 schema parity (ADR 0020): INFORMATION_SCHEMA of an isolated Wave 5 pool vs docs/database/model_catalog.json,
// in both directions (missing AND unexpected), for the ten Membership tables: columns (nullability + base type),
// indexes and foreign keys. The only approved deltas are the P0.3.2-M1 transfer history/guard (transfers.closed_at,
// transfers.open_flag, uq_transfers_membership_open) and the P0.9 open-period guard (membership_periods.open_flag
// STORED GENERATED as IF(ends_at IS NULL,1,NULL) + uq_membership_periods_membership_open). Also checked: no new CHECK
// on transfers.status / memberships.origin / legacy_member_numbers.status, the forbidden authority columns, the 17
// controlled rows, 0 roles carrying MEMBERSHIP permissions, the counter row present and never reset (last_value = the
// highest issued sequence_value), and 0 memberships with more than one open period.
// Env: P09_DSN (mysql:host=..;port=..;dbname=mepa_wave5_test_*), P09_USER, P09_PASSWORD.
const { execFileSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const outputIndex = process.argv.indexOf('--output')
const output = outputIndex >= 0 ? process.argv[outputIndex + 1] : null
// --schema-only: structure, guard and controlled rows only (for a pool that already holds test fixtures: roles seeded by
// tests legitimately carry MEMBERSHIP permissions there). The default mode is for a freshly migrated pool.
const schemaOnly = process.argv.includes('--schema-only')
const selectedTables = ['membership_statuses', 'memberships', 'membership_periods', 'member_number_sequences', 'member_numbers', 'legacy_member_numbers', 'milestone_types', 'ecclesiastical_milestones', 'transfers', 'workflows', 'workflow_instances']
const approvedColumnDeltas = [
  'transfers:closed_at:YES:datetime(6)',
  'transfers:open_flag:YES:tinyint unsigned',
  'membership_periods:open_flag:YES:tinyint unsigned',
]
const approvedIndexDeltas = [
  'transfers:uq_transfers_membership_open:membership_id', 'transfers:uq_transfers_membership_open:open_flag',
  'membership_periods:uq_membership_periods_membership_open:membership_id', 'membership_periods:uq_membership_periods_membership_open:open_flag',
]

function mysqlRows(sql) {
  const mysql = process.env.MYSQL_BIN || 'C:\\wamp64\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe'
  const parts = Object.fromEntries((process.env.P09_DSN || '').replace(/^mysql:/, '').split(';').filter(Boolean).map((item) => item.split('=')))
  if (!parts.dbname || !/^mepa_wave5_test_[a-z0-9_]+$/.test(parts.dbname)) throw new Error('P09_DSN must target an isolated Wave 5 test database')
  const args = ['--user=' + (process.env.P09_USER || 'root')]
  if (process.env.P09_PASSWORD) args.push('--password=' + process.env.P09_PASSWORD)
  args.push('--host=' + (parts.host || '127.0.0.1'), '--port=' + (parts.port || 3306), '--database=' + parts.dbname, '--batch', '--skip-column-names', '--execute=' + sql)
  // F2A-I01 (port of F1C-T05): on Windows the client output occasionally arrives truncated or with a line break inside a
  // row (observed: an INFORMATION_SCHEMA.STATISTICS read returning 0 rows). A read is interpreted only when two consecutive
  // reads of the same (read-only) query are identical (order-insensitive) and every row has the same column count (max 5
  // reads); otherwise the validator fails loudly. Never an unbounded retry.
  let previous = null
  for (let attempt = 1; attempt <= 5; attempt++) {
    const lines = execFileSync(mysql, args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trim().split(/\r?\n/).filter(Boolean)
    const text = [...lines].sort().join('\n')
    const rows = lines.map((line) => line.split('\t'))
    if (text === previous && rows.every((row) => row.length === rows[0].length)) return rows
    if (previous !== null) mysqlRows.retries = (mysqlRows.retries || 0) + 1
    previous = text
  }
  throw new Error('unstable mysql client output after 5 reads: ' + sql.slice(0, 80))
}

const baseType = (type) => type.replace(/\s+CHARACTER SET.*$/i, '').trim().toLowerCase()
const norm = (s) => (s || '').toLowerCase().replace(/[`\s()]/g, '')

let status = 'PASS'
let details = {}
try {
  const catalog = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'), 'utf8'))
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/physical/p09_membership_delta_manifest.json'), 'utf8'))
  const tableList = selectedTables.map((name) => `'${name}'`).join(',')
  const columns = mysqlRows(`SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${tableList}) ORDER BY TABLE_NAME,ORDINAL_POSITION`)
  const indexes = mysqlRows(`SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${tableList}) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX`)
  const fks = mysqlRows(`SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${tableList}) AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,COLUMN_NAME`)
  const actualColumns = new Set(columns.map(([table, column, nullable, type]) => `${table}:${column}:${nullable}:${type.toLowerCase()}`))
  const actualIndexes = new Set(indexes.map(([table, index, column]) => `${table}:${index}:${column}`))
  const actualFks = new Set(fks.map(([table, column, target, targetColumn]) => `${table}:${column}:${target}:${targetColumn}`))
  const missing = []
  const expectedColumns = new Set(approvedColumnDeltas)
  const expectedIndexes = new Set(approvedIndexDeltas)
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
  const guard = mysqlRows("SELECT GENERATION_EXPRESSION,EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='membership_periods' AND COLUMN_NAME='open_flag'")
  const guardOk = guard.length === 1 && norm(guard[0][0]) === 'ifends_atisnull,1,null' && guard[0][1].includes('STORED GENERATED')
  const guardUnique = mysqlRows("SELECT NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='membership_periods' AND INDEX_NAME='uq_membership_periods_membership_open' GROUP BY NON_UNIQUE").map(([v]) => v)
  const forbidden = manifest.forbidden_columns.map((value) => value.split('.'))
  const forbiddenPresent = mysqlRows(`SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (${forbidden.map(([t, c]) => `(TABLE_NAME='${t}' AND COLUMN_NAME='${c}')`).join(' OR ')})`).map(([value]) => value)
  const newChecks = mysqlRows("SELECT CONCAT(tc.TABLE_NAME,'.',tc.CONSTRAINT_NAME) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.CONSTRAINT_TYPE='CHECK' AND tc.TABLE_NAME IN ('transfers','memberships','legacy_member_numbers','membership_periods') ORDER BY 1").map(([value]) => value)
  const allowedChecks = ['membership_periods.ck_membership_periods_period', 'transfers.ck_transfers_origin_destination']
  const extraChecks = newChecks.filter((value) => !allowedChecks.includes(value))
  const expectedControlled = [...manifest.controlled_data].sort()
  const controlled = mysqlRows(
    "SELECT CONCAT('membership_statuses:',code) FROM membership_statuses WHERE is_active=1 AND code IN ('SUBMITTED','VALIDATED','REJECTED','WITHDRAWN','ACTIVE','INACTIVE','ENDED')"
    + " UNION ALL SELECT CONCAT('milestone_types:',code) FROM milestone_types WHERE is_active=1 AND code IN ('CONVERSION','BAPTISM')"
    + " UNION ALL SELECT CONCAT('permissions:',code) FROM permissions WHERE data_type='MEMBERSHIP' AND action=code AND maximum_classification='RESTRICTED'"
    + " UNION ALL SELECT CONCAT('workflows:',code) FROM workflows WHERE code='MEMBERSHIP_TRANSFER' AND version=1 AND status='ACTIVE'"
    + " UNION ALL SELECT CONCAT('member_number_sequences:',code) FROM member_number_sequences WHERE code='MEPA_NATIONAL'"
  ).map(([value]) => value).sort()
  const controlledOk = JSON.stringify(controlled) === JSON.stringify(expectedControlled) && controlled.length === 17
  const roleGrants = Number(mysqlRows("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.data_type='MEMBERSHIP'")[0][0])
  const counter = mysqlRows("SELECT s.last_value, COALESCE((SELECT MAX(sequence_value) FROM member_numbers),0) FROM member_number_sequences s WHERE s.code='MEPA_NATIONAL'")
  const counterOk = counter.length === 1 && Number(counter[0][0]) === Number(counter[0][1])
  const duplicateOpen = Number(mysqlRows('SELECT COUNT(*) FROM (SELECT membership_id FROM membership_periods WHERE ends_at IS NULL GROUP BY membership_id HAVING COUNT(*)>1) x')[0][0])
  if (!controlledOk || missing.length || unexpected.length || forbiddenPresent.length || extraChecks.length || !guardOk
    || JSON.stringify(guardUnique) !== JSON.stringify(['0']) || (!schemaOnly && roleGrants !== 0) || !counterOk || duplicateOpen !== 0
    || manifest.schema_changes.length !== 1 || manifest.new_tables.length !== 0) status = 'FAIL'
  details = {
    database: mysqlRows('SELECT DATABASE()')[0][0],
    comparison: 'BIDIRECTIONAL',
    compared_tables: selectedTables.length,
    compared_columns: columns.length,
    compared_indexes: indexes.length,
    compared_foreign_keys: fks.length,
    missing_or_mismatched: missing,
    unexpected,
    approved_deltas: { columns: approvedColumnDeltas, indexes: approvedIndexDeltas },
    open_period_guard: { generated_stored: guardOk, unique: JSON.stringify(guardUnique) === JSON.stringify(['0']) },
    forbidden_columns_present: forbiddenPresent,
    check_constraints: newChecks,
    unexpected_check_constraints: extraChecks,
    controlled_rows: controlled,
    controlled_rows_expected: expectedControlled.length,
    roles_with_membership_permissions: roleGrants,
    counter: counter.length ? { last_value: Number(counter[0][0]), max_issued_sequence: Number(counter[0][1]), consistent: counterOk } : null,
    memberships_with_more_than_one_open_period: duplicateOpen,
    manifest_schema_changes: manifest.schema_changes,
    stable_read_retries: mysqlRows.retries || 0,
  }
} catch (error) {
  status = 'FAIL'
  details = { error: String(error.message || error) }
}

const result = { status, mode: 'INFORMATION_SCHEMA_VS_MODEL_CATALOG_AND_CONTROLLED_DATA', schema_changes: 1, details }
const json = JSON.stringify(result, null, 2)
if (output) fs.writeFileSync(path.resolve(root, output), json + '\n')
console.log(json)
process.exit(status === 'PASS' ? 0 : 1)
