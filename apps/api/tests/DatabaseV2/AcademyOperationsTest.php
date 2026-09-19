<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFiveCase.php';

use App\Domain\Academy\AcademicAttendanceService;
use App\Domain\Academy\AcademicSessionLocationResolver;
use App\Domain\Academy\AcademyReason;
use App\Domain\Academy\ClassSessionService;
use App\Domain\Academy\InstructorAssignmentService;
use App\Domain\Academy\ProgressService;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

/**
 * Instructor assignment (A1, A5 sequential), class sessions + location resolution, academic
 * attendance (natural idempotency, class isolation, minors) and progress.
 */
final class AcademyOperationsTest extends PooledWaveFiveCase
{
    // ---- instructor assignment ---------------------------------------------------------------

    // A1
    public function test_a1_external_instructor_without_membership_is_assigned_and_can_teach(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE']);
        $external = $this->actor($w['unit'], ['ACADEMY_TEACH']);
        self::assertSame(0, (int) $this->db()->table('memberships')->where('person_id', $external['person'])->count());
        $svc = new InstructorAssignmentService($this->rt());
        $result = $svc->assign($manager['user'], $manager['session'], $w['class'], $external['person']);
        self::assertSame('ASSIGNED', $result['outcome']);
        self::assertSame(0, (int) $this->db()->table('memberships')->where('person_id', $external['person'])->count(), 'Instructor is not a membership');
        self::assertSame(1, (int) $this->db()->table('instructors')->where('person_id', $external['person'])->count());
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'class_instructors')->where('entity_id', $result['assignment_id'])->first();
        self::assertSame('class_instructor.assigned', $audit->action);
        // The freshly assigned external instructor can now run a session of that class.
        $created = (new ClassSessionService($this->rt()))->schedule($external['user'], $external['session'], $w['class'], $this->now()->modify('+1 day'), $this->now()->modify('+1 day 2 hours'));
        self::assertGreaterThan(0, $created['class_session_id']);
    }

    // A5 (sequential half)
    public function test_a5_duplicate_assignment_converges_and_periods_are_checked(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE']);
        $teacher = $this->actor($w['unit'], ['ACADEMY_TEACH']);
        $svc = new InstructorAssignmentService($this->rt());
        $first = $svc->assign($manager['user'], $manager['session'], $w['class'], $teacher['person'], $this->now()->modify('-1 hour'), $this->now()->modify('+1 day'));
        $error = $this->denied(AcademyReason::ALREADY_ASSIGNED, fn () => $svc->assign($manager['user'], $manager['session'], $w['class'], $teacher['person']));
        self::assertSame($first['assignment_id'], $error->context['assignment_id']);
        $this->denied(AcademyReason::ALREADY_ASSIGNED, fn () => $svc->assign($manager['user'], $manager['session'], $w['class'], $teacher['person'], $this->now()->modify('+2 hours'), $this->now()->modify('+3 hours')));
        // A period that starts after the first one ends does not overlap.
        $later = $svc->assign($manager['user'], $manager['session'], $w['class'], $teacher['person'], $this->now()->modify('+2 days'));
        self::assertNotSame($first['assignment_id'], $later['assignment_id']);
        self::assertSame(2, (int) $this->db()->table('class_instructors')->where('class_id', $w['class'])->count());
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->assign($manager['user'], $manager['session'], $w['class'], $this->row('people'), $this->now(), $this->now()->modify('-1 hour')));
    }

    public function test_a_teacher_cannot_self_assign_but_an_explicit_audited_admin_override_can(): void
    {
        $w = $this->world();
        $otherClass = $this->row('classes', ['academic_unit_id' => $w['academicUnit'], 'course_version_id' => $w['version'], 'status' => 'S_CLS_OPEN']);
        $teacher = $this->actor($w['unit'], ['ACADEMY_TEACH', 'ACADEMY_ASSESS', 'ACADEMY_ATTENDANCE']);
        $this->assign($teacher, $w['class']);
        $svc = new InstructorAssignmentService($this->rt());
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->assign($teacher['user'], $teacher['session'], $otherClass, $teacher['person']));
        $admin = $this->actor($w['unit'], ['ACADEMY_ADMIN']);
        $result = $svc->assign($admin['user'], $admin['session'], $otherClass, $teacher['person'], null, null, 'Cobertura urgente', null, 'Cobertura urgente');
        $actions = $this->db()->table('audit_logs')->where('entity_id', $result['assignment_id'])->where('entity_type', 'class_instructors')->orderBy('id')->pluck('action')->all();
        self::assertSame(['class_instructor.assigned', 'ACADEMY_ADMIN_OVERRIDE'], $actions);
    }

    public function test_ending_an_assignment_keeps_the_row_and_revokes_class_authority(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE', 'ACADEMY_VIEW']);
        $teacher = $this->actor($w['unit'], ['ACADEMY_TEACH']);
        $svc = new InstructorAssignmentService($this->rt());
        $assignment = $svc->assign($manager['user'], $manager['session'], $w['class'], $teacher['person'])['assignment_id'];
        self::assertCount(1, $svc->activeAssignments($manager['user'], $manager['session'], $w['class']));
        $sessions = new ClassSessionService($this->rt());
        $sessions->schedule($teacher['user'], $teacher['session'], $w['class'], $this->now()->modify('+1 day'), $this->now()->modify('+1 day 1 hour'));
        $svc->end($manager['user'], $manager['session'], $assignment, 'Fim de contrato');
        $row = $this->db()->table('class_instructors')->where('id', $assignment)->first();
        self::assertSame('S_CI_ENDED', $row->status);
        self::assertNotNull($row->ends_at, 'The row is closed, never deleted');
        self::assertSame([], $svc->activeAssignments($manager['user'], $manager['session'], $w['class']));
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => $sessions->schedule($teacher['user'], $teacher['session'], $w['class'], $this->now()->modify('+2 day'), $this->now()->modify('+2 day 1 hour')));
        self::assertSame('class_instructor.removed', $this->db()->table('audit_logs')->where('entity_id', $assignment)->where('entity_type', 'class_instructors')->orderByDesc('id')->value('action'));
    }

    public function test_inactive_instructor_profile_cannot_be_assigned(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE']);
        $person = $this->row('people');
        $this->row('instructors', ['person_id' => $person, 'status' => 'S_INS_SUSPENDED']);
        $this->denied(AcademyReason::INSTRUCTOR_NOT_ACTIVE, fn () => (new InstructorAssignmentService($this->rt()))->assign($manager['user'], $manager['session'], $w['class'], $person));
        self::assertSame(0, (int) $this->db()->table('class_instructors')->where('class_id', $w['class'])->count());
    }

    // ---- class sessions + location -----------------------------------------------------------

    public function test_session_scheduling_guards_and_initial_state_from_policy(): void
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_TEACH']);
        $this->assign($teacher, $w['class']);
        $svc = new ClassSessionService($this->rt());
        $start = $this->now()->modify('+1 day');
        $id = $svc->schedule($teacher['user'], $teacher['session'], $w['class'], $start, $start->modify('+2 hours'), $w['lesson'])['class_session_id'];
        $row = $this->db()->table('class_sessions')->where('id', $id)->first();
        self::assertSame('S_SES_PLANNED', $row->status);
        self::assertNull($row->event_session_id, 'A session exists without any Event');
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->schedule($teacher['user'], $teacher['session'], $w['class'], $start, $start));
        // A lesson of ANOTHER course version does not belong to this class.
        $foreign = $this->world();
        $this->denied(AcademyReason::CONTEXT_MISMATCH, fn () => $svc->schedule($teacher['user'], $teacher['session'], $w['class'], $start, $start->modify('+1 hour'), $foreign['lesson']));
        // Approved transition executes; the audit trail records before/after.
        self::assertSame('S_SES_HELD', $svc->transition($teacher['user'], $teacher['session'], $id, 'S_SES_HELD', 'realizada')['to']);
        $this->denied(AcademyReason::INVALID_TRANSITION, fn () => $svc->transition($teacher['user'], $teacher['session'], $id, 'S_SES_PLANNED'));
        // A class that is not in a configured teachable state accepts no sessions.
        $draft = $this->world(['status' => 'S_CLS_DRAFT']);
        $t2 = $this->actor($draft['unit'], ['ACADEMY_TEACH']);
        $this->assign($t2, $draft['class']);
        $this->denied(AcademyReason::CLASS_NOT_TEACHABLE, fn () => $svc->schedule($t2['user'], $t2['session'], $draft['class'], $start, $start->modify('+1 hour')));
    }

    public function test_event_session_link_must_belong_to_the_same_organizational_unit(): void
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_TEACH']);
        $this->assign($teacher, $w['class']);
        $svc = new ClassSessionService($this->rt());
        $start = $this->now()->modify('+1 day');
        $ownEvent = $this->row('events', ['owner_unit_id' => $w['unit']]);
        $ownSession = $this->row('event_sessions', ['event_id' => $ownEvent]);
        $foreignEvent = $this->row('events', ['owner_unit_id' => $this->row('organizational_units')]);
        $foreignSession = $this->row('event_sessions', ['event_id' => $foreignEvent]);
        $id = $svc->schedule($teacher['user'], $teacher['session'], $w['class'], $start, $start->modify('+1 hour'), null, $ownSession)['class_session_id'];
        self::assertSame($ownSession, (int) $this->db()->table('class_sessions')->where('id', $id)->value('event_session_id'));
        $this->denied(AcademyReason::CONTEXT_MISMATCH, fn () => $svc->schedule($teacher['user'], $teacher['session'], $w['class'], $start, $start->modify('+1 hour'), null, $foreignSession));
    }

    public function test_effective_location_prefers_explicit_event_location_over_class_default_without_copying(): void
    {
        $default = $this->row('physical_locations');
        $special = $this->row('physical_locations');
        $w = $this->world(['location_id' => $default]);
        $viewer = $this->actor($w['unit'], ['ACADEMY_VIEW']);
        $event = $this->row('events', ['owner_unit_id' => $w['unit']]);
        $eventSession = $this->row('event_sessions', ['event_id' => $event]);
        $plain = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_PLANNED']);
        $linked = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_PLANNED', 'event_session_id' => $eventSession]);
        $svc = new ClassSessionService($this->rt());
        // No explicit event location yet: the class default applies to both.
        self::assertSame(['location_id' => $default, 'source' => AcademicSessionLocationResolver::CLASS_DEFAULT], $svc->effectiveLocation($viewer['user'], $viewer['session'], $plain));
        self::assertSame(['location_id' => $default, 'source' => AcademicSessionLocationResolver::CLASS_DEFAULT], $svc->effectiveLocation($viewer['user'], $viewer['session'], $linked));
        // An explicit event location prevails for THAT session only; the class default is untouched.
        $this->row('event_locations', ['event_id' => $event, 'location_id' => $special, 'is_primary' => 1]);
        self::assertSame(['location_id' => $special, 'source' => AcademicSessionLocationResolver::EVENT_SESSION], $svc->effectiveLocation($viewer['user'], $viewer['session'], $linked));
        self::assertSame(['location_id' => $default, 'source' => AcademicSessionLocationResolver::CLASS_DEFAULT], $svc->effectiveLocation($viewer['user'], $viewer['session'], $plain));
        self::assertSame($default, (int) $this->db()->table('classes')->where('id', $w['class'])->value('location_id'));
        // No location anywhere is reported as such, never invented.
        $bare = $this->world();
        $bareViewer = $this->actor($bare['unit'], ['ACADEMY_VIEW']);
        $bareSession = $this->row('class_sessions', ['class_id' => $bare['class'], 'status' => 'S_SES_PLANNED']);
        self::assertSame(['location_id' => null, 'source' => AcademicSessionLocationResolver::NONE], $svc->effectiveLocation($bareViewer['user'], $bareViewer['session'], $bareSession));
    }

    // ---- attendance --------------------------------------------------------------------------

    private function attendanceWorld(): array
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_ATTENDANCE', 'ACADEMY_VIEW']);
        $this->assign($teacher, $w['class']);
        $classSession = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_PLANNED']);
        $student = $this->enrollment($w['class']);
        return $w + compact('teacher', 'classSession', 'student');
    }

    private function att(array $w, ?array $actor = null): AcademicAttendanceService
    {
        return new AcademicAttendanceService($this->rt());
    }

    public function test_attendance_is_naturally_idempotent_and_updates_are_audited_with_before_after(): void
    {
        $w = $this->attendanceWorld();
        $svc = $this->att($w);
        $t = $w['teacher'];
        $first = $svc->record($t['user'], $t['session'], $w['classSession'], $w['student']['id'], 'S_ATD_PRESENT');
        $again = $svc->record($t['user'], $t['session'], $w['classSession'], $w['student']['id'], 'S_ATD_PRESENT');
        self::assertSame(['CREATED', 'UNCHANGED'], [$first['outcome'], $again['outcome']]);
        self::assertSame($first['attendance_id'], $again['attendance_id']);
        $changed = $svc->record($t['user'], $t['session'], $w['classSession'], $w['student']['id'], 'S_ATD_ABSENT');
        self::assertSame('UPDATED', $changed['outcome']);
        self::assertSame(1, (int) $this->db()->table('academic_attendance')->where('class_session_id', $w['classSession'])->count());
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'academic_attendance')->where('entity_id', $first['attendance_id'])->orderBy('id')->get();
        self::assertSame(['attendance.recorded', 'attendance.updated'], $audit->pluck('action')->all());
        self::assertSame('S_ATD_PRESENT', json_decode($audit[1]->before_metadata, true)['status']);
        self::assertSame('S_ATD_ABSENT', json_decode($audit[1]->after_metadata, true)['status']);
        self::assertSame(0, (int) $this->db()->table('event_attendance')->count(), 'Academic attendance never writes event attendance');
        $listed = $svc->listForSession($t['user'], $t['session'], $w['classSession']);
        self::assertSame(1, $listed['total']);
    }

    public function test_attendance_requires_permission_scope_and_the_class_assignment(): void
    {
        $w = $this->attendanceWorld();
        $svc = $this->att($w);
        // A student (no ACADEMY_ATTENDANCE anywhere) cannot record their own presence.
        $studentUser = $this->actor($w['unit'], ['ACADEMY_VIEW']);
        $this->db()->table('users')->where('id', $studentUser['user'])->update(['person_id' => $w['student']['person']]);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->record($studentUser['user'], $studentUser['session'], $w['classSession'], $w['student']['id'], 'S_ATD_PRESENT'));
        // A teacher of another class of the same unit cannot.
        $otherClass = $this->row('classes', ['academic_unit_id' => $w['academicUnit'], 'course_version_id' => $w['version'], 'status' => 'S_CLS_OPEN']);
        $stranger = $this->actor($w['unit'], ['ACADEMY_ATTENDANCE']);
        $this->assign($stranger, $otherClass);
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => $svc->record($stranger['user'], $stranger['session'], $w['classSession'], $w['student']['id'], 'S_ATD_PRESENT'));
        // An explicit, audited admin override works.
        $admin = $this->actor($w['unit'], ['ACADEMY_ADMIN']);
        $result = $svc->record($admin['user'], $admin['session'], $w['classSession'], $w['student']['id'], 'S_ATD_PRESENT', 'Correcção administrativa');
        self::assertSame('CREATED', $result['outcome']);
        self::assertTrue($this->db()->table('audit_logs')->where('entity_id', $result['attendance_id'])->where('entity_type', 'academic_attendance')->where('action', 'ACADEMY_ADMIN_OVERRIDE')->exists());
        self::assertSame(1, (int) $this->db()->table('academic_attendance')->where('class_session_id', $w['classSession'])->count());
    }

    public function test_attendance_context_and_lifecycle_guards(): void
    {
        $w = $this->attendanceWorld();
        $svc = $this->att($w);
        $t = $w['teacher'];
        $other = $this->world();
        $foreign = $this->enrollment($other['class']);
        $this->denied(AcademyReason::CONTEXT_MISMATCH, fn () => $svc->record($t['user'], $t['session'], $w['classSession'], $foreign['id'], 'S_ATD_PRESENT'));
        $this->denied(AcademyReason::ATTENDANCE_STATUS_INVALID, fn () => $svc->record($t['user'], $t['session'], $w['classSession'], $w['student']['id'], 'S_ATD_INVENTED'));
        $pending = $this->enrollment($w['class'], null, 'S_ENR_PENDING');
        $this->denied(AcademyReason::ENROLLMENT_NOT_ACTIVE, fn () => $svc->record($t['user'], $t['session'], $w['classSession'], $pending['id'], 'S_ATD_PRESENT'));
        $closed = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_CANCELLED']);
        $this->denied(AcademyReason::SESSION_NOT_ATTENDABLE, fn () => $svc->record($t['user'], $t['session'], $closed, $w['student']['id'], 'S_ATD_PRESENT'));
        self::assertSame(0, (int) $this->db()->table('academic_attendance')->whereIn('class_session_id', [$w['classSession'], $closed])->count());
    }

    public function test_bulk_attendance_is_atomic(): void
    {
        $w = $this->attendanceWorld();
        $svc = $this->att($w);
        $t = $w['teacher'];
        $second = $this->enrollment($w['class']);
        $ok = $svc->recordBulk($t['user'], $t['session'], $w['classSession'], [$w['student']['id'] => 'S_ATD_PRESENT', $second['id'] => 'S_ATD_ABSENT']);
        self::assertSame(['CREATED', 'CREATED'], array_column($ok, 'outcome'));
        $third = $this->enrollment($w['class']);
        $fourth = $this->enrollment($w['class']);
        $this->denied(AcademyReason::ATTENDANCE_STATUS_INVALID, fn () => $svc->recordBulk($t['user'], $t['session'], $w['classSession'], [$third['id'] => 'S_ATD_PRESENT', $fourth['id'] => 'S_ATD_INVENTED']));
        self::assertSame(2, (int) $this->db()->table('academic_attendance')->where('class_session_id', $w['classSession'])->count(), 'The failed batch stored nothing');
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->recordBulk($t['user'], $t['session'], $w['classSession'], []));
    }

    public function test_minor_attendance_needs_a_live_cover_and_history_never_substitutes_for_it(): void
    {
        $w = $this->attendanceWorld();
        $svc = $this->att($w);
        $t = $w['teacher'];
        $c = $this->child($w['unit']);
        $enrollment = $this->enrollment($w['class'], $c['child']);
        self::assertSame('CREATED', $svc->record($t['user'], $t['session'], $w['classSession'], $enrollment['id'], 'S_ATD_PRESENT')['outcome']);
        // The enrollment predates the revocation: it must not keep attendance valid forever.
        $c['children']->revokeAuthorization($c['staff']['user'], $c['staff']['session'], $c['authorization'], 'revogado');
        $second = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_PLANNED']);
        $this->denied(AcademyReason::GUARDIAN_AUTHORIZATION_INVALID, fn () => $svc->record($t['user'], $t['session'], $second, $enrollment['id'], 'S_ATD_PRESENT'));
        self::assertSame(0, (int) $this->db()->table('academic_attendance')->where('class_session_id', $second)->count());
        // A minor with no consent at all is denied too, and an adult is unaffected.
        $bare = $this->row('people');
        $staff = $this->actor($w['unit'], ['CHILD_WRITE'], false, 'CHILDREN');
        (new \App\Domain\WaveFour\ChildrenService($this->db(), self::childPolicy(), self::eventPolicy()))->register($staff['user'], $staff['session'], $bare, $w['unit']);
        $bareEnrollment = $this->enrollment($w['class'], $bare);
        $this->denied(AcademyReason::CONSENT_REQUIRED, fn () => $svc->record($t['user'], $t['session'], $w['classSession'], $bareEnrollment['id'], 'S_ATD_PRESENT'));
        self::assertSame('CREATED', $svc->record($t['user'], $t['session'], $w['classSession'], $w['student']['id'], 'S_ATD_PRESENT')['outcome']);
    }

    // Mission section 11: an admin override never ignores child-safety requirements.
    public function test_admin_override_never_bypasses_the_minor_safety_cover(): void
    {
        $w = $this->attendanceWorld();
        $svc = $this->att($w);
        $admin = $this->actor($w['unit'], ['ACADEMY_ADMIN']);
        $staff = $this->actor($w['unit'], ['CHILD_WRITE'], false, 'CHILDREN');
        $bare = $this->row('people');
        (new \App\Domain\WaveFour\ChildrenService($this->db(), self::childPolicy(), self::eventPolicy()))->register($staff['user'], $staff['session'], $bare, $w['unit']);
        $uncovered = $this->enrollment($w['class'], $bare);
        $this->denied(AcademyReason::CONSENT_REQUIRED, fn () => $svc->record($admin['user'], $admin['session'], $w['classSession'], $uncovered['id'], 'S_ATD_PRESENT', 'Correcção administrativa'));
        self::assertSame(0, (int) $this->db()->table('academic_attendance')->where('enrollment_id', $uncovered['id'])->count());
        self::assertSame(0, (int) $this->db()->table('audit_logs')->where('action', 'ACADEMY_ADMIN_OVERRIDE')->where('unit_id', $w['unit'])->count(), 'A denied override leaves no override audit');
        // The same explicit override succeeds (and is audited) once the minor has a valid cover.
        $c = $this->child($w['unit']);
        $covered = $this->enrollment($w['class'], $c['child']);
        $result = $svc->record($admin['user'], $admin['session'], $w['classSession'], $covered['id'], 'S_ATD_PRESENT', 'Correcção administrativa');
        self::assertSame('CREATED', $result['outcome']);
        self::assertSame(1, (int) $this->db()->table('audit_logs')->where('action', 'ACADEMY_ADMIN_OVERRIDE')->where('entity_id', $result['attendance_id'])->count());
    }

    // ---- progress ----------------------------------------------------------------------------

    public function test_progress_is_derived_from_the_real_chain_and_records_facts_only(): void
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_TEACH']);
        $this->assign($teacher, $w['class']);
        $student = $this->enrollment($w['class']);
        $svc = new ProgressService($this->rt());
        $r = $svc->recordLesson($teacher['user'], $teacher['session'], $student['id'], $w['lesson'], '0.5000');
        $row = $this->db()->table('progress')->where('id', $r['progress_id'])->first();
        self::assertSame('S_PRG_OPEN', $row->status);
        self::assertSame('SYNTHETIC_ACADEMY_V1', $row->source_version, 'source_version records the policy version in force');
        self::assertNull($row->completed_at, 'Recording a ratio never marks a lesson done');
        $svc->recordLesson($teacher['user'], $teacher['session'], $student['id'], $w['lesson'], '0.7500');
        self::assertSame(1, (int) $this->db()->table('progress')->where('enrollment_id', $student['id'])->count());
        self::assertSame('0.7500', (string) $this->db()->table('progress')->where('id', $r['progress_id'])->value('completion_ratio'));
        $svc->recordLesson($teacher['user'], $teacher['session'], $student['id'], $w['lesson'], '1', 'S_PRG_DONE');
        $done = $this->db()->table('progress')->where('id', $r['progress_id'])->first();
        self::assertSame('S_PRG_DONE', $done->status);
        self::assertNotNull($done->completed_at);
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->recordLesson($teacher['user'], $teacher['session'], $student['id'], $w['lesson'], '1.5'));
        $foreign = $this->world();
        $this->denied(AcademyReason::CONTEXT_MISMATCH, fn () => $svc->recordLesson($teacher['user'], $teacher['session'], $student['id'], $foreign['lesson'], '0.1'));
        // The teacher of another class cannot touch this enrollment's progress.
        $stranger = $this->actor($w['unit'], ['ACADEMY_TEACH']);
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => $svc->recordLesson($stranger['user'], $stranger['session'], $student['id'], $w['lesson'], '0.1'));
    }

    public function test_resource_progress_follows_lesson_resources_of_the_same_course_version(): void
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_TEACH']);
        $this->assign($teacher, $w['class']);
        $student = $this->enrollment($w['class']);
        $resource = $this->row('resources');
        $this->row('lesson_resources', ['lesson_id' => $w['lesson'], 'resource_id' => $resource]);
        $unrelated = $this->row('resources');
        $svc = new ProgressService($this->rt());
        $id = $svc->recordResource($teacher['user'], $teacher['session'], $student['id'], $resource, 120)['resource_progress_id'];
        $svc->recordResource($teacher['user'], $teacher['session'], $student['id'], $resource, 300, 'S_RPG_DONE');
        $row = $this->db()->table('resource_progress')->where('id', $id)->first();
        self::assertSame([300, 'S_RPG_DONE'], [(int) $row->watched_seconds, $row->status]);
        self::assertNotNull($row->verified_at);
        $this->denied(AcademyReason::CONTEXT_MISMATCH, fn () => $svc->recordResource($teacher['user'], $teacher['session'], $student['id'], $unrelated, 10));
    }
}
