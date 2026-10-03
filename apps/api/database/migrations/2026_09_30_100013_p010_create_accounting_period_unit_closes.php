<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `accounting_period_unit_closes` -- NEW table (ADR 0021 D12, not in the catalog): independent month close per unit; reopen needs a reason and a different user (physical segregation CHECK).
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('accounting_period_unit_closes')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table accounting_period_unit_closes already exists; nothing changed.');
        }
        foreach (['accounting_periods', 'organizational_units', 'users'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: accounting_period_unit_closes requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `accounting_period_unit_closes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `closed_at` DATETIME(6) NOT NULL,
  `closed_by` BIGINT UNSIGNED NOT NULL,
  `reopened_at` DATETIME(6) NULL DEFAULT NULL,
  `reopened_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounting_period_unit_closes_period_id_unit_id` (`period_id`,`unit_id`),
  KEY `ix_accounting_period_unit_closes_unit_id` (`unit_id`),
  KEY `ix_accounting_period_unit_closes_closed_by` (`closed_by`),
  KEY `ix_accounting_period_unit_closes_reopened_by` (`reopened_by`),
  CONSTRAINT `fk_accounting_period_unit_closes_period_id` FOREIGN KEY (`period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_accounting_period_unit_closes_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_accounting_period_unit_closes_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_accounting_period_unit_closes_reopened_by` FOREIGN KEY (`reopened_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_accounting_period_unit_closes_status` CHECK (`status` IN ('CLOSED','REOPENED')),
  CONSTRAINT `ck_accounting_period_unit_closes_reopen_pair` CHECK ((`reopened_at` IS NULL) = (`reopened_by` IS NULL)),
  CONSTRAINT `ck_accounting_period_unit_closes_reopened` CHECK (`status` <> 'REOPENED' OR (`reopened_at` IS NOT NULL AND `reason` IS NOT NULL AND CHAR_LENGTH(TRIM(`reason`)) > 0)),
  CONSTRAINT `ck_accounting_period_unit_closes_segregation` CHECK (`status` <> 'REOPENED' OR `reopened_by` <> `closed_by`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('accounting_period_unit_closes')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: accounting_period_unit_closes holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `accounting_period_unit_closes`');
    }
};
