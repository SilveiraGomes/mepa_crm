<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1C Finance Core (ADR 0021 D06/D32): `bank_statement_lines` exactly as catalogued. A line is the bank's fact
// (date, signed amount: > 0 money in, < 0 money out, reference, description, ordinal inside its statement). It is NEVER
// a journal line and never produces one: the ledger fact and the bank fact are distinct identities, related only by
// reconciliation_matches. UNIQUE (statement, line number) keeps a statement's lines idempotent and ordered.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('bank_statement_lines')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table bank_statement_lines already exists; nothing changed.');
        }
        if (!Schema::hasTable('bank_statements')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: bank_statement_lines requires table bank_statements; nothing changed.');
        }

        DB::statement(<<<'SQL'
CREATE TABLE `bank_statement_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `statement_id` BIGINT UNSIGNED NOT NULL,
  `line_number` INT UNSIGNED NOT NULL,
  `external_reference` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `occurred_on` DATE NOT NULL,
  `amount_signed` DECIMAL(19,4) NOT NULL,
  `description` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_statement_lines_statement_id_line_number` (`statement_id`,`line_number`),
  CONSTRAINT `fk_bank_statement_lines_statement_id` FOREIGN KEY (`statement_id`) REFERENCES `bank_statements` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_bank_statement_lines_line_number` CHECK (`line_number` >= 1),
  CONSTRAINT `ck_bank_statement_lines_amount` CHECK (`amount_signed` <> 0 AND `amount_signed` = ROUND(`amount_signed`, 2) AND ABS(`amount_signed`) <= 999999999999.99),
  CONSTRAINT `ck_bank_statement_lines_description` CHECK (CHAR_LENGTH(TRIM(`description`)) > 0)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('bank_statement_lines')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: bank_statement_lines holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `bank_statement_lines`');
    }
};
