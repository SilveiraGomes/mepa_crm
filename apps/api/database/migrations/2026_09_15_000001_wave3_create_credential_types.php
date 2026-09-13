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
CREATE TABLE `credential_types` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` TINYINT UNSIGNED NOT NULL,
  `requires_member_number` TINYINT UNSIGNED NOT NULL,
  `default_validity_days` INT UNSIGNED NULL DEFAULT NULL,
  `requires_formal_approval` TINYINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credential_types_code` (`code`),
  CONSTRAINT `ck_credential_types_is_active` CHECK (`is_active` IN (0,1)),
  CONSTRAINT `ck_credential_types_requires_member_number` CHECK (`requires_member_number` IN (0,1)),
  CONSTRAINT `ck_credential_types_requires_formal_approval` CHECK (`requires_formal_approval` IN (0,1)),
  CONSTRAINT `ck_credential_types_validity` CHECK (`default_validity_days` IS NULL OR `default_validity_days` > 0)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `credential_types`');
    }
};
