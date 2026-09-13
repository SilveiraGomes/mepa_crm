<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: scopes; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `scopes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `department_instance_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `include_descendants` TINYINT UNSIGNED NOT NULL,
  `scope_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_scopes_unit_id_department_instance_id` (`unit_id`,`department_instance_id`),
  KEY `ix_scopes_department_instance_id` (`department_instance_id`),
  CONSTRAINT `fk_scopes_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_scopes_department_instance_id` FOREIGN KEY (`department_instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_scopes_include_descendants` CHECK (`include_descendants` IN (0,1)),
  CONSTRAINT `ck_scopes_scope_kind` CHECK (`scope_kind` IN ('UNIT','UNIT_DEPARTMENT'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `scopes`');
    }
};
