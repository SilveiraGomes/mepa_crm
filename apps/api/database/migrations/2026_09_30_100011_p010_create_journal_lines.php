<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `journal_lines` -- catalog columns + D10 counterparty_unit_id. Physical guards: exactly one positive side, business scale 2, amount limit; composite FKs force line unit = entry unit and financial account owned by the line unit.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('journal_lines')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table journal_lines already exists; nothing changed.');
        }
        foreach (['journal_entries', 'organizational_units', 'chart_of_accounts', 'accounts', 'funds', 'financial_categories'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: journal_lines requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `journal_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_id` BIGINT UNSIGNED NOT NULL,
  `line_number` INT UNSIGNED NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `ledger_account_id` BIGINT UNSIGNED NOT NULL,
  `financial_account_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `counterparty_unit_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `fund_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `debit` DECIMAL(19,4) NOT NULL,
  `credit` DECIMAL(19,4) NOT NULL,
  `description` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_lines_entry_id_line_number` (`entry_id`,`line_number`),
  KEY `ix_journal_lines_unit_id_ledger_account_id_entry_id` (`unit_id`,`ledger_account_id`,`entry_id`),
  KEY `ix_journal_lines_fund_id_entry_id` (`fund_id`,`entry_id`),
  KEY `ix_journal_lines_financial_account_id_entry_id` (`financial_account_id`,`entry_id`),
  KEY `ix_journal_lines_ledger_account_id` (`ledger_account_id`),
  KEY `ix_journal_lines_category_id` (`category_id`),
  KEY `ix_journal_lines_entry_id_unit_id` (`entry_id`,`unit_id`),
  KEY `ix_journal_lines_unit_id_financial_account_id` (`unit_id`,`financial_account_id`),
  KEY `ix_journal_lines_counterparty_unit_id` (`counterparty_unit_id`),
  CONSTRAINT `fk_journal_lines_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_lines_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_lines_ledger_account_id` FOREIGN KEY (`ledger_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_lines_financial_account_id` FOREIGN KEY (`financial_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_lines_counterparty_unit_id` FOREIGN KEY (`counterparty_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_lines_fund_id` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_lines_category_id` FOREIGN KEY (`category_id`) REFERENCES `financial_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_lines_entry_unit` FOREIGN KEY (`entry_id`,`unit_id`) REFERENCES `journal_entries` (`id`,`unit_id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_lines_unit_financial_account` FOREIGN KEY (`unit_id`,`financial_account_id`) REFERENCES `accounts` (`unit_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_journal_lines_one_side` CHECK ((`debit` > 0 AND `credit` = 0) OR (`credit` > 0 AND `debit` = 0)),
  CONSTRAINT `ck_journal_lines_scale` CHECK (`debit` = ROUND(`debit`, 2) AND `credit` = ROUND(`credit`, 2)),
  CONSTRAINT `ck_journal_lines_limit` CHECK (`debit` <= 999999999999.99 AND `credit` <= 999999999999.99),
  CONSTRAINT `ck_journal_lines_counterparty` CHECK (`counterparty_unit_id` IS NULL OR `counterparty_unit_id` <> `unit_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('journal_lines')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: journal_lines holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `journal_lines`');
    }
};
