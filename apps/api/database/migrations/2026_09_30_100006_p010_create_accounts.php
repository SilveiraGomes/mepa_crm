<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `accounts` -- financial accounts (CASH/BANK) owned by a unit; catalog columns + D06 public_id/opened_on/closed_on. No balance column: the balance is SUM of POSTED journal_lines. uq (unit_id, id) is the target of the owner-coherence composite FKs.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('accounts')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table accounts already exists; nothing changed.');
        }
        foreach (['organizational_units', 'chart_of_accounts', 'currencies'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: accounts requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `ledger_account_id` BIGINT UNSIGNED NOT NULL,
  `currency_id` BIGINT UNSIGNED NOT NULL,
  `code` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `opened_on` DATE NOT NULL,
  `closed_on` DATE NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_public_id` (`public_id`),
  UNIQUE KEY `uq_accounts_unit_id_code` (`unit_id`,`code`),
  UNIQUE KEY `uq_accounts_unit_id_id` (`unit_id`,`id`),
  KEY `ix_accounts_unit_id_status` (`unit_id`,`status`),
  KEY `ix_accounts_ledger_account_id` (`ledger_account_id`),
  KEY `ix_accounts_currency_id` (`currency_id`),
  CONSTRAINT `fk_accounts_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_accounts_ledger_account_id` FOREIGN KEY (`ledger_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_accounts_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_accounts_account_kind` CHECK (`account_kind` IN ('CASH','BANK')),
  CONSTRAINT `ck_accounts_status` CHECK (`status` IN ('OPEN','CLOSED')),
  CONSTRAINT `ck_accounts_closed` CHECK ((`status` = 'CLOSED') = (`closed_on` IS NOT NULL)),
  CONSTRAINT `ck_accounts_dates` CHECK (`closed_on` IS NULL OR `closed_on` >= `opened_on`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('accounts')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: accounts holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `accounts`');
    }
};
