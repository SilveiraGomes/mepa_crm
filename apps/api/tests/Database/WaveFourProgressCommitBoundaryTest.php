<?php
declare(strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
require_once __DIR__ . '/Support/WaveFourWorkerHarness.php';

use App\Domain\WaveFour\DomainClock;
use Tests\Database\Support\WaveFourCase;
use Tests\Database\Support\WaveFourWorkerHarness;

final class WaveFourProgressCommitBoundaryTest extends WaveFourCase
{
    public function test_progress_session_expires_during_success_audit_fk_wait_and_rolls_back(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $s = $this->evangelism();
        $campaign = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_M12_PROGRESS', DomainClock::now($this->db()));
        $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $f['person']);
        $track = $s->track($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_M12_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_M12');
        $f['step'] = $s->step($f['actor'], $f['auth'], $f['unit'], $track, 1, 'SYNTHETIC_M12');
        $f['enrollment'] = $s->enroll($f['actor'], $f['auth'], $f['unit'], $f['person'], $track);
        $before = (int) $this->db()->table('discipleship_progress')->count();
        $auditBefore = (int) $this->db()->table('audit_logs')->count();
        $lock = self::connect()->getConnection();
        $lock->beginTransaction();
        $lock->table('organizational_units')->where('id', $f['unit'])->lockForUpdate()->first();
        $harness = new WaveFourWorkerHarness(self::$root, 'wave4-m12-worker.php');
        try {
            $harness->start(0, ['method' => 'progress', 'fixture' => $f]);
            $harness->waitForState(0, 'ready');
            $this->db()->statement('UPDATE auth_sessions SET expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 60 SECOND) WHERE id=?', [$f['auth']]);
            $expiry = $this->db()->table('auth_sessions')->where('id', $f['auth'])->value('expires_at');
            $harness->signal(0, 'go');
            $harness->waitForState(0, 'started', 25);
            $deadline = microtime(true) + 25;
            $observed = false;
            while (microtime(true) < $deadline) {
                $rows = $this->db()->select('SELECT p.info AS trx_query FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.thread_id=w.requesting_thread_id JOIN information_schema.processlist p ON p.id=t.processlist_id WHERE p.db=?', [$this->db()->getDatabaseName()]);
                foreach ($rows as $row) {
                    if (str_contains(strtolower((string) $row->trx_query), 'audit_logs')) $observed = true;
                }
                if ($observed) break;
                usleep(10000);
            }
            self::assertTrue($observed, 'Observed a real audit FK lock wait after provisional authorization');
            $deadline = microtime(true) + 90;
            while (DomainClock::now($this->db())->format('Y-m-d H:i:s.u') <= $expiry) {
                if (microtime(true) >= $deadline) self::fail('Database lease expiry timeout');
                usleep(10000);
            }
            $lock->rollBack();
            $collected = $harness->collect(0);
            self::assertSame(0, $collected['exit'], $collected['stdout'] . $collected['stderr']);
            self::assertSame('', trim($collected['stderr']));
            self::assertTrue($collected['done']);
            $result = json_decode(trim($collected['stdout']), true, 512, JSON_THROW_ON_ERROR);
            self::assertLessThan($expiry, $result['started']);
            self::assertSame('ACTOR_NOT_AUTHORIZED', $result['result']);
            self::assertSame(0, $result['transaction_level']);
            self::assertSame($before, (int) $this->db()->table('discipleship_progress')->count());
            self::assertSame($auditBefore, (int) $this->db()->table('audit_logs')->count());
        } finally {
            if ($lock->transactionLevel()) $lock->rollBack();
            $harness->close();
        }
    }
}

