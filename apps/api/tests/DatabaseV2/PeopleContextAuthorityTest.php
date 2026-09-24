<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\People\PeopleAuthority;
use App\Domain\People\PeopleCatalog;
use App\Domain\People\PeopleError;
use App\Domain\People\PeopleReason;
use App\Http\People\PeopleServiceFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseV2\Support\PeopleHttpCase;

/**
 * P0.5-R1 (P05R-F01 / P05R-E01): Event registrations and Academy enrollments authorize a People context only
 * while their own domain says so. The vocabulary is the explicit synthetic test policy of config/academy_e2e.php
 * (Events access policy + Academy policy), loaded here as the server configuration; People never names a state.
 */
final class PeopleContextAuthorityTest extends PeopleHttpCase
{
    private const ALL = ['PEOPLE_VIEW', 'PEOPLE_CREATE', 'PEOPLE_EDIT', 'PEOPLE_SENSITIVE_VIEW', 'PEOPLE_CONTACT_MANAGE', 'PEOPLE_ADDRESS_MANAGE', 'HOUSEHOLD_VIEW', 'HOUSEHOLD_MANAGE', 'RELATIONSHIP_MANAGE', 'PEOPLE_EXPORT'];

    private array $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = require base_path('config/academy_e2e.php');
        config(['academy_http.access_policy' => $this->policy['access_policy'], 'academy' => $this->policy['academy']]);
        // The non-authorizing values used below are, by the policy's own definition, outside its permitted sets.
        self::assertNotContains('SYNTHETIC_CANCELLED', $this->policy['access_policy']['states']['registrations']);
        self::assertNotContains('SYNTHETIC_CLOSED', $this->policy['access_policy']['states']['events']);
    }

    // Domain vocabulary, read from the Events / Academy policy under test (never hardcoded in People).
    private function registrationValid(): string
    {
        return $this->policy['access_policy']['states']['registrations'][0];
    }

    private function eventValid(): string
    {
        return $this->policy['access_policy']['states']['events'][0];
    }

    private function enrollmentOperational(): string
    {
        return $this->policy['academy']['states']['enrollments']['sets']['operational'][0];
    }

    /** Enrollment states the Academy policy knows (initial + transition targets) that are NOT operational. */
    private function enrollmentNonOperational(): array
    {
        $enrollments = $this->policy['academy']['states']['enrollments'];
        $known = [$enrollments['initial']];
        foreach ($this->policy['academy']['transitions']['approved']['enrollments'] as [$from, $to]) {
            $known[] = $from;
            $known[] = $to;
        }
        $out = array_values(array_diff(array_unique($known), $enrollments['sets']['operational']));
        self::assertNotEmpty($out);
        return $out;
    }

    /** A Person whose ONLY context is one event registration at an event owned by $unit. */
    private function registrant(int $unit, string $name, ?string $registration = null, ?string $event = null, ?string $version = null): array
    {
        $person = $this->bare($name);
        $eventId = $this->row('events', ['owner_unit_id' => $unit, 'status' => $event ?? $this->eventValid(), 'eligibility_policy_version' => $version ?? $this->policy['access_policy']['version']]);
        $person['registration'] = $this->row('event_registrations', ['event_id' => $eventId, 'person_id' => $person['id'], 'status' => $registration ?? $this->registrationValid()]);
        $person['event'] = $eventId;
        return $person;
    }

    /** A Person whose ONLY context is one Academy enrollment in a class of $unit. */
    private function student(int $unit, string $name, ?string $status = null): array
    {
        $person = $this->bare($name);
        $academic = $this->row('academic_units', ['unit_id' => $unit]);
        $class = $this->row('classes', ['academic_unit_id' => $academic]);
        $person['enrollment'] = $this->row('enrollments', ['person_id' => $person['id'], 'class_id' => $class, 'status' => $status ?? $this->enrollmentOperational()]);
        return $person;
    }

    private function bare(string $name): array
    {
        $status = (int) DB::table('person_statuses')->where('code', 'ACTIVE')->value('id');
        $id = $this->row('people', ['full_name' => $name, 'status_id' => $status, 'birth_precision' => 'UNKNOWN', 'lock_version' => 0]);
        return ['id' => $id, 'public_id' => (string) DB::table('people')->where('id', $id)->value('public_id')];
    }

    private function listed(array $staff, string $search): array
    {
        return array_column($this->api($staff, 'GET', 'people?search=' . urlencode($search) . '&per_page=100')->assertOk()->json('data'), 'public_id');
    }

    private function exported(array $staff, string $search): string
    {
        return (string) $this->api($staff, 'POST', 'people/exports', ['class' => 'STANDARD', 'search' => $search])->assertCreated()->json('data.csv');
    }

    private function assertConcealed(array $staff, string $publicId): void
    {
        foreach ([
            $this->api($staff, 'GET', 'people/' . $publicId),
            $this->api($staff, 'PATCH', 'people/' . $publicId, ['full_name' => 'Nunca Escrito', 'lock_version' => 0]),
            $this->api($staff, 'GET', 'people/' . $publicId . '/contacts'),
            $this->api($staff, 'POST', 'people/' . $publicId . '/inactivate', ['lock_version' => 0]),
        ] as $response) {
            $response->assertStatus(404);
            self::assertSame($this->notFoundBody(), $response->json());
        }
    }

    // CTX-E1
    public function test_ctx_e1_valid_event_registration_grants_general_context(): void
    {
        $unit = $this->unit();
        $staff = $this->staff(self::ALL, $unit);
        $p = $this->registrant($unit, 'Ctx E1 Inscrito');
        $this->api($staff, 'GET', 'people/' . $p['public_id'])->assertOk()->assertJsonPath('data.projection', 'COMMON');
        self::assertSame([$p['public_id']], $this->listed($staff, 'Ctx E1'));
        self::assertStringContainsString($p['public_id'], $this->exported($staff, 'Ctx E1'));
        $this->api($staff, 'PATCH', 'people/' . $p['public_id'], ['full_name' => 'Ctx E1 Editado', 'lock_version' => 0])->assertOk();
        $audit = DB::table('audit_logs')->where('source', 'P05_PEOPLE')->where('entity_type', 'people')->where('entity_id', $p['id'])->orderByDesc('id')->first();
        self::assertSame($unit, (int) $audit->unit_id, 'audit unit is the event owner unit that authorized');
    }

    // CTX-E2
    public function test_ctx_e2_cancelled_event_registration_no_longer_grants_context(): void
    {
        $unit = $this->unit();
        $staff = $this->staff(self::ALL, $unit);
        $p = $this->registrant($unit, 'Ctx E2 Cancelado');
        $this->api($staff, 'GET', 'people/' . $p['public_id'])->assertOk();
        // Cancellation: a state the Events policy does not permit (CheckinService => REGISTRATION_CANCELLED).
        DB::table('event_registrations')->where('id', $p['registration'])->update(['status' => 'SYNTHETIC_CANCELLED']);
        $this->assertConcealed($staff, $p['public_id']);
        self::assertSame([], $this->listed($staff, 'Ctx E2'));
        self::assertStringNotContainsString($p['public_id'], $this->exported($staff, 'Ctx E2'));
        self::assertSame('Ctx E2 Cancelado', DB::table('people')->where('id', $p['id'])->value('full_name'));
    }

    // CTX-E2 (event side): the event itself leaves the Events-permitted states, or belongs to another policy version.
    public function test_ctx_e2_event_not_permitted_or_other_policy_version_does_not_grant_context(): void
    {
        $unit = $this->unit();
        $staff = $this->staff(self::ALL, $unit);
        $closed = $this->registrant($unit, 'Ctx E2b Evento Fechado');
        DB::table('events')->where('id', $closed['event'])->update(['status' => 'SYNTHETIC_CLOSED']);
        $foreignVersion = $this->registrant($unit, 'Ctx E2b Outra Versao', null, null, 'SYNTHETIC_OTHER_POLICY');
        $this->assertConcealed($staff, $closed['public_id']);
        $this->assertConcealed($staff, $foreignVersion['public_id']);
        self::assertSame([], $this->listed($staff, 'Ctx E2b'));
    }

    // CTX-A1
    public function test_ctx_a1_operational_enrollment_grants_academy_context(): void
    {
        $unit = $this->unit();
        $staff = $this->staff(self::ALL, $unit);
        $p = $this->student($unit, 'Ctx A1 Aluno');
        $detail = $this->api($staff, 'GET', 'people/' . $p['public_id'])->assertOk()->json('data');
        self::assertSame('MINIMAL', $detail['projection']);
        self::assertFalse($detail['capabilities']['can_edit']);
        self::assertSame([], $this->listed($staff, 'Ctx A1'), 'Academy contexts never enter the People directory');
    }

    // CTX-A2
    public function test_ctx_a2_enrollment_that_becomes_non_operational_no_longer_grants_context(): void
    {
        $unit = $this->unit();
        $staff = $this->staff(self::ALL, $unit);
        $p = $this->student($unit, 'Ctx A2 Aluno');
        $this->api($staff, 'GET', 'people/' . $p['public_id'])->assertOk();
        foreach ($this->enrollmentNonOperational() as $state) {
            DB::table('enrollments')->where('id', $p['enrollment'])->update(['status' => $state]);
            $response = $this->api($staff, 'GET', 'people/' . $p['public_id']);
            $response->assertStatus(404);
            self::assertSame($this->notFoundBody(), $response->json(), 'non-operational enrollment state ' . $state);
        }
        DB::table('enrollments')->where('id', $p['enrollment'])->update(['status' => $this->enrollmentOperational()]);
        $this->api($staff, 'GET', 'people/' . $p['public_id'])->assertOk();
    }

    // D-11 open: without an Academy / Events policy nothing is operational or permitted, so nothing authorizes.
    public function test_unconfigured_domain_policies_fail_closed(): void
    {
        $unit = $this->unit();
        $staff = $this->staff(self::ALL, $unit);
        $student = $this->student($unit, 'Ctx Sem Politica Aluno');
        $registrant = $this->registrant($unit, 'Ctx Sem Politica Inscrito');
        config(['academy' => ['policy_version' => null, 'states' => [], 'transitions' => ['approved' => [], 'pending' => []]], 'academy_http.access_policy' => ['version' => null, 'states' => [], 'credential_type_ids' => []]]);
        $this->assertConcealed($staff, $student['public_id']);
        $this->assertConcealed($staff, $registrant['public_id']);
    }

    // CTX-R1: authority read, then a CONCURRENT committed transaction cancels the registration / withdraws the
    // enrollment, then the People operation reaches its final locking re-check: denied and rolled back.
    public function test_ctx_r1_source_state_change_between_read_and_commit_is_denied(): void
    {
        config(['database.connections.ctx_race' => config('database.connections.mysql')]);
        $race = DB::connection('ctx_race');
        $unit = $this->unit();
        $staff = $this->staff(self::ALL, $unit);
        $registrant = $this->registrant($unit, 'Ctx R1 Inscrito');
        $student = $this->student($unit, 'Ctx R1 Aluno');
        $cases = [
            'event' => [$registrant, PeopleCatalog::PEOPLE_EDIT, [PeopleAuthority::GENERAL], fn () => $race->table('event_registrations')->where('id', $registrant['registration'])->update(['status' => 'SYNTHETIC_CANCELLED'])],
            'academy' => [$student, PeopleCatalog::PEOPLE_VIEW, [PeopleAuthority::ACADEMY], fn () => $race->table('enrollments')->where('id', $student['enrollment'])->update(['status' => $this->enrollmentNonOperational()[0]])],
        ];
        foreach ($cases as $label => [$target, $permission, $tiers, $invalidate]) {
            // Control: without the concurrent change the same operation commits.
            $this->app->make(PeopleServiceFactory::class)->runtime()->write($staff['user'], $staff['session'], function ($guard) use ($target, $permission, $tiers): void {
                $guard->require($permission, $target['id'], null, $tiers);
                DB::table('people')->where('id', $target['id'])->update(['full_name' => 'Ctx R1 Controlo']);
            });
            self::assertSame('Ctx R1 Controlo', DB::table('people')->where('id', $target['id'])->value('full_name'), $label . ' control');
            try {
                $this->app->make(PeopleServiceFactory::class)->runtime()->write($staff['user'], $staff['session'], function ($guard) use ($target, $permission, $tiers, $invalidate): void {
                    $guard->require($permission, $target['id'], null, $tiers);
                    DB::table('people')->where('id', $target['id'])->update(['full_name' => 'Ctx R1 Nunca Confirmado']);
                    self::assertSame(1, $invalidate(), 'the concurrent transaction commits before the People commit');
                });
                self::fail($label . ': the final re-check must deny');
            } catch (PeopleError $e) {
                self::assertSame(PeopleReason::OUT_OF_SCOPE, $e->reason, $label);
                self::assertSame('final', $e->context['stage'] ?? null, $label . ': denied by the commit-time re-check');
            }
            self::assertSame('Ctx R1 Controlo', DB::table('people')->where('id', $target['id'])->value('full_name'), $label . ' rolled back');
        }
        DB::purge('ctx_race');
    }

    // CTX-F06: nonexistent, malformed, cancelled-registration-only, non-operational-enrollment-only and
    // other-policy-version targets are externally indistinguishable.
    public function test_ctx_f06_nonexistent_and_non_authorizing_context_are_indistinguishable(): void
    {
        $unit = $this->unit();
        $staff = $this->staff(self::ALL, $unit);
        $cancelled = $this->registrant($unit, 'Ctx F06 Cancelado', 'SYNTHETIC_CANCELLED');
        $withdrawn = $this->student($unit, 'Ctx F06 Retirado', $this->enrollmentNonOperational()[0]);
        $versioned = $this->registrant($unit, 'Ctx F06 Versao', null, null, 'SYNTHETIC_OTHER_POLICY');
        foreach ([(string) Str::ulid(), '123', $cancelled['public_id'], $withdrawn['public_id'], $versioned['public_id']] as $target) {
            $this->assertConcealed($staff, $target);
        }
        // 403 stays reserved for a target that IS visible through a valid context but lacks the operation permission.
        $visible = $this->registrant($unit, 'Ctx F06 Visivel');
        $this->api($this->staff(['PEOPLE_VIEW'], $unit), 'PATCH', 'people/' . $visible['public_id'], ['full_name' => 'Xy', 'lock_version' => 0])->assertStatus(403);
    }
}
