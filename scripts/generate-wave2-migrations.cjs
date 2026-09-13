'use strict';
// Explicit generator: writes only Wave 2 migrations and planned audit artefacts.
// Mirrors scripts/generate-wave1-migrations.cjs; reuses wave1-catalog's indexName/createSql.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const w2 = require('./lib/wave2-catalog.cjs');
const { root, catalog, names, materialized, orderedTables, model, createSql } = w2;
const physical = path.join(root, 'docs/database/physical');
const tables = orderedTables().filter(t => t.name !== 'users');
const usersTable = catalog.tables.find(t => t.name === 'users');
const B = '`'; // single literal backtick, used only via string concatenation - never inside a template literal.
const NL = '\n';

const BASE = '2026_09_13_';
let n = 0;
const stamp = () => BASE + String(++n).padStart(6, '0');
const migrations = [];

function write(file, body) {
  fs.writeFileSync(path.join(root, 'apps/api/database/migrations', file), body);
  migrations.push(file);
}

const insertAfter = 'department_instances';
for (const t of tables) {
  const sql = createSql(model(t, false));
  const file = `${stamp()}_wave2_create_${t.name}.php`;
  write(file, `<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\n\n// P0.3.2: ${t.name}; source: approved model_catalog / dictionary.\n// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        if (DB::getDriverName() !== 'mysql') {\n            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');\n        }\n\n        DB::statement(<<<'SQL'\n${sql}\nSQL\n        );\n    }\n\n    public function down(): void\n    {\n        DB::statement('DROP TABLE ${B}${t.name}${B}');\n    }\n};\n`);

  if (t.name === insertAfter) {
    // W1-F01 materialization: files.owner_department_id -> department_instances.id now exists.
    const w1f01lines = [
      '<?php', '',
      'declare(strict_types=1);', '',
      'use Illuminate\\Database\\Migrations\\Migration;',
      'use Illuminate\\Support\\Facades\\DB;', '',
      '// P0.3.2: materializes W1-F01. department_instances now exists; the FK deferred in Wave 1',
      '// (files.owner_department_id) is added here, before any writer of that column is authorized.',
      'return new class extends Migration',
      '{',
      '    public function up(): void',
      '    {',
      "        if (DB::getDriverName() !== 'mysql') {",
      "            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');",
      '        }', '',
      "        DB::statement(<<<'SQL'",
      `ALTER TABLE ${B}files${B}`,
      `  ADD CONSTRAINT ${B}fk_files_owner_department_id${B} FOREIGN KEY (${B}owner_department_id${B})`,
      `  REFERENCES ${B}department_instances${B} (${B}id${B}) ON DELETE RESTRICT ON UPDATE RESTRICT`,
      'SQL', '        );',
      '    }', '',
      '    public function down(): void',
      '    {',
      `        DB::statement('ALTER TABLE ${B}files${B} DROP FOREIGN KEY ${B}fk_files_owner_department_id${B}');`,
      '    }', '};', '',
    ];
    write(`${stamp()}_wave2_materialize_w1_f01_files_owner_department_id.php`, w1f01lines.join(NL));
  }
}

// users: adapt the existing Wave 1 scaffolding table in place. Never DROP/CREATE - Wave 1's
// files.created_by FK already points at users.id and must survive untouched. Table is schema-only
// and empty at this point (no seed, no real accounts), so dropping scaffolding-only columns and
// adding NOT NULL columns without a backfill step is safe here and only here.
const usersLines = [
  '<?php', '',
  'declare(strict_types=1);', '',
  'use Illuminate\\Database\\Migrations\\Migration;',
  'use Illuminate\\Support\\Facades\\DB;', '',
  "// P0.3.2: adapts the Wave 1 Laravel scaffolding 'users' table to the approved model_catalog",
  '// shape. Deliberate ALTER, never DROP+CREATE.',
  'return new class extends Migration',
  '{',
  '    public function up(): void',
  '    {',
  "        if (DB::getDriverName() !== 'mysql') {",
  "            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');",
  '        }', '',
  "        DB::statement(<<<'SQL'",
  `ALTER TABLE ${B}users${B}`,
  `  DROP COLUMN ${B}name${B},`,
  `  DROP COLUMN ${B}email${B},`,
  `  DROP COLUMN ${B}email_verified_at${B},`,
  `  DROP COLUMN ${B}password${B},`,
  `  DROP COLUMN ${B}remember_token${B},`,
  `  DROP COLUMN ${B}updated_at${B},`,
  `  ADD COLUMN ${B}person_id${B} BIGINT UNSIGNED NULL DEFAULT NULL AFTER ${B}id${B},`,
  `  ADD COLUMN ${B}account_kind${B} VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER ${B}person_id${B},`,
  `  ADD COLUMN ${B}public_id${B} CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER ${B}account_kind${B},`,
  `  ADD COLUMN ${B}login${B} VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER ${B}public_id${B},`,
  `  ADD COLUMN ${B}password_hash${B} VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER ${B}login${B},`,
  `  ADD COLUMN ${B}status${B} VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER ${B}password_hash${B},`,
  `  ADD COLUMN ${B}mfa_required${B} TINYINT UNSIGNED NOT NULL AFTER ${B}status${B},`,
  `  ADD COLUMN ${B}archived_at${B} DATETIME(6) NULL DEFAULT NULL AFTER ${B}mfa_required${B},`,
  `  MODIFY COLUMN ${B}created_at${B} DATETIME(6) NOT NULL,`,
  `  ADD COLUMN ${B}lock_version${B} INT UNSIGNED NOT NULL DEFAULT 0`,
  'SQL', '        );',
  `        DB::statement('ALTER TABLE ${B}users${B} ADD UNIQUE KEY ${B}uq_users_public_id${B} (${B}public_id${B})');`,
  `        DB::statement('ALTER TABLE ${B}users${B} ADD UNIQUE KEY ${B}uq_users_login${B} (${B}login${B})');`,
  `        DB::statement('ALTER TABLE ${B}users${B} ADD UNIQUE KEY ${B}uq_users_person_id${B} (${B}person_id${B})');`,
  `        DB::statement('ALTER TABLE ${B}users${B} ADD CONSTRAINT ${B}fk_users_person_id${B} FOREIGN KEY (${B}person_id${B}) REFERENCES ${B}people${B} (${B}id${B}) ON DELETE RESTRICT ON UPDATE RESTRICT');`,
  `        DB::statement("ALTER TABLE ${B}users${B} ADD CONSTRAINT ${B}ck_users_account_kind${B} CHECK (${B}account_kind${B} IN ('HUMAN','SERVICE'))");`,
  `        DB::statement('ALTER TABLE ${B}users${B} ADD CONSTRAINT ${B}ck_users_mfa_required${B} CHECK (${B}mfa_required${B} IN (0,1))');`,
  '    }', '',
  '    public function down(): void',
  '    {',
  `        DB::statement('ALTER TABLE ${B}users${B} DROP CONSTRAINT ${B}ck_users_mfa_required${B}');`,
  `        DB::statement('ALTER TABLE ${B}users${B} DROP CONSTRAINT ${B}ck_users_account_kind${B}');`,
  `        DB::statement('ALTER TABLE ${B}users${B} DROP FOREIGN KEY ${B}fk_users_person_id${B}');`,
  `        DB::statement('ALTER TABLE ${B}users${B} DROP KEY ${B}uq_users_person_id${B}');`,
  `        DB::statement('ALTER TABLE ${B}users${B} DROP KEY ${B}uq_users_login${B}');`,
  `        DB::statement('ALTER TABLE ${B}users${B} DROP KEY ${B}uq_users_public_id${B}');`,
  "        DB::statement(<<<'SQL'",
  `ALTER TABLE ${B}users${B}`,
  `  DROP COLUMN ${B}lock_version${B},`,
  `  MODIFY COLUMN ${B}created_at${B} TIMESTAMP NULL DEFAULT NULL,`,
  `  DROP COLUMN ${B}archived_at${B},`,
  `  DROP COLUMN ${B}mfa_required${B},`,
  `  DROP COLUMN ${B}status${B},`,
  `  DROP COLUMN ${B}password_hash${B},`,
  `  DROP COLUMN ${B}login${B},`,
  `  DROP COLUMN ${B}public_id${B},`,
  `  DROP COLUMN ${B}account_kind${B},`,
  `  DROP COLUMN ${B}person_id${B},`,
  `  ADD COLUMN ${B}name${B} VARCHAR(255) NOT NULL,`,
  `  ADD COLUMN ${B}email${B} VARCHAR(255) NOT NULL,`,
  `  ADD COLUMN ${B}email_verified_at${B} TIMESTAMP NULL DEFAULT NULL,`,
  `  ADD COLUMN ${B}password${B} VARCHAR(255) NOT NULL,`,
  `  ADD COLUMN ${B}remember_token${B} VARCHAR(100) NULL DEFAULT NULL,`,
  `  ADD COLUMN ${B}updated_at${B} TIMESTAMP NULL DEFAULT NULL,`,
  `  ADD UNIQUE KEY ${B}users_email_unique${B} (${B}email${B})`,
  'SQL', '        );',
  '    }', '};', '',
];
write(`${stamp()}_wave2_adapt_users_table.php`, usersLines.join(NL));

fs.mkdirSync(physical, { recursive: true });
const hash = crypto.createHash('sha256').update(fs.readFileSync(path.join(root, 'docs/database/model_catalog.json'))).digest('hex');
fs.writeFileSync(path.join(physical, 'wave2_manifest.json'), JSON.stringify({ phase: 'P0.3.2', catalog_sha256: hash,
  tables: [...tables.map(t => t.name), 'users'], migrations, adapted_scaffolding: ['users'],
  materialized_foreign_keys: materialized, schema_execution_status: 'BLOCKED_FOR_EXECUTION_PENDING_AUTHORIZED_MYSQL' }, null, 2) + '\n');

const stats = { tables: tables.length + 1, columns: tables.reduce((s, t) => s + t.columns.length, 0) + usersTable.columns.length,
  public_id: tables.filter(t => t.identifiers.public_id_required).length + 1,
  foreign_keys: tables.reduce((s, t) => s + model(t).foreign_keys.length, 0) + usersTable.columns.filter(c => c.fk).length + 1,
  checks: tables.reduce((s, t) => s + model(t).checks.length, 0) + 2, cascade: 0 };
fs.writeFileSync(path.join(physical, 'wave2_planned_stats.json'), JSON.stringify(stats, null, 2) + '\n');
console.log(JSON.stringify({ ...stats, migration_files: migrations.length }, null, 2));
