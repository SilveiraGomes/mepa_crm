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
CREATE TABLE `credentials` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `person_id` BIGINT UNSIGNED NOT NULL,
  `credential_type_id` BIGINT UNSIGNED NOT NULL,
  `member_number_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `template_id` BIGINT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `token_hash` BINARY(32) NOT NULL,
  `issued_at` DATETIME(6) NOT NULL,
  `expires_at` DATETIME(6) NULL DEFAULT NULL,
  `revoked_at` DATETIME(6) NULL DEFAULT NULL,
  `revoked_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `render_file_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credentials_token_hash` (`token_hash`),
  UNIQUE KEY `uq_credentials_person_id_credential_type_id_version` (`person_id`,`credential_type_id`,`version`),
  UNIQUE KEY `uq_credentials_public_id` (`public_id`),
  KEY `ix_credentials_person_id_status` (`person_id`,`status`),
  KEY `ix_credentials_credential_type_id` (`credential_type_id`),
  KEY `ix_credentials_member_number_id` (`member_number_id`),
  KEY `ix_credentials_template_id` (`template_id`),
  KEY `ix_credentials_revoked_by` (`revoked_by`),
  KEY `ix_credentials_render_file_id` (`render_file_id`),
  CONSTRAINT `fk_credentials_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_credential_type_id` FOREIGN KEY (`credential_type_id`) REFERENCES `credential_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_member_number_id` FOREIGN KEY (`member_number_id`) REFERENCES `member_numbers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_template_id` FOREIGN KEY (`template_id`) REFERENCES `credential_templates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_credentials_render_file_id` FOREIGN KEY (`render_file_id`) REFERENCES `files` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_credentials_version` CHECK (`version` >= 1)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `credentials`');
    }
};
