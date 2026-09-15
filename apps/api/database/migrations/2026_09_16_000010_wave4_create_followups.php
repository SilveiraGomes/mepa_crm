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
CREATE TABLE `followups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contact_id` BIGINT UNSIGNED NOT NULL,
  `performed_by_person_id` BIGINT UNSIGNED NOT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  `outcome` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes_ciphertext` VARBINARY(2048) NULL DEFAULT NULL,
  `key_version` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_followups_contact_id_occurred_at` (`contact_id`,`occurred_at`),
  KEY `ix_followups_performed_by_person_id` (`performed_by_person_id`),
  CONSTRAINT `fk_followups_contact_id` FOREIGN KEY (`contact_id`) REFERENCES `outreach_contacts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_followups_performed_by_person_id` FOREIGN KEY (`performed_by_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
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
        DB::statement('DROP TABLE `followups`');
    }
};
