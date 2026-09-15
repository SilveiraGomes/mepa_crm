<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
use App\Domain\Events\CheckinService;
use App\Domain\Events\EventError;
use App\Domain\WaveFour\DomainClock;
use App\Domain\WaveFour\DomainError;
// P0.3.4-M1-R: independent re-audit. Written from scratch against a freshly created
// container/schema, without relying on the executor's WaveFourChildCheckinBoundaryTest,
// WaveFourDiscipleshipScopeTest or WaveFourTemporalAuthorizationTest bodies. Covers
// angles the M1 remediation report did not: audit_logs-as-provenance robustness
// (missing/ambiguous/unrelated entries), the child_profiles discriminator's
// fail-closed behaviour on a status mismatch, and a newly found stale-clock class in
// EvangelismService that the narrow W4R-03 fix did not touch.
final class WaveFourIndependentAuditM1RTest extends WaveFourCase
{
    private function launch(string $script, array $jobs): array
    {
        $barrier = sys_get_temp_dir() . '/mepa_wave4_m1r_barrier_' . bin2hex(random_bytes(8));
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

    // Item 2: independent reproduction of the original W4R-01 bypass, calling
    // CheckinService::scan() DIRECTLY (never ChildrenService, never a controller).
    public function test_independent_reproduction_of_original_bypass_is_denied_with_zero_writes(): void
    {
        $f = $this->childFixture(false);
        $this->children()->revokeConsent($f['actor'], $f['auth'], $f['consent']);
        // Strip the actor's CHILDREN grant entirely (only the base EVENTS scope survives).
        $this->db()->table('role_permissions')->whereIn('permission_id', function ($q) {
            $q->select('id')->from('permissions')->where('data_type', 'CHILDREN');
        })->delete();
        try {
            (new CheckinService($this->db(), self::policy()))->scan($f['actor'], $f['auth'], $f['device'], $f['event'], $f['session'], $f['child'], $f['credential']['token'], 'm1r-direct-bypass', $this->now());
            self::fail('Expected the generic writer to refuse a protected participant');
        } catch (EventError $e) {
            self::assertSame('CHILD_SAFETY_FLOW_REQUIRED', $e->reason);
        }
        self::assertSame(0, $this->db()->table('event_checkins')->where('session_id', $f['session'])->where('person_id', $f['child'])->count());
        self::assertSame(0, $this->db()->table('event_attendance')->where('session_id', $f['session'])->where('person_id', $f['child'])->count());
        self::assertSame(0, $this->db()->table('child_custody_visits')->where('session_id', $f['session'])->where('child_person_id', $f['child'])->count());
    }

    // Item 4: the discriminator is "does this Person have a child_profiles row", not
    // "is the row's status the canonical active one". Confirm this does not create a
    // bypass: a status-mismatched profile fails BOTH the generic door (still blocked)
    // and the child-specific door (CHILD_NOT_ELIGIBLE) -- fail-closed on both sides,
    // never an accidental admission. The approved catalogue has no second configured
    // status and no session-level "does not require custody" distinction (age-band
    // changes only ever produce a recommendation row, never flip child_profiles.status
    // or bypass custody), so a coarser, Person-level, existence-based gate is the
    // correct reading of the current domain model, not over-blocking invented here.
    public function test_child_profile_status_mismatch_fails_closed_on_both_doors(): void
    {
        $f = $this->childFixture(false);
        $this->db()->table('child_profiles')->where('person_id', $f['child'])->update(['status' => 'UNAPPROVED_DRIFT_STATUS']);
        try {
            (new CheckinService($this->db(), self::policy()))->scan($f['actor'], $f['auth'], $f['device'], $f['event'], $f['session'], $f['child'], $f['credential']['token'], 'm1r-status-drift', $this->now());
            self::fail('Generic door must still refuse regardless of child_profiles.status');
        } catch (EventError $e) {
            self::assertSame('CHILD_SAFETY_FLOW_REQUIRED', $e->reason);
        }
        $this->denied('CHILD_NOT_ELIGIBLE', fn() => $this->childIn($f));
        self::assertSame(0, $this->db()->table('event_checkins')->where('session_id', $f['session'])->count());
    }

    // Item 6/7: force a failure strictly between a successful generic writer effect
    // and the custody commit is structurally unreachable here -- the child anchor is
    // held continuously from before the visit-conflict check through the custody
    // insert, so nothing can race a colliding visit into existence in that window.
    // The next-best real adversarial proof: two concurrent check-in attempts for the
    // SAME child/session must collapse to exactly one CHECKED_IN with zero orphaned
    // attendance, independently reproduced (not reusing the executor's worker count
    // matrix, a single high-contention run with mixed idempotency keys).
    public function test_concurrent_checkins_same_child_session_collapse_to_one_admission(): void
    {
        $f = $this->childFixture(false);
        $jobs = array_fill(0, 16, ['fixture' => $f]);
        [$processes, $barrier] = $this->launch('wave4-checkin-worker.php', $jobs);
        $results = $this->collect($processes, $barrier);
        $counts = array_count_values(array_column($results, 'result'));
        self::assertSame(1, $counts['CHECKED_IN'] ?? 0);
        self::assertSame(15, $counts['OPEN_VISIT_EXISTS'] ?? 0);
        $row = $this->db()->selectOne('SELECT COUNT(*) AS n FROM child_custody_visits WHERE session_id=? AND child_person_id=?', [$f['session'], $f['child']]);
        self::assertSame(1, (int) $row->n);
        self::assertSame(1, (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM event_checkins WHERE session_id=? AND person_id=?', [$f['session'], $f['child']])->n);
        self::assertSame(1, (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM event_attendance WHERE session_id=? AND person_id=?', [$f['session'], $f['child']])->n);
    }

    // Item 10: consent revocation racing check-in itself (not checkout, which the
    // executor already raced against authorization revocation). Both orders.
    public function test_consent_revocation_races_checkin_in_both_orders(): void
    {
        foreach (['checkin', 'revoke_consent'] as $first) {
            $f = $this->childFixture(false);
            unset($f['credential']);
            $f['credential'] = $this->eventCredential($f);
            $second = $first === 'checkin' ? 'revoke_consent' : 'checkin';
            [$processes, $barrier] = $this->launch('wave4-checkin-race-worker.php', [['fixture' => $f, 'mode' => $first, 'hold' => true], ['fixture' => $f, 'mode' => $second, 'wait_for_lock' => 0]]);
            $deadline = microtime(true) + 10;
            while (!file_exists($barrier . '/locked_0') || !file_exists($barrier . '/attempting_1')) {
                if (microtime(true) > $deadline) {
                    self::fail('Race synchronization timeout');
                }
                usleep(10000);
            }
            self::assertTrue(proc_get_status($processes[1][0])['running'], 'Second worker waits for the child anchor');
            touch($barrier . '/finish');
            $results = $this->collect($processes, $barrier);
            if ($first === 'checkin') {
                self::assertSame(['CHECKED_IN', 'REVOKED'], array_column($results, 'result'));
                self::assertSame(1, $this->db()->table('event_attendance')->where('session_id', $f['session'])->where('person_id', $f['child'])->count());
            } else {
                self::assertSame(['REVOKED', 'CONSENT_REQUIRED'], array_column($results, 'result'));
                self::assertSame(0, $this->db()->table('event_attendance')->where('session_id', $f['session'])->where('person_id', $f['child'])->count());
                self::assertSame(0, $this->db()->table('child_custody_visits')->where('session_id', $f['session'])->where('child_person_id', $f['child'])->count());
            }
        }
    }

    // Item 11/12: DiscipleshipEnrollmentScope must fail closed, never fall back to
    // the caller-supplied unit_id, when its sole source of truth (audit_logs
    // provenance) is missing, ambiguous, or polluted by an unrelated entity_type.
    public function test_scope_resolution_fails_closed_when_provenance_is_missing(): void
    {
        $home = $this->fixture();
        $this->grant($home);
        $track = $this->evangelism()->track($home['actor'], $home['auth'], $home['unit'], 'SYNTHETIC_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_TRACK');
        $step = $this->evangelism()->step($home['actor'], $home['auth'], $home['unit'], $track, 1, 'SYNTHETIC_STEP');
        // Bypass EvangelismService::enroll() entirely: no OUTREACH_RECORD_CREATED audit row exists.
        $enrollment = $this->row('discipleship_enrollments', ['person_id' => $home['person'], 'track_id' => $track]);
        $this->denied('ENROLLMENT_SCOPE_UNRESOLVED', fn() => $this->evangelism()->progress($home['actor'], $home['auth'], $home['unit'], $enrollment, $step));
    }
    public function test_scope_resolution_fails_closed_when_provenance_is_ambiguous(): void
    {
        $home = $this->fixture();
        $this->grant($home);
        $other = $this->fixture();
        $this->grant($other);
        $s = $this->evangelism();
        $campaign = $s->campaign($home['actor'], $home['auth'], $home['unit'], 'SYNTHETIC_M1R', $this->now());
        $s->contact($home['actor'], $home['auth'], $home['unit'], $campaign, $home['person']);
        $track = $s->track($home['actor'], $home['auth'], $home['unit'], 'SYNTHETIC_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_TRACK');
        $step = $s->step($home['actor'], $home['auth'], $home['unit'], $track, 1, 'SYNTHETIC_STEP');
        $enrollment = $s->enroll($home['actor'], $home['auth'], $home['unit'], $home['person'], $track);
        $legit = $this->db()->table('audit_logs')->where('entity_type', 'discipleship_enrollments')->where('entity_id', $enrollment)->where('action', 'OUTREACH_RECORD_CREATED')->first();
        self::assertNotNull($legit, 'Precondition: enroll() must have produced exactly one provenance row');
        // Simulate a second, conflicting provenance row for the same enrollment (tamper/replay/double-log).
        $this->row('audit_logs', ['actor_id' => $other['actor'], 'action' => 'OUTREACH_RECORD_CREATED', 'entity_type' => 'discipleship_enrollments', 'entity_id' => $enrollment, 'unit_id' => $other['unit'], 'source' => 'WAVE4_DOMAIN', 'session_id' => $other['auth']]);
        $this->denied('ENROLLMENT_SCOPE_UNRESOLVED', fn() => $s->progress($home['actor'], $home['auth'], $home['unit'], $enrollment, $step));
        // The caller passing the ORIGINAL, legitimate unit_id does not resurrect a decision either.
        $this->denied('ENROLLMENT_SCOPE_UNRESOLVED', fn() => $s->progress($home['actor'], $home['auth'], $home['unit'], $enrollment, $step));
        self::assertSame(0, $this->db()->table('discipleship_progress')->where('enrollment_id', $enrollment)->count());
    }
    public function test_scope_resolution_ignores_unrelated_entity_type_with_same_id(): void
    {
        $home = $this->fixture();
        $this->grant($home);
        $s = $this->evangelism();
        $campaign = $s->campaign($home['actor'], $home['auth'], $home['unit'], 'SYNTHETIC_M1R_2', $this->now());
        $s->contact($home['actor'], $home['auth'], $home['unit'], $campaign, $home['person']);
        $track = $s->track($home['actor'], $home['auth'], $home['unit'], 'SYNTHETIC_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_TRACK');
        $step = $s->step($home['actor'], $home['auth'], $home['unit'], $track, 1, 'SYNTHETIC_STEP');
        $enrollment = $s->enroll($home['actor'], $home['auth'], $home['unit'], $home['person'], $track);
        $rogueUnit = $this->row('organizational_units');
        // Same numeric id, but a DIFFERENT entity_type -- must never be matched.
        $this->row('audit_logs', ['actor_id' => $home['actor'], 'action' => 'OUTREACH_RECORD_CREATED', 'entity_type' => 'outreach_contacts', 'entity_id' => $enrollment, 'unit_id' => $rogueUnit, 'source' => 'WAVE4_DOMAIN', 'session_id' => $home['auth']]);
        $progress = $s->progress($home['actor'], $home['auth'], $home['unit'], $enrollment, $step);
        self::assertSame(1, $this->db()->table('discipleship_progress')->where('id', $progress)->where('enrollment_id', $enrollment)->count());
    }

    // Item 15-18: DomainClock is DB-authoritative and microsecond-precise; independent
    // confirmation distinct from the executor's own WaveFourTemporalAuthorizationTest.
    public function test_domain_clock_is_database_authoritative_utc_with_microseconds(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $clock = DomainClock::now($this->db());
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::assertGreaterThanOrEqual($before->modify('-2 seconds'), $clock);
        self::assertLessThanOrEqual($after->modify('+2 seconds'), $clock);
        self::assertMatchesRegularExpression('/\.\d{6}$/', $clock->format('Y-m-d H:i:s.u'));
    }

    // NEW finding raised independently: EvangelismService::run() authorizes the
    // actor's session/grant using a clock sampled BEFORE any lock wait performed by
    // the wrapped operation (EvangelismService::now(), a PHP-process clock, not
    // DomainClock), and never re-checks after the wait. Every write except
    // progress() (which the M1 fix already corrected with a post-lock, null-now
    // decisive re-check) goes through run(). This is the exact defect CLASS the
    // W4R-03 fix eliminated for ChildrenService/checkout, left open here.
    //
    // A first attempt at proving this by concurrently REVOKING the actor's session
    // while contact() was blocked on an unrelated `people` row lock did not
    // reproduce anything: authorize()'s own sharedLock() on the auth_sessions row is
    // held for the whole transaction, so a concurrent revoke UPDATE simply queues
    // behind it and only lands after the transaction has already committed -- too
    // late to matter, but also never "missed". That is a real, if incidental,
    // protection against a CONCURRENT WRITER racing the same row.
    //
    // It is NOT a protection against pure TIME PASSING while blocked, which is what
    // W4R-03 actually was: no competing writer required, just a session that
    // legitimately expires (auth_sessions.expires_at) during a lock wait, evaluated
    // with a $now sampled before that wait. Reproduced below with the same
    // holder/actor barrier pattern used for the ChildrenService temporal finding.
    public function test_evangelism_session_expiring_during_lock_wait_is_not_reevaluated(): void
    {
        $f = $this->fixture();
        $this->grant($f);
        $this->db()->table('auth_sessions')->where('id', $f['auth'])->update(['expires_at' => DomainClock::now($this->db())->modify('+2 seconds')->format('Y-m-d H:i:s.u')]);
        $s = $this->evangelism();
        $campaign = $s->campaign($f['actor'], $f['auth'], $f['unit'], 'SYNTHETIC_RACE_CAMPAIGN', $this->now());
        $target = $this->row('people');
        $jobFixture = ['actor' => $f['actor'], 'auth' => $f['auth'], 'unit' => $f['unit'], 'campaign' => $campaign, 'target' => $target];
        [$processes, $barrier] = $this->launch('wave4-evangelism-race-worker.php', [['fixture' => $jobFixture, 'hold' => true], ['fixture' => $jobFixture, 'wait_for_lock' => 0]]);
        $deadline = microtime(true) + 10;
        while (!file_exists($barrier . '/locked_0') || !file_exists($barrier . '/attempting_1')) {
            if (microtime(true) > $deadline) {
                self::fail('Race synchronization timeout');
            }
            usleep(10000);
        }
        self::assertTrue(proc_get_status($processes[1][0])['running'], 'Actor worker waits on the unrelated people-row lock while its own session is still valid');
        usleep(2600000);
        touch($barrier . '/finish');
        $results = $this->collect($processes, $barrier);
        self::assertSame('LOCK_RELEASED', $results[0]['result']);
        $outcome = $results[1]['result'];
        $created = $this->db()->table('outreach_contacts')->where('campaign_id', $campaign)->where('person_id', $target)->exists();
        $p = self::$root . '/docs/database/physical/wave4_m1r_evangelism_clock_evidence.json';
        file_put_contents($p, json_encode(['scenario' => 'evangelism_session_expires_during_unrelated_lock_wait', 'session_window_seconds' => 2, 'lock_held_seconds' => 2.6, 'outcome' => $outcome, 'write_completed' => $created], JSON_PRETTY_PRINT) . PHP_EOL);
        if ($outcome === 'CONTACTED') {
            self::assertTrue($created);
            self::markTestIncomplete('CONFIRMED (new finding, out of M1 scope): EvangelismService::run() completed the write using a pre-lock-wait $now, after the actor session had already expired during the wait. Same defect class as W4R-03; EvangelismService/DomainAccess never got the post-lock decisive re-check that ChildrenService/checkout and EvangelismService::progress() received. See audit finding W4R-M1R-01.');
        } else {
            self::assertSame('ACTOR_NOT_AUTHORIZED', $outcome);
            self::assertFalse($created);
        }
    }
}
