<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.1: person_relationships; source: approved model_catalog / dictionary.
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
CREATE TABLE `person_relationships` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_person_id` BIGINT UNSIGNED NOT NULL,
  `related_person_id` BIGINT UNSIGNED NOT NULL,
  `relationship_type_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` DATETIME(6) NOT NULL,
  `ends_at` DATETIME(6) NULL DEFAULT NULL,
  `reason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `source_document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_person_relationships_subject_person_id_relationship_type_id` (`subject_person_id`,`relationship_type_id`),
  KEY `ix_person_relationships_related_person_id_relationship_type_id` (`related_person_id`,`relationship_type_id`),
  KEY `ix_person_relationships_relationship_type_id` (`relationship_type_id`),
  KEY `ix_person_relationships_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_person_relationships_subject_person_id` FOREIGN KEY (`subject_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_relationships_related_person_id` FOREIGN KEY (`related_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_relationships_relationship_type_id` FOREIGN KEY (`relationship_type_id`) REFERENCES `relationship_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_relationships_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_person_relationships_period` CHECK (`ends_at` IS NULL OR `ends_at` > `starts_at`),
  CONSTRAINT `ck_person_relationships_not_reflexive` CHECK (`subject_person_id` <> `related_person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `person_relationships`');
    }
};
