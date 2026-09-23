<?php

declare(strict_types=1);

use App\Domain\People\PeopleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.5-I controlled catalog data approved by ADR-0017: person statuses, household roles,
// relationship types (with semantics and closed inverse pairing), People contact types and the
// eleven People permissions. No role is created. Idempotent; rows are never deleted.
return new class extends Migration
{
    public function up(): void
    {
        PeopleCatalog::install(DB::connection());
    }

    public function down(): void
    {
        // Catalog rows are preserved on rollback: they may be referenced and V1 forbids hard delete.
    }
};
