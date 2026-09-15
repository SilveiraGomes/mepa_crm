<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
final class WaveFourPhysicalTest extends WaveFourCase
{
    protected static bool $physical = true;
    private function snapshot(array $excluded = []): array
    {
        $out = [];
        foreach ($this->db()->select('SHOW TABLES') as $row) {
            $name = array_values((array) $row)[0];
            if (in_array($name, $excluded, true)) {
                continue;
            }
            $create = array_values((array) $this->db()->selectOne('SHOW CREATE TABLE `' . $name . '`'))[1];
            $out[$name] = ['ddl' => preg_replace('/ AUTO_INCREMENT=\d+/', '', $create), 'rows' => $name === 'migrations' ? [] : array_map(fn($r) => (array) $r, $this->db()->select('SELECT * FROM `' . $name . '` ORDER BY 1'))];
        }
        ksort($out);
        return $out;
    }
    public function test_fresh_upgrade_empty_rollback_remigrate_and_populated_rollback_guard(): void
    {
        $names = json_decode(file_get_contents(self::$root . '/docs/database/physical/wave4_manifest.json'), true)['tables'];
        $before = $this->snapshot($names);
        self::assertCount(17, self::$migrator->run(self::$waveFourPaths));
        self::assertSame($before, $this->snapshot($names));
        $fresh = $this->snapshot();
        self::assertCount(17, self::$migrator->rollback(self::$waveFourPaths));
        self::assertSame($before, $this->snapshot($names));
        self::assertCount(17, self::$migrator->run(self::$waveFourPaths));
        self::assertSame($fresh, $this->snapshot());
        self::assertCount(17, self::$migrator->rollback(self::$waveFourPaths));
        // Populated Wave 3, then actual W3 -> W4 upgrade.
        $f = $this->fixture();
        $credential = $this->eventCredential($f);
        $this->scan($f, $credential['token']);
        $p = $this->row('memberships', ['person_id' => $f['person'], 'approved_by' => $f['actor'], 'approved_at' => $this->now()->format('Y-m-d H:i:s.u')]);
        $this->row('member_number_sequences', ['code' => 'MEPA_NATIONAL', 'last_value' => 0]);
        (new \App\Domain\Membership\MemberNumberGenerator($this->db()))->generateFor($p, $this->now());
        $destination = $this->row('organizational_units');
        foreach (['2026-02-03 04:05:06.123456', '2026-03-04 05:06:07.654321', null] as $closed) {
            $id = $this->row('transfers', ['membership_id' => $p, 'origin_unit_id' => $f['unit'], 'destination_unit_id' => $destination]);
            if ($closed !== null) {
                $this->db()->table('transfers')->where('id', $id)->update(['closed_at' => $closed]);
            }
        }
        $populated = $this->snapshot($names);
        self::assertCount(17, self::$migrator->run(self::$waveFourPaths));
        self::assertSame($populated, $this->snapshot($names));
        // Every Wave 4 table has synthetic durable data, including closed custody history.
        $child = $this->childFixture();
        $this->childOut($child);
        $this->grant($f);
        $s = $this->evangelism();
        $c = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_ROLLBACK', $this->now());
        $contact = $s->contact($f['actor'], $f['auth'], $f['unit'], $c, $f['person']);
        $s->followup($f['actor'], $f['auth'], $f['unit'], $contact, $f['person'], $this->now(), 'SYNTHETIC_RESULT');
        $s->decision($f['actor'], $f['auth'], $f['unit'], $f['person'], 'SYNTHETIC_DECISION', null, 'UNKNOWN', $contact);
        foreach ($names as $name) {
            if (!$this->db()->table($name)->exists()) {
                $this->row($name);
            }
        }
        $durable = $this->snapshot();
        $migrationRows = $this->db()->table('migrations')->orderBy('id')->get()->all();
        try {
            self::$migrator->rollback(self::$waveFourPaths);
            self::fail('Durable rollback must refuse');
        } catch (\RuntimeException $e) {
            self::assertSame('WAVE4_DURABLE_DATA_ROLLBACK_BLOCKED', $e->getMessage());
        }
        self::assertSame($durable, $this->snapshot());
        self::assertEquals($migrationRows, $this->db()->table('migrations')->orderBy('id')->get()->all());
        // Synthetic-only cleanup in the test harness, no deployment bypass.
        $this->db()->beginTransaction();
        foreach (array_reverse($names) as $name) {
            $this->db()->table($name)->delete();
        }
        $this->db()->commit();
        $baseline = $this->snapshot($names);
        self::assertCount(17, self::$migrator->rollback(self::$waveFourPaths));
        self::assertSame($baseline, $this->snapshot($names));
        self::assertCount(17, self::$migrator->run(self::$waveFourPaths));
        self::assertSame($baseline, $this->snapshot($names));
        self::assertSame(1, (int) $this->db()->selectOne('SELECT @@foreign_key_checks AS value')->value);
        self::assertSame([null, null, 1], $this->db()->table('transfers')->orderBy('id')->pluck('open_flag')->all());
        file_put_contents(self::$root . '/docs/database/physical/wave4_lifecycle_evidence.json', json_encode(['fresh' => 'PASS', 'upgrade' => 'PASS', 'empty_rollback' => 'PASS', 'remigrate' => 'PASS', 'durable_rollback' => 'BLOCKED_WITHOUT_LOSS_PASS', 'all_wave4_tables_populated' => 17, 'migration_rows_preserved' => true, 'baseline_data_and_ddl_preserved' => true, 'm1_guard_preserved' => true, 'baseline_tables' => count($baseline), 'baseline_sha256' => hash('sha256', serialize($baseline))], JSON_PRETTY_PRINT) . PHP_EOL);
    }
    public function test_foreign_keys_unique_checks_and_restrict_are_enforced(): void
    {
        $this->db()->beginTransaction();
        try {
            $f = $this->childFixture();
            $s = $this->evangelism();
            $campaign = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_ENFORCEMENT', $this->now());
            $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $f['guardian']);
            $cases = [[1062, fn() => $this->row('outreach_contacts', ['campaign_id' => $campaign, 'person_id' => $f['guardian']])], [1452, fn() => $this->row('child_profiles', ['person_id' => PHP_INT_MAX, 'owner_unit_id' => $f['unit']])], [3819, fn() => $this->row('age_band_rules', ['version' => 0])], [1451, fn() => $this->db()->table('outreach_campaigns')->where('id', $campaign)->delete()], [1451, fn() => $this->db()->table('people')->where('id', $f['guardian'])->delete()]];
            foreach ($cases as [$expected, $operation]) {
                try {
                    $operation();
                    self::fail('Database must reject invalid Wave 4 write');
                } catch (\Illuminate\Database\QueryException $e) {
                    self::assertSame($expected, (int) ($e->errorInfo[1] ?? 0));
                }
            }
            self::assertTrue($this->db()->table('guardian_authorizations')->where('id', $f['pickup'])->exists());
            self::assertTrue($this->db()->table('outreach_campaigns')->where('id', $campaign)->exists());
        } finally {
            $this->db()->rollBack();
        }
    }
}
