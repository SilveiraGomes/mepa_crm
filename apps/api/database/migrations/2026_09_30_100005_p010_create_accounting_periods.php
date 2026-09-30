<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `accounting_periods` -- catalog columns + D12 code/period_kind. Calendar year, month = posting period; national CLOSED is irreversible (no reopen path).
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('accounting_periods')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table accounting_periods already exists; nothing changed.');
        }
        foreach ([] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: accounting_periods requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `accounting_periods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `starts_on` DATE NOT NULL,
  `ends_on` DATE NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `closed_at` DATETIME(6) NULL DEFAULT NULL,
  `closed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounting_periods_starts_on_ends_on` (`starts_on`,`ends_on`),
  UNIQUE KEY `uq_accounting_periods_code` (`code`),
  KEY `ix_accounting_periods_closed_by` (`closed_by`),
  CONSTRAINT `fk_accounting_periods_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_accounting_periods_period_kind` CHECK (`period_kind` IN ('MONTH','YEAR')),
  CONSTRAINT `ck_accounting_periods_status` CHECK (`status` IN ('OPEN','CLOSED')),
  CONSTRAINT `ck_accounting_periods_range` CHECK (`ends_on` >= `starts_on`),
  CONSTRAINT `ck_accounting_periods_calendar` CHECK ((`period_kind` = 'MONTH' AND DAY(`starts_on`) = 1 AND `ends_on` = LAST_DAY(`starts_on`) AND `code` = DATE_FORMAT(`starts_on`, '%Y-%m')) OR (`period_kind` = 'YEAR' AND MONTH(`starts_on`) = 1 AND DAY(`starts_on`) = 1 AND MONTH(`ends_on`) = 12 AND DAY(`ends_on`) = 31 AND YEAR(`ends_on`) = YEAR(`starts_on`) AND `code` = DATE_FORMAT(`starts_on`, '%Y'))),
  CONSTRAINT `ck_accounting_periods_closed` CHECK ((`status` = 'CLOSED') = (`closed_at` IS NOT NULL AND `closed_by` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('accounting_periods')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: accounting_periods holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `accounting_periods`');
    }
};
