<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFiveCase.php';

use App\Domain\Academy\AcademyAuditWriter;
use App\Domain\Academy\AcademyDecision;
use App\Domain\Academy\AcademyError;
use App\Domain\Academy\AcademyReason;
use App\Domain\Academy\AssessmentAttemptService;
use App\Domain\Academy\AssessmentService;
use App\Domain\Academy\CertificateService;
use App\Domain\Academy\CompletionService;
use App\Domain\Academy\CurriculumService;
use App\Domain\Academy\DatabaseAcademyAudit;
use App\Domain\Academy\EnrollmentService;
use App\Domain\Academy\GradeService;
use App\Domain\Academy\InstructorAssignmentService;
use App\Domain\Academy\TranscriptService;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

/**
 * Assessments, attempts, grades (C5), completion / certificates (C6, A9), transcripts and curriculum
 * history (C8), audit transactionality and rollback (C10, mission sections 46 and 73).
 */
final class AcademyAssessmentCertificationTest extends PooledWaveFiveCase
{
    // ---- assessments: policy-neutral (D-09) --------------------------------------------------

    public function test_assessment_definition_is_policy_neutral_every_value_comes_from_the_caller(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE']);
        $svc = new AssessmentService($this->rt());
        // Any missing policy value is POLICY_NOT_CONFIGURED: no default, no inferred scale.
        foreach ([[null, '50', 3, '1'], ['100', null, 3, '1'], ['100', '50', null, '1'], ['100', '50', 3, null]] as [$max, $pass, $attempts, $weight]) {
            $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => $svc->create($manager['user'], $manager['session'], $w['version'], null, 'Prova', $max, $pass, $attempts, $weight));
        }
        // Technical shape only: floats are refused (no rounding), pass <= max, attempts >= 1.
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->create($manager['user'], $manager['session'], $w['version'], null, 'Prova', 100.5, '50', 3, '1'));
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->create($manager['user'], $manager['session'], $w['version'], null, 'Prova', '40', '50', 3, '1'));
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->create($manager['user'], $manager['session'], $w['version'], null, 'Prova', '100', '50', 0, '1'));
        self::assertSame(0, (int) $this->db()->table('assessments')->where('course_version_id', $w['version'])->count());
        // Whatever an authorized caller supplies is stored verbatim; the scale is never assumed.
        $id = $svc->create($manager['user'], $manager['session'], $w['version'], $w['lesson'], 'Prova A', '7.5', '3.25', 9, '0.3333')['assessment_id'];
        $row = $this->db()->table('assessments')->where('id', $id)->first();
        self::assertSame(['7.5000', '3.2500', 9, '0.3333', 'S_ASM_OPEN'], [(string) $row->max_score, (string) $row->pass_score, (int) $row->max_attempts, (string) $row->weight, $row->status]);
        self::assertSame('assessment.created', $this->db()->table('audit_logs')->where('entity_type', 'assessments')->where('entity_id', $id)->value('action'));
        // Without ACADEMY_MANAGE in scope nothing is created.
        $teacher = $this->actor($w['unit'], ['ACADEMY_ASSESS']);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->create($teacher['user'], $teacher['session'], $w['version'], null, 'Prova B', '10', '5', 1, '1'));
    }

    public function test_assessment_update_uses_an_allow_list_optimistic_locking_and_protects_used_definitions(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE']);
        $svc = new AssessmentService($this->rt());
        $id = $svc->create($manager['user'], $manager['session'], $w['version'], null, 'Prova', '100', '50', 3, '1')['assessment_id'];
        $version = (int) $this->db()->table('assessments')->where('id', $id)->value('lock_version');
        // Mass assignment is rejected, not silently ignored.
        foreach (['status', 'course_version_id', 'id', 'public_id'] as $field) {
            $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->update($manager['user'], $manager['session'], $id, [$field => 'x'], $version));
        }
        $svc->update($manager['user'], $manager['session'], $id, ['name' => 'Prova final', 'pass_score' => '60'], $version);
        $this->denied(AcademyReason::STALE_WRITE, fn () => $svc->update($manager['user'], $manager['session'], $id, ['name' => 'Outra'], $version));
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->update($manager['user'], $manager['session'], $id, ['pass_score' => '101'], $version + 1));
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'assessments')->where('entity_id', $id)->where('action', 'assessment.updated')->first();
        self::assertSame('50.0000', json_decode($audit->before_metadata, true)['pass_score']);
        // Once attempts exist the scoring basis is frozen; harmless fields still change.
        $student = $this->enrollment($w['class']);
        $this->attempt($id, $student['id']);
        $version = (int) $this->db()->table('assessments')->where('id', $id)->value('lock_version');
        $this->denied(AcademyReason::ASSESSMENT_IN_USE, fn () => $svc->update($manager['user'], $manager['session'], $id, ['max_score' => '10'], $version));
        $svc->update($manager['user'], $manager['session'], $id, ['name' => 'Renomeada'], $version);
        self::assertSame('Renomeada', $this->db()->table('assessments')->where('id', $id)->value('name'));
    }

    // ---- attempts ----------------------------------------------------------------------------

    public function test_attempts_are_numbered_under_lock_and_the_attempt_limit_is_not_enforced_d09(): void
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_ASSESS']);
        $this->assign($teacher, $w['class']);
        $student = $this->enrollment($w['class']);
        $assessment = $this->assessment($w['version'], ['max_attempts' => 1]);
        $svc = new AssessmentAttemptService($this->rt());
        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $svc->start($teacher['user'], $teacher['session'], $assessment, $student['id'])['attempt_number'];
        }
        self::assertSame([1, 2, 3], $numbers, 'max_attempts = 1 is a D-09 value not yet approved: it is not imposed');
        // A caller that knows the number it wants converges deterministically.
        $this->denied(AcademyReason::ATTEMPT_ALREADY_EXISTS, fn () => $svc->start($teacher['user'], $teacher['session'], $assessment, $student['id'], 2));
        $this->denied(AcademyReason::ATTEMPT_NUMBER_CONFLICT, fn () => $svc->start($teacher['user'], $teacher['session'], $assessment, $student['id'], 9));
        self::assertSame(4, $svc->start($teacher['user'], $teacher['session'], $assessment, $student['id'], 4)['attempt_number']);
        self::assertSame(4, (int) $this->db()->table('assessment_attempts')->where('assessment_id', $assessment)->where('enrollment_id', $student['id'])->count());
        self::assertSame(4, (int) $this->db()->table('audit_logs')->where('entity_type', 'assessment_attempts')->where('action', 'attempt.recorded')->whereIn('entity_id', $this->db()->table('assessment_attempts')->where('enrollment_id', $student['id'])->pluck('id'))->count());
    }

    public function test_attempt_context_lifecycle_and_class_isolation(): void
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_ASSESS']);
        $this->assign($teacher, $w['class']);
        $student = $this->enrollment($w['class']);
        $svc = new AssessmentAttemptService($this->rt());
        $foreign = $this->world();
        $this->denied(AcademyReason::CONTEXT_MISMATCH, fn () => $svc->start($teacher['user'], $teacher['session'], $this->assessment($foreign['version']), $student['id']));
        $this->denied(AcademyReason::ASSESSMENT_NOT_OPEN, fn () => $svc->start($teacher['user'], $teacher['session'], $this->assessment($w['version'], ['status' => 'S_ASM_DRAFT']), $student['id']));
        $pending = $this->enrollment($w['class'], null, 'S_ENR_PENDING');
        $this->denied(AcademyReason::ENROLLMENT_NOT_ACTIVE, fn () => $svc->start($teacher['user'], $teacher['session'], $this->assessment($w['version']), $pending['id']));
        $otherClass = $this->row('classes', ['academic_unit_id' => $w['academicUnit'], 'course_version_id' => $w['version'], 'status' => 'S_CLS_OPEN']);
        $stranger = $this->enrollment($otherClass);
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => $svc->start($teacher['user'], $teacher['session'], $this->assessment($w['version']), $stranger['id']));
        $id = $svc->start($teacher['user'], $teacher['session'], $this->assessment($w['version']), $student['id'], null, ['q1' => 'a'])['attempt_id'];
        $moved = $svc->transition($teacher['user'], $teacher['session'], $id, 'S_TRY_SUBMITTED');
        self::assertSame(['S_TRY_STARTED', 'S_TRY_SUBMITTED'], [$moved['from'], $moved['to']]);
        self::assertNotNull($this->db()->table('assessment_attempts')->where('id', $id)->value('submitted_at'));
        $this->denied(AcademyReason::INVALID_TRANSITION, fn () => $svc->transition($teacher['user'], $teacher['session'], $id, 'S_TRY_STARTED'));
    }

    // ---- grades (C5) -------------------------------------------------------------------------

    public function test_grade_versions_are_append_only_with_reason_history_and_audit(): void
    {
        $w = $this->gradingWorld();
        $t = $w['teacher'];
        $svc = new GradeService($this->rt());
        $v1 = $svc->record($t['user'], $t['session'], $w['attempt'], '55', 'primeira correcção');
        $this->denied(AcademyReason::GRADE_ALREADY_RECORDED, fn () => $svc->record($t['user'], $t['session'], $w['attempt'], '60'));
        $this->denied(AcademyReason::REASON_REQUIRED, fn () => $svc->revise($t['user'], $t['session'], $w['attempt'], 1, '60', '  '));
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->revise($t['user'], $t['session'], $w['attempt'], 1, '100.0001', 'acima do máximo'));
        $this->denied(AcademyReason::INVALID_INPUT, fn () => $svc->record($t['user'], $t['session'], $w['attempt'], 88.5));
        $v2 = $svc->revise($t['user'], $t['session'], $w['attempt'], 1, '62.5', 'revisão do exame');
        self::assertSame(2, $v2['version']);
        // Viewing needs ACADEMY_GRADES_VIEW in scope (mission section 34): ACADEMY_ASSESS alone does not read grades.
        $viewer = $this->actor($w['unit'], ['ACADEMY_GRADES_VIEW']);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->history($t['user'], $t['session'], $w['attempt']));
        $history = $svc->history($viewer['user'], $viewer['session'], $w['attempt']);
        self::assertSame([1, 2], array_column($history, 'version'));
        self::assertSame('55.0000', (string) $history[0]['score'], 'The earlier version is never overwritten');
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'grades')->where('entity_id', $v2['grade_id'])->first();
        self::assertSame('grade.revised', $audit->action);
        self::assertSame('revisão do exame', $audit->reason);
        self::assertEquals(['version' => 1, 'score' => '55.0000', 'status' => 'S_GRD_DRAFT'], json_decode($audit->before_metadata, true), 'MySQL JSON normalizes key order');
        self::assertSame($t['user'], (int) $audit->actor_id);
        self::assertNotNull($this->db()->table('audit_logs')->where('entity_type', 'grades')->where('entity_id', $v1['grade_id'])->where('action', 'grade.recorded')->first());
        // A plain-view actor cannot see grades and neither can a viewer of another unit.
        self::assertCount(2, $svc->history($viewer['user'], $viewer['session'], $w['attempt']));
        $plain = $this->actor($w['unit'], ['ACADEMY_VIEW']);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->history($plain['user'], $plain['session'], $w['attempt']));
        $elsewhere = $this->actor($this->world()['unit'], ['ACADEMY_GRADES_VIEW']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->history($elsewhere['user'], $elsewhere['session'], $w['attempt']));
    }

    // C5 (sequential half; the concurrent race is AcademyConcurrencyTest::test_e2_*)
    public function test_c5_stale_writer_is_rejected_and_history_and_audit_are_preserved(): void
    {
        $w = $this->gradingWorld();
        $a = $w['teacher'];
        $b = $this->actor($w['unit'], ['ACADEMY_ASSESS']);
        $this->assign($b, $w['class']);
        $svc = new GradeService($this->rt());
        $svc->record($a['user'], $a['session'], $w['attempt'], '40');
        // Actor A reads version 1; actor B revises to version 2; actor A then saves against version 1.
        $seenByA = (int) $this->db()->table('grades')->where('attempt_id', $w['attempt'])->max('version');
        $svc->revise($b['user'], $b['session'], $w['attempt'], 1, '75', 'B corrige');
        $error = $this->denied(AcademyReason::STALE_WRITE, fn () => $svc->revise($a['user'], $a['session'], $w['attempt'], $seenByA, '10', 'A escreve com versão antiga'));
        self::assertSame(2, $error->context['latest_version']);
        $rows = $this->db()->table('grades')->where('attempt_id', $w['attempt'])->orderBy('version')->get();
        self::assertSame(['40.0000', '75.0000'], $rows->pluck('score')->map(fn ($s) => (string) $s)->all(), 'The stale write stored nothing');
        $audits = $this->db()->table('audit_logs')->where('entity_type', 'grades')->whereIn('entity_id', $rows->pluck('id'))->pluck('action')->all();
        self::assertSame(['grade.recorded', 'grade.revised'], $audits, 'Audit of the accepted history is intact and the rejected write left none');
    }

    // A10 / D-11
    public function test_finalization_is_state_policy_pending_until_the_transition_is_approved(): void
    {
        $w = $this->gradingWorld();
        $t = $w['teacher'];
        $svc = new GradeService($this->rt());
        $svc->record($t['user'], $t['session'], $w['attempt'], '70');
        $this->denied(AcademyReason::STATE_POLICY_PENDING, fn () => $svc->finalize($t['user'], $t['session'], $w['attempt'], 1));
        self::assertSame('S_GRD_DRAFT', $this->db()->table('grades')->where('attempt_id', $w['attempt'])->value('status'));
        self::assertSame(0, (int) $this->db()->table('audit_logs')->where('entity_type', 'grades')->where('action', 'grade.finalized')->count());
        // Once the owners approve the transition (test policy), the very same call executes.
        $approved = $this->rt($this->academyPolicy(function (array $c) {
            $c['transitions']['approved']['grades'] = [['S_GRD_DRAFT', 'S_GRD_FINAL', self::NONE]];
            $c['transitions']['pending']['grades'] = [];
            return $c;
        }));
        $final = new GradeService($approved);
        $this->denied(AcademyReason::STALE_WRITE, fn () => $final->finalize($t['user'], $t['session'], $w['attempt'], 7));
        self::assertSame('S_GRD_FINAL', $final->finalize($t['user'], $t['session'], $w['attempt'], 1)['to']);
    }

    // ---- completion + certificates (C6, A9) --------------------------------------------------

    private function policy(int $version, ?array $policy): void
    {
        $this->db()->table('course_versions')->where('id', $version)->update(['completion_policy_metadata' => $policy === null ? null : json_encode($policy)]);
    }

    private function certWorld(): array
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_ASSESS']);
        $this->assign($teacher, $w['class']);
        $certifier = $this->actor($w['unit'], ['ACADEMY_CERTIFY']);
        $student = $this->enrollment($w['class']);
        return $w + compact('teacher', 'certifier', 'student') + ['file' => $this->file($w['unit'])];
    }

    // A9
    public function test_a9_missing_or_malformed_policy_never_defaults_and_never_completes(): void
    {
        $w = $this->certWorld();
        $svc = new CompletionService($this->rt());
        $t = $w['teacher'];
        foreach ([null, [], ['criteria' => []], ['criteria' => [['type' => 'PASS_MARK', 'value' => 10]]], ['criteria' => [['type' => 'ATTENDANCE_MIN_RATIO']]], ['criteria' => [['type' => 'ATTENDANCE_MIN_RATIO', 'min_ratio' => '1.5']]], ['criteria' => [['type' => 'ASSESSMENT_MIN_SCORE', 'assessment_id' => 1, 'min_score' => '5']]]] as $policy) {
            $this->policy($w['version'], $policy);
            $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => $svc->complete($t['user'], $t['session'], $w['student']['id']));
        }
        self::assertSame('S_ENR_ACTIVE', $this->db()->table('enrollments')->where('id', $w['student']['id'])->value('status'));
        self::assertSame(0, (int) $this->db()->table('audit_logs')->where('entity_type', 'enrollments')->where('entity_id', $w['student']['id'])->where('action', 'enrollment.completed')->count());
    }

    public function test_completion_needs_every_criterion_a_recorded_grade_is_not_enough(): void
    {
        $w = $this->certWorld();
        $t = $w['teacher'];
        $assessment = $this->assessment($w['version']);
        $attempt = $this->attempt($assessment, $w['student']['id']);
        $sessionA = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_HELD']);
        $sessionB = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_HELD']);
        $this->policy($w['version'], ['criteria' => [
            ['type' => 'ADMINISTRATIVE_APPROVAL'],
            ['type' => 'ATTENDANCE_MIN_RATIO', 'min_ratio' => '0.5000'],
            ['type' => 'ASSESSMENT_MIN_SCORE', 'assessment_id' => $assessment, 'min_score' => '60', 'attempt_selection' => 'LATEST'],
        ]]);
        $svc = new CompletionService($this->rt());
        // A grade is recorded (draft) but there is no attendance and nothing is final yet.
        (new GradeService($this->rt()))->record($t['user'], $t['session'], $attempt, '90');
        $error = $this->denied(AcademyReason::COMPLETION_CRITERIA_NOT_MET, fn () => $svc->complete($t['user'], $t['session'], $w['student']['id']));
        self::assertSame(['ATTENDANCE_MIN_RATIO', 'ASSESSMENT_MIN_SCORE'], $error->context['unmet']);
        // Attendance 1 of 2 = exactly the configured ratio (>=), but the grade is still not final.
        $this->row('academic_attendance', ['enrollment_id' => $w['student']['id'], 'class_session_id' => $sessionA, 'status' => 'S_ATD_PRESENT', 'recorded_by' => $t['user']]);
        $this->row('academic_attendance', ['enrollment_id' => $w['student']['id'], 'class_session_id' => $sessionB, 'status' => 'S_ATD_ABSENT', 'recorded_by' => $t['user']]);
        $error = $this->denied(AcademyReason::COMPLETION_CRITERIA_NOT_MET, fn () => $svc->complete($t['user'], $t['session'], $w['student']['id']));
        self::assertSame(['ASSESSMENT_MIN_SCORE'], $error->context['unmet']);
        // Finalized but below the configured minimum: still not complete.
        $this->db()->table('grades')->where('attempt_id', $attempt)->update(['status' => 'S_GRD_FINAL', 'score' => '59.9999']);
        $this->denied(AcademyReason::COMPLETION_CRITERIA_NOT_MET, fn () => $svc->complete($t['user'], $t['session'], $w['student']['id']));
        $this->db()->table('grades')->where('attempt_id', $attempt)->update(['score' => '60']);
        $done = $svc->complete($t['user'], $t['session'], $w['student']['id'], 'critérios cumpridos');
        self::assertSame(['S_ENR_ACTIVE', 'S_ENR_COMPLETED'], [$done['from'], $done['to']]);
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'enrollments')->where('entity_id', $w['student']['id'])->where('action', 'enrollment.completed')->first();
        self::assertSame('critérios cumpridos', $audit->reason);
        self::assertSame(['ADMINISTRATIVE_APPROVAL', 'ATTENDANCE_MIN_RATIO', 'ASSESSMENT_MIN_SCORE'], json_decode($audit->after_metadata, true)['criteria']);
    }

    // C6
    public function test_c6_certificate_is_never_issued_without_valid_completion_and_policy(): void
    {
        $w = $this->certWorld();
        $svc = new CertificateService($this->rt());
        $c = $w['certifier'];
        // Policy missing (the default '{}'): POLICY_NOT_CONFIGURED even though the enrollment exists.
        $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => $svc->issue($c['user'], $c['session'], $w['student']['id'], $w['file']));
        // Policy present but the enrollment is not completed: still denied.
        $this->policy($w['version'], ['criteria' => [['type' => 'ADMINISTRATIVE_APPROVAL']]]);
        $this->denied(AcademyReason::ENROLLMENT_NOT_COMPLETED, fn () => $svc->issue($c['user'], $c['session'], $w['student']['id'], $w['file']));
        self::assertSame(0, (int) $this->db()->table('certificates')->where('enrollment_id', $w['student']['id'])->count());
    }

    public function test_certificate_issue_duplicate_revoke_and_reissue_lifecycle(): void
    {
        $w = $this->certWorld();
        $c = $w['certifier'];
        $t = $w['teacher'];
        $this->policy($w['version'], ['criteria' => [['type' => 'ADMINISTRATIVE_APPROVAL']]]);
        (new CompletionService($this->rt()))->complete($t['user'], $t['session'], $w['student']['id']);
        $svc = new CertificateService($this->rt());
        $issued = $svc->issue($c['user'], $c['session'], $w['student']['id'], $w['file'], 'homologação');
        $row = $this->db()->table('certificates')->where('id', $issued['certificate_id'])->first();
        self::assertSame(1, (int) $row->version);
        self::assertSame(hash('sha256', $issued['token'], true), $row->token_hash, 'Only the SHA-256 of the token is stored');
        self::assertNotSame($issued['public_id'], $issued['token'], 'token_hash is never public_id');
        self::assertSame($c['user'], (int) $row->approved_by);
        self::assertNull($row->revoked_at);
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'certificates')->where('entity_id', $issued['certificate_id'])->first();
        self::assertSame('certificate.issued', $audit->action);
        self::assertStringNotContainsString($issued['token'], $audit->after_metadata, 'The token never reaches audit_logs');
        // Second issue while an active certificate exists.
        $error = $this->denied(AcademyReason::CERTIFICATE_ALREADY_EXISTS, fn () => $svc->issue($c['user'], $c['session'], $w['student']['id'], $w['file']));
        self::assertSame($issued['certificate_id'], $error->context['certificate_id']);
        // Revocation needs a reason, is scoped, sets revoked_at and never deletes.
        $this->denied(AcademyReason::REASON_REQUIRED, fn () => $svc->revoke($c['user'], $c['session'], $issued['certificate_id'], ''));
        $outsider = $this->actor($this->world()['unit'], ['ACADEMY_CERTIFY']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->revoke($outsider['user'], $outsider['session'], $issued['certificate_id'], 'motivo'));
        $svc->revoke($c['user'], $c['session'], $issued['certificate_id'], 'emitido por engano');
        $revoked = $this->db()->table('certificates')->where('id', $issued['certificate_id'])->first();
        self::assertNotNull($revoked, 'Revocation is not DELETE');
        self::assertSame('S_CRT_REVOKED', $revoked->status);
        self::assertNotNull($revoked->revoked_at);
        self::assertGreaterThan($revoked->issued_at, $revoked->revoked_at);
        $this->denied(AcademyReason::CERTIFICATE_ALREADY_REVOKED, fn () => $svc->revoke($c['user'], $c['session'], $issued['certificate_id'], 'de novo'));
        $revokeAudit = $this->db()->table('audit_logs')->where('entity_type', 'certificates')->where('entity_id', $issued['certificate_id'])->where('action', 'certificate.revoked')->first();
        self::assertSame('emitido por engano', $revokeAudit->reason);
        // A re-issue after revocation is a NEW version; the revoked row stays as history.
        $second = $svc->issue($c['user'], $c['session'], $w['student']['id'], $w['file']);
        self::assertSame(2, $second['version']);
        self::assertSame(2, (int) $this->db()->table('certificates')->where('enrollment_id', $w['student']['id'])->count());
        self::assertSame(1, (int) $this->db()->table('certificates')->where('enrollment_id', $w['student']['id'])->whereNull('revoked_at')->count());
        // The file must be AVAILABLE.
        $quarantined = $this->row('files', ['owner_unit_id' => $w['unit'], 'status' => 'QUARANTINED']);
        $other = $this->enrollment($w['class']);
        $this->db()->table('enrollments')->where('id', $other['id'])->update(['status' => 'S_ENR_COMPLETED']);
        $this->denied(AcademyReason::FILE_NOT_AVAILABLE, fn () => $svc->issue($c['user'], $c['session'], $other['id'], $quarantined));
    }

    public function test_certificate_revocation_is_state_policy_pending_when_the_transition_is_not_approved(): void
    {
        $w = $this->certWorld();
        $enrollment = $this->enrollment($w['class'], null, 'S_ENR_COMPLETED');
        $this->policy($w['version'], ['criteria' => [['type' => 'ADMINISTRATIVE_APPROVAL']]]);
        $issued = (new CertificateService($this->rt()))->issue($w['certifier']['user'], $w['certifier']['session'], $enrollment['id'], $w['file']);
        $noRevocation = $this->rt($this->academyPolicy(function (array $c) {
            $c['transitions']['approved']['certificates'] = [];
            $c['transitions']['pending']['certificates'] = [['S_CRT_ISSUED', 'S_CRT_REVOKED']];
            return $c;
        }));
        $this->denied(AcademyReason::STATE_POLICY_PENDING, fn () => (new CertificateService($noRevocation))->revoke($w['certifier']['user'], $w['certifier']['session'], $issued['certificate_id'], 'motivo'));
        self::assertNull($this->db()->table('certificates')->where('id', $issued['certificate_id'])->value('revoked_at'));
    }

    // ---- transcripts + curriculum history (C8) -----------------------------------------------

    public function test_transcript_states_facts_only_and_issue_is_blocked_without_policy(): void
    {
        $w = $this->certWorld();
        $enrollment = $this->enrollment($w['class'], null, 'S_ENR_COMPLETED');
        $assessment = $this->assessment($w['version']);
        $attempt = $this->attempt($assessment, $enrollment['id']);
        $this->row('grades', ['attempt_id' => $attempt, 'version' => 1, 'score' => '77', 'status' => 'S_GRD_FINAL', 'graded_by' => $w['teacher']['user']]);
        $viewer = $this->actor($w['unit'], ['ACADEMY_GRADES_VIEW']);
        $svc = new TranscriptService($this->rt());
        $compiled = $svc->compile($viewer['user'], $viewer['session'], $enrollment['person'], $w['curriculum']);
        self::assertCount(1, $compiled['lines']);
        $line = $compiled['lines'][0];
        self::assertSame('S_ENR_COMPLETED', $line['status'], 'result status is the stored enrollment status verbatim');
        self::assertNull($line['final_score'], 'No aggregate is invented without an approved D-09 policy');
        self::assertSame(AcademyReason::POLICY_NOT_CONFIGURED, $line['final_score_state']);
        self::assertSame('77.0000', $line['grades'][0]['score']);
        $plain = $this->actor($w['unit'], ['ACADEMY_VIEW']);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->compile($plain['user'], $plain['session'], $enrollment['person'], $w['curriculum']));
        // Official issue: needs ACADEMY_CERTIFY, an AVAILABLE file and a configured transcript state.
        $certifier = $w['certifier'];
        $issued = $svc->issue($certifier['user'], $certifier['session'], $enrollment['person'], $w['curriculum'], $w['file']);
        self::assertSame([1, 1], [$issued['version'], $issued['lines']]);
        $stored = $this->db()->table('transcript_lines')->where('transcript_id', $issued['transcript_id'])->first();
        self::assertNull($stored->final_score);
        self::assertSame('S_ENR_COMPLETED', $stored->result_status);
        self::assertSame(2, $svc->issue($certifier['user'], $certifier['session'], $enrollment['person'], $w['curriculum'], $w['file'])['version']);
        $blocked = $this->rt($this->academyPolicy(function (array $c) {
            unset($c['states']['transcripts']);
            return $c;
        }));
        $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => (new TranscriptService($blocked))->issue($certifier['user'], $certifier['session'], $enrollment['person'], $w['curriculum'], $w['file']));
        $this->denied(AcademyReason::TRANSCRIPT_EMPTY, fn () => $svc->issue($certifier['user'], $certifier['session'], $this->row('people'), $w['curriculum'], $w['file']));
        self::assertSame(2, (int) $this->db()->table('transcripts')->where('person_id', $enrollment['person'])->count());
    }

    // C8
    public function test_c8_a_new_curriculum_version_never_rewrites_a_students_history(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE']);
        $viewer = $this->actor($w['unit'], ['ACADEMY_GRADES_VIEW']);
        $student = $this->enrollment($w['class']);
        $svc = new CurriculumService($this->rt());
        $published = $svc->publish($manager['user'], $manager['session'], $w['curriculum']);
        $v1 = $this->db()->table('curricula')->where('id', $w['curriculum'])->first();
        self::assertSame('S_CUR_PUBLISHED', $v1->status);
        $this->denied(AcademyReason::CURRICULUM_ALREADY_PUBLISHED, fn () => $svc->publish($manager['user'], $manager['session'], $w['curriculum']));
        $this->denied(AcademyReason::CURRICULUM_PUBLISHED_IMMUTABLE, fn () => $svc->addCourse($manager['user'], $manager['session'], $w['curriculum'], $this->row('courses'), 9, true));
        // A change to the curriculum is a NEW version.
        $next = $svc->createVersion($manager['user'], $manager['session'], $w['program']);
        self::assertSame(2, $next['version']);
        $svc->addCourse($manager['user'], $manager['session'], $next['curriculum_id'], $w['course'], 1, true);
        $svc->publish($manager['user'], $manager['session'], $next['curriculum_id']);
        // The original version is byte-identical; the class and enrollment still point at the original course version.
        $after = $this->db()->table('curricula')->where('id', $w['curriculum'])->first();
        self::assertSame([$v1->version, $v1->status, $v1->published_at, $v1->lock_version], [$after->version, $after->status, $after->published_at, $after->lock_version]);
        self::assertSame($published['published_at'], $after->published_at);
        self::assertSame($w['version'], (int) $this->db()->table('classes')->where('id', $w['class'])->value('course_version_id'));
        self::assertSame($w['curriculum'], (int) $this->db()->table('cohorts')->where('id', $w['cohort'])->value('curriculum_id'));
        $transcripts = new TranscriptService($this->rt());
        self::assertCount(1, $transcripts->compile($viewer['user'], $viewer['session'], $student['person'], $w['curriculum'])['lines'], 'History stays on the original curriculum version');
        self::assertCount(0, $transcripts->compile($viewer['user'], $viewer['session'], $student['person'], $next['curriculum_id'])['lines']);
        self::assertSame(['curriculum.published', 'curriculum.version_created', 'curriculum.course_added', 'curriculum.published'], $this->db()->table('audit_logs')->where('source', 'WAVE5_DOMAIN')->whereIn('action', ['curriculum.published', 'curriculum.version_created', 'curriculum.course_added'])->where('unit_id', $w['unit'])->orderBy('id')->pluck('action')->all());
    }

    // ---- audit transactionality + rollback (C10, section 73) ----------------------------------

    private function failingAudit(bool $writeFirst): AcademyAuditWriter
    {
        $inner = new DatabaseAcademyAudit($this->db());
        return new class($inner, $writeFirst) implements AcademyAuditWriter {
            public function __construct(private DatabaseAcademyAudit $inner, private bool $writeFirst)
            {
            }

            public function record(AcademyDecision $decision, int $unitId, string $action, string $entityType, int $entityId, ?array $before, ?array $after, ?string $reason): void
            {
                if ($this->writeFirst) {
                    $this->inner->record($decision, $unitId, $action, $entityType, $entityId, $before, $after, $reason);
                }
                throw new AcademyError(AcademyReason::STORAGE_CONFLICT, ['injected' => 'audit_failure']);
            }
        };
    }

    // C10
    public function test_c10_multi_write_operation_leaves_no_partial_state_when_its_required_audit_fails(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE']);
        $fresh = $this->row('people');
        $audits = (int) $this->db()->table('audit_logs')->count();
        // assign() writes an instructors profile AND a class_instructors row, then the required audit.
        foreach ([false, true] as $writeFirst) {
            $svc = new InstructorAssignmentService($this->rt(null, $this->failingAudit($writeFirst)));
            $this->denied(AcademyReason::STORAGE_CONFLICT, fn () => $svc->assign($manager['user'], $manager['session'], $w['class'], $fresh));
            self::assertSame(0, (int) $this->db()->table('instructors')->where('person_id', $fresh)->count(), 'No orphan instructor profile');
            self::assertSame(0, (int) $this->db()->table('class_instructors')->where('class_id', $w['class'])->count(), 'No assignment');
            self::assertSame($audits, (int) $this->db()->table('audit_logs')->count(), 'No misleading success audit survives a failed operation');
        }
        // The very same request succeeds with the real audit writer: business and audit commit together.
        $ok = (new InstructorAssignmentService($this->rt()))->assign($manager['user'], $manager['session'], $w['class'], $fresh);
        self::assertSame(1, (int) $this->db()->table('instructors')->where('person_id', $fresh)->count());
        self::assertSame(1, (int) $this->db()->table('audit_logs')->where('entity_type', 'class_instructors')->where('entity_id', $ok['assignment_id'])->count());
    }

    public function test_required_audit_failure_rolls_back_enrollment_grade_and_certificate_writes(): void
    {
        $g = $this->gradingWorld();
        $t = $g['teacher'];
        $this->denied(AcademyReason::STORAGE_CONFLICT, fn () => (new GradeService($this->rt(null, $this->failingAudit(true))))->record($t['user'], $t['session'], $g['attempt'], '50'));
        self::assertSame(0, (int) $this->db()->table('grades')->where('attempt_id', $g['attempt'])->count());
        $enroller = $this->actor($g['unit'], ['ACADEMY_ENROLL']);
        $person = $this->row('people');
        $this->denied(AcademyReason::STORAGE_CONFLICT, fn () => (new EnrollmentService($this->rt(null, $this->failingAudit(true))))->enroll($enroller['user'], $enroller['session'], $g['class'], $person));
        self::assertSame(0, (int) $this->db()->table('enrollments')->where('person_id', $person)->count());
        $c = $this->certWorld();
        $this->policy($c['version'], ['criteria' => [['type' => 'ADMINISTRATIVE_APPROVAL']]]);
        $done = $this->enrollment($c['class'], null, 'S_ENR_COMPLETED');
        $this->denied(AcademyReason::STORAGE_CONFLICT, fn () => (new CertificateService($this->rt(null, $this->failingAudit(true))))->issue($c['certifier']['user'], $c['certifier']['session'], $done['id'], $c['file']));
        self::assertSame(0, (int) $this->db()->table('certificates')->where('enrollment_id', $done['id'])->count());
    }
}
