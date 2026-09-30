<?php

declare(strict_types=1);

use App\Domain\Finance\FinanceCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.10-F1A controlled data (ADR 0021 D02, D08, D09, D14, D31, D32 + D-04A): AOA, fund GENERAL, the 18-account
// structural chart, the §16 rubrics + transfer purposes, 16 FINANCE permissions, 10 finance legal_document_types and the
// FINANCE_INTERNAL_TRANSFER / FINANCE_PAYABLE workflows. Idempotent, INSERT of missing rows only; an existing code with a
// different meaning aborts (never repaired). No role, no period, no account, no statutory rate is seeded.
return new class extends Migration
{
    public function up(): void { FinanceCatalog::install(DB::connection()); }
    public function down(): void { /* controlled finance data is preserved (never reset, no hard delete) */ }
};
