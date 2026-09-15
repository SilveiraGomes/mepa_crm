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
CREATE TABLE `outreach_campaigns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `event_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` DATETIME(6) NOT NULL,
  `ends_at` DATETIME(6) NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outreach_campaigns_public_id` (`public_id`),
  KEY `ix_outreach_campaigns_unit_id` (`unit_id`),
  KEY `ix_outreach_campaigns_event_id` (`event_id`),
  CONSTRAINT `fk_outreach_campaigns_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_outreach_campaigns_event_id` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
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
        DB::statement('DROP TABLE `outreach_campaigns`');
    }
};
