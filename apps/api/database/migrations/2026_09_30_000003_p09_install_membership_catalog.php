<?php

declare(strict_types=1);

use App\Domain\Membership\MembershipCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.9 controlled data (ADR 0020 "Migrations necessárias" 2): 7 membership_statuses, 2 milestone_types,
// 6 MEMBERSHIP permissions, workflow MEMBERSHIP_TRANSFER v1 and the MEPA_NATIONAL counter (last_value = 0 only when
// absent). Idempotent, inserts missing rows only, no role, no DDL.
return new class extends Migration
{
    public function up(): void { MembershipCatalog::install(DB::connection()); }
    public function down(): void { /* referenced catalog data and the national counter are preserved (never reset, no hard delete) */ }
};
