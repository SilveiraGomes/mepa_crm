<?php
declare(strict_types=1);
namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFourCase.php';
require_once __DIR__ . '/../Database/Support/WaveFourWorkerHarness.php';

use App\Domain\WaveFour\DomainClock;
use Tests\DatabaseV2\Support\PooledWaveFourCase;
use Tests\Database\Support\WaveFourWorkerHarness;

/**
 * P0-TI.1 Test Infrastructure V2 pilot. Ported from
 * apps/api/tests/Database/WaveFourCommitAuthorizationTest.php (unmodified)
 * -- only the two "critical suite" targets named in the P0-TI.1 spec
 * (track/step commit authorization), byte-identical bodies, running against
 * a pooled/reset database instead of a per-run CREATE/DROP schema. See
 * PooledWaveFourCase for what actually differs (database lifecycle only).
 */
final class PooledCommitAuthorizationTest extends PooledWaveFourCase
{
    private function prepare(string $method): array
    {
        $f = $this->fixture();
        $this->grant($f);
        $s = $this->evangelism();
        if ($method === 'track') {
            $f['code'] = 'SYNTHETIC_M12_' . bin2hex(random_bytes(8));
        } elseif ($method === 'step' || $method === 'progress') {
            $f['track'] = $s->track($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_M12_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_M12');
            if ($method === 'progress') {
                $f['step'] = $s->step($f['actor'], $f['auth'], $f['unit'], $f['track'], 1, 'SYNTHETIC_M12');
                $campaign = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_M12', DomainClock::now($this->db()));
                $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $f['person']);
                $f['enrollment'] = $s->enroll($f['actor'], $f['auth'], $f['unit'], $f['person'], $f['track']);
            }
        } else {
            $f['name'] = 'SYNTHETIC_M12_' . bin2hex(random_bytes(8));
        }
        return $f;
    }
    private function separateConfigureGrant(array $f): int
    {
        $permission = (int) $this->db()->table('permissions')->where('code', 'DISCIPLESHIP_CONFIGURE')->where('data_type', 'EVANGELISM')->value('id');
        $this->db()->table('role_permissions')->where('permission_id', $permission)->delete();
        $role = $this->row('roles');
        $scope = $this->row('scopes', ['unit_id' => $f['unit'], 'include_descendants' => 0]);
        $this->row('role_permissions', ['role_id' => $role, 'permission_id' => $permission]);
        return $this->row('user_role_scopes', ['user_id' => $f['actor'], 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $f['actor'], 'ends_at' => null]);
    }
    private function waitForSqlWait(string $expected, WaveFourWorkerHarness $harness, int $seconds = 45): void
    {
        $deadline = microtime(true) + $seconds;
        $schema = $this->db()->getDatabaseName();
        while (microtime(true) < $deadline) {
            $rows = $this->db()->select('SELECT p.info AS trx_query FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.thread_id=w.requesting_thread_id JOIN information_schema.processlist p ON p.id=t.processlist_id WHERE p.db=?', [$schema]);
            foreach ($rows as $row) {
                if (str_contains(strtolower((string) $row->trx_query), $expected)) return;
            }
            clearstatcache(true, $harness->stateFile(0, 'done'));
            if (file_exists($harness->stateFile(0, 'done'))) {
                $early = $harness->collect(0);
                self::fail('Worker finished before target SQL wait: ' . json_encode($early));
            }
            usleep(10000);
        }
        self::fail('Expected real SQL lock wait on ' . $expected);
    }
    private function waitForExpiry(string $expiry): void
    {
        $deadline = microtime(true) + 90;
        while (DomainClock::now($this->db())->format('Y-m-d H:i:s.u') <= $expiry) {
            if (microtime(true) >= $deadline) self::fail('Database-authoritative lease did not expire');
            usleep(10000);
        }
    }
    private function lateWait(string $method, bool $configure): void
    {
        $f = $this->prepare($method);
        $grant = $configure ? $this->separateConfigureGrant($f) : null;
        $table = match ($method) {
            'track' => 'discipleship_tracks', 'step' => 'discipleship_steps',
            'progress' => 'discipleship_progress', 'campaign' => 'outreach_campaigns'
        };
        $before = (int) $this->db()->table($table)->count();
        $auditBefore = (int) $this->db()->table('audit_logs')->count();
        $lock = self::connect()->getConnection();
        $lock->beginTransaction();
        $lock->table('organizational_units')->where('id', $f['unit'])->lockForUpdate()->first();
        $harness = new WaveFourWorkerHarness(self::$root, 'wave4-m12-worker.php', 35);
        try {
            $harness->start(0, ['method' => $method, 'fixture' => $f]);
            $harness->waitForState(0, 'ready');
            if ($configure) {
                $this->db()->statement('UPDATE user_role_scopes SET ends_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 60 SECOND) WHERE id=?', [$grant]);
                $expiry = $this->db()->table('user_role_scopes')->where('id', $grant)->value('ends_at');
            } else {
                $this->db()->statement('UPDATE auth_sessions SET expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 60 SECOND) WHERE id=?', [$f['auth']]);
                $expiry = $this->db()->table('auth_sessions')->where('id', $f['auth'])->value('expires_at');
            }
            $harness->signal(0, 'go');
            $harness->waitForState(0, 'started', 25);
            $this->waitForSqlWait($method === 'campaign' ? 'outreach_campaigns' : 'audit_logs', $harness);
            $this->waitForExpiry($expiry);
            $lock->rollBack();
            $collected = $harness->collect(0);
            self::assertSame(0, $collected['exit'], $collected['stderr'] . $collected['stdout']);
            self::assertSame('', trim($collected['stderr']));
            self::assertTrue($collected['done'], 'A worker exit alone is not success');
            $result = json_decode(trim($collected['stdout']), true, 512, JSON_THROW_ON_ERROR);
            self::assertLessThan($expiry, $result['started'], 'Worker began while authorization was valid');
            self::assertSame('ACTOR_NOT_AUTHORIZED', $result['result']);
            self::assertSame(0, $result['transaction_level']);
            self::assertSame($before, (int) $this->db()->table($table)->count(), 'Business write rolled back');
            self::assertSame($auditBefore, (int) $this->db()->table('audit_logs')->count(), 'Success audit rolled back');
        } finally {
            if ($lock->transactionLevel()) $lock->rollBack();
            $harness->close();
        }
    }
    public function test_track_configure_expires_during_success_audit_fk_wait(): void { $this->lateWait('track', true); }
    public function test_step_configure_expires_during_success_audit_fk_wait(): void { $this->lateWait('step', true); }
}
