<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `financial_documents` -- exactly the catalog design (D14: Files/Documents L7 are referenced, never duplicated).
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('financial_documents')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table financial_documents already exists; nothing changed.');
        }
        foreach (['journal_entries', 'legal_documents'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: financial_documents requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `financial_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entry_id` BIGINT UNSIGNED NOT NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_financial_documents_entry_id_document_id` (`entry_id`,`document_id`),
  KEY `ix_financial_documents_document_id` (`document_id`),
  CONSTRAINT `fk_financial_documents_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_financial_documents_document_id` FOREIGN KEY (`document_id`) REFERENCES `legal_documents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('financial_documents')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: financial_documents holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `financial_documents`');
    }
};
