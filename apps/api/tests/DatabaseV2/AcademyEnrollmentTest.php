<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFiveCase.php';

use App\Domain\Academy\AcademyError;
use App\Domain\Academy\AcademyReason;
use App\Domain\Academy\AcademyRuntime;
use App\Domain\Academy\AcademyTarget;
use App\Domain\Academy\EnrollmentService;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

/**
 * Enrollment (C1, C2, C7), idempotency/duplicate convergence (sequential half of C4/E1), child safety
 * matrix (A4, mission section 74), lifecycle guards (D-11) and bounded listing.
 */
final class AcademyEnrollmentTest extends PooledWaveFiveCase
{
    private function enroller(array $w): array
    {
        return $this->actor($w['unit'], ['ACADEMY_ENROLL', 'ACADEMY_VIEW']);
    }

    private function enroll(array $w, array $actor, int|string $person): array
    {
        return (new EnrollmentService($this->rt()))->enroll($actor['user'], $actor['session'], $w['class'], $person);
    }

    // C1
    public function test_c1_external_student_without_membership_enrolls(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $person = $this->row('people');
        self::assertSame(0, (int) $this->db()->table('memberships')->where('person_id', $person)->count());
        $result = $this->enroll($w, $actor, $person);
        self::assertSame('CREATED', $result['outcome']);
        self::assertSame(0, (int) $this->db()->table('memberships')->where('person_id', $person)->count(), 'Enrollment never creates a membership');
        self::assertSame(0, (int) $this->db()->table('member_numbers')->count(), 'No member number is ever issued for a student');
        $row = $this->db()->table('enrollments')->where('id', $result['enrollment_id'])->first();
        self::assertSame('S_ENR_PENDING', $row->status, 'Initial state comes from policy, not from code');
        self::assertNull($row->approved_by);
    }

    // C2
    public function test_c2_existing_person_is_reused_by_id_or_public_id_and_never_duplicated(): void
    {
        $w = $this->world();
        $w2 = $this->world();
        $actor = $this->enroller($w);
        $actor2 = $this->enroller($w2);
        $person = $this->row('people');
        $publicId = (string) $this->db()->table('people')->where('id', $person)->value('public_id');
        $peopleBefore = (int) $this->db()->table('people')->count();
        $first = $this->enroll($w, $actor, $person);
        $second = (new EnrollmentService($this->rt()))->enroll($actor2['user'], $actor2['session'], $w2['class'], $publicId);
        self::assertSame($person, $first['person_id']);
        self::assertSame($person, $second['person_id'], 'A ULID public id resolves to the same people.id');
        self::assertSame($peopleBefore, (int) $this->db()->table('people')->count(), '0 new Person rows');
        self::assertSame(2, (int) $this->db()->table('enrollments')->where('person_id', $person)->count());
    }

    public function test_member_number_is_never_an_academic_identifier(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $this->denied(AcademyReason::INVALID_PERSON_REFERENCE, fn () => $this->enroll($w, $actor, 'MEPA2609000001'));
        $this->denied(AcademyReason::INVALID_PERSON_REFERENCE, fn () => $this->enroll($w, $actor, 'not-a-person'));
        $this->denied(AcademyReason::TARGET_NOT_FOUND, fn () => $this->enroll($w, $actor, '01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        self::assertSame(0, (int) $this->db()->table('enrollments')->where('class_id', $w['class'])->count());
    }

    // C4 (sequential half; the concurrent half is AcademyConcurrencyTest::test_e1_*)
    public function test_c4_sequential_duplicate_enrollment_converges_on_already_enrolled_with_one_row(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $person = $this->row('people');
        $first = $this->enroll($w, $actor, $person);
        $error = $this->denied(AcademyReason::ALREADY_ENROLLED, fn () => $this->enroll($w, $actor, $person));
        self::assertSame($first['enrollment_id'], $error->context['enrollment_id']);
        self::assertSame(1, (int) $this->db()->table('enrollments')->where('class_id', $w['class'])->where('person_id', $person)->count());
        self::assertSame(1, (int) $this->db()->table('audit_logs')->where('entity_type', 'enrollments')->where('action', 'enrollment.created')->where('entity_id', $first['enrollment_id'])->count());
    }

    public function test_database_unique_violation_is_translated_never_leaked(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $person = $this->row('people');
        $this->enroll($w, $actor, $person);
        $rt = $this->rt();
        // Bypass the application pre-check on purpose: the UNIQUE index alone must produce the domain error.
        $error = $this->denied(AcademyReason::ALREADY_ENROLLED, fn () => $rt->write(
            'enrollment.create',
            $actor['user'],
            $actor['session'],
            fn () => $rt->scope->forClass($w['class']),
            fn ($d, AcademyTarget $t) => $rt->db->table('enrollments')->insert(['public_id' => (string) \Illuminate\Support\Str::ulid(), 'person_id' => $person, 'class_id' => $w['class'], 'enrolled_at' => AcademyRuntime::ts($rt->now()), 'status' => 'X', 'created_at' => AcademyRuntime::ts($rt->now())]),
            null,
            null,
            AcademyReason::ALREADY_ENROLLED
        ));
        self::assertNotNull($error->getPrevious(), 'The real database error stays reachable');
        self::assertSame(1062, $error->context['db_error_code']);
        self::assertSame(1, (int) $this->db()->table('enrollments')->where('class_id', $w['class'])->where('person_id', $person)->count());
    }

    public function test_enrollment_authorization_is_derived_from_the_persisted_class(): void
    {
        $w = $this->world();
        $other = $this->world();
        $actor = $this->enroller($other);
        $person = $this->row('people');
        // Caller claims its own authorized unit while targeting a class of another unit.
        $svc = new EnrollmentService($this->rt());
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->enroll($actor['user'], $actor['session'], $w['class'], $person, ['academic_unit_id' => $other['academicUnit'], 'unit_id' => $other['unit']]));
        self::assertSame(0, (int) $this->db()->table('enrollments')->where('class_id', $w['class'])->count());
    }

    public function test_enrollment_is_refused_unless_the_class_state_is_configured_enrollable(): void
    {
        $w = $this->world(['status' => 'S_CLS_DRAFT']);
        $actor = $this->enroller($w);
        $this->denied(AcademyReason::CLASS_NOT_ENROLLABLE, fn () => $this->enroll($w, $actor, $this->row('people')));
        // With no state policy at all (the production default while D-11 is open) it fails closed.
        $open = $this->world();
        $actor2 = $this->enroller($open);
        $empty = $this->rt(\App\Domain\Academy\AcademyPolicy::fromConfig(require self::$root . '/apps/api/config/academy.php'));
        $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => (new EnrollmentService($empty))->enroll($actor2['user'], $actor2['session'], $open['class'], $this->row('people')));
        self::assertSame(0, (int) $this->db()->table('enrollments')->where('class_id', $open['class'])->count());
    }

    public function test_ineligible_person_cannot_be_enrolled(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $person = $this->row('people');
        $this->db()->table('people')->where('id', $person)->update(['archived_at' => $this->later('-1 hour')]);
        $this->denied(AcademyReason::PERSON_NOT_ELIGIBLE, fn () => $this->enroll($w, $actor, $person));
    }

    // ---- lifecycle (D-11): only APPROVED transitions execute -------------------------------------

    public function test_only_approved_transitions_execute_and_pending_or_unknown_ones_are_denied(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $svc = new EnrollmentService($this->rt());
        $id = $this->enroll($w, $actor, $this->row('people'))['enrollment_id'];
        // PENDING -> ACTIVE is approved and (by the configured approval role) records the approver.
        $moved = $svc->transition($actor['user'], $actor['session'], $id, 'S_ENR_ACTIVE', 'ok');
        self::assertSame(['S_ENR_PENDING', 'S_ENR_ACTIVE'], [$moved['from'], $moved['to']]);
        self::assertSame($actor['user'], (int) $this->db()->table('enrollments')->where('id', $id)->value('approved_by'));
        // ACTIVE -> FAILED exists only as a PENDING (not approved) transition: explicit denial, no state change.
        $this->denied(AcademyReason::STATE_POLICY_PENDING, fn () => $svc->transition($actor['user'], $actor['session'], $id, 'S_ENR_FAILED', 'x'));
        // A transition nobody proposed is INVALID; a no-op is INVALID too.
        $this->denied(AcademyReason::INVALID_TRANSITION, fn () => $svc->transition($actor['user'], $actor['session'], $id, 'S_ENR_INVENTED', 'x'));
        $this->denied(AcademyReason::INVALID_TRANSITION, fn () => $svc->transition($actor['user'], $actor['session'], $id, 'S_ENR_ACTIVE', 'x'));
        self::assertSame('S_ENR_ACTIVE', $this->db()->table('enrollments')->where('id', $id)->value('status'));
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'enrollments')->where('entity_id', $id)->where('action', 'enrollment.transitioned')->get();
        self::assertCount(1, $audit, 'Denied transitions leave no misleading success audit');
        self::assertSame('S_ENR_PENDING', json_decode($audit[0]->before_metadata, true)['status']);
    }

    // A10
    public function test_a10_with_no_approved_transitions_nothing_executes(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $noneApproved = $this->rt($this->academyPolicy(function (array $c) {
            $c['transitions']['approved'] = [];
            return $c;
        }));
        $svc = new EnrollmentService($noneApproved);
        $id = $svc->enroll($actor['user'], $actor['session'], $w['class'], $this->row('people'))['enrollment_id'];
        foreach (['S_ENR_ACTIVE', 'S_ENR_COMPLETED', 'S_ENR_WITHDRAWN', 'S_ENR_FAILED'] as $state) {
            $error = $this->caught(fn () => $svc->transition($actor['user'], $actor['session'], $id, $state));
            self::assertContains($error->reason, [AcademyReason::INVALID_TRANSITION, AcademyReason::STATE_POLICY_PENDING]);
        }
        self::assertSame('S_ENR_PENDING', $this->db()->table('enrollments')->where('id', $id)->value('status'));
    }

    public function test_stale_transition_is_rejected_by_lock_version(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $svc = new EnrollmentService($this->rt());
        $id = $this->enroll($w, $actor, $this->row('people'))['enrollment_id'];
        $version = (int) $this->db()->table('enrollments')->where('id', $id)->value('lock_version');
        $svc->transition($actor['user'], $actor['session'], $id, 'S_ENR_ACTIVE', null, $version);
        $this->denied(AcademyReason::STALE_WRITE, fn () => $svc->transition($actor['user'], $actor['session'], $id, 'S_ENR_WITHDRAWN', 'late', $version));
        self::assertSame('S_ENR_ACTIVE', $this->db()->table('enrollments')->where('id', $id)->value('status'));
    }

    // ---- child safety (Wave 4 gate): C7, A4, mission section 74 ----------------------------------

    public function test_adult_or_non_child_normal_flow_passes_without_any_child_records(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        self::assertSame('CREATED', $this->enroll($w, $actor, $this->row('people'))['outcome']);
    }

    // C7
    public function test_c7_minor_without_authorization_is_denied_and_nothing_is_stored(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $staff = $this->actor($w['unit'], ['CHILD_WRITE'], false, 'CHILDREN');
        $child = $this->row('people');
        (new \App\Domain\WaveFour\ChildrenService($this->db(), self::childPolicy(), self::eventPolicy()))->register($staff['user'], $staff['session'], $child, $w['unit']);
        $before = (int) $this->db()->table('audit_logs')->count();
        $this->denied(AcademyReason::CONSENT_REQUIRED, fn () => $this->enroll($w, $actor, $child));
        self::assertSame(0, (int) $this->db()->table('enrollments')->where('person_id', $child)->count());
        self::assertSame($before, (int) $this->db()->table('audit_logs')->count(), 'No success audit for a denied operation');
    }

    public function test_minor_with_valid_consent_and_guardian_authorization_is_enrolled(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);
        $c = $this->child($w['unit']);
        self::assertSame('CREATED', $this->enroll($w, $actor, $c['child'])['outcome']);
        self::assertSame(0, (int) $this->db()->table('memberships')->where('person_id', $c['child'])->count());
    }

    // A4
    public function test_a4_revoked_expired_or_withdrawn_cover_blocks_enrollment(): void
    {
        $w = $this->world();
        $actor = $this->enroller($w);

        $revoked = $this->child($w['unit']);
        $revoked['children']->revokeAuthorization($revoked['staff']['user'], $revoked['staff']['session'], $revoked['authorization'], 'revoked by guardian');
        $this->denied(AcademyReason::GUARDIAN_AUTHORIZATION_INVALID, fn () => $this->enroll($w, $actor, $revoked['child']));

        $expired = $this->child($w['unit']);
        $this->db()->table('guardian_authorizations')->where('id', $expired['authorization'])->update(['ends_at' => $this->later('-1 minute')]);
        $this->denied(AcademyReason::GUARDIAN_AUTHORIZATION_INVALID, fn () => $this->enroll($w, $actor, $expired['child']));

        $noConsent = $this->child($w['unit']);
        $noConsent['children']->revokeConsent($noConsent['staff']['user'], $noConsent['staff']['session'], $noConsent['consent']);
        $this->denied(AcademyReason::CONSENT_REQUIRED, fn () => $this->enroll($w, $actor, $noConsent['child']));

        $stalePolicy = $this->child($w['unit']);
        $this->db()->table('person_consents')->where('id', $stalePolicy['consent'])->update(['policy_version' => 'SYNTHETIC_OLD']);
        $this->denied(AcademyReason::CONSENT_REQUIRED, fn () => $this->enroll($w, $actor, $stalePolicy['child']));

        foreach ([$revoked, $expired, $noConsent, $stalePolicy] as $c) {
            self::assertSame(0, (int) $this->db()->table('enrollments')->where('person_id', $c['child'])->count());
        }
    }

    // ---- listing ---------------------------------------------------------------------------------

    public function test_listing_is_scoped_filtered_and_paginated(): void
    {
        $w = $this->world();
        $other = $this->world();
        $actor = $this->enroller($w);
        for ($i = 0; $i < 5; $i++) {
            $this->enrollment($w['class']);
        }
        $this->enrollment($other['class']);
        $svc = new EnrollmentService($this->rt());
        $page1 = $svc->listForClass($actor['user'], $actor['session'], $w['class'], null, 1, 2);
        $page3 = $svc->listForClass($actor['user'], $actor['session'], $w['class'], null, 3, 2);
        self::assertSame(5, $page1['total']);
        self::assertCount(2, $page1['items']);
        self::assertCount(1, $page3['items']);
        self::assertSame(100, $svc->listForClass($actor['user'], $actor['session'], $w['class'], null, 1, 100000)['per_page'], 'Page size is bounded');
        self::assertSame(0, $svc->listForClass($actor['user'], $actor['session'], $w['class'], 'S_NO_SUCH_STATUS')['total']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->listForClass($actor['user'], $actor['session'], $other['class']));
        foreach ($page1['items'] as $item) {
            self::assertArrayNotHasKey('full_name', $item);
        }
    }

    private function caught(callable $fn): AcademyError
    {
        try {
            $fn();
        } catch (AcademyError $e) {
            return $e;
        }
        self::fail('Expected an AcademyError');
    }
}
