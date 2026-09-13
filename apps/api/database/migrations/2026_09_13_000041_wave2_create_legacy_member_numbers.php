<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: legacy_member_numbers; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `legacy_member_numbers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `membership_id` BIGINT UNSIGNED NOT NULL,
  `source_system` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_number` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_number` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `import_record_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_legacy_member_numbers_source_system_normalized_number` (`source_system`,`normalized_number`),
  KEY `ix_legacy_member_numbers_membership_id` (`membership_id`),
  KEY `ix_legacy_member_numbers_import_record_id` (`import_record_id`),
  CONSTRAINT `fk_legacy_member_numbers_membership_id` FOREIGN KEY (`membership_id`) REFERENCES `memberships` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_legacy_member_numbers_import_record_id` FOREIGN KEY (`import_record_id`) REFERENCES `import_records` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `legacy_member_numbers`');
    }
};
