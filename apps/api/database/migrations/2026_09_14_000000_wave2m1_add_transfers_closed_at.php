<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// P0.3.2-M1.1: closed_at is durable domain history, separate from the reversible guard.
// Ordered after approved Wave 2 and before M1 enforcement. Existing M1 installations
// already have this column: adopting it must neither recreate nor rewrite it.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Wave 2 requires the qualified MySQL driver; SQLite is not supported.');
        }
        if (!Schema::hasColumn('transfers', 'closed_at')) {
            DB::statement('ALTER TABLE `transfers` ADD COLUMN `closed_at` DATETIME(6) NULL DEFAULT NULL AFTER `effective_at`');
        }
    }

    public function down(): void
    {
        // Intentionally retained: rollback must never erase domain closure history.
        // The approved transfers table migration owns table deletion, not this guard lifecycle.
    }
};
