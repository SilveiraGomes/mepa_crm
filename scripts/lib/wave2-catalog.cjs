'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const catalog = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'), 'utf8'));
const wave1 = require('./wave1-catalog.cjs');
const { indexName, createSql } = wave1;

const names = [
  'education_types', 'employment_types', 'person_qualifications', 'person_employment',
  'membership_statuses', 'member_number_sequences', 'milestone_types', 'ecclesiastical_milestones',
  'ministerial_classes', 'positions', 'functions', 'ministerial_class_periods',
  'organizational_posts', 'ministerial_assignments', 'function_assignments',
  'department_categories', 'departments', 'department_applicability', 'department_instances',
  'department_posts', 'department_appointments', 'department_memberships',
  'users', 'person_merges', 'memberships', 'membership_periods', 'member_numbers',
  'devices', 'auth_sessions', 'roles', 'permissions', 'role_permissions', 'scopes', 'user_role_scopes',
  'workflows', 'workflow_instances', 'transfers', 'idempotency_requests',
  'import_batches', 'import_records', 'legacy_member_numbers', 'import_issues', 'import_identity_maps',
];

// W1-F01: files.owner_department_id -> department_instances.id. Wave 2 creates the target;
// this migration materializes the FK. Tracked here so the validator can assert MATERIALIZED
// instead of silently forgetting the finding once it stops being "missing".
const materialized = [{ id: 'W1-F01', table: 'files', column: 'owner_department_id', target_table: 'department_instances',
  target_column: 'id', reason: 'Alvo Departamentos criado na Wave 2; FK materializada conforme plano.',
  origin_wave: 1, target_wave: 2, status: 'MATERIALIZED' }];

// Fixed enums confirmed directly from the dictionary's own Descrição column (not "status"/generic
// placeholder text) - the same standard Wave 1 used for organizational_units.status, files.status,
// physical_locations.public_visibility. Anything else ("status", "appointment kind") is D-11 pending
// and must NOT get an invented CHECK enum.
function checks(table) {
  const out = [];
  const add = (suffix, expression, source) => out.push({ name: `ck_${table.name}_${suffix}`, expression, source });
  for (const c of table.columns) if (c.type === 'TINYINT UNSIGNED')
    add(c.name, `\`${c.name}\` IN (0,1)`, '04_database_constraints: todos os booleanos 0/1');
  if (table.temporal) add('period', '`ends_at` IS NULL OR `ends_at` > `starts_at`', '04/06: intervalo semiaberto');
  if (table.name === 'ministerial_assignments') add('appointment_kind', "`appointment_kind` IN ('SUBSTANTIVE','INTERIM')", '02: SUBSTANTIVE ou INTERIM (valor fixado no dicionário)');
  if (table.name === 'department_instances') add('status', "`status` IN ('NON_CONSTITUTED','ACTIVE','INACTIVE')", '02/04: estados já definidos da instância');
  if (table.name === 'organizational_posts') { add('occupancy_status', "`occupancy_status` IN ('VACANT','FILLED','INTERIM','INACTIVE')", '02/04: estados já definidos de ocupação'); add('slot', '`slot` >= 1', '04: slot/version/attempt_number >=1'); }
  if (table.name === 'department_posts') { add('occupancy_status', "`occupancy_status` IN ('VACANT','FILLED','INTERIM','INACTIVE')", '02/04: estados já definidos de ocupação'); add('slot', '`slot` >= 1', '04: slot/version/attempt_number >=1'); }
  if (table.name === 'users') add('account_kind', "`account_kind` IN ('HUMAN','SERVICE')", '02: HUMAN ou SERVICE (valor fixado no dicionário)');
  if (table.name === 'member_numbers') {
    add('origin', "`origin` IN ('APPROVED_ADMISSION','APPROVED_LEGACY_MAPPING')", '02: valores fixados no dicionário');
    add('issued_month', '`issued_month` BETWEEN 1 AND 12', '04: issued_month 1..12');
    add('sequence_value', '`sequence_value` BETWEEN 1 AND 999999', '04: sequence_value 1..999999');
  }
  if (table.name === 'scopes') add('scope_kind', "`scope_kind` IN ('UNIT','UNIT_DEPARTMENT')", '02: UNIT ou UNIT_DEPARTMENT (valor fixado no dicionário)');
  if (table.name === 'person_merges') add('not_self', '`source_person_id` <> `target_person_id`', '04: merged_into sem ciclo, source != target');
  if (table.name === 'transfers') add('origin_destination', '`origin_unit_id` <> `destination_unit_id`', '04: origem != destino');
  return out;
}

// Same shape as wave1-catalog.model(); staged=true excludes columns whose FK is not yet
// creatable within this ordering pass (used only internally by orderedTables()).
function model(table, staged = false, extraDeferredCheck = () => false) {
  return {
    name: table.name, engine: 'InnoDB', charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci',
    columns: table.columns.map(c => ({ name: c.name, type: c.type.split(' CHARACTER SET')[0].toLowerCase(),
      nullable: c.nullable, default: c.default === '0' ? '0' : null, auto_increment: c.default === 'AUTO_INCREMENT',
      charset: c.name === 'public_id' ? 'ascii' : /^(CHAR|VARCHAR|TEXT)/.test(c.type) ? 'utf8mb4' : null,
      collation: c.name === 'public_id' ? 'ascii_bin' : c.name === 'code' ? 'utf8mb4_bin' : /^(CHAR|VARCHAR|TEXT)/.test(c.type) ? 'utf8mb4_unicode_ci' : null })),
    primary: table.columns.filter(c => c.pk).map(c => c.name),
    indexes: [ ...table.unique.map(cols => ({ name: indexName(table, cols, true), columns: cols, unique: true })),
      ...table.indexes.map(cols => ({ name: indexName(table, cols, false), columns: cols, unique: false })) ],
    foreign_keys: table.columns.filter(c => c.fk && !(staged && extraDeferredCheck(table.name, c.name)))
      .map(c => ({ name: `fk_${table.name}_${c.name}`, columns: [c.name], target_table: c.fk,
        target_columns: ['id'], on_delete: c.on_delete, on_update: c.on_update })),
    checks: checks(table),
  };
}

function orderedTables() {
  const pending = catalog.tables.filter(t => names.includes(t.name));
  if (pending.length !== names.length) throw new Error('Unknown Wave 2 table');
  const result = [];
  // Wave 1 tables (all 31) plus 'users' (adapted, not created) are already available targets.
  const created = new Set([...wave1.names, 'users']);
  while (pending.length) {
    const i = pending.findIndex(t => t.columns.every(c => !c.fk || c.fk === t.name || created.has(c.fk)));
    if (i < 0) throw new Error('Unresolved Wave 2 dependency cycle: ' + pending.map(t => t.name).join(','));
    const [table] = pending.splice(i, 1); result.push(table); created.add(table.name);
  }
  return result;
}

module.exports = { root, catalog, names, materialized, indexName, checks, model, orderedTables, createSql };
