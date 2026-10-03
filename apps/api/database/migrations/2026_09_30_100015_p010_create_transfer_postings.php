<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.10-F1A Finance Core (ADR 0021 D32/D33 + D-04A): `transfer_postings` -- catalog columns + D10 CHECK posting_stage (SEND, RECEIVE, REVERSE_SEND); UNIQUE (transfer, stage) = one SEND/RECEIVE per transfer.
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (Schema::hasTable('transfer_postings')) {
            throw new RuntimeException('P010_PRECONDITION_FAILED: table transfer_postings already exists; nothing changed.');
        }
        foreach (['internal_transfers', 'journal_entries'] as $required) {
            if (!Schema::hasTable($required)) {
                throw new RuntimeException('P010_PRECONDITION_FAILED: transfer_postings requires table ' . $required . '; nothing changed.');
            }
        }

        DB::statement(<<<'SQL'
CREATE TABLE `transfer_postings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfer_id` BIGINT UNSIGNED NOT NULL,
  `posting_stage` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transfer_postings_transfer_id_posting_stage` (`transfer_id`,`posting_stage`),
  UNIQUE KEY `uq_transfer_postings_entry_id` (`entry_id`),
  CONSTRAINT `fk_transfer_postings_transfer_id` FOREIGN KEY (`transfer_id`) REFERENCES `internal_transfers` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_transfer_postings_entry_id` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `ck_transfer_postings_posting_stage` CHECK (`posting_stage` IN ('SEND','RECEIVE','REVERSE_SEND'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }

    public function down(): void
    {
        if (DB::table('transfer_postings')->exists()) {
            throw new RuntimeException('P010_ROLLBACK_REFUSED: transfer_postings holds rows; rollback never deletes financial data.');
        }
        DB::statement('DROP TABLE `transfer_postings`');
    }
};
