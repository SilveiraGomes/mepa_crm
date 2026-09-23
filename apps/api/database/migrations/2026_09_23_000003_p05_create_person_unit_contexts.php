<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.5-I delta A (ADR-0017 D01): temporal provenance fallback linking a Person to an organizational
// unit ONLY when no domain record (Membership, Academy, Events, Departments/Appointments, Children,
// Governance, functions) already provides it. It is not ownership and never grants access alone.
// No public_id: the row is never addressed externally.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.5 requires the qualified MySQL driver; SQLite is not supported.');
        }
        DB::statement(<<<'SQL'
CREATE TABLE `person_unit_contexts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `person_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `context_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` DATETIME(6) NOT NULL,
  `ends_at` DATETIME(6) NULL DEFAULT NULL,
  `reason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `source_document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_person_unit_contexts_person_id_status_starts_at` (`person_id`,`status`,`starts_at`),
  KEY `ix_person_unit_contexts_unit_id_context_kind_status_starts_at` (`unit_id`,`context_kind`,`status`,`starts_at`),
  KEY `ix_person_unit_contexts_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_person_unit_contexts_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_unit_contexts_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_unit_contexts_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_person_unit_contexts_period` CHECK (`ends_at` IS NULL OR `ends_at` > `starts_at`),
  CONSTRAINT `ck_person_unit_contexts_context_kind` CHECK (`context_kind` IN ('ONBOARDING','EMPLOYMENT','DISCIPLESHIP','LEGACY_IMPORT')),
  CONSTRAINT `ck_person_unit_contexts_status` CHECK (`status` IN ('ACTIVE','INACTIVE'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `person_unit_contexts`');
    }
};
