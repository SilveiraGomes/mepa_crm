<?php

declare(strict_types=1);

use App\Domain\Territorial\TerritorialCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void { TerritorialCatalog::install(DB::connection()); }
    public function down(): void { /* referenced catalog data and the serialization anchor are preserved */ }
};
