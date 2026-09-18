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
CREATE TABLE `class_instructors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `class_id` BIGINT UNSIGNED NOT NULL,
  `instructor_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_at` DATETIME(6) NOT NULL,
  `ends_at` DATETIME(6) NULL DEFAULT NULL,
  `reason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `source_document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_class_instructors_class_id_starts_at` (`class_id`,`starts_at`),
  KEY `ix_class_instructors_instructor_id` (`instructor_id`),
  KEY `ix_class_instructors_source_document_id` (`source_document_id`),
  CONSTRAINT `fk_class_instructors_class_id` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_class_instructors_instructor_id` FOREIGN KEY (`instructor_id`) REFERENCES `instructors` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_class_instructors_source_document_id` FOREIGN KEY (`source_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_class_instructors_period` CHECK (`ends_at` IS NULL OR `ends_at` > `starts_at`)
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
        DB::statement('DROP TABLE `class_instructors`');
    }
};
