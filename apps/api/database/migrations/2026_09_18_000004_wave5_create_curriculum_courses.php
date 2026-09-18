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
CREATE TABLE `curriculum_courses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `curriculum_id` BIGINT UNSIGNED NOT NULL,
  `course_id` BIGINT UNSIGNED NOT NULL,
  `sequence` INT UNSIGNED NOT NULL,
  `required` TINYINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_curriculum_courses_curriculum_id_course_id` (`curriculum_id`,`course_id`),
  UNIQUE KEY `uq_curriculum_courses_curriculum_id_sequence` (`curriculum_id`,`sequence`),
  KEY `ix_curriculum_courses_course_id` (`course_id`),
  CONSTRAINT `fk_curriculum_courses_curriculum_id` FOREIGN KEY (`curriculum_id`) REFERENCES `curricula` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_curriculum_courses_course_id` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_curriculum_courses_sequence` CHECK (`sequence` >= 1),
  CONSTRAINT `ck_curriculum_courses_required` CHECK (`required` IN (0,1))
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
        DB::statement('DROP TABLE `curriculum_courses`');
    }
};
