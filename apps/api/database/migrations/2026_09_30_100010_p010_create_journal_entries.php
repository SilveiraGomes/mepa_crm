<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `journal_entries` -- catalog columns + D07 unit_id (single owner unit), entry_kind, submitted_by/at, reason; D11 UNIQUE reversal_of_id (replaces the catalog index). uq (id, unit_id) is the target of the line-unit composite FK. POSTED is immutable (service has no update path; validator).
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('journal_entries')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table journal_entries already exists; nothing changed.');
        }
        foreach (['organizational_units', 'accounting_periods', 'currencies', 'users', 'legal_documents', 'idempotency_requests'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: journal_entries requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `journal_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `period_id` BIGINT UNSIGNED NOT NULL,
  `currency_id` BIGINT UNSIGNED NOT NULL,
  `entry_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_date` DATE NOT NULL,
  `reference` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `submitted_at` DATETIME(6) NULL DEFAULT NULL,
  `submitted_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `posted_at` DATETIME(6) NULL DEFAULT NULL,
  `posted_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `reversal_of_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `idempotency_request_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_entries_public_id` (`public_id`),
  UNIQUE KEY `uq_journal_entries_reference` (`reference`),
  UNIQUE KEY `uq_journal_entries_idempotency_request_id` (`idempotency_request_id`),
  UNIQUE KEY `uq_journal_entries_reversal_of_id` (`reversal_of_id`),
  UNIQUE KEY `uq_journal_entries_id_unit_id` (`id`,`unit_id`),
  KEY `ix_journal_entries_period_id_status_entry_date_id` (`period_id`,`status`,`entry_date`,`id`),
  KEY `ix_journal_entries_unit_id_period_id_status` (`unit_id`,`period_id`,`status`),
  KEY `ix_journal_entries_currency_id` (`currency_id`),
  KEY `ix_journal_entries_submitted_by` (`submitted_by`),
  KEY `ix_journal_entries_posted_by` (`posted_by`),
  KEY `ix_journal_entries_created_by` (`created_by`),
  KEY `ix_journal_entries_document_id` (`document_id`),
  CONSTRAINT `fk_journal_entries_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_entries_period_id` FOREIGN KEY (`period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_entries_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_entries_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_entries_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_entries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_entries_reversal_of_id` FOREIGN KEY (`reversal_of_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_entries_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_journal_entries_idempotency_request_id` FOREIGN KEY (`idempotency_request_id`) REFERENCES `idempotency_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_journal_entries_status` CHECK (`status` IN ('DRAFT','SUBMITTED','POSTED','DISCARDED')),
  CONSTRAINT `ck_journal_entries_entry_kind` CHECK (`entry_kind` IN ('REVENUE','EXPENSE','ACCOUNT_TRANSFER','OPENING_BALANCE','ADJUSTMENT','REVERSAL','CONTRIBUTION','PAYABLE_RECOGNITION','RECEIVABLE_RECOGNITION','SETTLEMENT','TRANSFER_SEND','TRANSFER_RECEIVE','TRANSFER_REVERSE_SEND','PAYROLL_ACCRUAL','PAYROLL_PAYMENT','PAYROLL_REVERSAL','LIABILITY_PAYMENT')),
  CONSTRAINT `ck_journal_entries_posted` CHECK ((`status` = 'POSTED') = (`posted_at` IS NOT NULL AND `posted_by` IS NOT NULL)),
  CONSTRAINT `ck_journal_entries_submitted` CHECK ((`submitted_at` IS NULL) = (`submitted_by` IS NULL)),
  CONSTRAINT `ck_journal_entries_reversal` CHECK ((`entry_kind` IN ('REVERSAL','TRANSFER_REVERSE_SEND','PAYROLL_REVERSAL')) = (`reversal_of_id` IS NOT NULL)),
  CONSTRAINT `ck_journal_entries_reason` CHECK (`entry_kind` NOT IN ('REVERSAL','TRANSFER_REVERSE_SEND','PAYROLL_REVERSAL','ADJUSTMENT') OR (`reason` IS NOT NULL AND CHAR_LENGTH(TRIM(`reason`)) > 0))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('journal_entries')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: journal_entries holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `journal_entries`');
    }
};
