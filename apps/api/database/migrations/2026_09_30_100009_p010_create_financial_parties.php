<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `financial_parties` -- catalog columns + D32 CHECK XOR by party_kind.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('financial_parties')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table financial_parties already exists; nothing changed.');
        }
        foreach (['people', 'households', 'organizational_units', 'department_instances'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: financial_parties requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `financial_parties` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `person_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `household_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `unit_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `department_instance_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `external_name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_financial_parties_person_id` (`person_id`),
  KEY `ix_financial_parties_unit_id` (`unit_id`),
  KEY `ix_financial_parties_household_id` (`household_id`),
  KEY `ix_financial_parties_department_instance_id` (`department_instance_id`),
  CONSTRAINT `fk_financial_parties_person_id` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_financial_parties_household_id` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_financial_parties_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_financial_parties_department_instance_id` FOREIGN KEY (`department_instance_id`) REFERENCES `department_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_financial_parties_party_kind` CHECK (`party_kind` IN ('PERSON','HOUSEHOLD','UNIT','DEPARTMENT_INSTANCE','EXTERNAL')),
  CONSTRAINT `ck_financial_parties_xor` CHECK ((`party_kind` = 'PERSON' AND `person_id` IS NOT NULL AND `household_id` IS NULL AND `unit_id` IS NULL AND `department_instance_id` IS NULL AND `external_name` IS NULL) OR (`party_kind` = 'HOUSEHOLD' AND `person_id` IS NULL AND `household_id` IS NOT NULL AND `unit_id` IS NULL AND `department_instance_id` IS NULL AND `external_name` IS NULL) OR (`party_kind` = 'UNIT' AND `person_id` IS NULL AND `household_id` IS NULL AND `unit_id` IS NOT NULL AND `department_instance_id` IS NULL AND `external_name` IS NULL) OR (`party_kind` = 'DEPARTMENT_INSTANCE' AND `person_id` IS NULL AND `household_id` IS NULL AND `unit_id` IS NULL AND `department_instance_id` IS NOT NULL AND `external_name` IS NULL) OR (`party_kind` = 'EXTERNAL' AND `person_id` IS NULL AND `household_id` IS NULL AND `unit_id` IS NULL AND `department_instance_id` IS NULL AND `external_name` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('financial_parties')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: financial_parties holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `financial_parties`');
    }
};
