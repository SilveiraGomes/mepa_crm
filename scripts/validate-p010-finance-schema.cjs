// P0.10-F1A Finance Core schema parity (ADR 0021 D32/D33 + D-04A). Three models of the 21 F1A tables are compared in
// BOTH directions (missing AND unexpected): EXPECTED = docs/database/model_catalog.json + the approved deltas of
// docs/database/physical/p010_finance_delta_manifest.json; STATIC = the CREATE TABLE text of the P0.10 migrations;
// LIVE = INFORMATION_SCHEMA of an isolated Wave 5 pool. Reported per category: tables, columns (nullability + type),
// indexes (ordered columns + uniqueness), foreign keys (ordered columns, target, RESTRICT/RESTRICT), CHECK constraints
// (by name), the generated approved_guard. Also: 35-table plan, deferred/payroll tables absent, forbidden columns
// (stored balances, department owners), money = DECIMAL(19,4), no FLOAT/DOUBLE, FK columns BIGINT UNSIGNED (D-01),
// 0 CASCADE / SET NULL, controlled finance rows, 0 roles carrying FINANCE permissions.
// Env: P010_DSN (mysql:host=..;port=..;dbname=mepa_wave5_test_*), P010_USER, P010_PASSWORD.
// Flags: --static-only (catalog <-> migrations only, no database), --schema-only (skip the role check on a used pool),
//        --output <file>
const { execFileSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const root = path.resolve(__dirname, '..')
const arg = (name) => { const i = process.argv.indexOf(name); return i >= 0 ? process.argv[i + 1] : null }
const output = arg('--output')
const staticOnly = process.argv.includes('--static-only')
const schemaOnly = process.argv.includes('--schema-only')
const baseType = (type) => type.replace(/\s+CHARACTER SET.*$/i, '').replace(/\s+GENERATED.*$/i, '').trim().toLowerCase()

function mysqlRows(sql) {
  const mysql = process.env.MYSQL_BIN || 'C:\\wamp64\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe'
  const parts = Object.fromEntries((process.env.P010_DSN || '').replace(/^mysql:/, '').split(';').filter(Boolean).map((item) => item.split('=')))
  if (!parts.dbname || !/^mepa_wave5_test_[a-z0-9_]+$/.test(parts.dbname)) throw new Error('P010_DSN must target an isolated Wave 5 test database')
  const args = ['--user=' + (process.env.P010_USER || 'root')]
  if (process.env.P010_PASSWORD) args.push('--password=' + process.env.P010_PASSWORD)
  args.push('--host=' + (parts.host || '127.0.0.1'), '--port=' + (parts.port || 3306), '--database=' + parts.dbname, '--batch', '--skip-column-names', '--execute=' + sql)
  return execFileSync(mysql, args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trim().split(/\r?\n/).filter(Boolean).map((line) => line.split('\t'))
}

// ---- STATIC: parse the migrations' CREATE TABLE text ------------------------------------------------------------------
function parseMigration(text) {
  const m = text.match(/CREATE TABLE `(\w+)` \(([\s\S]*?)\n\) ENGINE/)
  if (!m) return null
  const model = { table: m[1], columns: {}, indexes: {}, fks: {}, checks: [], generated: {} }
  for (let line of m[2].split('\n')) {
    line = line.trim().replace(/,$/, '')
    let r
    if ((r = line.match(/^`(\w+)` (.+)$/))) {
      const rest = r[2]
      const type = rest.split(/\s+(?=NOT NULL|NULL|GENERATED)/)[0]
      model.columns[r[1]] = { nullable: /NOT NULL/.test(rest) ? 'NO' : 'YES', type: baseType(type) }
      const g = rest.match(/GENERATED ALWAYS AS \((.+)\) STORED/)
      if (g) model.generated[r[1]] = g[1]
    } else if ((r = line.match(/^PRIMARY KEY \((.+)\)$/))) {
      model.indexes.PRIMARY = { cols: r[1].replace(/`/g, '').split(','), unique: true }
    } else if ((r = line.match(/^(UNIQUE )?KEY `(\w+)` \((.+)\)$/))) {
      model.indexes[r[2]] = { cols: r[3].replace(/`/g, '').split(','), unique: Boolean(r[1]) }
    } else if ((r = line.match(/^CONSTRAINT `(\w+)` FOREIGN KEY \((.+?)\) REFERENCES `(\w+)` \((.+?)\) ON DELETE (\w+) ON UPDATE (\w+)$/))) {
      model.fks[r[1]] = { cols: r[2].replace(/`/g, '').split(','), target: r[3], targetCols: r[4].replace(/`/g, '').split(','), onDelete: r[5], onUpdate: r[6] }
    } else if ((r = line.match(/^CONSTRAINT `(\w+)` CHECK/))) {
      model.checks.push(r[1])
    }
  }
  return model
}

const fkKey = (table, fk) => `${table}:${fk.cols.join(',')}->${fk.target}(${fk.targetCols.join(',')})`
const idxKey = (table, name, idx) => `${table}:${name}:${idx.cols.join(',')}:${idx.unique ? 'U' : 'N'}`
const colKey = (table, name, c) => `${table}:${name}:${c.nullable}:${c.type}`

let status = 'PASS'
let details = {}
try {
  const catalog = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'), 'utf8'))
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/physical/p010_finance_delta_manifest.json'), 'utf8'))
  const tables = manifest.materialized_tables_f1a
  const catalogTables = Object.fromEntries(catalog.tables.map((t) => [t.name, t]))
  const errors = []

  // ---- EXPECTED = catalog + approved deltas -------------------------------------------------------------------------
  const expected = { columns: new Set(), indexes: new Set(), fks: new Set(), checks: new Set() }
  const removedIndexes = new Set(manifest.index_removals.map((v) => v.split(':').slice(0, 2).join(':')))
  const deferredFks = new Set(manifest.fk_deferred.map((v) => v.split('->')[0]))
  const changed = Object.fromEntries(manifest.column_changes.map((v) => { const [t, c, n, ty] = v.split(':'); return [`${t}:${c}`, `${t}:${c}:${n.split('->')[1]}:${ty}`] }))
  for (const table of tables) {
    const t = catalogTables[table]
    if (!t) {
      const def = manifest.new_tables[table]
      if (!def) { errors.push(`table ${table}: neither catalog nor approved new table`); continue }
      for (const c of def.columns) { const [name, n, ...ty] = c.split(':'); expected.columns.add(`${table}:${name}:${n}:${ty.join(':')}`) }
      for (const i of def.indexes) {
        const [name, cols] = i.split(':')
        expected.indexes.add(`${table}:${name}:${cols}:${name === 'PRIMARY' || name.startsWith('uq_') ? 'U' : 'N'}`)
      }
      for (const f of def.foreign_keys) { const [, spec] = f.split(/:(.+)/); const [cols, rest] = spec.split('->'); expected.fks.add(`${table}:${cols}->${rest}`) }
      continue
    }
    const names = {}
    for (const c of t.columns) {
      const key = `${table}:${c.name}`
      expected.columns.add(changed[key] || `${key}:${c.nullable ? 'YES' : 'NO'}:${baseType(c.type)}`)
      if (c.pk) expected.indexes.add(`${table}:PRIMARY:${c.name}:U`)
      for (const n of c.unique || []) (names[n] ||= { cols: new Set(), unique: true }).cols.add(c.name)
      for (const n of c.index || []) (names[n] ||= { cols: new Set(), unique: false }).cols.add(c.name)
      if (c.target && !deferredFks.has(key)) { const [tt, tc] = c.target.split('.'); expected.fks.add(`${table}:${c.name}->${tt}(${tc})`) }
    }
    const ordered = [...(t.unique || []), ...(t.indexes || [])]
    for (const [name, def] of Object.entries(names)) {
      if (removedIndexes.has(`${table}:${name}`)) continue
      const order = ordered.find((cols) => cols.length === def.cols.size && cols.every((c) => def.cols.has(c)))
      if (!order) { errors.push(`catalog index ${table}.${name} has no ordered definition`); continue }
      expected.indexes.add(`${table}:${name}:${order.join(',')}:${def.unique ? 'U' : 'N'}`)
    }
  }
  for (const d of manifest.column_deltas) expected.columns.add(d)
  for (const d of manifest.index_deltas) { const [t, n, cols] = d.split(':'); expected.indexes.add(`${t}:${n}:${cols}:${n.startsWith('uq_') ? 'U' : 'N'}`) }
  for (const d of manifest.fk_deltas) { const [t, , spec] = d.split(':'); const [cols, target] = spec.split('->'); const [tt, tc] = target.split('.'); expected.fks.add(`${t}:${cols}->${tt}(${tc})`) }
  for (const [t, names] of Object.entries(manifest.checks)) for (const n of names) expected.checks.add(`${t}:${n}`)

  // ---- STATIC --------------------------------------------------------------------------------------------------------
  const migDir = path.join(root, 'apps/api/database/migrations')
  const stat = { columns: new Set(), indexes: new Set(), fks: new Set(), checks: new Set(), rules: [], generated: {} }
  const staticTables = []
  for (const file of manifest.migrations) {
    const text = fs.readFileSync(path.join(migDir, file), 'utf8')
    const model = parseMigration(text)
    if (!model) { if (!/FinanceCatalog::install/.test(text)) errors.push(`migration ${file}: no CREATE TABLE and not the installer`); continue }
    staticTables.push(model.table)
    if (!/P010_PRECONDITION_FAILED/.test(text) || !/P010_ROLLBACK_REFUSED/.test(text) || !/getDriverName\(\) !== 'mysql'/.test(text)) errors.push(`migration ${file}: missing explicit precondition / safe rollback`)
    if (/CASCADE|SET NULL|FLOAT|DOUBLE/i.test(text.replace(/SET NULL DEFAULT/g, ''))) errors.push(`migration ${file}: CASCADE / SET NULL / FLOAT / DOUBLE`)
    if ((text.match(/DB::statement\('DROP TABLE/g) || []).length !== 1 || !text.includes("DROP TABLE `" + model.table + "`")) errors.push(`migration ${file}: down() must drop only its own table`)
    for (const [n, c] of Object.entries(model.columns)) stat.columns.add(colKey(model.table, n, c))
    for (const [n, i] of Object.entries(model.indexes)) stat.indexes.add(idxKey(model.table, n, i))
    for (const fk of Object.values(model.fks)) { stat.fks.add(fkKey(model.table, fk)); stat.rules.push(`${fk.onDelete}/${fk.onUpdate}`) }
    for (const n of model.checks) stat.checks.add(`${model.table}:${n}`)
    Object.assign(stat.generated, Object.fromEntries(Object.entries(model.generated).map(([c, g]) => [`${model.table}.${c}`, g])))
  }
  if (JSON.stringify(staticTables) !== JSON.stringify(tables)) errors.push(`migration tables ${staticTables} != manifest ${tables}`)
  if (stat.rules.some((r) => r !== 'RESTRICT/RESTRICT')) errors.push('non RESTRICT foreign key in migrations')

  const diff = (a, b) => [...a].filter((v) => !b.has(v)).sort()
  const compare = (label, exp, got) => {
    const out = {}
    for (const cat of ['columns', 'indexes', 'fks', 'checks']) out[cat] = { expected: exp[cat].size, actual: got[cat].size, missing: diff(exp[cat], got[cat]), unexpected: diff(got[cat], exp[cat]) }
    for (const cat of Object.keys(out)) if (out[cat].missing.length || out[cat].unexpected.length) errors.push(`${label} ${cat} mismatch`)
    return out
  }
  const catalogVsMigrations = compare('catalog+deltas<->migrations', expected, stat)

  // Plan and forbidden structure (static).
  const plan = manifest.plan
  const planned = 28 - plan.catalog_tables_not_created_v1.length + plan.new_finance_tables.length + plan.payroll_tables_reserved_f2.length
  const phased = tables.length + manifest.deferred_finance_tables.F1B.length + manifest.deferred_finance_tables.F1C.length + plan.payroll_tables_reserved_f2.length
  if (planned !== 35 || phased !== 35 || manifest.planned_table_count !== 35) errors.push(`35-table plan broken: planned=${planned} phased=${phased}`)
  const forbiddenStatic = manifest.forbidden_columns.filter((fc) => { const [t, c] = fc.split('.'); return [...stat.columns].some((v) => v.startsWith(`${t}:${c}:`)) })
  if (forbiddenStatic.length) errors.push(`forbidden columns in migrations: ${forbiddenStatic}`)
  const money = [...stat.columns].filter((v) => /:(amount|debit|credit|requested_amount|approved_amount)$/.test(v.split(':').slice(0, 2).join(':')))
  if (money.some((v) => !v.endsWith(':decimal(19,4)'))) errors.push('money column not DECIMAL(19,4)')
  const generatedOk = JSON.stringify(Object.keys(stat.generated)) === JSON.stringify(Object.keys(manifest.generated_columns))

  details = {
    comparison: 'BIDIRECTIONAL',
    planned_table_count: planned, phased_table_count: phased, f1a_tables: tables.length,
    catalog_vs_migrations: catalogVsMigrations,
    approved_deltas: { columns: manifest.column_deltas.length, column_changes: manifest.column_changes, index_deltas: manifest.index_deltas.length, index_removals: manifest.index_removals, fk_deltas: manifest.fk_deltas.length, fk_deferred: manifest.fk_deferred },
    money_columns: money.length, generated_columns_static: stat.generated, generated_declared: generatedOk,
  }
  if (!generatedOk) errors.push('generated columns differ from the manifest')

  // ---- LIVE ----------------------------------------------------------------------------------------------------------
  if (!staticOnly) {
    const list = tables.map((t) => `'${t}'`).join(',')
    const live = { columns: new Set(), indexes: new Set(), fks: new Set(), checks: new Set() }
    for (const [t, c, n, ty] of mysqlRows(`SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${list})`)) live.columns.add(`${t}:${c}:${n}:${ty.toLowerCase()}`)
    const idx = {}
    for (const [t, n, c, nu] of mysqlRows(`SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${list}) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX`)) (idx[`${t}:${n}`] ||= { t, n, cols: [], unique: nu === '0' }).cols.push(c)
    for (const i of Object.values(idx)) live.indexes.add(idxKey(i.t, i.n, i))
    const fks = {}
    for (const [t, n, c, rt, rc] of mysqlRows(`SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${list}) AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION`)) {
      const fk = (fks[`${t}:${n}`] ||= { t, cols: [], target: rt, targetCols: [] }); fk.cols.push(c); fk.targetCols.push(rc)
    }
    for (const fk of Object.values(fks)) live.fks.add(fkKey(fk.t, fk))
    for (const [t, n] of mysqlRows(`SELECT TABLE_NAME,CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${list}) AND CONSTRAINT_TYPE='CHECK'`)) live.checks.add(`${t}:${n}`)
    const catalogVsLive = compare('catalog+deltas<->information_schema', expected, live)
    const liveTables = mysqlRows(`SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${list})`).map(([t]) => t)
    const absentWanted = [...plan.catalog_tables_not_created_v1, ...manifest.deferred_finance_tables.F1B, ...manifest.deferred_finance_tables.F1C, ...plan.payroll_tables_reserved_f2]
    const presentDeferred = mysqlRows(`SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${absentWanted.map((t) => `'${t}'`).join(',')})`).map(([t]) => t)
    const rules = mysqlRows(`SELECT CONSTRAINT_NAME,UPDATE_RULE,DELETE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN (${list})`).filter(([, u, d]) => u !== 'RESTRICT' || d !== 'RESTRICT').map(([n]) => n)
    const fkTypes = mysqlRows(`SELECT CONCAT(k.TABLE_NAME,'.',k.COLUMN_NAME),c.COLUMN_TYPE FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k JOIN INFORMATION_SCHEMA.COLUMNS c ON c.TABLE_SCHEMA=k.TABLE_SCHEMA AND c.TABLE_NAME=k.TABLE_NAME AND c.COLUMN_NAME=k.COLUMN_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME IN (${list}) AND k.REFERENCED_TABLE_NAME IS NOT NULL`).filter(([, t]) => t !== 'bigint unsigned').map(([c]) => c)
    const floats = mysqlRows(`SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${list}) AND DATA_TYPE IN ('float','double','real')`).map(([c]) => c)
    const liveMoney = mysqlRows(`SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME),COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (${list}) AND DATA_TYPE='decimal'`)
    const forbidden = manifest.forbidden_columns.map((v) => v.split('.'))
    const forbiddenLive = mysqlRows(`SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (${forbidden.map(([t, c]) => `(TABLE_NAME='${t}' AND COLUMN_NAME='${c}')`).join(' OR ')})`).map(([c]) => c)
    const gen = mysqlRows("SELECT GENERATION_EXPRESSION,EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='budgets' AND COLUMN_NAME='approved_guard'")
    const genOk = gen.length === 1 && gen[0][1].includes('STORED GENERATED') && gen[0][0].replace(/\\+'/g, "'") === manifest.generated_columns['budgets.approved_guard']
    const counts = manifest.controlled_data_counts
    const controlled = Object.fromEntries(mysqlRows(
      "SELECT 'currencies',COUNT(*) FROM currencies WHERE code='AOA' AND minor_units=2"
      + " UNION ALL SELECT 'funds',COUNT(*) FROM funds WHERE code='GENERAL'"
      + " UNION ALL SELECT 'chart_of_accounts',COUNT(*) FROM chart_of_accounts WHERE system_role IS NOT NULL"
      + " UNION ALL SELECT 'financial_categories',COUNT(*) FROM financial_categories WHERE code LIKE 'GRP\\_%' OR economic_nature IS NOT NULL"
      + " UNION ALL SELECT 'permissions',COUNT(*) FROM permissions WHERE data_type='FINANCE' AND action=code AND maximum_classification='CONFIDENTIAL'"
      + " UNION ALL SELECT 'legal_document_types',COUNT(*) FROM legal_document_types WHERE code IN ('RECEIPT','INVOICE','BANK_PROOF','EXPENSE_VOUCHER','TRANSFER_PROOF','BANK_STATEMENT','VALUATION_REPORT','PAYSLIP','PAYROLL_SHEET','PAYROLL_RULE_SOURCE')"
      + " UNION ALL SELECT 'workflows',COUNT(*) FROM workflows WHERE code IN ('FINANCE_INTERNAL_TRANSFER','FINANCE_PAYABLE') AND version=1 AND status='ACTIVE'"
    ).map(([k, v]) => [k, Number(v)]))
    const vocab = Object.fromEntries(mysqlRows("SELECT system_role,account_kind FROM chart_of_accounts WHERE system_role IN ('INTERUNIT_CLEARING_OUT','INTERUNIT_CLEARING_IN','FIXED_ASSETS')"))
    const fixedAssetRubrics = Number(mysqlRows("SELECT COUNT(*) FROM financial_categories c JOIN chart_of_accounts a ON a.id=c.ledger_account_id WHERE c.code LIKE 'INV\\_AST\\_%' AND a.system_role='FIXED_ASSETS' AND c.economic_nature='INVESTMENT'")[0][0])
    const purposes = mysqlRows("SELECT c.code FROM financial_categories c JOIN chart_of_accounts a ON a.id=c.ledger_account_id WHERE c.economic_nature='INTERNAL_TRANSFER' AND a.account_kind='INTERUNIT_CONTROL' ORDER BY c.code").map(([c]) => c)
    const roleGrants = Number(mysqlRows("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.data_type='FINANCE'")[0][0])
    const controlledOk = Object.entries(counts).every(([k, v]) => controlled[k] === v)
    const vocabOk = vocab.INTERUNIT_CLEARING_OUT === 'INTERUNIT_CONTROL' && vocab.INTERUNIT_CLEARING_IN === 'INTERUNIT_CONTROL' && vocab.FIXED_ASSETS === 'ASSET' && fixedAssetRubrics === 7
      && JSON.stringify(purposes) === JSON.stringify(['TRF_BUDGET_QUOTA', 'TRF_OTHER', 'TRF_PROJECT', 'TRF_REMITTANCE', 'TRF_SPECIAL_CONTRIBUTION', 'TRF_SUPPORT'])
    if (liveTables.length !== tables.length) errors.push('live table set differs')
    if (presentDeferred.length) errors.push(`deferred/payroll tables present: ${presentDeferred}`)
    if (rules.length || fkTypes.length || floats.length || forbiddenLive.length || !genOk || liveMoney.some(([, t]) => t !== 'decimal(19,4)')) errors.push('live structural rule broken')
    if (!controlledOk || !vocabOk) errors.push('controlled finance data / D-04A vocabulary mismatch')
    if (!schemaOnly && roleGrants !== 0) errors.push('roles carry FINANCE permissions')
    Object.assign(details, {
      database: mysqlRows('SELECT DATABASE()')[0][0], catalog_vs_information_schema: catalogVsLive, live_tables: liveTables.length, deferred_or_payroll_tables_present: presentDeferred,
      non_restrict_fks: rules, non_bigint_fk_columns: fkTypes, float_columns: floats, live_money_columns: liveMoney.length, forbidden_columns_present: forbiddenLive,
      approved_guard_generated_stored: genOk, controlled_rows: controlled, controlled_rows_expected: counts,
      d04a_vocabulary: { clearing_accounts: vocab, fixed_asset_rubrics: fixedAssetRubrics, transfer_purposes: purposes }, roles_with_finance_permissions: roleGrants,
    })
  }
  details.errors = errors
  if (errors.length) status = 'FAIL'
} catch (error) {
  status = 'FAIL'
  details = { error: String(error.message || error) }
}

const result = { status, mode: staticOnly ? 'CATALOG_VS_MIGRATIONS' : 'CATALOG_VS_MIGRATIONS_VS_INFORMATION_SCHEMA', details }
const json = JSON.stringify(result, null, 2)
if (output) fs.writeFileSync(path.resolve(root, output), json + '\n')
console.log(json)
process.exit(status === 'PASS' ? 0 : 1)
