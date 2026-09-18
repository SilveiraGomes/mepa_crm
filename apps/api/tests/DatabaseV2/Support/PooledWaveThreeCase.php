<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

require_once __DIR__ . '/../../Database/Support/WaveThreeCase.php';

use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Database\Support\WaveThreeCase;

/**
 * P0-TI.1 Test Infrastructure V2 pilot. Mirrors PooledWaveFourCase for the
 * Wave 3 bootstrap -- see that class's docblock. WaveThreeCase.php is not
 * modified.
 */
abstract class PooledWaveThreeCase extends WaveThreeCase
{
    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 5);
        self::$capsule = self::connect();
        $db = self::$capsule->getConnection();
        DB::swap(self::$capsule->getDatabaseManager());
        Schema::swap($db->getSchemaBuilder());

        if ($db->select('SHOW TABLES') === []) {
            // Defensive fallback only -- the pilot's own flow always has
            // tools/test-infrastructure/pool.py migrate the pool database
            // via migrate_pool_db.php before any PHPUnit class runs.
            self::runMigrationsOnce($db);
        } else {
            self::truncateBusinessData($db);
        }

        // WaveThreeCase::$catalog is `private`, not `protected` (unlike
        // WaveFourCase), so it is not inherited/writable from this
        // subclass directly -- row()/fixture() are defined in WaveThreeCase
        // itself and read it via `self::`, which resolves correctly
        // regardless of subclass because private-property access is bound
        // to the declaring class. Reflection is the only way to populate it
        // here without modifying WaveThreeCase.php.
        $catalog = json_decode(file_get_contents(self::$root . '/docs/database/model_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
        $prop = new \ReflectionProperty(WaveThreeCase::class, 'catalog');
        $prop->setAccessible(true);
        $prop->setValue(null, $catalog);
    }

    private static function truncateBusinessData(\Illuminate\Database\Connection $db): void
    {
        $schema = $db->getDatabaseName();
        $rows = $db->select(
            'SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_NAME != ?',
            [$schema, 'migrations']
        );
        $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($rows as $row) {
            $db->statement('TRUNCATE TABLE `' . $row->TABLE_NAME . '`');
        }
        $db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private static function runMigrationsOnce(\Illuminate\Database\Connection $db): void
    {
        $repo = new DatabaseMigrationRepository(self::$capsule->getDatabaseManager(), 'migrations');
        $repo->createRepository();
        $migrator = new Migrator($repo, self::$capsule->getDatabaseManager(), new Filesystem());
        $base = self::$root . '/apps/api/database/migrations/';
        $scaffold = ['2014_10_12_000000_create_users_table.php', '2014_10_12_100000_create_password_resets_table.php', '2019_08_19_000000_create_failed_jobs_table.php', '2019_12_14_000001_create_personal_access_tokens_table.php'];
        $migrator->run(array_map(fn ($f) => $base . $f, $scaffold));
        foreach ([1, 2] as $wave) {
            $manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave' . $wave . '_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $files = array_map(fn ($e) => is_array($e) ? $e['file'] : $e, $manifest['migrations']);
            $migrator->run(array_map(fn ($f) => $base . $f, $files));
        }
        $migrator->run([$base . '2026_09_14_000000_wave2m1_add_transfers_closed_at.php', $base . '2026_09_14_000001_wave2m1_add_transfers_open_guard.php']);
        $wave3Manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave3_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $migrator->run(array_map(fn ($f) => $base . $f, $wave3Manifest['migrations']));
    }
}
