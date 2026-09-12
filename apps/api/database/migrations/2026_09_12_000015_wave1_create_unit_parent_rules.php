<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.1: unit_parent_rules; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
// Run the existing capability preflight before execution.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 1 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `unit_parent_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `child_type_id` BIGINT UNSIGNED NOT NULL,
  `parent_type_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_parent_rules_child_type_id_parent_type_id` (`child_type_id`,`parent_type_id`),
  KEY `ix_unit_parent_rules_parent_type_id` (`parent_type_id`),
  CONSTRAINT `fk_unit_parent_rules_child_type_id` FOREIGN KEY (`child_type_id`) REFERENCES `organizational_unit_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_unit_parent_rules_parent_type_id` FOREIGN KEY (`parent_type_id`) REFERENCES `organizational_unit_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `unit_parent_rules`');
    }
};
