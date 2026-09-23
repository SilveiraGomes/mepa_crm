'use strict';

// P0.5-I schema parity: the physical schema of an isolated test database must equal
//   Wave 1 approved catalog model (docs/database/model_catalog.json, unchanged)
//   + the ADR-0017 approved delta (people.birth_month + birth CHECK, relationship_types semantics/inverse,
//     person_unit_contexts)
// with the Wave 1 comparison engine (scripts/lib/wave1-schema.cjs), strictly (no extra/missing column,
// index, FK or CHECK). model_catalog.json is not modified, so the Wave 1 manifest hash is untouched.
// Usage: PHP_BIN=... P05_DSN=mysql:host=127.0.0.1;port=N;dbname=mepa_wave5_test_... P05_USER=root node scripts/validate-p05-people-schema.cjs
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { orderedTables, model } = require('./lib/wave1-catalog.cjs');
const { compare } = require('./lib/wave1-schema.cjs');

const root = path.resolve(__dirname, '..');
const col = (name, type, nullable, extra = {}) => ({ name, type, nullable, default: null, auto_increment: false,
  charset: /^(char|varchar|text)/.test(type) ? 'utf8mb4' : null, collation: /^(char|varchar|text)/.test(type) ? 'utf8mb4_unicode_ci' : null, ...extra });

const BIRTH = "(`birth_precision` = 'EXACT' AND `birth_date` IS NOT NULL AND `birth_year` IS NULL AND `birth_month` IS NULL)"
  + " OR (`birth_precision` = 'MONTH' AND `birth_date` IS NULL AND `birth_year` IS NOT NULL AND `birth_month` IS NOT NULL AND `birth_month` BETWEEN 1 AND 12)"
  + " OR (`birth_precision` = 'YEAR' AND `birth_date` IS NULL AND `birth_year` IS NOT NULL AND `birth_month` IS NULL)"
  + " OR (`birth_precision` = 'UNKNOWN' AND `birth_date` IS NULL AND `birth_year` IS NULL AND `birth_month` IS NULL)";

function expectedModel() {
  const tables = orderedTables().map((t) => model(t));
  const people = tables.find((t) => t.name === 'people');
  people.columns.push(col('birth_month', 'tinyint unsigned', true));
  people.checks = people.checks.filter((c) => c.name !== 'ck_people_birth').concat([{ name: 'ck_people_birth', expression: BIRTH, source: 'ADR-0017 D-10 (+ birth_month IS NOT NULL, P05-F01)' }]);
  const rel = tables.find((t) => t.name === 'relationship_types');
  rel.columns.push(col('semantics', 'varchar(32)', false), col('inverse_relationship_type_id', 'bigint unsigned', true));
  rel.indexes.push({ name: 'ix_relationship_types_inverse_relationship_type_id', columns: ['inverse_relationship_type_id'], unique: false });
  rel.foreign_keys.push({ name: 'fk_relationship_types_inverse_relationship_type_id', columns: ['inverse_relationship_type_id'], target_table: 'relationship_types', target_columns: ['id'], on_delete: 'RESTRICT', on_update: 'RESTRICT' });
  rel.checks.push({ name: 'ck_relationship_types_semantics', expression: "`semantics` IN ('SYMMETRIC','INVERSE_PAIRED')" });
  tables.push({
    name: 'person_unit_contexts', engine: 'InnoDB', charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci',
    columns: [
      col('id', 'bigint unsigned', false, { auto_increment: true }), col('person_id', 'bigint unsigned', false), col('unit_id', 'bigint unsigned', false),
      col('context_kind', 'varchar(64)', false), col('status', 'varchar(64)', false), col('starts_at', 'datetime(6)', false), col('ends_at', 'datetime(6)', true),
      col('reason', 'text', true), col('source_document_id', 'bigint unsigned', true), col('created_at', 'datetime(6)', false), col('lock_version', 'int unsigned', false, { default: '0' }),
    ],
    primary: ['id'],
    indexes: [
      { name: 'ix_person_unit_contexts_person_id_status_starts_at', columns: ['person_id', 'status', 'starts_at'], unique: false },
      { name: 'ix_person_unit_contexts_unit_id_context_kind_status_starts_at', columns: ['unit_id', 'context_kind', 'status', 'starts_at'], unique: false },
      { name: 'ix_person_unit_contexts_source_document_id', columns: ['source_document_id'], unique: false },
    ],
    foreign_keys: [
      { name: 'fk_person_unit_contexts_person_id', columns: ['person_id'], target_table: 'people', target_columns: ['id'], on_delete: 'RESTRICT', on_update: 'RESTRICT' },
      { name: 'fk_person_unit_contexts_unit_id', columns: ['unit_id'], target_table: 'organizational_units', target_columns: ['id'], on_delete: 'RESTRICT', on_update: 'RESTRICT' },
      { name: 'fk_person_unit_contexts_source_document_id', columns: ['source_document_id'], target_table: 'legal_documents', target_columns: ['id'], on_delete: 'RESTRICT', on_update: 'RESTRICT' },
    ],
    checks: [
      { name: 'ck_person_unit_contexts_period', expression: '`ends_at` IS NULL OR `ends_at` > `starts_at`' },
      { name: 'ck_person_unit_contexts_context_kind', expression: "`context_kind` IN ('ONBOARDING','EMPLOYMENT','DISCIPLESHIP','LEGACY_IMPORT')" },
      { name: 'ck_person_unit_contexts_status', expression: "`status` IN ('ACTIVE','INACTIVE')" },
    ],
  });
  return tables;
}

if (require.main === module) {
  const capture = spawnSync(process.env.PHP_BIN || 'php', [path.join(root, 'scripts/inspect-p05-people-schema.php')], { cwd: root, encoding: 'utf8', env: process.env });
  if (capture.status !== 0) {
    process.stderr.write(capture.stderr || 'Schema inspection unavailable\n');
    process.exit(2);
  }
  const data = JSON.parse(capture.stdout);
  const expected = expectedModel();
  if (process.argv.includes('--probe')) {
    // In-memory drifts of the PHYSICAL capture that the parity check must reject.
    const clone = () => JSON.parse(JSON.stringify(data.tables));
    const drifts = {
      'people.unit_id added': (t) => t.find((x) => x.name === 'people').columns.push({ name: 'unit_id', type: 'bigint unsigned', nullable: true, default: null, auto_increment: false, charset: null, collation: null }),
      'birth_month missing': (t) => { const p = t.find((x) => x.name === 'people'); p.columns = p.columns.filter((c) => c.name !== 'birth_month'); },
      'MONTH without month accepted (ADR literal CHECK)': (t) => { const p = t.find((x) => x.name === 'people'); p.checks.find((c) => c.name === 'ck_people_birth').expression = BIRTH.replace('`birth_month` IS NOT NULL AND ', ''); },
      'semantics CHECK dropped': (t) => { const r = t.find((x) => x.name === 'relationship_types'); r.checks = r.checks.filter((c) => c.name !== 'ck_relationship_types_semantics'); },
      'inverse FK cascades': (t) => { t.find((x) => x.name === 'relationship_types').foreign_keys.find((f) => f.name.includes('inverse')).on_delete = 'CASCADE'; },
      'person_unit_contexts missing': (t) => t.splice(t.findIndex((x) => x.name === 'person_unit_contexts'), 1),
      'context_kind unconstrained': (t) => { const c = t.find((x) => x.name === 'person_unit_contexts'); c.checks = c.checks.filter((k) => k.name !== 'ck_person_unit_contexts_context_kind'); },
    };
    const results = Object.entries(drifts).map(([name, mutate]) => { const t = clone(); mutate(t); return { name, detected: compare(expected, t).length > 0 }; });
    const control = compare(expected, clone()).length === 0;
    console.log(JSON.stringify({ probe: 'P05_PEOPLE_SCHEMA_PARITY_DRIFTS', control_clean: control, results, status: control && results.every((r) => r.detected) ? 'PASS' : 'FAIL' }, null, 2));
    process.exit(control && results.every((r) => r.detected) ? 0 : 1);
  }
  const errors = compare(expected, data.tables);
  const rejected = data.tables.filter((t) => ['people', 'households'].includes(t.name) && t.columns.some((c) => c.name === 'unit_id')).map((t) => `${t.name}.unit_id`);
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/physical/p05_people_delta_manifest.json'), 'utf8'));
  const missingFiles = manifest.migrations.filter((f) => !fs.existsSync(path.join(root, 'apps/api/database/migrations', f)));
  const report = {
    validator: 'P05_PEOPLE_SCHEMA_PARITY',
    engine: data.engine,
    tables: data.tables.length,
    columns: data.tables.reduce((n, t) => n + t.columns.length, 0),
    foreign_keys: data.tables.reduce((n, t) => n + t.foreign_keys.length, 0),
    checks: data.tables.reduce((n, t) => n + t.checks.length, 0),
    rejected_columns_present: rejected,
    missing_delta_migrations: missingFiles,
    status: errors.length || rejected.length || missingFiles.length ? 'DRIFT_DETECTED' : 'STRICT_PARITY_PASS',
    errors,
  };
  const out = process.argv.indexOf('--output');
  if (out >= 0) fs.writeFileSync(path.resolve(process.argv[out + 1]), JSON.stringify(report, null, 2) + '\n');
  console.log(JSON.stringify(report, null, 2));
  process.exit(report.status === 'STRICT_PARITY_PASS' ? 0 : 1);
}

module.exports = { expectedModel };
