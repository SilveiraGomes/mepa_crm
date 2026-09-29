<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// P0.5-I delta D + E (ADR-0017 "Delta estrutural aprovado", D-10): people.birth_month and the
// EXACT / MONTH / YEAR / UNKNOWN birth CHECK. YEAR_ONLY is renamed to YEAR; no day, month or
// instant is ever completed artificially. people.unit_id is NOT added (ADR-0017 D01).
return new class extends Migration
{
    // ADR-0017 text, with one explicit `birth_month IS NOT NULL`: in SQL three-valued logic
    // `NULL BETWEEN 1 AND 12` is UNKNOWN and a CHECK only rejects FALSE, so the literal ADR expression would
    // accept MONTH without a month. The added predicate enforces exactly the ADR's stated intent (P05-F01).
    private const NEW_CHECK = "(`birth_precision` = 'EXACT' AND `birth_date` IS NOT NULL AND `birth_year` IS NULL AND `birth_month` IS NULL)"
        . " OR (`birth_precision` = 'MONTH' AND `birth_date` IS NULL AND `birth_year` IS NOT NULL AND `birth_month` IS NOT NULL AND `birth_month` BETWEEN 1 AND 12)"
        . " OR (`birth_precision` = 'YEAR' AND `birth_date` IS NULL AND `birth_year` IS NOT NULL AND `birth_month` IS NULL)"
        . " OR (`birth_precision` = 'UNKNOWN' AND `birth_date` IS NULL AND `birth_year` IS NULL AND `birth_month` IS NULL)";

    private const OLD_CHECK = "(`birth_precision` = 'EXACT' AND `birth_date` IS NOT NULL AND `birth_year` IS NULL)"
        . " OR (`birth_precision` = 'YEAR_ONLY' AND `birth_date` IS NULL AND `birth_year` IS NOT NULL)"
        . " OR (`birth_precision` = 'UNKNOWN' AND `birth_date` IS NULL AND `birth_year` IS NULL)";

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('P0.5 requires the qualified MySQL driver; SQLite is not supported.');
        }
        DB::statement('ALTER TABLE `people` DROP CHECK `ck_people_birth`, ADD COLUMN `birth_month` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `birth_year`');
        DB::statement("UPDATE `people` SET `birth_precision` = 'YEAR' WHERE `birth_precision` = 'YEAR_ONLY'");
        DB::statement('ALTER TABLE `people` ADD CONSTRAINT `ck_people_birth` CHECK (' . self::NEW_CHECK . ')');
    }

    public function down(): void
    {
        // Rolling back cannot represent MONTH without inventing a day: refuse instead of losing precision.
        if ((int) DB::table('people')->where('birth_precision', 'MONTH')->count() > 0) {
            throw new RuntimeException('Rollback refused: MONTH birth precision cannot be represented by the previous schema.');
        }
        DB::statement('ALTER TABLE `people` DROP CHECK `ck_people_birth`');
        DB::statement("UPDATE `people` SET `birth_precision` = 'YEAR_ONLY' WHERE `birth_precision` = 'YEAR'");
        DB::statement('ALTER TABLE `people` DROP COLUMN `birth_month`, ADD CONSTRAINT `ck_people_birth` CHECK (' . self::OLD_CHECK . ')');
    }
};
