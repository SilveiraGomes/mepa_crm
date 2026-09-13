<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: person_merges; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `person_merges` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_person_id` BIGINT UNSIGNED NOT NULL,
  `target_person_id` BIGINT UNSIGNED NOT NULL,
  `approved_by` BIGINT UNSIGNED NOT NULL,
  `reason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mapping_metadata` JSON NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_person_merges_source_person_id` (`source_person_id`),
  KEY `ix_person_merges_target_person_id` (`target_person_id`),
  KEY `ix_person_merges_approved_by` (`approved_by`),
  CONSTRAINT `fk_person_merges_source_person_id` FOREIGN KEY (`source_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_merges_target_person_id` FOREIGN KEY (`target_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_merges_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_person_merges_not_self` CHECK (`source_person_id` <> `target_person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `person_merges`');
    }
};
