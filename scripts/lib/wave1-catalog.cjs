'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const catalog = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'), 'utf8'));
const names = [
  'person_statuses', 'sex_types', 'civil_status_types', 'people',
  'identity_document_types', 'person_documents', 'contact_types', 'person_contacts',
  'addresses', 'person_addresses', 'households', 'household_role_types',
  'household_members', 'relationship_types', 'person_relationships', 'person_files',
  'territorial_area_types', 'territorial_areas', 'organizational_unit_types',
  'unit_parent_rules', 'organizational_units', 'unit_parent_periods',
  'organizational_structure_lock', 'files', 'legal_document_types', 'legal_documents',
  'document_versions', 'physical_locations', 'properties', 'occupation_types', 'unit_location_links',
];
const deferred = [{ table: 'files', column: 'owner_department_id', target: 'department_instances.id', wave: 2,
  reason: 'Alvo Departamentos fora do âmbito autorizado; coluna e índice mantidos. Finding W1-F01; drift estrito permanece aberto.' }];
function indexName(table, columns, unique) {
  const field = unique ? 'unique' : 'index';
  const matches = table.columns.filter(c => columns.includes(c.name)).map(c => c[field] || []);
  const candidates = matches[0]?.filter(n => matches.every(a => a.includes(n)) && table.columns.filter(c => (c[field] || []).includes(n)).every(c => columns.includes(c.name))) || [];
  if (candidates.length !== 1) throw new Error(`Ambiguous approved index: ${table.name}(${columns})`);
  return candidates[0];
}
function checks(table) {
  const out = [];
  const add = (suffix, expression, source) => out.push({ name: `ck_${table.name}_${suffix}`, expression, source });
  for (const c of table.columns) if (c.type === 'TINYINT UNSIGNED')
    add(c.name, `\`${c.name}\` IN (0,1)`, '04_database_constraints: todos os booleanos 0/1');
  if (table.temporal) add('period', '`ends_at` IS NULL OR `ends_at` > `starts_at`', '04/06: intervalo semiaberto');
  if (table.name === 'people') add('birth',
    "(`birth_precision` = 'EXACT' AND `birth_date` IS NOT NULL AND `birth_year` IS NULL) OR (`birth_precision` = 'YEAR_ONLY' AND `birth_date` IS NULL AND `birth_year` IS NOT NULL) OR (`birth_precision` = 'UNKNOWN' AND `birth_date` IS NULL AND `birth_year` IS NULL)",
    '02/04: nascimento consistente com EXACT/YEAR_ONLY/UNKNOWN; birth_year só quando apenas ano conhecido');
  if (table.name === 'person_relationships') add('not_reflexive', '`subject_person_id` <> `related_person_id`', '04: parentesco não reflexivo');
  if (table.name === 'physical_locations') {
    add('coordinates', '(`latitude` IS NULL AND `longitude` IS NULL) OR (`latitude` IS NOT NULL AND `longitude` IS NOT NULL AND `latitude` BETWEEN -90 AND 90 AND `longitude` BETWEEN -180 AND 180)', '01/04: coordenadas emparelhadas e intervalos');
    add('visibility', "`public_visibility` IN ('PRIVATE','APPROVED_PUBLIC')", '02: estados de visibilidade fechados');
  }
  if (table.name === 'organizational_units') add('status', "`status` IN ('DRAFT','ACTIVE','CLOSED')", '02/04: estados já definidos da unidade');
  if (table.name === 'files') add('status', "`status` IN ('QUARANTINED','AVAILABLE','TOMBSTONE','PURGED')", '02: estados já definidos de storage');
  if (table.name === 'document_versions') add('version', '`version` >= 1', '04: version >=1');
  return out;
}
function model(table, staged = false) {
  return {
    name: table.name, engine: 'InnoDB', charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci',
    columns: table.columns.map(c => ({ name: c.name, type: c.type.split(' CHARACTER SET')[0].toLowerCase(),
      nullable: c.nullable, default: c.default === '0' ? '0' : null, auto_increment: c.default === 'AUTO_INCREMENT',
      charset: c.name === 'public_id' ? 'ascii' : /^(CHAR|VARCHAR|TEXT)/.test(c.type) ? 'utf8mb4' : null,
      collation: c.name === 'public_id' ? 'ascii_bin' : c.name === 'code' ? 'utf8mb4_bin' : /^(CHAR|VARCHAR|TEXT)/.test(c.type) ? 'utf8mb4_unicode_ci' : null })),
    primary: table.columns.filter(c => c.pk).map(c => c.name),
    indexes: [ ...table.unique.map(cols => ({ name: indexName(table, cols, true), columns: cols, unique: true })),
      ...table.indexes.map(cols => ({ name: indexName(table, cols, false), columns: cols, unique: false })) ],
    foreign_keys: table.columns.filter(c => c.fk && !(staged && deferred.some(d => d.table === table.name && d.column === c.name)))
      .map(c => ({ name: `fk_${table.name}_${c.name}`, columns: [c.name], target_table: c.fk,
        target_columns: ['id'], on_delete: c.on_delete, on_update: c.on_update })),
    checks: checks(table),
  };
}
function orderedTables() {
  const pending = catalog.tables.filter(t => names.includes(t.name));
  if (pending.length !== names.length) throw new Error('Unknown Wave 1 table');
  const result = [], created = new Set(['users']);
  while (pending.length) {
    const i = pending.findIndex(t => t.columns.every(c => !c.fk || c.fk === t.name || created.has(c.fk)
      || deferred.some(d => d.table === t.name && d.column === c.name)));
    if (i < 0) throw new Error('Unresolved Wave 1 dependency cycle');
    const [table] = pending.splice(i, 1); result.push(table); created.add(table.name);
  }
  return result;
}
const quote = x => '`' + x + '`';
function createSql(m) {
  const parts = m.columns.map(c => `${quote(c.name)} ${c.type.toUpperCase()}${c.charset ? ` CHARACTER SET ${c.charset} COLLATE ${c.collation}` : ''} ${c.nullable ? 'NULL' : 'NOT NULL'}${c.auto_increment ? ' AUTO_INCREMENT' : c.default !== null ? ' DEFAULT ' + c.default : c.nullable ? ' DEFAULT NULL' : ''}`);
  parts.push(`PRIMARY KEY (${m.primary.map(quote).join(',')})`);
  for (const i of m.indexes) parts.push(`${i.unique ? 'UNIQUE KEY' : 'KEY'} ${quote(i.name)} (${i.columns.map(quote).join(',')})`);
  for (const f of m.foreign_keys) parts.push(`CONSTRAINT ${quote(f.name)} FOREIGN KEY (${f.columns.map(quote).join(',')}) REFERENCES ${quote(f.target_table)} (${f.target_columns.map(quote).join(',')}) ON DELETE ${f.on_delete} ON UPDATE ${f.on_update}`);
  for (const c of m.checks) parts.push(`CONSTRAINT ${quote(c.name)} CHECK (${c.expression})`);
  return `CREATE TABLE ${quote(m.name)} (\n  ${parts.join(',\n  ')}\n) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`;
}
module.exports = { root, catalog, names, deferred, indexName, checks, model, orderedTables, createSql };
