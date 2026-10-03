<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1B Finance Core (ADR 0021 D04/D09/D32): `contributions` -- catalog columns + D32 valuation_status, valued_by,
// valuation_approved_by, valuation_document_id. A contribution is ALWAYS an external origin of funds received by
// receiving_unit_id (an internal MEPA origin is an internal_transfer, never a contribution: D09). CHECKs: kind and
// identification vocabularies, IDENTIFIED <=> party, MONETARY <=> amount (posted: journal_entry_id) and IN_KIND <=>
// description + valuation state, valuation coherence, money at business scale.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('contributions')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table contributions already exists; nothing changed.');
        }
        foreach (['organizational_units', 'financial_parties', 'financial_categories', 'funds', 'currencies', 'journal_entries', 'legal_documents', 'users'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: contributions requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `contributions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `receiving_unit_id` BIGINT UNSIGNED NOT NULL,
  `party_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `fund_id` BIGINT UNSIGNED NOT NULL,
  `currency_id` BIGINT UNSIGNED NOT NULL,
  `contribution_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `identification_kind` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` DECIMAL(19,4) NULL DEFAULT NULL,
  `valuation_amount` DECIMAL(19,4) NULL DEFAULT NULL,
  `in_kind_description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `valuation_status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `valued_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `valuation_approved_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `valuation_document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `received_at` DATETIME(6) NOT NULL,
  `journal_entry_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `document_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contributions_public_id` (`public_id`),
  KEY `ix_contributions_receiving_unit_id_received_at` (`receiving_unit_id`,`received_at`),
  KEY `ix_contributions_party_id_received_at` (`party_id`,`received_at`),
  KEY `ix_contributions_category_id` (`category_id`),
  KEY `ix_contributions_fund_id` (`fund_id`),
  KEY `ix_contributions_currency_id` (`currency_id`),
  KEY `ix_contributions_journal_entry_id` (`journal_entry_id`),
  KEY `ix_contributions_document_id` (`document_id`),
  KEY `ix_contributions_valued_by` (`valued_by`),
  KEY `ix_contributions_valuation_approved_by` (`valuation_approved_by`),
  KEY `ix_contributions_valuation_document_id` (`valuation_document_id`),
  CONSTRAINT `fk_contributions_receiving_unit_id` FOREIGN KEY (`receiving_unit_id`) REFERENCES `organizational_units` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contributions_party_id` FOREIGN KEY (`party_id`) REFERENCES `financial_parties` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contributions_category_id` FOREIGN KEY (`category_id`) REFERENCES `financial_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contributions_fund_id` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contributions_currency_id` FOREIGN KEY (`currency_id`) REFERENCES `currencies` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contributions_journal_entry_id` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contributions_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contributions_valued_by` FOREIGN KEY (`valued_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contributions_valuation_approved_by` FOREIGN KEY (`valuation_approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_contributions_valuation_document_id` FOREIGN KEY (`valuation_document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_contributions_contribution_kind` CHECK (`contribution_kind` IN ('MONETARY','IN_KIND')),
  CONSTRAINT `ck_contributions_identification_kind` CHECK (`identification_kind` IN ('IDENTIFIED','ANONYMOUS','AGGREGATED')),
  CONSTRAINT `ck_contributions_identified_party` CHECK ((`identification_kind` = 'IDENTIFIED') = (`party_id` IS NOT NULL)),
  CONSTRAINT `ck_contributions_status` CHECK (`status` IN ('RECORDED','CANCELLED')),
  CONSTRAINT `ck_contributions_kind_xor` CHECK ((`contribution_kind` = 'MONETARY' AND `amount` IS NOT NULL AND `journal_entry_id` IS NOT NULL AND `valuation_amount` IS NULL AND `in_kind_description` IS NULL AND `valuation_status` IS NULL AND `valued_by` IS NULL AND `valuation_approved_by` IS NULL AND `valuation_document_id` IS NULL) OR (`contribution_kind` = 'IN_KIND' AND `amount` IS NULL AND `in_kind_description` IS NOT NULL AND CHAR_LENGTH(TRIM(`in_kind_description`)) > 0 AND `valuation_status` IS NOT NULL)),
  CONSTRAINT `ck_contributions_valuation_status` CHECK (`valuation_status` IS NULL OR `valuation_status` IN ('UNVALUED','VALUED','APPROVED')),
  CONSTRAINT `ck_contributions_valuation` CHECK (`valuation_status` IS NULL OR (`valuation_status` = 'UNVALUED' AND `valuation_amount` IS NULL AND `valued_by` IS NULL AND `valuation_approved_by` IS NULL AND `journal_entry_id` IS NULL) OR (`valuation_status` = 'VALUED' AND `valuation_amount` IS NOT NULL AND `valued_by` IS NOT NULL AND `valuation_document_id` IS NOT NULL AND `valuation_approved_by` IS NULL AND `journal_entry_id` IS NULL) OR (`valuation_status` = 'APPROVED' AND `valuation_amount` IS NOT NULL AND `valued_by` IS NOT NULL AND `valuation_document_id` IS NOT NULL AND `valuation_approved_by` IS NOT NULL AND `journal_entry_id` IS NOT NULL)),
  CONSTRAINT `ck_contributions_amount` CHECK (`amount` IS NULL OR (`amount` > 0 AND `amount` = ROUND(`amount`, 2) AND `amount` <= 999999999999.99)),
  CONSTRAINT `ck_contributions_valuation_amount` CHECK (`valuation_amount` IS NULL OR (`valuation_amount` > 0 AND `valuation_amount` = ROUND(`valuation_amount`, 2) AND `valuation_amount` <= 999999999999.99))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('contributions')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: contributions holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `contributions`');
    }
};
