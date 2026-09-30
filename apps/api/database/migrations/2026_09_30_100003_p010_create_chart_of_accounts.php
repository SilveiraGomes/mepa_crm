<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `chart_of_accounts` -- catalog columns + D08 system_role (structural chart) + D-04A class INTERUNIT_CONTROL.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('chart_of_accounts')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table chart_of_accounts already exists; nothing changed.');
        }
        foreach ([] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: chart_of_accounts requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `chart_of_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `code` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normal_side` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `system_role` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `postable` TINYINT UNSIGNED NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chart_of_accounts_code` (`code`),
  UNIQUE KEY `uq_chart_of_accounts_system_role` (`system_role`),
  KEY `ix_chart_of_accounts_parent_id` (`parent_id`),
  CONSTRAINT `fk_chart_of_accounts_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_chart_of_accounts_account_kind` CHECK (`account_kind` IN ('ASSET','LIABILITY','EQUITY','INCOME','EXPENSE','INTERUNIT_CONTROL')),
  CONSTRAINT `ck_chart_of_accounts_normal_side` CHECK (`normal_side` IN ('DEBIT','CREDIT')),
  CONSTRAINT `ck_chart_of_accounts_kind_side` CHECK ((`account_kind` IN ('ASSET','EXPENSE') AND `normal_side` = 'DEBIT') OR (`account_kind` IN ('LIABILITY','EQUITY','INCOME') AND `normal_side` = 'CREDIT') OR `account_kind` = 'INTERUNIT_CONTROL'),
  CONSTRAINT `ck_chart_of_accounts_postable` CHECK (`postable` IN (0,1)),
  CONSTRAINT `ck_chart_of_accounts_status` CHECK (`status` IN ('ACTIVE','INACTIVE'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('chart_of_accounts')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: chart_of_accounts holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `chart_of_accounts`');
    }
};
