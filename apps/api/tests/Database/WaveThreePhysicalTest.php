<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveThreeCase.php';
use Illuminate\Support\Facades\Schema;
use Tests\Database\Support\WaveThreeCase;
final class WaveThreePhysicalTest extends WaveThreeCase
{
    protected static bool $physical = true;
    private function baseline(): array
    {
        $out = [];
        $db = $this->db();
        $names = array_map(fn($r) => array_values((array) $r)[0], $db->select('SHOW TABLES'));
        $wave = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave3_manifest.json'), true)['tables'];
        foreach (array_diff($names, $wave) as $name) {
            $create = (array) $db->selectOne('SHOW CREATE TABLE `' . $name . '`');
            $rows = $name === 'migrations' ? [] : $db->select('SELECT * FROM `' . $name . '` ORDER BY 1');
            $out[$name] = ['ddl' => preg_replace('/ AUTO_INCREMENT=\d+/', '', array_values($create)[1]), 'rows' => array_map(fn($r) => (array) $r, $rows)];
        }
        return $out;
    }
    private function state(): array
    {
        $manifest = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave3_manifest.json'), true);
        $schema = $this->db()->getDatabaseName();
        $tables = $this->db()->table('information_schema.TABLES')->where('TABLE_SCHEMA', $schema)->whereIn('TABLE_NAME', $manifest['tables'])->orderBy('TABLE_NAME')->pluck('TABLE_NAME')->all();
        $columns = $this->db()->table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $schema)->where('TABLE_NAME', 'transfers')->whereIn('COLUMN_NAME', ['closed_at', 'open_flag'])->orderBy('ORDINAL_POSITION')->pluck('COLUMN_NAME')->all();
        return ['wave3_table_count' => count($tables), 'wave3_tables' => $tables, 'm1_columns' => $columns, 'm1_unique_present' => $this->db()->table('information_schema.STATISTICS')->where('TABLE_SCHEMA', $schema)->where('TABLE_NAME', 'transfers')->where('INDEX_NAME', 'uq_transfers_membership_open')->exists()];
    }
    public function test_fresh_populated_upgrade_rollback_and_remigrate_preserve_baseline(): void
    {
        $states = [];
        $before = $this->baseline();
        $this->assertCount(32, self::$migrator->run(self::$wavePaths));
        $states['A'] = $this->state();
        $this->assertSame($before, $this->baseline());
        $this->assertSame(1, (int) $this->db()->selectOne('SELECT @@foreign_key_checks AS value')->value);
        $this->assertCount(32, self::$migrator->rollback(self::$wavePaths));
        $this->assertSame($before, $this->baseline());
        foreach (['credentials', 'events', 'event_checkins', 'audit_logs'] as $name) {
            $this->assertFalse(Schema::hasTable($name));
        }
        $person = $this->row('people');
        $unit = $this->row('organizational_units');
        $status = $this->row('membership_statuses');
        $membership = $this->row('memberships', ['person_id' => $person, 'status_id' => $status]);
        $this->row('member_numbers', ['membership_id' => $membership, 'issued_year' => 2026, 'issued_month' => 9]);
        $destination = $this->row('organizational_units');
        $history = [];
        foreach (['2026-09-13 11:12:13.123456', '2026-09-14 14:15:16.654321', null] as $closed) {
            $id = $this->row('transfers', ['membership_id' => $membership, 'origin_unit_id' => $unit, 'destination_unit_id' => $destination]);
            if ($closed !== null) {
                $this->db()->table('transfers')->where('id', $id)->update(['closed_at' => $closed]);
            }
            $history[] = $id;
        }
        $this->row('ministerial_class_periods', ['person_id' => $person, 'ends_at' => null]);
        $department = $this->row('department_instances', ['unit_id' => $unit]);
        $this->row('department_memberships', ['instance_id' => $department, 'person_id' => $person, 'ends_at' => null]);
        $populated = $this->baseline();
        $this->assertCount(32, self::$migrator->run(self::$wavePaths));
        $this->assertSame($populated, $this->baseline());
        $f = $this->fixture();
        $credential = $this->eventCredential($f);
        $this->scan($f, $credential['token']);
        // Capture after legitimate fixture writes to earlier-wave identities; rollback alone must change none.
        $beforeRollback = $this->baseline();
        $closedValues = $this->db()->table('transfers')->whereIn('id', $history)->orderBy('id')->pluck('closed_at')->all();
        $this->assertSame(['2026-09-13 11:12:13.123456', '2026-09-14 14:15:16.654321', null], $closedValues);
        $this->assertCount(32, self::$migrator->rollback(self::$wavePaths));
        $states['B'] = $this->state();
        $this->assertSame($beforeRollback, $this->baseline());
        $this->assertCount(32, self::$migrator->run(self::$wavePaths));
        $this->assertSame($beforeRollback, $this->baseline());
        $states['C'] = $this->state();
        $this->assertSame([32, 0, 32], array_column($states, 'wave3_table_count'));
        $this->assertSame([null, null, 1], $this->db()->table('transfers')->whereIn('id', $history)->orderBy('id')->pluck('open_flag')->all());
        $this->assertTrue(Schema::hasColumn('transfers', 'closed_at'));
        $this->assertTrue(Schema::hasColumn('transfers', 'open_flag'));
        $this->assertSame(1, (int) $this->db()->table('information_schema.STATISTICS')->where('TABLE_SCHEMA', $this->db()->getDatabaseName())->where('TABLE_NAME', 'transfers')->where('INDEX_NAME', 'uq_transfers_membership_open')->where('SEQ_IN_INDEX', 1)->count());
        file_put_contents(self::$root . '/docs/database/physical/wave3_lifecycle_evidence.json', json_encode(['fresh' => 'PASS', 'empty_rollback' => 'PASS', 'populated_upgrade' => 'PASS', 'populated_rollback' => 'PASS', 'remigrate' => 'PASS', 'baseline_tables' => count($beforeRollback), 'baseline_data_and_ddl_identical' => true, 'm1_guard_preserved' => true, 'historical_transfers' => 3, 'closed_at_values_preserved' => $closedValues, 'states' => $states, 'membership_number_ministry_departments_populated' => true, 'foreign_key_checks' => 1, 'baseline_sha256' => hash('sha256', serialize($beforeRollback))], JSON_PRETTY_PRINT) . PHP_EOL);
    }
}
