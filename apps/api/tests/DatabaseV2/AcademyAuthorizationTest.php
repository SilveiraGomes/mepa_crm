<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFiveCase.php';

use App\Domain\Academy\AcademyReason;
use App\Domain\Academy\AssessmentService;
use App\Domain\Academy\CertificateService;
use App\Domain\Academy\EnrollmentService;
use App\Domain\Academy\GradeService;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

/**
 * P0.3.5-A2 authorization matrix (mission section 72), C3, C9, A2, A3 and the sequential halves of
 * A7/A8. Authority is always derived from persisted rows: permission -> institutional scope ->
 * (class assignment). The concurrent halves live in AcademyConcurrencyTest.
 */
final class AcademyAuthorizationTest extends PooledWaveFiveCase
{
    private function grade(array $w, ?array $actor = null, mixed $score = '80', ?string $override = null, ?array $claimed = null): array
    {
        $actor ??= $w['teacher'];
        return (new GradeService($this->rt()))->record($actor['user'], $actor['session'], $w['attempt'], $score, null, $override, $claimed);
    }

    private function gradeCount(array $w): int
    {
        return (int) $this->db()->table('grades')->where('attempt_id', $w['attempt'])->count();
    }

    public function test_no_permission_denies(): void
    {
        $w = $this->gradingWorld();
        $other = $this->actor($w['unit'], ['ACADEMY_VIEW']);
        $this->assign($other, $w['class']);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $this->grade($w, $other));
        self::assertSame(0, $this->gradeCount($w));
    }

    public function test_permission_with_wrong_institutional_scope_denies(): void
    {
        $w = $this->gradingWorld();
        $elsewhere = $this->world();
        $actor = $this->actor($elsewhere['unit'], ['ACADEMY_ASSESS']);
        $this->assign($actor, $w['class']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $this->grade($w, $actor));
        self::assertSame(0, $this->gradeCount($w));
    }

    public function test_correct_scope_without_class_assignment_denies(): void
    {
        $w = $this->gradingWorld();
        $actor = $this->actor($w['unit'], ['ACADEMY_ASSESS']);
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => $this->grade($w, $actor));
        self::assertSame(0, $this->gradeCount($w));
    }

    public function test_permission_scope_and_assignment_allow_and_audit(): void
    {
        $w = $this->gradingWorld();
        $result = $this->grade($w);
        self::assertSame(1, $result['version']);
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'grades')->where('entity_id', $result['grade_id'])->where('action', 'grade.recorded')->first();
        self::assertNotNull($audit);
        self::assertSame('WAVE5_DOMAIN', $audit->source);
        self::assertSame($w['teacher']['user'], (int) $audit->actor_id);
        self::assertSame($w['unit'], (int) $audit->unit_id);
        self::assertSame('DIRECT', json_decode($audit->after_metadata, true)['authority']);
    }

    // C3
    public function test_c3_teacher_of_class_a_cannot_grade_class_b_even_with_assess_permission(): void
    {
        $a = $this->gradingWorld();
        // Class B: same academic unit, same course version, different class the teacher is NOT assigned to.
        $classB = $this->row('classes', ['academic_unit_id' => $a['academicUnit'], 'course_version_id' => $a['version'], 'status' => 'S_CLS_OPEN']);
        $studentB = $this->enrollment($classB);
        $attemptB = $this->attempt($a['assessment'], $studentB['id']);
        $rt = $this->rt();
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => (new GradeService($rt))->record($a['teacher']['user'], $a['teacher']['session'], $attemptB, '90'));
        self::assertSame(0, (int) $this->db()->table('grades')->where('attempt_id', $attemptB)->count());
        // ...while their own class still works.
        self::assertSame(1, $this->grade($a)['version']);
    }

    // A3
    public function test_a3_admin_override_is_explicit_scoped_reasoned_and_audited(): void
    {
        $w = $this->gradingWorld();
        $admin = $this->actor($w['unit'], ['ACADEMY_ADMIN']);
        // No silent bypass: ACADEMY_ADMIN alone never acts without an explicit override request.
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $this->grade($w, $admin));
        // An explicit override needs a reason.
        $this->denied(AcademyReason::ADMIN_REASON_REQUIRED, fn () => $this->grade($w, $admin, '70', '   '));
        self::assertSame(0, $this->gradeCount($w));
        $result = $this->grade($w, $admin, '70', 'Registo em falta na turma sem instrutor activo');
        self::assertSame(1, $result['version']);
        $rows = $this->db()->table('audit_logs')->where('entity_type', 'grades')->where('entity_id', $result['grade_id'])->orderBy('id')->get();
        self::assertSame(['grade.recorded', 'ACADEMY_ADMIN_OVERRIDE'], $rows->pluck('action')->all());
        foreach ($rows as $row) {
            self::assertSame('Registo em falta na turma sem instrutor activo', $row->reason);
            self::assertSame($admin['user'], (int) $row->actor_id);
        }
        self::assertSame('ADMIN_OVERRIDE', json_decode($rows[0]->after_metadata, true)['authority']);
        self::assertSame($rows[0]->correlation_id, $rows[1]->correlation_id);
    }

    public function test_admin_override_still_requires_institutional_scope(): void
    {
        $w = $this->gradingWorld();
        $admin = $this->actor($this->world()['unit'], ['ACADEMY_ADMIN']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $this->grade($w, $admin, '70', 'motivo'));
        self::assertSame(0, $this->gradeCount($w));
    }

    public function test_admin_override_is_refused_where_the_matrix_has_none(): void
    {
        $w = $this->world();
        $admin = $this->actor($w['unit'], ['ACADEMY_ADMIN']);
        $person = $this->row('people');
        // enrollment.create has no override in the matrix: even an explicit request cannot bypass ACADEMY_ENROLL.
        $svc = new EnrollmentService($this->rt());
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->enroll($admin['user'], $admin['session'], $w['class'], $person));
        self::assertSame(0, (int) $this->db()->table('enrollments')->where('class_id', $w['class'])->count());
    }

    public function test_revoked_or_expired_scope_denies(): void
    {
        $w = $this->gradingWorld();
        $this->db()->table('user_role_scopes')->where('id', $w['teacher']['link'])->update(['status' => 'S_REVOKED']);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $this->grade($w));
        $this->db()->table('user_role_scopes')->where('id', $w['teacher']['link'])->update(['status' => 'SYNTHETIC_READY', 'ends_at' => $this->later('-1 minute')]);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $this->grade($w));
        self::assertSame(0, $this->gradeCount($w));
    }

    public function test_revoked_session_or_archived_user_denies(): void
    {
        $w = $this->gradingWorld();
        $this->db()->table('auth_sessions')->where('id', $w['teacher']['session'])->update(['revoked_at' => $this->later('-1 minute')]);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $this->grade($w));
        $this->db()->table('auth_sessions')->where('id', $w['teacher']['session'])->update(['revoked_at' => null]);
        $this->db()->table('users')->where('id', $w['teacher']['user'])->update(['archived_at' => $this->later('-1 minute')]);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $this->grade($w));
    }

    public function test_revoked_assignment_denies(): void
    {
        $w = $this->gradingWorld();
        $assignment = (int) $this->db()->table('class_instructors')->where('class_id', $w['class'])->value('id');
        $this->db()->table('class_instructors')->where('id', $assignment)->update(['status' => 'S_CI_ENDED']);
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => $this->grade($w));
        $this->db()->table('class_instructors')->where('id', $assignment)->update(['status' => 'S_CI_ACTIVE', 'ends_at' => $this->later('-1 minute')]);
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => $this->grade($w));
        $this->db()->table('class_instructors')->where('id', $assignment)->update(['ends_at' => null]);
        $this->db()->table('instructors')->where('person_id', $w['teacher']['person'])->update(['status' => 'S_INS_INACTIVE']);
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => $this->grade($w));
        self::assertSame(0, $this->gradeCount($w));
    }

    // A2
    public function test_a2_assess_is_not_certify_and_certify_needs_no_class_assignment(): void
    {
        $w = $this->gradingWorld();
        $file = $this->file($w['unit']);
        $svc = new CertificateService($this->rt());
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->issue($w['teacher']['user'], $w['teacher']['session'], $w['student']['id'], $file));
        // ACADEMY_CERTIFY without ASSESS and without any class assignment passes authorization; the
        // request then stops at the (unconfigured) completion policy, never at authorization.
        $certifier = $this->actor($w['unit'], ['ACADEMY_CERTIFY']);
        $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => $svc->issue($certifier['user'], $certifier['session'], $w['student']['id'], $file));
        self::assertSame(0, (int) $this->db()->table('certificates')->count());
    }

    // C9
    public function test_c9_caller_supplied_scope_is_not_authority(): void
    {
        $mine = $this->gradingWorld();
        $victim = $this->gradingWorld();
        $claimed = ['class_id' => $mine['class'], 'academic_unit_id' => $mine['academicUnit'], 'unit_id' => $mine['unit']];
        $rt = $this->rt();
        // The payload names MY authorized context but the attempt is really in the victim's class.
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => (new GradeService($rt))->record($mine['teacher']['user'], $mine['teacher']['session'], $victim['attempt'], '99', null, null, $claimed));
        self::assertSame(0, $this->gradeCount($victim));
        // A claim that disagrees with the derived target of an otherwise authorized request can only deny.
        $wrong = ['class_id' => $victim['class']];
        $this->denied(AcademyReason::CONTEXT_MISMATCH, fn () => (new GradeService($rt))->record($mine['teacher']['user'], $mine['teacher']['session'], $mine['attempt'], '99', null, null, $wrong));
        self::assertSame(0, $this->gradeCount($mine));
        // A truthful claim changes nothing about the decision.
        self::assertSame(1, (new GradeService($rt))->record($mine['teacher']['user'], $mine['teacher']['session'], $mine['attempt'], '99', null, null, $claimed)['version']);
    }

    public function test_unit_level_scope_with_descendants_covers_a_descendant_unit(): void
    {
        $parent = $this->row('organizational_units');
        $childUnit = $this->row('organizational_units', ['parent_id' => $parent]);
        $academicUnit = $this->row('academic_units', ['unit_id' => $childUnit]);
        $class = $this->row('classes', ['academic_unit_id' => $academicUnit, 'course_version_id' => $this->row('course_versions'), 'status' => 'S_CLS_OPEN']);
        $svc = new EnrollmentService($this->rt());
        $exact = $this->actor($parent, ['ACADEMY_ENROLL'], false);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->enroll($exact['user'], $exact['session'], $class, $this->row('people')));
        $broad = $this->actor($parent, ['ACADEMY_ENROLL'], true);
        self::assertSame('CREATED', $svc->enroll($broad['user'], $broad['session'], $class, $this->row('people'))['outcome']);
    }

    public function test_shared_course_version_needs_authority_over_every_unit_and_unresolved_scope_denies(): void
    {
        $a = $this->world();
        $b = $this->world();
        // Course version taught by classes of two different academic units.
        $version = $this->row('course_versions');
        $this->row('classes', ['academic_unit_id' => $a['academicUnit'], 'course_version_id' => $version, 'status' => 'S_CLS_OPEN']);
        $this->row('classes', ['academic_unit_id' => $b['academicUnit'], 'course_version_id' => $version, 'status' => 'S_CLS_OPEN']);
        $svc = new AssessmentService($this->rt());
        $onlyA = $this->actor($a['unit'], ['ACADEMY_MANAGE']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->create($onlyA['user'], $onlyA['session'], $version, null, 'Prova', '100', '50', 3, '1'));
        // A course version attached to nothing cannot be scoped at all.
        $orphan = $this->row('course_versions');
        $this->denied(AcademyReason::SCOPE_UNRESOLVED, fn () => $svc->create($onlyA['user'], $onlyA['session'], $orphan, null, 'Prova', '100', '50', 3, '1'));
        self::assertSame(0, (int) $this->db()->table('assessments')->where('course_version_id', $version)->count());
    }
}
