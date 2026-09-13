<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.3; approved catalogue. Early audit support: ADR 0012.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') throw new RuntimeException('Qualified MySQL required');
        DB::statement(<<<'SQL'
CREATE TABLE `invitation_criteria` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `list_id` BIGINT UNSIGNED NOT NULL,
  `criterion_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `position_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `class_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `body_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `unit_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `department_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `include_descendants` TINYINT UNSIGNED NOT NULL,
  `operator` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_invitation_criteria_list_id` (`list_id`),
  KEY `ix_invitation_criteria_position_id` (`position_id`),
  KEY `ix_invitation_criteria_class_id` (`class_id`),
  KEY `ix_invitation_criteria_body_id` (`body_id`),
  KEY `ix_invitation_criteria_unit_id` (`unit_id`),
  KEY `ix_invitation_criteria_department_id` (`department_id`),
  CONSTRAINT `fk_invitation_criteria_list_id` FOREIGN KEY (`list_id`) REFERENCES `event_invitation_lists` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_position_id` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_class_id` FOREIGN KEY (`class_id`) REFERENCES `ministerial_classes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_body_id` FOREIGN KEY (`body_id`) REFERENCES `governance_bodies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_invitation_criteria_department_id` FOREIGN KEY (`department_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_invitation_criteria_include_descendants` CHECK (`include_descendants` IN (0,1)),
  CONSTRAINT `ck_invitation_criteria_target` CHECK ((`position_id` IS NOT NULL AND `class_id` IS NULL AND `body_id` IS NULL AND `unit_id` IS NULL AND `department_id` IS NULL) OR (`position_id` IS NULL AND `class_id` IS NOT NULL AND `body_id` IS NULL AND `unit_id` IS NULL AND `department_id` IS NULL) OR (`position_id` IS NULL AND `class_id` IS NULL AND `body_id` IS NOT NULL AND `unit_id` IS NULL AND `department_id` IS NULL) OR (`position_id` IS NULL AND `class_id` IS NULL AND `body_id` IS NULL AND `unit_id` IS NOT NULL AND `department_id` IS NULL) OR (`position_id` IS NULL AND `class_id` IS NULL AND `body_id` IS NULL AND `unit_id` IS NULL AND `department_id` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `invitation_criteria`');
    }
};
