<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.4 approved catalogue. Durable-data rollback: ADR 0013.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') throw new RuntimeException('Qualified MySQL required');
        DB::statement(<<<'SQL'
CREATE TABLE `person_consents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_person_id` BIGINT UNSIGNED NOT NULL,
  `given_by_person_id` BIGINT UNSIGNED NOT NULL,
  `purpose` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `policy_version` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `granted_at` DATETIME(6) NOT NULL,
  `revoked_at` DATETIME(6) NULL DEFAULT NULL,
  `document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_consents_public_id` (`public_id`),
  KEY `ix_person_consents_subject_person_id` (`subject_person_id`),
  KEY `ix_person_consents_given_by_person_id` (`given_by_person_id`),
  KEY `ix_person_consents_document_id` (`document_id`),
  CONSTRAINT `fk_person_consents_subject_person_id` FOREIGN KEY (`subject_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_consents_given_by_person_id` FOREIGN KEY (`given_by_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_consents_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        // The first migration rolled back checks ALL Wave 4 tables before any DROP.
        foreach (['child_profiles', 'guardian_authorizations', 'person_consents', 'child_emergency_contacts', 'child_custody_visits', 'age_band_rules', 'department_transition_recommendations', 'outreach_campaigns', 'outreach_contacts', 'followups', 'decisions', 'discipleship_tracks', 'discipleship_enrollments', 'integration_events', 'courses', 'discipleship_steps', 'discipleship_progress'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('WAVE4_DURABLE_DATA_ROLLBACK_BLOCKED');
            }
        }
        DB::statement('DROP TABLE `person_consents`');
    }
};
