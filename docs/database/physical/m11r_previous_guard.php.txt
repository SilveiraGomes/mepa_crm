<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.3.2-M1: fixes F-W2F-01 (independent reconciliation finding). Two concurrent, incompatible
// PENDING transfers for the same membership could previously both persist - nothing tied
// membership_id to "at most one transfer still in flight".
//
// `transfers.status` has no institutionally fixed vocabulary yet (D-11 pending: dictionary
// description is the generic placeholder "status", example "DRAFT" - see 04_database_constraints.md
// "catálogo exacto por tabela... antes de migrations"). So the invariant here is deliberately
// status-string-agnostic: `closed_at` is set the moment a transfer stops being "in flight", for
// ANY reason (effectuated via `effective_at`, or abandoned/cancelled without ever taking effect -
// `effective_at` stays NULL forever in that case, which is what makes it distinct from
// `effective_at IS NULL` alone). `open_flag` is a generated column that is 1 only while
// `closed_at IS NULL`, and NULL once closed - MySQL treats NULL as distinct for UNIQUE indexes
// (multiple NULLs allowed), so `UNIQUE(membership_id, open_flag)` is the MySQL-native equivalent
// of a Postgres partial unique index: at most one *open* transfer per membership, physically
// enforced, while any number of *closed* (historical) transfers coexist freely. History is never
// deleted or rewritten.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }

        DB::statement(<<<'SQL'
ALTER TABLE `transfers`
  ADD COLUMN `closed_at` DATETIME(6) NULL DEFAULT NULL AFTER `effective_at`,
  ADD COLUMN `open_flag` TINYINT UNSIGNED GENERATED ALWAYS AS (IF(`closed_at` IS NULL, 1, NULL)) STORED
SQL
        );
        DB::statement('ALTER TABLE `transfers` ADD UNIQUE KEY `uq_transfers_membership_open` (`membership_id`, `open_flag`)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `transfers` DROP KEY `uq_transfers_membership_open`');
        DB::statement('ALTER TABLE `transfers` DROP COLUMN `open_flag`');
        DB::statement('ALTER TABLE `transfers` DROP COLUMN `closed_at`');
    }
};
