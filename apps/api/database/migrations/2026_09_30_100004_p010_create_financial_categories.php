<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `financial_categories` -- catalog columns + D09 economic_nature. A rubric is postable iff it has a ledger account; group nodes (16.x) carry neither.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('financial_categories')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table financial_categories already exists; nothing changed.');
        }
        foreach (['chart_of_accounts'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: financial_categories requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `financial_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `code` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ledger_account_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `economic_nature` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `classification_status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_financial_categories_code` (`code`),
  KEY `ix_financial_categories_parent_id` (`parent_id`),
  KEY `ix_financial_categories_ledger_account_id` (`ledger_account_id`),
  CONSTRAINT `fk_financial_categories_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `financial_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_financial_categories_ledger_account_id` FOREIGN KEY (`ledger_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_financial_categories_economic_nature` CHECK (`economic_nature` IS NULL OR `economic_nature` IN ('OPERATING_REVENUE','COST_OF_SALES','ADMINISTRATIVE_EXPENSE','FINANCIAL_EXPENSE','PERSONNEL_EXPENSE','MATERIALS_EXPENSE','INVESTMENT','NON_OPERATING_INCOME','NON_OPERATING_EXPENSE','INTERNAL_TRANSFER','BALANCE_SHEET')),
  CONSTRAINT `ck_financial_categories_postable_nature` CHECK ((`ledger_account_id` IS NULL) = (`economic_nature` IS NULL)),
  CONSTRAINT `ck_financial_categories_classification_status` CHECK (`classification_status` IN ('APPROVED','PENDING_REVIEW')),
  CONSTRAINT `ck_financial_categories_status` CHECK (`status` IN ('ACTIVE','INACTIVE'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('financial_categories')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: financial_categories holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `financial_categories`');
    }
};
