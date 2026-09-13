<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: member_numbers; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `member_numbers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `membership_id` BIGINT UNSIGNED NOT NULL,
  `number` CHAR(14) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sequence_value` INT UNSIGNED NOT NULL,
  `issued_year` SMALLINT UNSIGNED NOT NULL,
  `issued_month` SMALLINT UNSIGNED NOT NULL,
  `issued_at` DATETIME(6) NOT NULL,
  `origin` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_numbers_membership_id` (`membership_id`),
  UNIQUE KEY `uq_member_numbers_number` (`number`),
  UNIQUE KEY `uq_member_numbers_sequence_value` (`sequence_value`),
  CONSTRAINT `fk_member_numbers_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_member_numbers_origin` CHECK (`origin` IN ('APPROVED_ADMISSION','APPROVED_LEGACY_MAPPING')),
  CONSTRAINT `ck_member_numbers_issued_month` CHECK (`issued_month` BETWEEN 1 AND 12),
  CONSTRAINT `ck_member_numbers_sequence_value` CHECK (`sequence_value` BETWEEN 1 AND 999999)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `member_numbers`');
    }
};
