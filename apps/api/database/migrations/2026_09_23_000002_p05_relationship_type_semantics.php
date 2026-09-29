<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.5-I delta B + C (ADR-0017): relationship_types.semantics (SYMMETRIC | INVERSE_PAIRED) and the
// nullable self-FK inverse_relationship_type_id. The inverse stays nullable during controlled catalog
// loading; the service refuses any type whose pairing is not closed (SYMMETRIC -> itself,
// INVERSE_PAIRED -> another type that points back).
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.5 requires the qualified MySQL driver; SQLite is not supported.');
        }
        // ADR-0017 records an empty catalog. A populated one would need an explicit semantics decision per row.
        if ((int) DB::table('relationship_types')->count() > 0) {
            throw new RuntimeException('relationship_types must be empty before semantics are added (ADR-0017).');
        }
        DB::statement(<<<'SQL'
ALTER TABLE `relationship_types`
  ADD COLUMN `semantics` VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL AFTER `name`,
  ADD COLUMN `inverse_relationship_type_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `semantics`,
  ADD KEY `ix_relationship_types_inverse_relationship_type_id` (`inverse_relationship_type_id`),
  ADD CONSTRAINT `fk_relationship_types_inverse_relationship_type_id` FOREIGN KEY (`inverse_relationship_type_id`) REFERENCES `relationship_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `ck_relationship_types_semantics` CHECK (`semantics` IN ('SYMMETRIC','INVERSE_PAIRED'))
SQL
        );
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE `relationship_types`
  DROP CHECK `ck_relationship_types_semantics`,
  DROP FOREIGN KEY `fk_relationship_types_inverse_relationship_type_id`
SQL
        );
        DB::statement('ALTER TABLE `relationship_types` DROP KEY `ix_relationship_types_inverse_relationship_type_id`, DROP COLUMN `inverse_relationship_type_id`, DROP COLUMN `semantics`');
    }
};
