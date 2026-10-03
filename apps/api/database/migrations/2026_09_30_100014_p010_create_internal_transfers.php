<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `internal_transfers` -- catalog columns + D10 origin/destination units (destination_account_id NULL until RECEIVE), cancel_reason; composite FKs force each account to belong to its unit; D-04A.5 purpose (category_id) mandatory from SEND.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('internal_transfers')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table internal_transfers already exists; nothing changed.');
        }
        foreach (['organizational_units', 'accounts', 'currencies', 'funds', 'financial_categories', 'accounting_periods', 'legal_documents', 'workflow_instances'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: internal_transfers requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `internal_transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `origin_unit_id` BIGINT UNSIGNED NOT NULL,
  `destination_unit_id` BIGINT UNSIGNED NOT NULL,
  `origin_account_id` BIGINT UNSIGNED NOT NULL,
  `destination_account_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `currency_id` BIGINT UNSIGNED NOT NULL,
  `fund_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `period_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(19,4) NOT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` DATETIME(6) NULL DEFAULT NULL,
  `received_at` DATETIME(6) NULL DEFAULT NULL,
  `reconciled_at` DATETIME(6) NULL DEFAULT NULL,
  `cancel_reason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `workflow_instance_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_internal_transfers_public_id` (`public_id`),
  KEY `ix_internal_transfers_origin_account_id_status` (`origin_account_id`,`status`),
  KEY `ix_internal_transfers_destination_account_id_status` (`destination_account_id`,`status`),
  KEY `ix_internal_transfers_origin_unit_id_origin_account_id` (`origin_unit_id`,`origin_account_id`),
  KEY `ix_internal_transfers_destination_unit_id_account_id` (`destination_unit_id`,`destination_account_id`),
  KEY `ix_internal_transfers_currency_id` (`currency_id`),
  KEY `ix_internal_transfers_fund_id` (`fund_id`),
  KEY `ix_internal_transfers_category_id` (`category_id`),
  KEY `ix_internal_transfers_period_id` (`period_id`),
  KEY `ix_internal_transfers_document_id` (`document_id`),
  KEY `ix_internal_transfers_workflow_instance_id` (`workflow_instance_id`),
  CONSTRAINT `fk_internal_transfers_origin_unit_id` FOREIGN KEY (`origin_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_destination_unit_id` FOREIGN KEY (`destination_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_origin_account_id` FOREIGN KEY (`origin_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_destination_account_id` FOREIGN KEY (`destination_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_fund_id` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_category_id` FOREIGN KEY (`category_id`) REFERENCES `financial_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_period_id` FOREIGN KEY (`period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_workflow_instance_id` FOREIGN KEY (`workflow_instance_id`) REFERENCES `workflow_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_origin_unit_account` FOREIGN KEY (`origin_unit_id`,`origin_account_id`) REFERENCES `accounts` (`unit_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_internal_transfers_destination_unit_account` FOREIGN KEY (`destination_unit_id`,`destination_account_id`) REFERENCES `accounts` (`unit_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_internal_transfers_units` CHECK (`origin_unit_id` <> `destination_unit_id`),
  CONSTRAINT `ck_internal_transfers_status` CHECK (`status` IN ('DRAFT','SENT','RECEIVED','CANCELLED')),
  CONSTRAINT `ck_internal_transfers_amount` CHECK (`amount` > 0 AND `amount` = ROUND(`amount`, 2) AND `amount` <= 999999999999.99),
  CONSTRAINT `ck_internal_transfers_sent` CHECK (`status` NOT IN ('SENT','RECEIVED') OR (`sent_at` IS NOT NULL AND `category_id` IS NOT NULL)),
  CONSTRAINT `ck_internal_transfers_received` CHECK ((`status` = 'RECEIVED') = (`received_at` IS NOT NULL) AND (`status` = 'RECEIVED') = (`destination_account_id` IS NOT NULL) AND (`status` = 'RECEIVED') = (`reconciled_at` IS NOT NULL)),
  CONSTRAINT `ck_internal_transfers_received_after_sent` CHECK (`received_at` IS NULL OR `received_at` >= `sent_at`),
  CONSTRAINT `ck_internal_transfers_cancelled` CHECK (`status` <> 'CANCELLED' OR (`cancel_reason` IS NOT NULL AND CHAR_LENGTH(TRIM(`cancel_reason`)) > 0))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('internal_transfers')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: internal_transfers holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `internal_transfers`');
    }
};
