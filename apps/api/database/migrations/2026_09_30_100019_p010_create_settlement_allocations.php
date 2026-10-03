<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `settlement_allocations` -- catalog columns + D32 CHECK XOR (receivable or payable) and amount > 0.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('settlement_allocations')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table settlement_allocations already exists; nothing changed.');
        }
        foreach (['settlements', 'receivables', 'payables'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: settlement_allocations requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `settlement_allocations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `settlement_id` BIGINT UNSIGNED NOT NULL,
  `receivable_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `payable_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `amount` DECIMAL(19,4) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_settlement_allocations_receivable_id` (`receivable_id`),
  KEY `ix_settlement_allocations_payable_id` (`payable_id`),
  KEY `ix_settlement_allocations_settlement_id` (`settlement_id`),
  CONSTRAINT `fk_settlement_allocations_settlement_id` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_settlement_allocations_receivable_id` FOREIGN KEY (`receivable_id`) REFERENCES `receivables` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_settlement_allocations_payable_id` FOREIGN KEY (`payable_id`) REFERENCES `payables` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_settlement_allocations_xor` CHECK ((`receivable_id` IS NULL) <> (`payable_id` IS NULL)),
  CONSTRAINT `ck_settlement_allocations_amount` CHECK (`amount` > 0 AND `amount` = ROUND(`amount`, 2) AND `amount` <= 999999999999.99)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('settlement_allocations')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: settlement_allocations holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `settlement_allocations`');
    }
};
