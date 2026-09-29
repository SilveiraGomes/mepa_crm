<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

require_once __DIR__ . '/Support/PooledWaveFiveCase.php';

use App\Domain\Academy\AcademyAuditWriter;
use App\Domain\Academy\AcademyDecision;
use App\Domain\Academy\AcademyPolicy;
use App\Domain\Academy\AcademyReason;
use App\Domain\Academy\CertificateService;
use App\Domain\Academy\CompletionService;
use App\Domain\Academy\DatabaseAcademyAudit;
use App\Domain\Academy\EnrollmentService;
use App\Domain\Academy\GradeService;
use App\Domain\Academy\InstructorAssignmentService;
use App\Domain\Academy\TranscriptService;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

/**
 * P0.3.5-A2.1 remediation of the independent audit blockers A2R-01..A2R-03 (A2R-04 is the static
 * validator and is proven by scripts/validate-wave5-application-contracts.cjs + the M1-M5 mutation
 * run). Every scenario below was first reproduced against the audited commit 7cbc299.
 *
 * All state names, criteria values and classifications are SYNTHETIC test data: the code under test
 * carries none (D-09/D-11 stay open).
 */
final class AcademyRemediationTest extends PooledWaveFiveCase
{
    // ---- helpers ------------------------------------------------------------------------------

    private function criteria(int $version, ?array $criteria): void
    {
        $this->db()->table('course_versions')->where('id', $version)->update(['completion_policy_metadata' => $criteria === null ? null : json_encode(['criteria' => $criteria])]);
    }

    // One class, one held session, an ACTIVE student who attended nothing, and a configured criterion
    // ("attend every held session": test data) that the student does not meet.
    private function completionWorld(): array
    {
        $w = $this->world();
        $session = $this->row('class_sessions', ['class_id' => $w['class'], 'status' => 'S_SES_HELD']);
        $this->criteria($w['version'], [['type' => 'ATTENDANCE_MIN_RATIO', 'min_ratio' => '1.0000']]);
        $teacher = $this->actor($w['unit'], ['ACADEMY_ASSESS']);
        $this->assign($teacher, $w['class']);
        $clerk = $this->actor($w['unit'], ['ACADEMY_ENROLL']);
        $both = $this->actor($w['unit'], ['ACADEMY_ENROLL', 'ACADEMY_ASSESS']);
        $this->assign($both, $w['class']);
        $certifier = $this->actor($w['unit'], ['ACADEMY_CERTIFY']);
        $viewer = $this->actor($w['unit'], ['ACADEMY_GRADES_VIEW']);
        $student = $this->enrollment($w['class']);
        return $w + compact('session', 'teacher', 'clerk', 'both', 'certifier', 'viewer', 'student') + ['file' => $this->file($w['unit'])];
    }

    private function attend(array $w, array $student): void
    {
        $this->row('academic_attendance', ['enrollment_id' => $student['id'], 'class_session_id' => $w['session'], 'status' => 'S_ATD_PRESENT', 'recorded_by' => $w['teacher']['user']]);
    }

    private function status(int $enrollment): string
    {
        return (string) $this->db()->table('enrollments')->where('id', $enrollment)->value('status');
    }

    // A second, independent grant of $permissions for an existing user.
    private function grant(array $actor, int $unit, array $permissions): int
    {
        $role = $this->row('roles');
        $scope = $this->row('scopes', ['unit_id' => $unit, 'include_descendants' => 0]);
        foreach ($permissions as $code) {
            $permission = (int) $this->db()->table('permissions')->where('code', $code)->value('id')
                ?: $this->row('permissions', ['code' => $code, 'action' => $code, 'data_type' => 'ACADEMY']);
            $this->row('role_permissions', ['role_id' => $role, 'permission_id' => $permission]);
        }
        return $this->row('user_role_scopes', ['user_id' => $actor['user'], 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $actor['user'], 'ends_at' => null]);
    }

    private function audits(int $enrollment, string $action): int
    {
        return (int) $this->db()->table('audit_logs')->where('entity_type', 'enrollments')->where('entity_id', $enrollment)->where('action', $action)->count();
    }

    // ---- A2R-01: the generic transition cannot produce a completion the specialized operation refuses ----

    // R1.1
    public function test_r1_1_completion_service_refuses_when_the_configured_criteria_are_not_met(): void
    {
        $w = $this->completionWorld();
        $t = $w['teacher'];
        $error = $this->denied(AcademyReason::COMPLETION_CRITERIA_NOT_MET, fn () => (new CompletionService($this->rt()))->complete($t['user'], $t['session'], $w['student']['id']));
        self::assertSame(['ATTENDANCE_MIN_RATIO'], $error->context['unmet']);
        self::assertSame('S_ENR_ACTIVE', $this->status($w['student']['id']));
    }

    // R1.2 + R1.3 -- the exact exploit of the audit (an ENROLL-only clerk), plus the variants that still hold some authority.
    public function test_r1_2_and_r1_3_the_generic_transition_is_refused_and_persists_nothing(): void
    {
        $w = $this->completionWorld();
        $id = $w['student']['id'];
        $before = $this->db()->table('enrollments')->where('id', $id)->first();
        $auditsBefore = (int) $this->db()->table('audit_logs')->count();
        $svc = new EnrollmentService($this->rt());
        // (a) the audited exploit: ACADEMY_ENROLL alone -> no completion authority.
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->transition($w['clerk']['user'], $w['clerk']['session'], $id, 'S_ENR_COMPLETED', 'marcar concluído'));
        // (b) completion authority alone (ASSESS + assignment) is not the transition authority.
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => $svc->transition($w['teacher']['user'], $w['teacher']['session'], $id, 'S_ENR_COMPLETED'));
        // (c) BOTH authorities: the same guard as CompletionService still refuses the unmet criterion.
        $error = $this->denied(AcademyReason::COMPLETION_CRITERIA_NOT_MET, fn () => $svc->transition($w['both']['user'], $w['both']['session'], $id, 'S_ENR_COMPLETED'));
        self::assertSame(['ATTENDANCE_MIN_RATIO'], $error->context['unmet']);
        // (d) completion authority without the class assignment (a foreign class): CLASS_ASSIGNMENT_REQUIRED.
        $unassigned = $this->actor($w['unit'], ['ACADEMY_ENROLL', 'ACADEMY_ASSESS']);
        $this->denied(AcademyReason::CLASS_ASSIGNMENT_REQUIRED, fn () => $svc->transition($unassigned['user'], $unassigned['session'], $id, 'S_ENR_COMPLETED'));
        // R1.3: nothing persisted, not even a misleading audit row.
        $after = $this->db()->table('enrollments')->where('id', $id)->first();
        self::assertEquals($before, $after, 'status and lock_version are untouched');
        self::assertSame($auditsBefore, (int) $this->db()->table('audit_logs')->count());
    }

    // R1.4 + R1.5
    public function test_r1_4_and_r1_5_no_certificate_and_no_false_completion_on_the_transcript_after_a_refused_bypass(): void
    {
        $w = $this->completionWorld();
        $id = $w['student']['id'];
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => (new EnrollmentService($this->rt()))->transition($w['clerk']['user'], $w['clerk']['session'], $id, 'S_ENR_COMPLETED'));
        $c = $w['certifier'];
        $this->denied(AcademyReason::ENROLLMENT_NOT_COMPLETED, fn () => (new CertificateService($this->rt()))->issue($c['user'], $c['session'], $id, $w['file']));
        self::assertSame(0, (int) $this->db()->table('certificates')->where('enrollment_id', $id)->count());
        $line = (new TranscriptService($this->rt()))->compile($w['viewer']['user'], $w['viewer']['session'], $w['student']['person'], $w['curriculum'])['lines'][0];
        self::assertSame('S_ENR_ACTIVE', $line['status'], 'the transcript states the stored status, which never became completed');
        self::assertNull($line['certificate_id']);
    }

    // R1.6
    public function test_r1_6_when_the_requirements_are_really_met_the_specialized_completion_and_the_guarded_transition_pass(): void
    {
        $w = $this->completionWorld();
        $this->attend($w, $w['student']);
        $done = (new CompletionService($this->rt()))->complete($w['teacher']['user'], $w['teacher']['session'], $w['student']['id'], 'critérios cumpridos');
        self::assertSame(['S_ENR_ACTIVE', 'S_ENR_COMPLETED'], [$done['from'], $done['to']]);
        self::assertSame(1, $this->audits($w['student']['id'], 'enrollment.completed'));
        // Certificate issuance follows the (legitimate) completion.
        $c = $w['certifier'];
        self::assertSame(1, (new CertificateService($this->rt()))->issue($c['user'], $c['session'], $w['student']['id'], $w['file'])['version']);
        // The generic transition reaches the same outcome ONLY through the same guard, with both authorities.
        $other = $this->enrollment($w['class']);
        $this->attend($w, $other);
        $moved = (new EnrollmentService($this->rt()))->transition($w['both']['user'], $w['both']['session'], $other['id'], 'S_ENR_COMPLETED');
        self::assertSame(['S_ENR_ACTIVE', 'S_ENR_COMPLETED'], [$moved['from'], $moved['to']]);
        $audit = $this->db()->table('audit_logs')->where('entity_type', 'enrollments')->where('entity_id', $other['id'])->where('action', 'enrollment.completed')->first();
        self::assertNotNull($audit, 'a completion is audited as a completion whichever entry point produced it');
        self::assertSame('enrollment.transition', json_decode($audit->after_metadata, true)['via']);
        self::assertSame(0, $this->audits($other['id'], 'enrollment.transitioned'));
    }

    // R1.7
    public function test_r1_7_transitions_unrelated_to_completion_keep_working_as_the_policy_declares(): void
    {
        $w = $this->world();
        $clerk = $this->actor($w['unit'], ['ACADEMY_ENROLL']);
        $pending = $this->enrollment($w['class'], null, 'S_ENR_PENDING');
        $svc = new EnrollmentService($this->rt());
        self::assertSame('S_ENR_ACTIVE', $svc->transition($clerk['user'], $clerk['session'], $pending['id'], 'S_ENR_ACTIVE')['to']);
        self::assertSame('S_ENR_WITHDRAWN', $svc->transition($clerk['user'], $clerk['session'], $pending['id'], 'S_ENR_WITHDRAWN')['to']);
        self::assertSame(1, $this->audits($pending['id'], 'enrollment.transitioned') - 1, 'the second transition is audited as a plain transition');
        // Parked (pending) and unknown transitions keep their D-11 answers.
        $active = $this->enrollment($w['class']);
        $this->denied(AcademyReason::STATE_POLICY_PENDING, fn () => $svc->transition($clerk['user'], $clerk['session'], $active['id'], 'S_ENR_FAILED'));
        $this->denied(AcademyReason::INVALID_TRANSITION, fn () => $svc->transition($clerk['user'], $clerk['session'], $active['id'], 'S_ENR_UNKNOWN'));
    }

    // R1.8
    public function test_r1_8_enroll_alone_never_grants_completion_or_certification(): void
    {
        $w = $this->completionWorld();
        $this->attend($w, $w['student']);                     // requirements ARE met: only authority is missing
        $clerk = $w['clerk'];
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => (new EnrollmentService($this->rt()))->transition($clerk['user'], $clerk['session'], $w['student']['id'], 'S_ENR_COMPLETED'));
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => (new CompletionService($this->rt()))->complete($clerk['user'], $clerk['session'], $w['student']['id']));
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => (new CertificateService($this->rt()))->issue($clerk['user'], $clerk['session'], $w['student']['id'], $w['file']));
        self::assertSame('S_ENR_ACTIVE', $this->status($w['student']['id']));
        self::assertSame(0, (int) $this->db()->table('certificates')->where('enrollment_id', $w['student']['id'])->count());
    }

    // D-09: nothing is invented when the policy is absent.
    public function test_r1_missing_completion_policy_is_policy_not_configured_through_the_generic_path_too(): void
    {
        $w = $this->completionWorld();
        $this->criteria($w['version'], null);
        $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => (new EnrollmentService($this->rt()))->transition($w['both']['user'], $w['both']['session'], $w['student']['id'], 'S_ENR_COMPLETED'));
        $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => (new CompletionService($this->rt()))->complete($w['both']['user'], $w['both']['session'], $w['student']['id']));
        self::assertSame('S_ENR_ACTIVE', $this->status($w['student']['id']));
    }

    // D-11: the effect comes from the policy. Undeclared or contradictory declarations fail closed.
    public function test_r1_the_transition_effect_is_declared_by_the_policy_and_an_ambiguous_declaration_fails_closed(): void
    {
        $w = $this->completionWorld();
        $this->attend($w, $w['student']);                     // met, and both authorities are held: only the policy can stop it
        $b = $w['both'];
        $id = $w['student']['id'];
        // [label => [policy tweak, the target state whose declaration is ambiguous]]
        $variants = [
            'effect undeclared' => [fn (array $c) => $this->transitions($c, [['S_ENR_ACTIVE', 'S_ENR_COMPLETED']]), 'S_ENR_COMPLETED'],
            'completion declared as no effect' => [fn (array $c) => $this->transitions($c, [['S_ENR_ACTIVE', 'S_ENR_COMPLETED', AcademyPolicy::EFFECT_NONE]]), 'S_ENR_COMPLETED'],
            'a non-completed state declared as completion' => [fn (array $c) => $this->transitions($c, [['S_ENR_ACTIVE', 'S_ENR_COMPLETED', AcademyPolicy::EFFECT_COMPLETION], ['S_ENR_ACTIVE', 'S_ENR_WITHDRAWN', AcademyPolicy::EFFECT_COMPLETION]]), 'S_ENR_WITHDRAWN'],
            'conflicting duplicate declarations' => [fn (array $c) => $this->transitions($c, [['S_ENR_ACTIVE', 'S_ENR_COMPLETED', AcademyPolicy::EFFECT_COMPLETION], ['S_ENR_ACTIVE', 'S_ENR_COMPLETED', AcademyPolicy::EFFECT_NONE]]), 'S_ENR_COMPLETED'],
            'unknown effect' => [fn (array $c) => $this->transitions($c, [['S_ENR_ACTIVE', 'S_ENR_COMPLETED', 'SOMETHING_ELSE']]), 'S_ENR_COMPLETED'],
        ];
        foreach ($variants as $label => [$tweak, $target]) {
            $rt = $this->rt($this->academyPolicy($tweak));
            $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => (new EnrollmentService($rt))->transition($b['user'], $b['session'], $id, $target));
            if ($target === 'S_ENR_COMPLETED') {
                $this->denied(AcademyReason::POLICY_NOT_CONFIGURED, fn () => (new CompletionService($rt))->complete($b['user'], $b['session'], $id));
            }
            self::assertSame('S_ENR_ACTIVE', $this->status($id), $label);
        }
        // Control: the well-formed policy completes it.
        self::assertSame('S_ENR_COMPLETED', (new EnrollmentService($this->rt()))->transition($b['user'], $b['session'], $id, 'S_ENR_COMPLETED')['to']);
    }

    private function transitions(array $config, array $enrollments): array
    {
        $config['transitions']['approved']['enrollments'] = $enrollments;
        return $config;
    }

    // Commit-time: the completion authority attached to the generic transition is re-verified with the final check.
    public function test_r1_completion_authority_attached_to_the_generic_transition_is_rechecked_at_commit(): void
    {
        $w = $this->completionWorld();
        $this->attend($w, $w['student']);
        $actor = $this->actor($w['unit'], ['ACADEMY_ENROLL']);
        $assessGrant = $this->grant($actor, $w['unit'], ['ACADEMY_ASSESS']);
        $this->assign($actor, $w['class']);
        $revokesAssess = new class(new DatabaseAcademyAudit($this->db()), $this->db(), $assessGrant) implements AcademyAuditWriter {
            public function __construct(private DatabaseAcademyAudit $inner, private $db, private int $grant)
            {
            }

            public function record(AcademyDecision $decision, int $unitId, string $action, string $entityType, int $entityId, ?array $before, ?array $after, ?string $reason): void
            {
                $this->inner->record($decision, $unitId, $action, $entityType, $entityId, $before, $after, $reason);
                $this->db->table('user_role_scopes')->where('id', $this->grant)->update(['status' => 'S_REVOKED']);   // lapses while the operation runs
            }
        };
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => (new EnrollmentService($this->rt(null, $revokesAssess)))->transition($actor['user'], $actor['session'], $w['student']['id'], 'S_ENR_COMPLETED'));
        self::assertSame('S_ENR_ACTIVE', $this->status($w['student']['id']), 'the revoked completion authority rolled the completion back');
        self::assertSame(0, $this->audits($w['student']['id'], 'enrollment.completed'));
    }

    // ---- A2R-02: read scope of the history / transcript ---------------------------------------------

    // Unit A owns the curriculum; unit B owns a class whose cohort points at A's curriculum (the schema allows it).
    private function crossUnitWorld(): array
    {
        $a = $this->world();
        $b = $this->world();
        $classB = $this->row('classes', ['academic_unit_id' => $b['academicUnit'], 'course_version_id' => $b['version'], 'cohort_id' => $a['cohort'], 'status' => 'S_CLS_OPEN']);
        $teacherB = $this->actor($b['unit'], ['ACADEMY_ASSESS']);
        $person = $this->row('people');
        $inA = $this->enrollment($a['class'], $person);
        $inB = $this->enrollment($classB, $person);
        $grades = [];
        foreach ([['a', $a['version'], $inA, '71'], ['b', $b['version'], $inB, '88']] as [$k, $version, $enrollment, $score]) {
            $assessment = $this->assessment($version);
            $attempt = $this->attempt($assessment, $enrollment['id']);
            $this->row('grades', ['attempt_id' => $attempt, 'version' => 1, 'score' => $score, 'status' => 'S_GRD_FINAL', 'graded_by' => $teacherB['user']]);
            $grades[$k] = $attempt;
        }
        $viewerA = $this->actor($a['unit'], ['ACADEMY_GRADES_VIEW']);
        return compact('a', 'b', 'classB', 'person', 'inA', 'inB', 'grades', 'viewerA', 'teacherB');
    }

    // R2.1
    public function test_r2_1_grades_view_in_unit_a_reads_the_grade_of_unit_a(): void
    {
        $x = $this->crossUnitWorld();
        $v = $x['viewerA'];
        $history = (new GradeService($this->rt()))->history($v['user'], $v['session'], $x['grades']['a']);
        self::assertSame('71.0000', (string) $history[0]['score']);
        // A person whose whole history lives in unit A: the transcript is returned.
        $onlyA = $this->enrollment($x['a']['class']);
        $lines = (new TranscriptService($this->rt()))->compile($v['user'], $v['session'], $onlyA['person'], $x['a']['curriculum'])['lines'];
        self::assertCount(1, $lines);
    }

    // R2.2
    public function test_r2_2_grades_view_in_unit_a_does_not_read_the_grade_of_unit_b(): void
    {
        $x = $this->crossUnitWorld();
        $v = $x['viewerA'];
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => (new GradeService($this->rt()))->history($v['user'], $v['session'], $x['grades']['b']));
        // The audited leak: the aggregate transcript (curriculum in A) used to list the enrollment and the 88 of unit B.
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => (new TranscriptService($this->rt()))->compile($v['user'], $v['session'], $x['person'], $x['a']['curriculum']));
    }

    // R2.3
    public function test_r2_3_a_claimed_unit_never_changes_the_real_target(): void
    {
        $x = $this->crossUnitWorld();
        $v = $x['viewerA'];
        $claimA = ['academic_unit_id' => $x['a']['academicUnit'], 'unit_id' => $x['a']['unit'], 'class_id' => $x['a']['class']];
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => (new GradeService($this->rt()))->history($v['user'], $v['session'], $x['grades']['b'], $claimA));
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => (new TranscriptService($this->rt()))->compile($v['user'], $v['session'], $x['person'], $x['a']['curriculum'], $claimA));
        // An actor authorized in BOTH units is still not fooled by a claim that names the wrong one.
        $both = $this->actor($x['a']['unit'], ['ACADEMY_GRADES_VIEW']);
        $this->grant($both, $x['b']['unit'], ['ACADEMY_GRADES_VIEW']);
        $this->denied(AcademyReason::CONTEXT_MISMATCH, fn () => (new GradeService($this->rt()))->history($both['user'], $both['session'], $x['grades']['b'], $claimA));
        self::assertCount(1, (new GradeService($this->rt()))->history($both['user'], $both['session'], $x['grades']['b'], ['academic_unit_id' => $x['b']['academicUnit']]));
    }

    // R2.4 -- explicit aggregate semantics (documented decision B): authority over EVERY contributing unit, else the whole operation is denied.
    public function test_r2_4_the_aggregate_transcript_needs_authority_over_every_contributing_unit_and_is_never_partial(): void
    {
        $x = $this->crossUnitWorld();
        $svc = new TranscriptService($this->rt());
        $v = $x['viewerA'];
        $viewerB = $this->actor($x['b']['unit'], ['ACADEMY_GRADES_VIEW']);
        // Only A, only B (B does not own the curriculum): neither gets a partial answer.
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->compile($v['user'], $v['session'], $x['person'], $x['a']['curriculum']));
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->compile($viewerB['user'], $viewerB['session'], $x['person'], $x['a']['curriculum']));
        // A + B (two independent grants): the complete transcript, both lines.
        $both = $this->actor($x['a']['unit'], ['ACADEMY_GRADES_VIEW']);
        $this->grant($both, $x['b']['unit'], ['ACADEMY_GRADES_VIEW']);
        $lines = $svc->compile($both['user'], $both['session'], $x['person'], $x['a']['curriculum'])['lines'];
        self::assertSame([$x['inA']['id'], $x['inB']['id']], array_column($lines, 'enrollment_id'));
        // The official issue has the same rule: CERTIFY in A alone cannot freeze unit B's records into a document.
        $certifierA = $this->actor($x['a']['unit'], ['ACADEMY_CERTIFY']);
        $file = $this->file($x['a']['unit']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->issue($certifierA['user'], $certifierA['session'], $x['person'], $x['a']['curriculum'], $file));
        self::assertSame(0, (int) $this->db()->table('transcripts')->where('person_id', $x['person'])->count());
        $certifierBoth = $this->actor($x['a']['unit'], ['ACADEMY_CERTIFY']);
        $this->grant($certifierBoth, $x['b']['unit'], ['ACADEMY_CERTIFY']);
        self::assertSame(2, $svc->issue($certifierBoth['user'], $certifierBoth['session'], $x['person'], $x['a']['curriculum'], $file)['lines']);
    }

    // R2.5
    public function test_r2_5_a_superior_scope_with_descendants_is_authorized_and_admin_is_never_a_read_bypass(): void
    {
        $x = $this->crossUnitWorld();
        $parent = $this->row('organizational_units');
        $this->db()->table('organizational_units')->whereIn('id', [$x['a']['unit'], $x['b']['unit']])->update(['parent_id' => $parent]);
        $superior = $this->actor($parent, ['ACADEMY_GRADES_VIEW'], true);
        $lines = (new TranscriptService($this->rt()))->compile($superior['user'], $superior['session'], $x['person'], $x['a']['curriculum'])['lines'];
        self::assertCount(2, $lines);
        self::assertCount(1, (new GradeService($this->rt()))->history($superior['user'], $superior['session'], $x['grades']['b']));
        // Reads have no admin override: ACADEMY_ADMIN alone is not GRADES_VIEW.
        $admin = $this->actor($x['a']['unit'], ['ACADEMY_ADMIN']);
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => (new GradeService($this->rt()))->history($admin['user'], $admin['session'], $x['grades']['a']));
        $this->denied(AcademyReason::NOT_AUTHORIZED, fn () => (new TranscriptService($this->rt()))->compile($admin['user'], $admin['session'], $x['inA']['person'], $x['a']['curriculum']));
    }

    // R2.6
    public function test_r2_6_membership_never_changes_the_academic_scope(): void
    {
        $x = $this->crossUnitWorld();
        $v = $x['viewerA'];
        // Membership of the person in unit A does not make the class of unit B readable ...
        $this->row('memberships', ['person_id' => $x['person'], 'unit_id' => $x['a']['unit']]);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => (new TranscriptService($this->rt()))->compile($v['user'], $v['session'], $x['person'], $x['a']['curriculum']));
        // ... and an external person (no membership at all) is read exactly like a member.
        $external = $this->enrollment($x['a']['class']);
        self::assertSame(0, (int) $this->db()->table('memberships')->where('person_id', $external['person'])->count());
        self::assertCount(1, (new TranscriptService($this->rt()))->compile($v['user'], $v['session'], $external['person'], $x['a']['curriculum'])['lines']);
    }

    // Deriving the transcript target now looks at the Person's enrollments; that must not turn the call into an
    // existence oracle: the Person is looked up without failing and any error about it comes AFTER authorization.
    public function test_r2_person_existence_is_revealed_only_after_authorization(): void
    {
        $x = $this->crossUnitWorld();
        $svc = new TranscriptService($this->rt());
        $outsider = $this->actor($x['b']['unit'], ['ACADEMY_GRADES_VIEW']);           // no authority over the curriculum's unit (A)
        $insider = $x['viewerA'];
        $ghost = '01ARZ3NDEKTSV4RRFFQ69G5FAV';                                          // well-formed ULID, no such Person
        foreach ([$ghost, 'not-a-person', 987654321] as $reference) {
            $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->compile($outsider['user'], $outsider['session'], $reference, $x['a']['curriculum']));
        }
        $this->denied(AcademyReason::TARGET_NOT_FOUND, fn () => $svc->compile($insider['user'], $insider['session'], $ghost, $x['a']['curriculum']));
        $this->denied(AcademyReason::INVALID_PERSON_REFERENCE, fn () => $svc->compile($insider['user'], $insider['session'], 'not-a-person', $x['a']['curriculum']));
        $certifier = $this->actor($x['a']['unit'], ['ACADEMY_CERTIFY']);
        $file = $this->file($x['a']['unit']);
        $this->denied(AcademyReason::TARGET_NOT_FOUND, fn () => $svc->issue($certifier['user'], $certifier['session'], $ghost, $x['a']['curriculum'], $file));
        $outsiderCertifier = $this->actor($x['b']['unit'], ['ACADEMY_CERTIFY']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->issue($outsiderCertifier['user'], $outsiderCertifier['session'], $ghost, $x['a']['curriculum'], $file));
    }

    // ---- A2R-03: files and documents referenced by academic objects ----------------------------------

    private function certificateWorld(): array
    {
        $w = $this->world();
        $this->criteria($w['version'], [['type' => 'ADMINISTRATIVE_APPROVAL']]);
        $certifier = $this->actor($w['unit'], ['ACADEMY_CERTIFY']);
        $student = $this->enrollment($w['class'], null, 'S_ENR_COMPLETED');
        $other = $this->world();
        return $w + compact('certifier', 'student', 'other');
    }

    private function certificates(int $enrollment): int
    {
        return (int) $this->db()->table('certificates')->where('enrollment_id', $enrollment)->count();
    }

    // R3.1
    public function test_r3_1_a_certificate_with_a_file_owned_by_its_own_unit_is_issued(): void
    {
        $w = $this->certificateWorld();
        $c = $w['certifier'];
        $issued = (new CertificateService($this->rt()))->issue($c['user'], $c['session'], $w['student']['id'], $this->file($w['unit']));
        self::assertSame(1, $issued['version']);
    }

    // R3.2 + R3.3
    public function test_r3_2_and_r3_3_a_file_of_another_unit_is_refused_whatever_its_classification(): void
    {
        $w = $this->certificateWorld();
        $c = $w['certifier'];
        $svc = new CertificateService($this->rt());
        $issuedAudits = (int) $this->db()->table('audit_logs')->where('action', 'certificate.issued')->count();
        foreach (['R-CONFIDENCIAL', 'INTERNAL_SYNTHETIC', 'SYNTHETIC_ANYTHING'] as $classification) {
            $foreign = $this->row('files', ['owner_unit_id' => $w['other']['unit'], 'status' => 'AVAILABLE', 'classification' => $classification]);
            $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->issue($c['user'], $c['session'], $w['student']['id'], $foreign));
        }
        self::assertSame(0, $this->certificates($w['student']['id']), 'no certificate row, no misleading audit');
        self::assertSame($issuedAudits, (int) $this->db()->table('audit_logs')->where('action', 'certificate.issued')->count());
        // The foreign file's own status is not revealed: quarantined or not, it is out of scope first.
        $quarantined = $this->row('files', ['owner_unit_id' => $w['other']['unit'], 'status' => 'QUARANTINED']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->issue($c['user'], $c['session'], $w['student']['id'], $quarantined));
        // Holding CERTIFY in the foreign unit too does not turn it into the enrollment's file: the target unit is the class's.
        $both = $this->actor($w['unit'], ['ACADEMY_CERTIFY']);
        $this->grant($both, $w['other']['unit'], ['ACADEMY_CERTIFY']);
        $foreign = $this->file($w['other']['unit']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->issue($both['user'], $both['session'], $w['student']['id'], $foreign));
    }

    // R3.4 (+ P08-D-F01: the source document is referenced by its public_id only, resolved after authority)
    public function test_r3_4_a_source_document_of_another_unit_is_refused_and_nothing_is_created(): void
    {
        $w = $this->world();
        $manager = $this->actor($w['unit'], ['ACADEMY_MANAGE']);
        $type = $this->row('legal_document_types');
        $foreign = $this->row('legal_documents', ['document_type_id' => $type, 'owner_unit_id' => $this->world()['unit'], 'status' => 'ACTIVE']);
        $own = $this->row('legal_documents', ['document_type_id' => $type, 'owner_unit_id' => $w['unit'], 'status' => 'ACTIVE']);
        $archived = $this->row('legal_documents', ['document_type_id' => $type, 'owner_unit_id' => $w['unit'], 'status' => 'ARCHIVED']);
        $pub = fn (int $id): string => (string) $this->db()->table('legal_documents')->where('id', $id)->value('public_id');
        $person = $this->row('people');
        $svc = new InstructorAssignmentService($this->rt());
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->assign($manager['user'], $manager['session'], $w['class'], $person, null, null, 'nomeação', $pub($foreign)));
        self::assertSame(0, (int) $this->db()->table('instructors')->where('person_id', $person)->count(), 'no orphan instructor profile');
        self::assertSame(0, (int) $this->db()->table('class_instructors')->where('class_id', $w['class'])->count());
        // A document of the class's own unit, no document at all, and an unknown / malformed / numeric / archived
        // document: the last four converge on the same concealed reason (no existence or ownership oracle).
        $ok = $svc->assign($manager['user'], $manager['session'], $w['class'], $person, null, null, 'nomeação', $pub($own));
        self::assertSame($own, (int) $this->db()->table('class_instructors')->where('id', $ok['assignment_id'])->value('source_document_id'));
        $other = $this->row('people');
        self::assertNull($this->db()->table('class_instructors')->where('id', $svc->assign($manager['user'], $manager['session'], $w['class'], $other)['assignment_id'])->value('source_document_id'));
        foreach (['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid', (string) $own, $pub($archived)] as $reference) {
            $third = $this->row('people');
            $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->assign($manager['user'], $manager['session'], $w['class'], $third, null, null, null, $reference));
        }
        // The internal key itself is not even a valid argument any more.
        $this->expectException(\TypeError::class);
        $svc->assign($manager['user'], $manager['session'], $w['class'], $this->row('people'), null, null, null, $own);
    }

    // R3.5
    public function test_r3_5_claiming_unit_a_in_the_payload_does_not_make_a_unit_b_file_usable(): void
    {
        $w = $this->certificateWorld();
        $c = $w['certifier'];
        $foreign = $this->file($w['other']['unit']);
        $claim = ['academic_unit_id' => $w['academicUnit'], 'unit_id' => $w['unit'], 'class_id' => $w['class']];   // TRUE about the target ...
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => (new CertificateService($this->rt()))->issue($c['user'], $c['session'], $w['student']['id'], $foreign, null, null, $claim));   // ... irrelevant to the file
        $lying = ['unit_id' => $w['other']['unit']];
        $this->denied(AcademyReason::CONTEXT_MISMATCH, fn () => (new CertificateService($this->rt()))->issue($c['user'], $c['session'], $w['student']['id'], $this->file($w['unit']), null, null, $lying));
        self::assertSame(0, $this->certificates($w['student']['id']));
    }

    // R3.6
    public function test_r3_6_an_unknown_file_is_a_deterministic_domain_error(): void
    {
        $w = $this->certificateWorld();
        $c = $w['certifier'];
        foreach ([987654321, 987654322] as $missing) {
            $this->denied(AcademyReason::FILE_NOT_AVAILABLE, fn () => (new CertificateService($this->rt()))->issue($c['user'], $c['session'], $w['student']['id'], $missing));
        }
        // Own-unit files that are not available keep FILE_NOT_AVAILABLE.
        foreach (['QUARANTINED', 'TOMBSTONE'] as $status) {
            $file = $this->row('files', ['owner_unit_id' => $w['unit'], 'status' => $status]);
            $this->denied(AcademyReason::FILE_NOT_AVAILABLE, fn () => (new CertificateService($this->rt()))->issue($c['user'], $c['session'], $w['student']['id'], $file));
        }
        self::assertSame(0, $this->certificates($w['student']['id']));
    }

    // R3.7 -- no global/shared-resource mechanism is approved, so none is granted: an ancestor unit's file is out of scope too.
    public function test_r3_7_no_shared_resource_exception_exists_an_ancestor_units_file_is_refused_as_well(): void
    {
        $w = $this->certificateWorld();
        $parent = $this->row('organizational_units');
        $this->db()->table('organizational_units')->where('id', $w['unit'])->update(['parent_id' => $parent]);
        $shared = $this->file($parent);
        $c = $w['certifier'];
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => (new CertificateService($this->rt()))->issue($c['user'], $c['session'], $w['student']['id'], $shared));
        // A department-owned file cannot be vouched for by a UNIT-scoped Academy authority either.
        $department = $this->row('department_instances', ['unit_id' => $w['unit']]);
        $owned = $this->row('files', ['owner_unit_id' => $w['unit'], 'owner_department_id' => $department, 'status' => 'AVAILABLE']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => (new CertificateService($this->rt()))->issue($c['user'], $c['session'], $w['student']['id'], $owned));
        self::assertSame(0, $this->certificates($w['student']['id']));
    }

    // The transcript file goes through the same guard.
    public function test_r3_the_official_transcript_file_is_checked_like_the_certificate_file(): void
    {
        $w = $this->certificateWorld();
        $c = $w['certifier'];
        $svc = new TranscriptService($this->rt());
        $foreign = $this->file($w['other']['unit']);
        $this->denied(AcademyReason::OUT_OF_SCOPE, fn () => $svc->issue($c['user'], $c['session'], $w['student']['person'], $w['curriculum'], $foreign));
        self::assertSame(0, (int) $this->db()->table('transcripts')->where('person_id', $w['student']['person'])->count());
        self::assertSame(1, $svc->issue($c['user'], $c['session'], $w['student']['person'], $w['curriculum'], $this->file($w['unit']))['version']);
    }
}
