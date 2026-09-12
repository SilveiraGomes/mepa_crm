<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.1: people; source: approved model_catalog / dictionary.
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
CREATE TABLE `people` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `full_name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `birth_date` DATE NULL DEFAULT NULL,
  `birth_precision` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `birth_year` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `sex_type_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `civil_status_type_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status_id` BIGINT UNSIGNED NOT NULL,
  `merged_into_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `archived_at` DATETIME(6) NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_people_public_id` (`public_id`),
  KEY `ix_people_full_name_id` (`full_name`,`id`),
  KEY `ix_people_birth_date_id` (`birth_date`,`id`),
  KEY `ix_people_sex_type_id` (`sex_type_id`),
  KEY `ix_people_civil_status_type_id` (`civil_status_type_id`),
  KEY `ix_people_status_id` (`status_id`),
  KEY `ix_people_merged_into_id` (`merged_into_id`),
  CONSTRAINT `fk_people_sex_type_id` FOREIGN KEY (`sex_type_id`) REFERENCES `sex_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_people_civil_status_type_id` FOREIGN KEY (`civil_status_type_id`) REFERENCES `civil_status_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_people_status_id` FOREIGN KEY (`status_id`) REFERENCES `person_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_people_merged_into_id` FOREIGN KEY (`merged_into_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_people_birth` CHECK ((`birth_precision` = 'EXACT' AND `birth_date` IS NOT NULL AND `birth_year` IS NULL) OR (`birth_precision` = 'YEAR_ONLY' AND `birth_date` IS NULL AND `birth_year` IS NOT NULL) OR (`birth_precision` = 'UNKNOWN' AND `birth_date` IS NULL AND `birth_year` IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `people`');
    }
};
