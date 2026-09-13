<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: department_applicability; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `department_applicability` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` BIGINT UNSIGNED NOT NULL,
  `unit_type_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_applicability_department_id_unit_type_id` (`department_id`,`unit_type_id`),
  KEY `ix_department_applicability_unit_type_id` (`unit_type_id`),
  CONSTRAINT `fk_department_applicability_department_id` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_applicability_unit_type_id` FOREIGN KEY (`unit_type_id`) REFERENCES `organizational_unit_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `department_applicability`');
    }
};
