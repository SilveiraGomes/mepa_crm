<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: adapts the Wave 1 Laravel scaffolding 'users' table to the approved model_catalog
// shape. Deliberate ALTER, never DROP+CREATE.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
ALTER TABLE `users`
  DROP COLUMN `name`,
  DROP COLUMN `email`,
  DROP COLUMN `email_verified_at`,
  DROP COLUMN `password`,
  DROP COLUMN `remember_token`,
  DROP COLUMN `updated_at`,
  ADD COLUMN `person_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `id`,
  ADD COLUMN `account_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER `person_id`,
  ADD COLUMN `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER `account_kind`,
  ADD COLUMN `login` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER `public_id`,
  ADD COLUMN `password_hash` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER `login`,
  ADD COLUMN `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER `password_hash`,
  ADD COLUMN `mfa_required` TINYINT UNSIGNED NOT NULL AFTER `status`,
  ADD COLUMN `archived_at` DATETIME(6) NULL DEFAULT NULL AFTER `mfa_required`,
  MODIFY COLUMN `created_at` DATETIME(6) NOT NULL,
  ADD COLUMN `lock_version` INT UNSIGNED NOT NULL DEFAULT 0
SQL
        );
        DB::statement('ALTER TABLE `users` ADD UNIQUE KEY `uq_users_public_id` (`public_id`)');
        DB::statement('ALTER TABLE `users` ADD UNIQUE KEY `uq_users_login` (`login`)');
        DB::statement('ALTER TABLE `users` ADD UNIQUE KEY `uq_users_person_id` (`person_id`)');
        DB::statement('ALTER TABLE `users` ADD CONSTRAINT `fk_users_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT');
        DB::statement("ALTER TABLE `users` ADD CONSTRAINT `ck_users_account_kind` CHECK (`account_kind` IN ('HUMAN','SERVICE'))");
        DB::statement('ALTER TABLE `users` ADD CONSTRAINT `ck_users_mfa_required` CHECK (`mfa_required` IN (0,1))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `users` DROP CONSTRAINT `ck_users_mfa_required`');
        DB::statement('ALTER TABLE `users` DROP CONSTRAINT `ck_users_account_kind`');
        DB::statement('ALTER TABLE `users` DROP FOREIGN KEY `fk_users_person_id`');
        DB::statement('ALTER TABLE `users` DROP KEY `uq_users_person_id`');
        DB::statement('ALTER TABLE `users` DROP KEY `uq_users_login`');
        DB::statement('ALTER TABLE `users` DROP KEY `uq_users_public_id`');
        DB::statement(<<<'SQL'
ALTER TABLE `users`
  DROP COLUMN `lock_version`,
  MODIFY COLUMN `created_at` TIMESTAMP NULL DEFAULT NULL,
  DROP COLUMN `archived_at`,
  DROP COLUMN `mfa_required`,
  DROP COLUMN `status`,
  DROP COLUMN `password_hash`,
  DROP COLUMN `login`,
  DROP COLUMN `public_id`,
  DROP COLUMN `account_kind`,
  DROP COLUMN `person_id`,
  ADD COLUMN `name` VARCHAR(255) NOT NULL,
  ADD COLUMN `email` VARCHAR(255) NOT NULL,
  ADD COLUMN `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN `password` VARCHAR(255) NOT NULL,
  ADD COLUMN `remember_token` VARCHAR(100) NULL DEFAULT NULL,
  ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL,
  ADD UNIQUE KEY `users_email_unique` (`email`)
SQL
        );
    }
};
