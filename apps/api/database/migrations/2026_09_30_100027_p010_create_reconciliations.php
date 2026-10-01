<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1C Finance Core (ADR 0021 D06/D32, 04_database_constraints): `reconciliations` = catalog columns + the D32 delta
// `counted_balance`. One versioned reconciliation of one financial account for one MONTH period: BANK against a bank
// statement (statement_id), CASH against a physical count (counted_balance, no statement) -- exactly one of the two
// (D06). Lifecycle OPEN -> CLOSED ("reconciliation fechada é versão imutável"): CLOSED <=> closed_at + approved_by.
// The matched / partially matched / unmatched state of each line is DERIVED from reconciliation_matches, never stored.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('reconciliations')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table reconciliations already exists; nothing changed.');
        }
        foreach (['accounts', 'bank_statements', 'accounting_periods', 'users'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: reconciliations requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `reconciliations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `statement_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `counted_balance` DECIMAL(19,4) NULL DEFAULT NULL,
  `period_id` BIGINT UNSIGNED NOT NULL,
  `version` INT UNSIGNED NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `closed_at` DATETIME(6) NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reconciliations_public_id` (`public_id`),
  UNIQUE KEY `uq_reconciliations_account_id_period_id_version` (`account_id`,`period_id`,`version`),
  KEY `ix_reconciliations_statement_id` (`statement_id`),
  KEY `ix_reconciliations_period_id` (`period_id`),
  KEY `ix_reconciliations_approved_by` (`approved_by`),
  CONSTRAINT `fk_reconciliations_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_reconciliations_statement_id` FOREIGN KEY (`statement_id`) REFERENCES `bank_statements` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_reconciliations_period_id` FOREIGN KEY (`period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_reconciliations_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_reconciliations_status` CHECK (`status` IN ('OPEN','CLOSED')),
  CONSTRAINT `ck_reconciliations_version` CHECK (`version` >= 1),
  CONSTRAINT `ck_reconciliations_closed` CHECK ((`status` = 'CLOSED') = (`closed_at` IS NOT NULL AND `approved_by` IS NOT NULL)),
  CONSTRAINT `ck_reconciliations_source` CHECK ((`statement_id` IS NULL) <> (`counted_balance` IS NULL)),
  CONSTRAINT `ck_reconciliations_counted_balance` CHECK (`counted_balance` IS NULL OR (`counted_balance` = ROUND(`counted_balance`, 2) AND ABS(`counted_balance`) <= 999999999999.99))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('reconciliations')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: reconciliations holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `reconciliations`');
    }
};
