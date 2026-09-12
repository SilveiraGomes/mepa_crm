'use strict';
// Explicit generator: writes only Wave 1 migrations and planned audit artefacts.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { root, catalog, names, deferred, orderedTables, model, createSql } = require('./lib/wave1-catalog.cjs');
const physical = path.join(root, 'docs/database/physical');
fs.mkdirSync(physical, { recursive: true });
const tables = orderedTables();
const migrations = tables.map((t, i) => ({ table: t.name, file: `2026_09_12_${String(i + 1).padStart(6, '0')}_wave1_create_${t.name}.php` }));
for (const [i, t] of tables.entries()) {
  const sql = createSql(model(t, true));
  const body = `<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\n\n// P0.3.1: ${t.name}; source: approved model_catalog / dictionary.\n// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.\n// Run the existing capability preflight before execution.\n${t.name === 'files' ? '// W1-F01: owner_department_id FK deferred to Wave 2; strict drift must report it.\n' : ''}return new class extends Migration\n{\n    public function up(): void\n    {\n        if (DB::getDriverName() !== 'mysql') {\n            throw new RuntimeException('Wave 1 requires the qualified MySQL driver; SQLite is not supported.');\n        }\n\n        DB::statement(<<<'SQL'\n${sql}\nSQL\n        );\n    }\n\n    public function down(): void\n    {\n        DB::statement('DROP TABLE \`${t.name}\`');\n    }\n};\n`;
  fs.writeFileSync(path.join(root, 'apps/api/database/migrations', migrations[i].file), body);
}
const hash = crypto.createHash('sha256').update(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'))).digest('hex');
fs.writeFileSync(path.join(physical, 'wave1_manifest.json'), JSON.stringify({ phase: 'P0.3.1', catalog_sha256: hash,
  tables: tables.map(t => t.name), migrations, external_scaffolding: ['users'], deferred_foreign_keys: deferred,
  physical_candidates_applied: false, schema_execution_status: 'BLOCKED_FOR_EXECUTION_PENDING_AUTHORIZED_MYSQL' }, null, 2) + '\n');
const wave = Object.fromEntries(catalog.tables.map(t => [t.name, ({ Identidade: 2, Estrutura: 1, 'Storage e património': 8,
  'Membros e credenciais': 2, 'Ministério e departamentos': 2, 'Governança e eventos': 3, Crianças: 4,
  Evangelismo: 4, Academia: 5, Financeiro: 6, Comunicação: 7, Estatística: 8, 'Segurança e workflows': 2, Importação: 2 })[t.domain]]));
for (const n of names) wave[n] = 1;
for (const n of ['credential_types','credential_classes','credential_templates','credential_class_styles','credentials','department_activities']) wave[n] = 3;
for (const n of ['audit_logs','system_settings','outbox_events']) wave[n] = 8;
for (const n of ['workflow_steps','workflow_tasks','workflow_decisions','membership_workflows']) wave[n] = 7;
wave.courses = 4;
let changed;
do {
  changed = false;
  for (const t of catalog.tables) for (const c of t.columns.filter(c => c.fk)) {
    if (wave[t.name] === 1) continue;
    if (wave[c.fk] > wave[t.name]) { wave[c.fk] = wave[t.name]; changed = true; }
  }
} while (changed);
const waves = [];
for (let n = 1; n <= 8; n++) {
  const pending = catalog.tables.filter(t => wave[t.name] === n), ordered = [], installed = new Set(catalog.tables.filter(t => wave[t.name] < n).map(t => t.name));
  const cyclic = [];
  while (pending.length) {
    const i = pending.findIndex(t => t.columns.every(c => !c.fk || c.fk === t.name || installed.has(c.fk)
      || (n === 1 && (c.fk === 'users' || deferred.some(d => d.table === t.name && d.column === c.name)))));
    if (i < 0) { cyclic.push(...pending.map(t => t.name)); ordered.push(...pending.map(t => t.name)); break; }
    const [t] = pending.splice(i, 1); installed.add(t.name); ordered.push(t.name);
  }
  waves.push({ wave: n, tables: ordered, create_before_adding_cyclic_foreign_keys: cyclic });
}
fs.writeFileSync(path.join(physical, 'migration_waves.json'), JSON.stringify({ total_tables: catalog.tables.length, waves,
  cross_wave_deferred_foreign_keys: deferred, scaffolding_adaptation: 'users: expand/backfill/validate/contract in Wave 2; never recreate occupied table' }, null, 2) + '\n');
const plan = path.join(root, 'docs/database/12_physical_migration_plan.md');
const intro = fs.readFileSync(plan, 'utf8').split('\n<!-- GENERATED INVENTORY -->')[0];
fs.writeFileSync(plan, intro + '\n<!-- GENERATED INVENTORY -->\n\n## Inventário completo\n\n' + waves.map(w => `### Wave ${w.wave} — ${w.tables.length} tabelas\n\n${w.tables.map((t, i) => `${i + 1}. ${t}`).join('\n')}\n\n${w.create_before_adding_cyclic_foreign_keys.length ? 'Ciclo nesta onda: criar tabelas primeiro; adicionar FKs depois: ' + w.create_before_adding_cyclic_foreign_keys.join(', ') + '.\n' : ''}`).join('\n'));
const stats = { tables: tables.length, columns: tables.reduce((n,t)=>n+t.columns.length,0),
  public_id: tables.filter(t=>t.identifiers.public_id_required).length, primary_keys: tables.length,
  foreign_keys_catalog: tables.reduce((n,t)=>n+t.columns.filter(c=>c.fk).length,0),
  foreign_keys_emitted: tables.reduce((n,t)=>n+model(t,true).foreign_keys.length,0),
  checks: tables.reduce((n,t)=>n+model(t,true).checks.length,0),
  unique: tables.reduce((n,t)=>n+t.unique.length,0), indexes: tables.reduce((n,t)=>n+t.indexes.length,0), cascade: 0 };
fs.writeFileSync(path.join(physical, 'wave1_planned_stats.json'), JSON.stringify(stats,null,2)+'\n');
fs.writeFileSync(path.join(physical, 'wave1_planned_schema.sql'), '-- PLANNED DDL, NOT A DATABASE SNAPSHOT. No data. W1-F01 remains deferred.\n' + tables.map(t=>createSql(model(t,true))+';').join('\n\n')+'\n');
let report = '# Wave 1 — especificação emitida (não introspecção de BD)\n\nEstado: BLOCKED FOR EXECUTION até MySQL autorizado. SQL planeado não é snapshot físico aprovado. W1-F01: uma FK departamental pendente; não omitir do drift estrito.\n\n```json\n'+JSON.stringify(stats,null,2)+'\n```\n\n';
for (const t of tables) {
  const m = model(t,true);
  report += `## ${t.name}\n\nInnoDB; utf8mb4; utf8mb4_unicode_ci. PRIMARY KEY (${m.primary}).\n\n| Coluna | Tipo | NULL | Default | Charset/collation |\n|---|---|---|---|---|\n`;
  for (const c of m.columns) report += `| ${c.name} | ${c.type} | ${c.nullable} | ${c.auto_increment ? 'AUTO_INCREMENT' : c.default ?? (c.nullable ? 'NULL' : 'nenhum')} | ${c.charset ? c.charset+'/'+c.collation : '—'} |\n`;
  report += '\n| Índice | Colunas | Classe | Motivo / consulta esperada |\n|---|---|---|---|\n';
  for (const ix of m.indexes) report += `| ${ix.name} | ${ix.columns.join(', ')} | ${ix.unique ? 'UNIQUE' : 'INDEX'} | ${ix.unique ? 'Unicidade aprovada / igualdade pela chave' : 'Índice aprovado / filtro e percurso pelo prefixo ('+ix.columns.join(', ')+')'} |\n`;
  report += '\n| FK | Colunas | Alvo | DELETE/UPDATE |\n|---|---|---|---|\n';
  for (const fk of model(t).foreign_keys) report += `| ${fk.name} | ${fk.columns} | ${fk.target_table}.${fk.target_columns} | ${fk.on_delete}/${fk.on_update}${deferred.some(d=>d.table===t.name&&d.column===fk.columns[0]) ? ' — PENDENTE WAVE 2' : ''} |\n`;
  report += '\n| CHECK | Expressão | Fonte |\n|---|---|---|\n';
  for (const ck of m.checks) report += `| ${ck.name} | ${ck.expression.replaceAll('|','\\|')} | ${ck.source} |\n`;
  report += '\n';
}
fs.writeFileSync(path.join(physical, 'wave1_schema_report.md'), report);
fs.writeFileSync(path.join(physical, 'P0.3.1_wave1_tables.md'), '# Inventário Wave 1\n\nFonte: catálogo aprovado, ordem de dependência. PK em todas: BIGINT UNSIGNED AUTO_INCREMENT. Sem Membership.\n\n| Ordem | Tabela | Colunas | public_id | FKs previstas/emitidas |\n|---|---|---|---|---|\n'+tables.map((t,i)=>`| ${i+1} | ${t.name} | ${t.columns.length} | ${t.identifiers.public_id_required} | ${model(t).foreign_keys.length}/${model(t,true).foreign_keys.length} |`).join('\n')+'\n\nproperties é necessária como alvo de unit_location_links; property_documents, temples, facilities e assets permanecem Wave 8. files e legal_documents/document_versions suportam documentos e evidência dos períodos. Uma FK departamental pendente impede paridade completa.\n');
console.log(JSON.stringify(stats,null,2));
