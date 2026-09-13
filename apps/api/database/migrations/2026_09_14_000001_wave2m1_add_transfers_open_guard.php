<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.3.2-M1.1 / F-M1R-01: reversible technical enforcement only.
// Durable closed_at belongs to 2026_09_14_000000_wave2m1_add_transfers_closed_at.php.
// Open means closed_at IS NULL, independently of the unresolved D-11 status vocabulary.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (!Schema::hasColumn('transfers', 'closed_at')) {
            throw new RuntimeException('TRANSFER_CLOSURE_HISTORY_REQUIRED: apply the closed_at domain migration first.');
        }
        $invalid = DB::table('transfers')->select('membership_id')->whereNull('closed_at')
            ->groupBy('membership_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($invalid) {
            throw new RuntimeException('TRANSFER_OPEN_GUARD_INVALID_DATA: multiple open transfers for a membership; no data changed.');
        }

        // One MySQL atomic DDL statement: a racing duplicate also fails enforcement without
        // leaving a partially installed generated column. No data is repaired or inferred.
        DB::statement(<<<'SQL'
ALTER TABLE `transfers`
  ADD COLUMN `open_flag` TINYINT UNSIGNED GENERATED ALWAYS AS (IF(`closed_at` IS NULL, 1, NULL)) STORED,
  ADD UNIQUE KEY `uq_transfers_membership_open` (`membership_id`, `open_flag`)
SQL
        );
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE `transfers`
  DROP KEY `uq_transfers_membership_open`,
  DROP COLUMN `open_flag`
SQL
        );
    }
};
