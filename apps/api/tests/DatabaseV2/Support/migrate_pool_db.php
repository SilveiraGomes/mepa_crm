<?php

declare(strict_types=1);

/**
 * P0-TI.1 Test Infrastructure V2 pilot: migrate-once CLI for a pool database.
 *
 * Runs the exact same migration sequence WaveFourCase::setUpBeforeClass() /
 * WaveThreeCase::setUpBeforeClass() already run (same manifests, same file
 * list, same order) against an already-created, empty pool database. Invoked
 * once per pool database by tools/test-infrastructure/pool.py -- never by
 * PHPUnit itself. WaveFourCase.php / WaveThreeCase.php are not modified;
 * this script reuses their connect() guard (schema name + ALLOW_SYNTHETIC)
 * unchanged, it does not relax it.
 *
 * Usage: php migrate_pool_db.php wave3|wave4
 * Env (matching the guard connect() enforces): WAVE{3,4}_DSN,
 * WAVE{3,4}_USER, WAVE{3,4}_PASSWORD, WAVE{3,4}_ALLOW_SYNTHETIC=1
 */

$root = dirname(__DIR__, 5);
require $root . '/apps/api/vendor/autoload.php';
require $root . '/apps/api/tests/Database/Support/WaveFourCase.php';
require $root . '/apps/api/tests/Database/Support/WaveThreeCase.php';

use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Database\Support\WaveFourCase;
use Tests\Database\Support\WaveThreeCase;

$target = $argv[1] ?? '';
if (!in_array($target, ['wave3', 'wave4'], true)) {
    fwrite(STDERR, "usage: php migrate_pool_db.php wave3|wave4\n");
    exit(2);
}

$capsule = $target === 'wave4' ? WaveFourCase::connect() : WaveThreeCase::connect();
$db = $capsule->getConnection();
// Migrations use the Schema/DB facades statically; outside PHPUnit's
// bootstrap (which does this in setUpBeforeClass()) the facade root is
// never set, so this CLI script must do it itself.
DB::swap($capsule->getDatabaseManager());
Schema::swap($db->getSchemaBuilder());
$existing = $db->select('SHOW TABLES');
if ($existing !== []) {
    fwrite(STDERR, 'ABORT: pool database is not empty (' . count($existing) . " tables present)\n");
    exit(3);
}

$repo = new DatabaseMigrationRepository($capsule->getDatabaseManager(), 'migrations');
$repo->createRepository();
$migrator = new Migrator($repo, $capsule->getDatabaseManager(), new Filesystem());
$base = $root . '/apps/api/database/migrations/';
$ran = 0;

$scaffold = [
    '2014_10_12_000000_create_users_table.php',
    '2014_10_12_100000_create_password_resets_table.php',
    '2019_08_19_000000_create_failed_jobs_table.php',
    '2019_12_14_000001_create_personal_access_tokens_table.php',
];
$ran += count($migrator->run(array_map(fn ($f) => $base . $f, $scaffold)));

foreach ([1, 2] as $wave) {
    $manifest = json_decode(file_get_contents($root . '/docs/database/physical/wave' . $wave . '_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $files = array_map(fn ($e) => is_array($e) ? $e['file'] : $e, $manifest['migrations']);
    $ran += count($migrator->run(array_map(fn ($f) => $base . $f, $files)));
}

$ran += count($migrator->run([
    $base . '2026_09_14_000000_wave2m1_add_transfers_closed_at.php',
    $base . '2026_09_14_000001_wave2m1_add_transfers_open_guard.php',
]));

$wave3Manifest = json_decode(file_get_contents($root . '/docs/database/physical/wave3_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$ran += count($migrator->run(array_map(fn ($f) => $base . $f, $wave3Manifest['migrations'])));

if ($target === 'wave4') {
    $wave4Manifest = json_decode(file_get_contents($root . '/docs/database/physical/wave4_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $ran += count($migrator->run(array_map(fn ($f) => $base . $f, $wave4Manifest['migrations'])));
}

$tables = $db->select('SHOW TABLES');
echo json_encode(['status' => 'MIGRATED', 'migrations_ran' => $ran, 'tables' => count($tables)], JSON_PRETTY_PRINT), PHP_EOL;
