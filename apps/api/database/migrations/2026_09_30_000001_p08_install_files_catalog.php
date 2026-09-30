<?php

declare(strict_types=1);

use App\Domain\Files\FilesCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.8 controlled data (ADR 0019 "Migrations necessárias"): 9 FILES permissions + 8 legal_document_types.
// Idempotent, inserts missing rows only, no role, no DDL.
return new class extends Migration
{
    public function up(): void { FilesCatalog::install(DB::connection()); }
    public function down(): void { /* referenced catalog data is preserved (R-INSTITUCIONAL, no hard delete) */ }
};
