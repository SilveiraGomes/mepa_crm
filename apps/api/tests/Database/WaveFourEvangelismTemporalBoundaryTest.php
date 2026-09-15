<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\WaveFour\DomainClock;
use App\Domain\WaveFour\DomainError;
// P0.3.4-M1.1: closes W4R-M1R-01 (MEDIUM, discovered in P0.3.4-M1-R). EvangelismService::run()
// authorized the actor's session/grant with a clock sampled BEFORE any lock wait performed by
// the wrapped operation and never re-validated after. Fixed by adding a decisive, fresh-
// DomainClock re-check immediately after every lock the operation takes and before any write
// can commit -- the same provisional/decisive pattern already proven in ChildrenService.
final class WaveFourEvangelismTemporalBoundaryTest extends WaveFourCase
{
    private function launch(string $script, array $jobs): array
    {
        $barrier = sys_get_temp_dir() . '/mepa_wave4_evang_temporal_' . bin2hex(random_bytes(8));
        mkdir($barrier);
        $processes = [];
        foreach ($jobs as $i => $job) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, self::$root . '/scripts/' . $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::$root);
            self::assertIsResource($process);
            $job['barrier'] = $barrier;
            $job['worker'] = $i;
            fwrite($pipes[0], json_encode($job, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes[1], $pipes[2]];
        }
        $deadline = microtime(true) + 25;
        while (count(glob($barrier . '/ready_*')) < count($jobs)) {
            if (microtime(true) > $deadline) {
                touch($barrier . '/release');
                self::fail('Worker readiness timeout');
            }
            usleep(20000);
        }
        touch($barrier . '/release');
        return [$processes, $barrier];
    }
    private function collect(array $processes, string $barrier): array
    {
        $results = [];
        foreach ($processes as [$process, $stdout, $stderr]) {
            $out = stream_get_contents($stdout);
            $err = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            $exit = proc_close($process);
            self::assertSame('', trim($err), 'No raw SQL or PHP warnings');
            self::assertSame(0, $exit, 'Worker must converge: ' . $out);
            $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }
        foreach (glob($barrier . '/*') as $file) {
            unlink($file);
        }
        rmdir($barrier);
        return $results;
    }
    private function unitFixture(): array
    {
        $f = $this->fixture();
        $this->grant($f);
        return $f;
    }
    // 1) A session valid before AND after a lock wait admits the write (no regression).
    public function test_valid_session_before_and_after_a_lock_wait_admits_the_write(): void
    {
        $f = $this->unitFixture();
        $s = $this->evangelism();
        $campaign = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_TEMPORAL_OK', $this->now());
        $target = $this->row('people');
        $id = $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $target);
        self::assertSame(1, $this->db()->table('outreach_contacts')->where('id', $id)->count());
    }
    // 2) Session expires DURING a real lock wait (no competing writer, pure wall-clock
    // time passing) -> the decisive re-check denies the write. Writer: contact(), which
    // goes through the generic run() fix.
    public function test_session_expiring_during_lock_wait_denies_contact(): void
    {
        $f = $this->unitFixture();
        $this->db()->table('auth_sessions')->where('id', $f['auth'])->update(['expires_at' => DomainClock::now($this->db())->modify('+2 seconds')->format('Y-m-d H:i:s.u')]);
        $s = $this->evangelism();
        $campaign = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_TEMPORAL_CONTACT', $this->now());
        $target = $this->row('people');
        $jobFixture = ['actor' => $f['actor'], 'auth' => $f['auth'], 'unit' => $f['unit'], 'campaign' => $campaign, 'target' => $target];
        $auditBefore = (int) $this->db()->table('audit_logs')->where('entity_type', 'outreach_contacts')->count();
        [$processes, $barrier] = $this->launch('wave4-evangelism-temporal-worker.php', [['fixture' => $jobFixture, 'hold' => true, 'lock_table' => 'people', 'lock_id' => $target], ['fixture' => $jobFixture, 'method' => 'contact', 'wait_for_lock' => 0]]);
        $this->raceAndAssertDenied($processes, $barrier, 'ACTOR_NOT_AUTHORIZED');
        self::assertSame(0, $this->db()->table('outreach_contacts')->where('campaign_id', $campaign)->where('person_id', $target)->count());
        self::assertSame($auditBefore, (int) $this->db()->table('audit_logs')->where('entity_type', 'outreach_contacts')->count(), 'No partial audit row either -- full rollback');
    }
    // 3) Same mechanism, different writer: step(), which uses its OWN additional
    // DISCIPLESHIP_CONFIGURE decisive check positioned after the track lock.
    public function test_session_expiring_during_lock_wait_denies_step_configuration(): void
    {
        $f = $this->unitFixture();
        $this->db()->table('auth_sessions')->where('id', $f['auth'])->update(['expires_at' => DomainClock::now($this->db())->modify('+2 seconds')->format('Y-m-d H:i:s.u')]);
        $s = $this->evangelism();
        $track = $s->track($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_TRACK');
        $jobFixture = ['actor' => $f['actor'], 'auth' => $f['auth'], 'unit' => $f['unit'], 'track' => $track, 'sequence' => 1];
        [$processes, $barrier] = $this->launch('wave4-evangelism-temporal-worker.php', [['fixture' => $jobFixture, 'hold' => true, 'lock_table' => 'discipleship_tracks', 'lock_id' => $track], ['fixture' => $jobFixture, 'method' => 'step', 'wait_for_lock' => 0]]);
        $this->raceAndAssertDenied($processes, $barrier, 'ACTOR_NOT_AUTHORIZED');
        self::assertSame(0, $this->db()->table('discipleship_steps')->where('track_id', $track)->count());
    }
    private function raceAndAssertDenied(array $processes, string $barrier, string $expectedReason): void
    {
        $deadline = microtime(true) + 10;
        while (!file_exists($barrier . '/locked_0') || !file_exists($barrier . '/attempting_1')) {
            if (microtime(true) > $deadline) {
                self::fail('Race synchronization timeout');
            }
            usleep(10000);
        }
        self::assertTrue(proc_get_status($processes[1][0])['running'], 'Actor worker waits on the unrelated lock while its own session is still valid');
        usleep(2600000);
        touch($barrier . '/finish');
        $results = $this->collect($processes, $barrier);
        self::assertSame('LOCK_RELEASED', $results[0]['result']);
        self::assertSame($expectedReason, $results[1]['result']);
    }
    // 4) A session already expired BEFORE the call starts is denied immediately (the
    // provisional check), for every writer that goes through EvangelismService, with
    // zero durable writes in each case.
    public function test_already_expired_session_denies_every_affected_writer_with_zero_writes(): void
    {
        $f = $this->unitFixture();
        $s = $this->evangelism();
        // Set up legitimate targets FIRST, with a valid session.
        $campaign = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_TEMPORAL_MATRIX', $this->now());
        $person = $this->row('people');
        $contact = $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $person);
        $track = $s->track($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_TRACK');
        $step = $s->step($f['actor'], $f['auth'], $f['unit'], $track, 1, 'SYNTHETIC_STEP');
        $enrollment = $s->enroll($f['actor'], $f['auth'], $f['unit'], $person, $track);
        // Now the session expires.
        $this->db()->table('auth_sessions')->where('id', $f['auth'])->update(['expires_at' => DomainClock::now($this->db())->modify('-1 second')->format('Y-m-d H:i:s.u')]);
        $newPerson = $this->row('people');
        $cases = [
            'campaign' => fn() => $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_SHOULD_NOT_EXIST', $this->now()),
            'contact' => fn() => $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $newPerson),
            'followup' => fn() => $s->followup($f['actor'], $f['auth'], $f['unit'], $contact, $person, $this->now(), 'SYNTHETIC_RESULT'),
            'decision' => fn() => $s->decision($f['actor'], $f['auth'], $f['unit'], $person, 'SYNTHETIC_DECISION', null, 'UNKNOWN', $contact),
            'track' => fn() => $s->track($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_SHOULD_NOT_EXIST_' . bin2hex(random_bytes(4)), 1, 'SYNTHETIC_TRACK'),
            'step' => fn() => $s->step($f['actor'], $f['auth'], $f['unit'], $track, 2, 'SYNTHETIC_SHOULD_NOT_EXIST'),
            'enroll' => fn() => $s->enroll($f['actor'], $f['auth'], $f['unit'], $newPerson, $track),
            'integrate' => fn() => $s->integrate($f['actor'], $f['auth'], $f['unit'], $person),
            'progress' => fn() => $s->progress($f['actor'], $f['auth'], $f['unit'], $enrollment, $step),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail("Expected ACTOR_NOT_AUTHORIZED for already-expired session on: $name");
            } catch (DomainError $e) {
                self::assertSame('ACTOR_NOT_AUTHORIZED', $e->reason, "Writer: $name");
            }
        }
        self::assertSame(0, $this->db()->table('outreach_campaigns')->where('name', 'SYNTHETIC_SHOULD_NOT_EXIST')->count());
        self::assertSame(0, $this->db()->table('outreach_contacts')->where('campaign_id', $campaign)->where('person_id', $newPerson)->count());
        self::assertSame(0, $this->db()->table('discipleship_progress')->where('enrollment_id', $enrollment)->count());
    }
    // 5) DATETIME(6) precision is not truncated for this decisive check either: a
    // session that expired one microsecond ago is already denied.
    public function test_authorization_boundary_respects_microsecond_precision(): void
    {
        $f = $this->unitFixture();
        $s = $this->evangelism();
        $campaign = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_TEMPORAL_US', $this->now());
        $dbNow = DomainClock::now($this->db());
        $this->db()->table('auth_sessions')->where('id', $f['auth'])->update(['expires_at' => $dbNow->modify('-1 microsecond')->format('Y-m-d H:i:s.u')]);
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $this->row('people')));
        $this->db()->table('auth_sessions')->where('id', $f['auth'])->update(['expires_at' => $dbNow->modify('+5 seconds')->format('Y-m-d H:i:s.u')]);
        $id = $s->contact($f['actor'], $f['auth'], $f['unit'], $campaign, $this->row('people'));
        self::assertSame(1, $this->db()->table('outreach_contacts')->where('id', $id)->count());
        $column = $this->db()->select("SHOW COLUMNS FROM auth_sessions LIKE 'expires_at'")[0];
        self::assertStringContainsString('datetime(6)', strtolower($column->Type));
    }
    // 6) No public EvangelismService method accepts a clock/now-like parameter that
    // could let a caller spoof the decisive authorization instant; the only
    // DateTimeImmutable parameters are business dates (campaign starts/ends, followup
    // occurred_at, decision date), never used to decide authorization validity.
    public function test_no_public_parameter_lets_a_caller_supply_a_stale_decisive_clock(): void
    {
        $reflection = new \ReflectionClass(\App\Domain\WaveFour\EvangelismService::class);
        $suspicious = ['now', 'clock', 'time', 'currenttime', 'authorizedat'];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $param) {
                self::assertNotContains(strtolower($param->getName()), $suspicious, "{$method->getName()}(\${$param->getName()}) looks like a caller-suppliable clock override");
            }
        }
        // Concrete demonstration: an arbitrary, far-future business date on a business
        // field does not smuggle in a favourable authorization instant.
        $f = $this->unitFixture();
        $this->db()->table('auth_sessions')->where('id', $f['auth'])->update(['expires_at' => DomainClock::now($this->db())->modify('-1 second')->format('Y-m-d H:i:s.u')]);
        $s = $this->evangelism();
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_SPOOF_ATTEMPT', $this->now()->modify('+1 year'), $this->now()->modify('+2 years')));
        self::assertSame(0, $this->db()->table('outreach_campaigns')->where('name', 'SYNTHETIC_SPOOF_ATTEMPT')->count());
    }
}
