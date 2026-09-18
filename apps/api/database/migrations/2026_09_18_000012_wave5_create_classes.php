<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.5-A1 approved catalogue (model_catalog.json, domain Academia). Durable-data rollback: same pattern as ADR 0013 (Wave 4).
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') throw new RuntimeException('Qualified MySQL required');
        DB::statement(<<<'SQL'
CREATE TABLE `classes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `cohort_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `academic_unit_id` BIGINT UNSIGNED NOT NULL,
  `course_version_id` BIGINT UNSIGNED NOT NULL,
  `location_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `code` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `capacity` INT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_classes_code` (`code`),
  UNIQUE KEY `uq_classes_public_id` (`public_id`),
  KEY `ix_classes_cohort_id` (`cohort_id`),
  KEY `ix_classes_academic_unit_id` (`academic_unit_id`),
  KEY `ix_classes_course_version_id` (`course_version_id`),
  KEY `ix_classes_location_id` (`location_id`),
  CONSTRAINT `fk_classes_cohort_id` FOREIGN KEY (`cohort_id`) REFERENCES `cohorts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_classes_academic_unit_id` FOREIGN KEY (`academic_unit_id`) REFERENCES `academic_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_classes_course_version_id` FOREIGN KEY (`course_version_id`) REFERENCES `course_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_classes_location_id` FOREIGN KEY (`location_id`) REFERENCES `physical_locations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        // Wave 5 down() checks ALL Wave 5 tables before any DROP (same pattern as Wave 4 / ADR 0013).
        foreach (['academic_units', 'programs', 'curricula', 'curriculum_courses', 'course_versions', 'course_prerequisites', 'course_modules', 'lessons', 'resources', 'lesson_resources', 'cohorts', 'classes', 'instructors', 'class_instructors', 'class_sessions', 'enrollments', 'academic_attendance', 'assessments', 'assessment_attempts', 'grades', 'progress', 'resource_progress', 'certificates', 'transcripts', 'transcript_lines'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('WAVE5_DURABLE_DATA_ROLLBACK_BLOCKED');
            }
        }
        DB::statement('DROP TABLE `classes`');
    }
};
