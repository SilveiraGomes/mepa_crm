<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `budget_lines` -- catalog columns + D32 CHECK amounts >= 0 at business scale.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('budget_lines')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table budget_lines already exists; nothing changed.');
        }
        foreach (['budgets', 'financial_categories'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: budget_lines requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `budget_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `budget_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `requested_amount` DECIMAL(19,4) NOT NULL,
  `approved_amount` DECIMAL(19,4) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_budget_lines_budget_id_category_id` (`budget_id`,`category_id`),
  KEY `ix_budget_lines_category_id` (`category_id`),
  CONSTRAINT `fk_budget_lines_budget_id` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_budget_lines_category_id` FOREIGN KEY (`category_id`) REFERENCES `financial_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_budget_lines_requested_amount` CHECK (`requested_amount` >= 0 AND `requested_amount` = ROUND(`requested_amount`, 2) AND `requested_amount` <= 999999999999.99),
  CONSTRAINT `ck_budget_lines_approved_amount` CHECK (`approved_amount` >= 0 AND `approved_amount` = ROUND(`approved_amount`, 2) AND `approved_amount` <= 999999999999.99)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('budget_lines')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: budget_lines holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `budget_lines`');
    }
};
