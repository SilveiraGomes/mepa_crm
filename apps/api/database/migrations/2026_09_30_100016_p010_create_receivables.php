<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `receivables` -- catalog columns + CHECKs (amount, states, recognition). obligation_id keeps column + index, its FK is DEFERRED (obligations is not created in V1, D05) and it must stay NULL in V1.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('receivables')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table receivables already exists; nothing changed.');
        }
        foreach (['financial_parties', 'organizational_units', 'currencies', 'journal_entries', 'legal_documents'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: receivables requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `receivables` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `party_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `obligation_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `currency_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(19,4) NOT NULL,
  `due_on` DATE NOT NULL,
  `recognition_entry_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receivables_public_id` (`public_id`),
  KEY `ix_receivables_unit_id_status_due_on` (`unit_id`,`status`,`due_on`),
  KEY `ix_receivables_party_id` (`party_id`),
  KEY `ix_receivables_obligation_id` (`obligation_id`),
  KEY `ix_receivables_currency_id` (`currency_id`),
  KEY `ix_receivables_recognition_entry_id` (`recognition_entry_id`),
  KEY `ix_receivables_document_id` (`document_id`),
  CONSTRAINT `fk_receivables_party_id` FOREIGN KEY (`party_id`) REFERENCES `financial_parties` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_receivables_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_receivables_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_receivables_recognition_entry_id` FOREIGN KEY (`recognition_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_receivables_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_receivables_amount` CHECK (`amount` > 0 AND `amount` = ROUND(`amount`, 2) AND `amount` <= 999999999999.99),
  CONSTRAINT `ck_receivables_status` CHECK (`status` IN ('PENDING','RECOGNIZED','SETTLED','CANCELLED')),
  CONSTRAINT `ck_receivables_recognized` CHECK (`status` NOT IN ('RECOGNIZED','SETTLED') OR `recognition_entry_id` IS NOT NULL),
  CONSTRAINT `ck_receivables_obligation_v1` CHECK (`obligation_id` IS NULL)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('receivables')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: receivables holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `receivables`');
    }
};
