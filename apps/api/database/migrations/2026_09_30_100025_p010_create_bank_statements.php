<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1C Finance Core (ADR 0021 D06/D14/D32): `bank_statements` exactly as catalogued. An imported bank statement is
// an EXTERNAL fact of one BANK financial account (the owner unit is the account's unit; no second owner column): the
// period range, the opening/closing balances printed by the bank (signed: an overdrawn bank balance is a fact too), the
// statement file (file_id NOT NULL, D14) and its provenance hash (UNIQUE per account: the same source is never imported
// twice). There is no update path: header and lines are written once, in one transaction (immutable provenance).
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('bank_statements')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table bank_statements already exists; nothing changed.');
        }
        foreach (['accounts', 'files'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: bank_statements requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `bank_statements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `starts_on` DATE NOT NULL,
  `ends_on` DATE NOT NULL,
  `opening_balance` DECIMAL(19,4) NOT NULL,
  `closing_balance` DECIMAL(19,4) NOT NULL,
  `file_id` BIGINT UNSIGNED NOT NULL,
  `source_hash` BINARY(32) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_statements_public_id` (`public_id`),
  UNIQUE KEY `uq_bank_statements_account_id_source_hash` (`account_id`,`source_hash`),
  KEY `ix_bank_statements_file_id` (`file_id`),
  CONSTRAINT `fk_bank_statements_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_bank_statements_file_id` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_bank_statements_range` CHECK (`ends_on` >= `starts_on`),
  CONSTRAINT `ck_bank_statements_balances` CHECK (`opening_balance` = ROUND(`opening_balance`, 2) AND `closing_balance` = ROUND(`closing_balance`, 2) AND ABS(`opening_balance`) <= 999999999999.99 AND ABS(`closing_balance`) <= 999999999999.99)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('bank_statements')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: bank_statements holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `bank_statements`');
    }
};
