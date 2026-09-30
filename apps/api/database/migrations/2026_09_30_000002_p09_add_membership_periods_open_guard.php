<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.9 (ADR 0020, "Migrations necessárias" 1): physical guard of the D03 invariant "exactly one open period per
// membership" (ends_at IS NULL). Same precedent as F-W2F-01 / uq_transfers_membership_open. The pre-check aborts
// WITHOUT changing any row when a membership already has more than one open period: incompatible data is reported,
// never repaired, inferred or closed silently.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.9 requires the qualified MySQL driver; SQLite is not supported.');
        }
        $invalid = DB::table('membership_periods')->select('membership_id')->whereNull('ends_at')
            ->groupBy('membership_id')->havingRaw('COUNT(*) > 1')->count();
        if ($invalid > 0) {
            throw new RuntimeException('MEMBERSHIP_OPEN_PERIOD_GUARD_INVALID_DATA: ' . $invalid . ' membership(s) with more than one open period; no data changed.');
        }

        // One MySQL atomic DDL statement: a racing duplicate also fails enforcement without leaving a partially
        // installed generated column.
        DB::statement(<<<'SQL'
ALTER TABLE `membership_periods`
  ADD COLUMN `open_flag` TINYINT UNSIGNED GENERATED ALWAYS AS (IF(`ends_at` IS NULL, 1, NULL)) STORED,
  ADD UNIQUE KEY `uq_membership_periods_membership_open` (`membership_id`, `open_flag`)
SQL
        );
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE `membership_periods`
  DROP KEY `uq_membership_periods_membership_open`,
  DROP COLUMN `open_flag`
SQL
        );
    }
};
