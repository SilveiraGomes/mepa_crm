<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.1: person_contacts; source: approved model_catalog / dictionary.
// MySQL-specific DDL preserves binary types, microseconds and named CHECKs.
// Run the existing capability preflight before execution.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 1 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `person_contacts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `person_id` BIGINT UNSIGNED NOT NULL,
  `contact_type_id` BIGINT UNSIGNED NOT NULL,
  `value_ciphertext` VARBINARY(2048) NOT NULL,
  `value_blind_index` BINARY(32) NOT NULL,
  `key_version` SMALLINT UNSIGNED NOT NULL,
  `is_primary` TINYINT UNSIGNED NOT NULL,
  `verified_at` DATETIME(6) NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_person_contacts_person_id_contact_type_id` (`person_id`,`contact_type_id`),
  KEY `ix_person_contacts_value_blind_index` (`value_blind_index`),
  KEY `ix_person_contacts_contact_type_id` (`contact_type_id`),
  CONSTRAINT `fk_person_contacts_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_person_contacts_contact_type_id` FOREIGN KEY (`contact_type_id`) REFERENCES `contact_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_person_contacts_is_primary` CHECK (`is_primary` IN (0,1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE `person_contacts`');
    }
};
