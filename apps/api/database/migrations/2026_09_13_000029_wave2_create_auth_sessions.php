<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2: auth_sessions; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `auth_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` BINARY(32) NOT NULL,
  `expires_at` DATETIME(6) NOT NULL,
  `revoked_at` DATETIME(6) NULL DEFAULT NULL,
  `ip_hash` BINARY(32) NULL DEFAULT NULL,
  `device_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_sessions_token_hash` (`token_hash`),
  KEY `ix_auth_sessions_user_id_expires_at` (`user_id`,`expires_at`),
  KEY `ix_auth_sessions_device_id` (`device_id`),
  CONSTRAINT `fk_auth_sessions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_auth_sessions_device_id` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `auth_sessions`');
    }
};
