<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1C Finance Core (ADR 0021 D30 C7, 04_database_constraints): `reconciliation_matches` exactly as catalogued.
// A match allocates `matched_amount` (> 0, business scale) of ONE bank statement line to ONE POSTED journal line of the
// same BANK account: partial and many-to-many correspondences are allowed by design, and the service enforces, under
// row locks of both lines, Σ matched_amount <= |statement line amount| and Σ matched_amount <= journal line amount
// across every reconciliation (no value is ever reconciled twice). UNIQUE (reconciliation, statement line, journal
// line) keeps one allocation per pair and reconciliation.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('reconciliation_matches')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table reconciliation_matches already exists; nothing changed.');
        }
        foreach (['reconciliations', 'bank_statement_lines', 'journal_lines'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: reconciliation_matches requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `reconciliation_matches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reconciliation_id` BIGINT UNSIGNED NOT NULL,
  `statement_line_id` BIGINT UNSIGNED NOT NULL,
  `journal_line_id` BIGINT UNSIGNED NOT NULL,
  `matched_amount` DECIMAL(19,4) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recon_statement_journal_match` (`reconciliation_id`,`statement_line_id`,`journal_line_id`),
  KEY `ix_reconciliation_matches_statement_line_id` (`statement_line_id`),
  KEY `ix_reconciliation_matches_journal_line_id` (`journal_line_id`),
  CONSTRAINT `fk_reconciliation_matches_reconciliation_id` FOREIGN KEY (`reconciliation_id`) REFERENCES `reconciliations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_reconciliation_matches_statement_line_id` FOREIGN KEY (`statement_line_id`) REFERENCES `bank_statement_lines` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_reconciliation_matches_journal_line_id` FOREIGN KEY (`journal_line_id`) REFERENCES `journal_lines` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_reconciliation_matches_amount` CHECK (`matched_amount` > 0 AND `matched_amount` = ROUND(`matched_amount`, 2) AND `matched_amount` <= 999999999999.99)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('reconciliation_matches')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: reconciliation_matches holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `reconciliation_matches`');
    }
};
