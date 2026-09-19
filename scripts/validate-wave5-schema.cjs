'use strict';
// P0.3.5-A1: static structural parity check between the 25 Wave 5 migration
// files and model_catalog.json's Academia domain declarations, plus the two
// manifest cross-validation rules the user fixed in P0.3.5-A1 (see
// docs/database/physical/wave5_migrations_manifest.json's cross_validation_rule).
// Purely static (regex-parses the raw SQL literal inside each migration's
// DB::statement heredoc) -- no live DB connection required, matching the
// project's convention that catalog/contract drift checks run without MySQL.
const fs = require('node:fs');
const crypto = require('node:crypto');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const migrationsDir = path.join(root, 'apps/api/database/migrations');
const designManifest = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/physical/wave5_manifest.json'), 'utf8'));
const migrManifest = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/physical/wave5_migrations_manifest.json'), 'utf8'));
const catalog = JSON.parse(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'), 'utf8'));

const errors = [];

// 1. Catalog hash drift (same convention as validate-wave4-schema.cjs).
const catalogHash = crypto.createHash('sha256').update(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'))).digest('hex');
if (catalogHash !== migrManifest.catalog_sha256) errors.push({ path: 'catalog', error: 'hash drift', expected: migrManifest.catalog_sha256, actual: catalogHash });

// 2. Manifest cross-validation (WAVE5_MANIFEST_CONFLICT rule set by the user in P0.3.5-A1).
const designTables = Object.fromEntries(designManifest.tables.map(t => [t.planned_table, t]));
for (const name of migrManifest.tables) {
  const d = designTables[name];
  if (!d) { errors.push({ path: name, error: 'WAVE5_MANIFEST_CONFLICT: not in design manifest tables[]' }); continue; }
  if (!d.ready_for_migration) errors.push({ path: name, error: 'WAVE5_MANIFEST_CONFLICT: ready_for_migration is not true in design manifest' });
  if (String(d.decision || '').trim().toUpperCase().startsWith('REJECTED')) errors.push({ path: name, error: 'WAVE5_MANIFEST_CONFLICT: design manifest marks the table REJECTED' });
}
for (const [name, d] of Object.entries(designTables)) {
  if (d.wave5 === true && !migrManifest.tables.includes(name)) errors.push({ path: name, error: 'WAVE5_MANIFEST_CONFLICT: design manifest has wave5=true but table missing from migration manifest' });
}
if (designTables.courses && designTables.courses.already_exists && migrManifest.tables.includes('courses')) errors.push({ path: 'courses', error: 'WAVE5_MANIFEST_CONFLICT: courses is already physical (Wave 4) but listed for (re)creation' });

// 3. Migration files on disk must exactly match the manifest, in order, and be the only `_wave5_` files.
const onDisk = fs.readdirSync(migrationsDir).filter(f => f.includes('_wave5_')).sort();
const manifestSorted = [...migrManifest.migrations].sort();
if (JSON.stringify(onDisk) !== JSON.stringify(manifestSorted)) errors.push({ path: 'migrations', error: 'unregistered or missing Wave 5 migration file', on_disk: onDisk, manifest: manifestSorted });

// 4. Parse each migration's CREATE TABLE SQL and compare structurally against the catalog.
const catalogTables = Object.fromEntries(catalog.tables.filter(t => t.domain === 'Academia').map(t => [t.name, t]));

function parseCreateTable(sql, tableName) {
  const m = sql.match(/CREATE TABLE `(\w+)` \(([\s\S]*)\) ENGINE=(\w+) DEFAULT CHARACTER SET (\w+) COLLATE (\w+)/);
  if (!m) return null;
  const body = m[2];
  const lines = [];
  let depth = 0, cur = '';
  for (const ch of body) {
    if (ch === '(') depth++;
    if (ch === ')') depth--;
    if (ch === ',' && depth === 0) { lines.push(cur.trim()); cur = ''; continue; }
    cur += ch;
  }
  if (cur.trim()) lines.push(cur.trim());
  const columns = [];
  const uniques = [];
  const fks = [];
  const checks = [];
  let pk = null;
  for (const line of lines) {
    let cm;
    if ((cm = line.match(/^`(\w+)` ([\w()0-9,]+(?: UNSIGNED)?(?: CHARACTER SET \w+ COLLATE \w+)?) (NOT NULL|NULL)/))) {
      columns.push({ name: cm[1], type: cm[2], nullable: cm[3] === 'NULL' });
    } else if ((cm = line.match(/^`(\w+)` (JSON) (NOT NULL|NULL)/))) {
      columns.push({ name: cm[1], type: cm[2], nullable: cm[3] === 'NULL' });
    } else if (line.startsWith('PRIMARY KEY')) {
      pk = line.match(/`(\w+)`/)[1];
    } else if (line.startsWith('UNIQUE KEY')) {
      const cols = [...line.matchAll(/`(\w+)`/g)].map(x => x[1]).slice(1);
      uniques.push(cols);
    } else if (line.startsWith('CONSTRAINT') && line.includes('FOREIGN KEY')) {
      const fm = line.match(/CONSTRAINT `(\w+)` FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`\w+`\) ON DELETE (\w+) ON UPDATE (\w+)/);
      if (fm) fks.push({ name: fm[1], column: fm[2], target: fm[3], on_delete: fm[4], on_update: fm[5] });
    } else if (line.startsWith('CONSTRAINT') && line.includes('CHECK')) {
      checks.push(line);
    }
  }
  return { name: tableName, columns, uniques, fks, checks, pk, engine: m[3] };
}

for (const file of migrManifest.migrations) {
  const table = file.match(/wave5_create_(\w+)\.php$/)[1];
  const src = fs.readFileSync(path.join(migrationsDir, file), 'utf8');
  const sqlMatch = src.match(/DB::statement\(<<<'SQL'\n([\s\S]*?)\nSQL/);
  if (!sqlMatch) { errors.push({ path: file, error: 'no DB::statement heredoc found in up()' }); continue; }
  const parsed = parseCreateTable(sqlMatch[1], table);
  if (!parsed) { errors.push({ path: file, error: 'CREATE TABLE could not be parsed' }); continue; }
  if (parsed.engine !== 'InnoDB') errors.push({ path: file, error: 'engine must be InnoDB, found ' + parsed.engine });
  if (parsed.pk !== 'id') errors.push({ path: file, error: 'PRIMARY KEY must be id' });

  const cat = catalogTables[table];
  if (!cat) { errors.push({ path: file, error: 'table not found in model_catalog.json Academia domain' }); continue; }

  const catCols = new Set(cat.columns.map(c => c.name));
  const migCols = new Set(parsed.columns.map(c => c.name));
  for (const c of catCols) if (!migCols.has(c)) errors.push({ path: table, error: `catalog column '${c}' missing from migration` });
  // P0.3.5-A1.1 (A1R-02): model_catalog.json now carries classes.location_id
  // (ADR-0015 Decision A, ACCEPTED) directly -- no exception needed here.
  for (const c of migCols) if (!catCols.has(c)) errors.push({ path: table, error: `migration column '${c}' not present in model_catalog.json (undeclared addition)` });

  const publicIdRequired = cat.identifiers && cat.identifiers.public_id_required;
  const hasPublicId = migCols.has('public_id');
  if (Boolean(publicIdRequired) !== Boolean(hasPublicId)) errors.push({ path: table, error: `public_id presence (${hasPublicId}) does not match catalog identifiers.public_id_required (${Boolean(publicIdRequired)})` });

  for (const fk of parsed.fks) {
    if (fk.on_delete !== 'RESTRICT' || fk.on_update !== 'RESTRICT') errors.push({ path: table + '.' + fk.column, error: `FK must be RESTRICT/RESTRICT, found ${fk.on_delete}/${fk.on_update}` });
  }
}

// 5. Rollback guard: every migration's down() must reference WAVE5_DURABLE_DATA_ROLLBACK_BLOCKED
//    and enumerate exactly the 25 Wave 5 tables (same pattern as Wave 4 / ADR 0013).
for (const file of migrManifest.migrations) {
  const src = fs.readFileSync(path.join(migrationsDir, file), 'utf8');
  if (!src.includes('WAVE5_DURABLE_DATA_ROLLBACK_BLOCKED')) errors.push({ path: file, error: 'down() missing WAVE5_DURABLE_DATA_ROLLBACK_BLOCKED guard' });
  const listMatch = src.match(/foreach \(\[([^\]]+)\] as \$table\)/);
  if (!listMatch) { errors.push({ path: file, error: 'down() guard table list not found' }); continue; }
  const guardedTables = [...listMatch[1].matchAll(/'(\w+)'/g)].map(m => m[1]);
  if (JSON.stringify(guardedTables) !== JSON.stringify(migrManifest.tables)) errors.push({ path: file, error: 'down() guard table list does not match wave5_migrations_manifest.json tables[] exactly' });
}

// Aggregate physical counts from the parsed migrations (static -- matches what
// the live information_schema introspection in WaveFivePhysicalTest confirmed).
let totalColumns = 0, totalFks = 0, totalChecks = 0, totalUnique = 0, totalPublicId = 0, totalCascade = 0;
for (const file of migrManifest.migrations) {
  const table = file.match(/wave5_create_(\w+)\.php$/)[1];
  const src = fs.readFileSync(path.join(migrationsDir, file), 'utf8');
  const sqlMatch = src.match(/DB::statement\(<<<'SQL'\n([\s\S]*?)\nSQL/);
  const parsed = sqlMatch && parseCreateTable(sqlMatch[1], table);
  if (!parsed) continue;
  totalColumns += parsed.columns.length;
  totalFks += parsed.fks.length;
  totalChecks += parsed.checks.length;
  totalUnique += parsed.uniques.length;
  if (parsed.columns.some(c => c.name === 'public_id')) totalPublicId++;
  totalCascade += parsed.fks.filter(f => f.on_delete === 'CASCADE' || f.on_update === 'CASCADE').length;
}

const report = {
  phase: 'P0.3.5-A1',
  status: errors.length ? 'DRIFT_DETECTED' : 'STRICT_PARITY_PASS',
  scope: '25 Wave 5 (Academia) migration files, statically parsed, vs. model_catalog.json + wave5_manifest.json (design authority) + wave5_migrations_manifest.json (execution authority)',
  tables: migrManifest.migrations.length,
  columns: totalColumns,
  foreign_keys: totalFks,
  checks: totalChecks,
  unique: totalUnique,
  public_id: totalPublicId,
  cascade: totalCascade,
  unexpected_drift: errors.length,
  errors,
};
fs.writeFileSync(path.join(root, 'docs/database/physical/wave5_schema_validation.json'), JSON.stringify(report, null, 2) + '\n');
console.log(JSON.stringify(report, null, 2));
process.exit(errors.length ? 1 : 0);
