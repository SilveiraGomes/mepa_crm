<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `settlements` -- catalog columns + CHECKs (direction, status, amount). Owner unit = the settlement account's unit.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('settlements')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table settlements already exists; nothing changed.');
        }
        foreach (['accounts', 'currencies', 'journal_entries'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: settlements requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `settlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `currency_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(19,4) NOT NULL,
  `settled_at` DATETIME(6) NOT NULL,
  `entry_id` BIGINT UNSIGNED NOT NULL,
  `direction` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settlements_public_id` (`public_id`),
  KEY `ix_settlements_account_id_settled_at` (`account_id`,`settled_at`),
  KEY `ix_settlements_currency_id` (`currency_id`),
  KEY `ix_settlements_entry_id` (`entry_id`),
  CONSTRAINT `fk_settlements_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_settlements_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_settlements_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_settlements_amount` CHECK (`amount` > 0 AND `amount` = ROUND(`amount`, 2) AND `amount` <= 999999999999.99),
  CONSTRAINT `ck_settlements_direction` CHECK (`direction` IN ('RECEIPT','PAYMENT')),
  CONSTRAINT `ck_settlements_status` CHECK (`status` IN ('POSTED','CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('settlements')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: settlements holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `settlements`');
    }
};
