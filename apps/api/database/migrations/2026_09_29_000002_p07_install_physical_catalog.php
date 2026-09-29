<?php

declare(strict_types=1);

use App\Domain\Physical\PhysicalCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.7 controlled data (ADR 0018 delta B): 6 occupation types + 8 permissions. Idempotent, no DDL.
return new class extends Migration
{
    public function up(): void { PhysicalCatalog::install(DB::connection()); }
    public function down(): void { /* referenced catalog data is preserved (R-INSTITUCIONAL, no hard delete) */ }
};
