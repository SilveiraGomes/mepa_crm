<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `budgets` -- catalog columns + D13 submitted_by/reviewed_by and the physical guard approved_guard (STORED GENERATED) + UNIQUE: at most one APPROVED version per unit/period/fund; approver != submitter.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('budgets')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table budgets already exists; nothing changed.');
        }
        foreach (['organizational_units', 'accounting_periods', 'funds', 'currencies', 'users'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: budgets requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `budgets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `period_id` BIGINT UNSIGNED NOT NULL,
  `fund_id` BIGINT UNSIGNED NOT NULL,
  `currency_id` BIGINT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `submitted_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `reviewed_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `approved_at` DATETIME(6) NULL DEFAULT NULL,
  `approved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `approved_guard` TINYINT UNSIGNED GENERATED ALWAYS AS (IF(`status` = 'APPROVED', 1, NULL)) STORED,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_budgets_unit_id_period_id_fund_id_version` (`unit_id`,`period_id`,`fund_id`,`version`),
  UNIQUE KEY `uq_budgets_public_id` (`public_id`),
  UNIQUE KEY `uq_budgets_unit_id_period_id_fund_id_approved` (`unit_id`,`period_id`,`fund_id`,`approved_guard`),
  KEY `ix_budgets_period_id` (`period_id`),
  KEY `ix_budgets_fund_id` (`fund_id`),
  KEY `ix_budgets_currency_id` (`currency_id`),
  KEY `ix_budgets_approved_by` (`approved_by`),
  KEY `ix_budgets_submitted_by` (`submitted_by`),
  KEY `ix_budgets_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_budgets_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_budgets_period_id` FOREIGN KEY (`period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_budgets_fund_id` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_budgets_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_budgets_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_budgets_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_budgets_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_budgets_status` CHECK (`status` IN ('DRAFT','SUBMITTED','REVIEWED','APPROVED','SUPERSEDED','CLOSED','CANCELLED')),
  CONSTRAINT `ck_budgets_version` CHECK (`version` >= 1),
  CONSTRAINT `ck_budgets_approved` CHECK (`status` NOT IN ('APPROVED','SUPERSEDED','CLOSED') OR (`approved_at` IS NOT NULL AND `approved_by` IS NOT NULL)),
  CONSTRAINT `ck_budgets_segregation` CHECK (`approved_by` IS NULL OR `submitted_by` IS NULL OR `approved_by` <> `submitted_by`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('budgets')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: budgets holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `budgets`');
    }
};
