<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: membership_periods; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `membership_periods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `membership_id` BIGINT UNSIGNED NOT NULL,
  `congregation_id` BIGINT UNSIGNED NOT NULL,
  `status_id` BIGINT UNSIGNED NOT NULL,
  `starts_at` DATETIME(6) NOT NULL,
  `ends_at` DATETIME(6) NULL DEFAULT NULL,
  `reason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `source_document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_membership_periods_membership_id_starts_at` (`membership_id`,`starts_at`),
  KEY `ix_membership_periods_congregation_id_status_id_starts_at` (`congregation_id`,`status_id`,`starts_at`),
  KEY `ix_membership_periods_status_id` (`status_id`),
  KEY `ix_membership_periods_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_membership_periods_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_membership_periods_congregation_id` FOREIGN KEY (`congregation_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_membership_periods_status_id` FOREIGN KEY (`status_id`) REFERENCES `membership_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_membership_periods_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_membership_periods_period` CHECK (`ends_at` IS NULL OR `ends_at` > `starts_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `membership_periods`');
    }
};
