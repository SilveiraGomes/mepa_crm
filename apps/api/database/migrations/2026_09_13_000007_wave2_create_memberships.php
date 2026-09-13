<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: memberships; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `memberships` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `person_id` BIGINT UNSIGNED NOT NULL,
  `status_id` BIGINT UNSIGNED NOT NULL,
  `admitted_on` DATE NULL DEFAULT NULL,
  `date_precision` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `approved_at` DATETIME(6) NULL DEFAULT NULL,
  `source_document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `origin` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_memberships_person_id` (`person_id`),
  UNIQUE KEY `uq_memberships_public_id` (`public_id`),
  KEY `ix_memberships_status_id` (`status_id`),
  KEY `ix_memberships_approved_by` (`approved_by`),
  KEY `ix_memberships_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_memberships_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_memberships_status_id` FOREIGN KEY (`status_id`) REFERENCES `membership_statuses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_memberships_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_memberships_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `memberships`');
    }
};
