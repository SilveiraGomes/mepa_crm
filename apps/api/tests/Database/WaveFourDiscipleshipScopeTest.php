<?php

declare (strict_types=1);
namespace Tests\Database;

require_once __DIR__ . '/Support/WaveFourCase.php';
use Tests\Database\Support\WaveFourCase;
// P0.3.4-M1: closes W4R-02 (MEDIUM). discipleship_enrollments has no owner_unit
// column in the approved catalogue, so EvangelismService::progress() must resolve
// the enrollment's true owning unit from its creation provenance (audit_logs) via
// DiscipleshipEnrollmentScope, instead of trusting the caller-supplied unit_id.
final class WaveFourDiscipleshipScopeTest extends WaveFourCase
{
    private function unitFixture(): array
    {
        $f = $this->fixture();
        $this->grant($f);
        return $f;
    }
    public function test_actor_scoped_to_a_different_unit_cannot_progress_an_enrollment_it_does_not_own(): void
    {
        $s = $this->evangelism();
        $home = $this->unitFixture();
        $other = $this->unitFixture();
        $campaign = $s->campaign($home['actor'], $home['auth'], $home['unit'], 'SYNTHETIC_HOME_CAMPAIGN', $this->now());
        $s->contact($home['actor'], $home['auth'], $home['unit'], $campaign, $home['person']);
        $track = $s->track($home['actor'], $home['auth'], $home['unit'], 'SYNTHETIC_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_TRACK');
        $step = $s->step($home['actor'], $home['auth'], $home['unit'], $track, 1, 'SYNTHETIC_STEP');
        $enrollment = $s->enroll($home['actor'], $home['auth'], $home['unit'], $home['person'], $track);
        // The same Person also becomes a contact in another unit's campaign. Cross-domain
        // reuse of the identity is legitimate; it must not extend authority over the enrollment.
        $foreignCampaign = $s->campaign($other['actor'], $other['auth'], $other['unit'], 'SYNTHETIC_FOREIGN_CAMPAIGN', $this->now());
        $s->contact($other['actor'], $other['auth'], $other['unit'], $foreignCampaign, $home['person']);
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $s->progress($other['actor'], $other['auth'], $other['unit'], $enrollment, $step));
        self::assertSame(0, $this->db()->table('discipleship_progress')->where('enrollment_id', $enrollment)->count());
        // Passing the true owning unit_id as a parameter does not help either: the
        // service resolves scope from provenance, not from the caller-supplied value.
        $this->denied('ACTOR_NOT_AUTHORIZED', fn() => $s->progress($other['actor'], $other['auth'], $home['unit'], $enrollment, $step));
        self::assertSame(0, $this->db()->table('discipleship_progress')->where('enrollment_id', $enrollment)->count());
    }
    public function test_actor_scoped_to_the_owning_unit_can_progress_the_enrollment(): void
    {
        $s = $this->evangelism();
        $home = $this->unitFixture();
        $campaign = $s->campaign($home['actor'], $home['auth'], $home['unit'], 'SYNTHETIC_HOME_CAMPAIGN', $this->now());
        $s->contact($home['actor'], $home['auth'], $home['unit'], $campaign, $home['person']);
        $track = $s->track($home['actor'], $home['auth'], $home['unit'], 'SYNTHETIC_' . bin2hex(random_bytes(8)), 1, 'SYNTHETIC_TRACK');
        $step = $s->step($home['actor'], $home['auth'], $home['unit'], $track, 1, 'SYNTHETIC_STEP');
        $enrollment = $s->enroll($home['actor'], $home['auth'], $home['unit'], $home['person'], $track);
        $progress = $s->progress($home['actor'], $home['auth'], $home['unit'], $enrollment, $step);
        self::assertSame($progress, $s->progress($home['actor'], $home['auth'], $home['unit'], $enrollment, $step));
        self::assertSame(1, $this->db()->table('discipleship_progress')->where('enrollment_id', $enrollment)->where('step_id', $step)->count());
    }
}
