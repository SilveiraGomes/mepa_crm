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
CREATE TABLE `child_custody_visits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` BIGINT UNSIGNED NOT NULL,
  `child_person_id` BIGINT UNSIGNED NOT NULL,
  `delivered_by_person_id` BIGINT UNSIGNED NOT NULL,
  `authorization_id` BIGINT UNSIGNED NOT NULL,
  `checked_in_at` DATETIME(6) NOT NULL,
  `checked_in_by` BIGINT UNSIGNED NOT NULL,
  `collected_by_person_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `collection_authorization_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `checked_out_at` DATETIME(6) NULL DEFAULT NULL,
  `checked_out_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_child_custody_visits_session_id_child_person_id_checked_in_at` (`session_id`,`child_person_id`,`checked_in_at`),
  KEY `ix_child_custody_visits_child_person_id` (`child_person_id`),
  KEY `ix_child_custody_visits_delivered_by_person_id` (`delivered_by_person_id`),
  KEY `ix_child_custody_visits_authorization_id` (`authorization_id`),
  KEY `ix_child_custody_visits_checked_in_by` (`checked_in_by`),
  KEY `ix_child_custody_visits_collected_by_person_id` (`collected_by_person_id`),
  KEY `ix_child_custody_visits_collection_authorization_id` (`collection_authorization_id`),
  KEY `ix_child_custody_visits_checked_out_by` (`checked_out_by`),
  CONSTRAINT `fk_child_custody_visits_session_id` FOREIGN KEY (`session_id`) REFERENCES `event_sessions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_child_person_id` FOREIGN KEY (`child_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_delivered_by_person_id` FOREIGN KEY (`delivered_by_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_authorization_id` FOREIGN KEY (`authorization_id`) REFERENCES `guardian_authorizations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_checked_in_by` FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_collected_by_person_id` FOREIGN KEY (`collected_by_person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_collection_authorization_id` FOREIGN KEY (`collection_authorization_id`) REFERENCES `guardian_authorizations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_child_custody_visits_checked_out_by` FOREIGN KEY (`checked_out_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
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
        DB::statement('DROP TABLE `child_custody_visits`');
    }
};
