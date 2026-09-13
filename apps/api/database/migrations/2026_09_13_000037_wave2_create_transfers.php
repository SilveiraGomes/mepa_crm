<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: transfers; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `membership_id` BIGINT UNSIGNED NOT NULL,
  `origin_unit_id` BIGINT UNSIGNED NOT NULL,
  `destination_unit_id` BIGINT UNSIGNED NOT NULL,
  `requested_at` DATETIME(6) NOT NULL,
  `effective_at` DATETIME(6) NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `workflow_instance_id` BIGINT UNSIGNED NOT NULL,
  `source_document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transfers_public_id` (`public_id`),
  KEY `ix_transfers_membership_id_status` (`membership_id`,`status`),
  KEY `ix_transfers_destination_unit_id_status` (`destination_unit_id`,`status`),
  KEY `ix_transfers_origin_unit_id` (`origin_unit_id`),
  KEY `ix_transfers_workflow_instance_id` (`workflow_instance_id`),
  KEY `ix_transfers_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_transfers_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_transfers_origin_unit_id` FOREIGN KEY (`origin_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_transfers_destination_unit_id` FOREIGN KEY (`destination_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_transfers_workflow_instance_id` FOREIGN KEY (`workflow_instance_id`) REFERENCES `workflow_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_transfers_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_transfers_origin_destination` CHECK (`origin_unit_id` <> `destination_unit_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `transfers`');
    }
};
