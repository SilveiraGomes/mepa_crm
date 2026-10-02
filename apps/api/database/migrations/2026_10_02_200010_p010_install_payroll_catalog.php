<?php

declare(strict_types=1);

use App\Domain\Payroll\PayrollCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.10-F2A controlled data (ADR 0021 D24, D25, D31 + D-04A.15): the 9 HR permissions (data_type HR) and the 13 generic
// compensation component types. Idempotent, INSERT of missing rows only; an existing code with a different meaning aborts
// (never repaired). No role, no employment, no payroll rule, no rate, no bracket and no amount is seeded.
return new class extends Migration
{
    public function up(): void { PayrollCatalog::install(DB::connection()); }
    public function down(): void { /* controlled payroll data is preserved (never reset, no hard delete) */ }
};
