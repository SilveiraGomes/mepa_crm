<?php

declare (strict_types=1);
namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFourCase.php';
use Tests\DatabaseV2\Support\PooledWaveFourCase;
use App\Domain\Events\CheckinService;
use App\Domain\Events\EventError;

/**
 * P0-TI.1 Test Infrastructure V2 pilot. Ported from
 * apps/api/tests/Database/WaveFourChildCheckinBoundaryTest.php (unmodified)
 * -- only the "child safety generic-path rejection" target named in the
 * P0-TI.1 spec (the literal W4R-01 reproduction/regression test),
 * byte-identical body, running against a pooled/reset database instead of a
 * per-run CREATE/DROP schema.
 */
final class PooledChildCheckinBoundaryTest extends PooledWaveFourCase
{
    private function otherActor(array $f, bool $withChildren): array
    {
        $person = $this->row('people');
        $actor = $this->row('users', ['person_id' => $person]);
        $auth = $this->row('auth_sessions', ['user_id' => $actor]);
        $scope = $this->row('scopes', ['unit_id' => $f['unit'], 'include_descendants' => 0]);
        $role = $this->row('roles');
        $codes = ['CHECKIN' => 'EVENTS', 'CHECKIN_MANUAL' => 'EVENTS'];
        if ($withChildren) {
            $codes['CHILD_CHECKIN'] = 'CHILDREN';
        }
        foreach ($codes as $code => $type) {
            $permission = (int) $this->db()->table('permissions')->where('code', $code)->where('data_type', $type)->value('id');
            if (!$permission) {
                $permission = $this->row('permissions', ['code' => $code, 'action' => $code, 'data_type' => $type]);
            }
            $this->row('role_permissions', ['role_id' => $role, 'permission_id' => $permission]);
        }
        $this->row('user_role_scopes', ['user_id' => $actor, 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $f['actor']]);
        return ['actor' => $actor, 'auth' => $auth];
    }
    private function assertNoWrites(array $f): void
    {
        self::assertSame(0, $this->db()->table('event_checkins')->where('session_id', $f['session'])->where('person_id', $f['child'])->count());
        self::assertSame(0, $this->db()->table('event_attendance')->where('session_id', $f['session'])->where('person_id', $f['child'])->count());
        self::assertSame(0, $this->db()->table('child_custody_visits')->where('session_id', $f['session'])->where('child_person_id', $f['child'])->count());
    }
    // Preserves the exact W4R-01 reproduction: revoked consent + actor without CHILDREN,
    // calling CheckinService directly. Must now be DENIED with zero durable writes.
    public function test_generic_service_direct_call_reproduces_original_bypass_scenario_and_is_now_denied(): void
    {
        $f = $this->childFixture(false);
        $this->children()->revokeConsent($f['actor'], $f['auth'], $f['consent']);
        $stranger = $this->otherActor($f, false);
        try {
            (new CheckinService($this->db(), self::policy()))->scan($stranger['actor'], $stranger['auth'], $f['device'], $f['event'], $f['session'], $f['child'], $f['credential']['token'], 'bypass-w4r-01', $this->now());
            self::fail('Expected denial reproducing W4R-01');
        } catch (EventError $e) {
            self::assertSame('CHILD_SAFETY_FLOW_REQUIRED', $e->reason);
        }
        $this->assertNoWrites($f);
    }
}
