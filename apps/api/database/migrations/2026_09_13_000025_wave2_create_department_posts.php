<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: department_posts; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `department_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `instance_id` BIGINT UNSIGNED NOT NULL,
  `position_id` BIGINT UNSIGNED NOT NULL,
  `slot` INT UNSIGNED NOT NULL,
  `occupancy_status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_department_posts_instance_id_position_id_slot` (`instance_id`,`position_id`,`slot`),
  UNIQUE KEY `uq_department_posts_public_id` (`public_id`),
  KEY `ix_department_posts_position_id` (`position_id`),
  CONSTRAINT `fk_department_posts_instance_id` FOREIGN KEY (`instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_department_posts_position_id` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_department_posts_occupancy_status` CHECK (`occupancy_status` IN ('VACANT','FILLED','INTERIM','INACTIVE')),
  CONSTRAINT `ck_department_posts_slot` CHECK (`slot` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `department_posts`');
    }
};
