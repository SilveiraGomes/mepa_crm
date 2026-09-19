<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

require_once __DIR__ . '/../../Database/Support/WaveFiveCase.php';

use App\Domain\Academy\AcademyAuditWriter;
use App\Domain\Academy\AcademyPolicy;
use App\Domain\Academy\AcademyRuntime;
use App\Domain\Events\EventPolicy;
use App\Domain\WaveFour\ChildrenService;
use App\Domain\WaveFour\DomainPolicy;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Database\Support\WaveFiveCase;

/**
 * P0.3.5-A2 test base (Test Infrastructure V2). Reuses an already-migrated, session-scoped Wave 5
 * pool database (created by tools/test-infrastructure/pool.py after mysql_instance.py attested the
 * dedicated instance) and TRUNCATE-resets it once per class -- no CREATE/DROP DATABASE per run.
 * WaveFiveCase.php is not modified.
 *
 * Every state name below is SYNTHETIC test data injected the way the Wave 4 tests inject theirs: no
 * production catalog exists (D-11 is open), so nothing here is an institutional value.
 */
abstract class PooledWaveFiveCase extends WaveFiveCase
{
    protected const NONE = AcademyPolicy::EFFECT_NONE;
    protected const COMPLETION = AcademyPolicy::EFFECT_COMPLETION;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 5);
        self::$capsule = self::connect();
        $db = self::$capsule->getConnection();
        DB::swap(self::$capsule->getDatabaseManager());
        Schema::swap($db->getSchemaBuilder());
        if ($db->select('SHOW TABLES') === []) {
            throw new \RuntimeException('Pool database is empty: run tools/test-infrastructure/pool.py create-and-migrate first');
        }
        $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->select('SELECT TABLE_NAME AS t FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_NAME != ?', [$db->getDatabaseName(), 'migrations']) as $row) {
            $db->statement('TRUNCATE TABLE `' . $row->t . '`');
        }
        $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        self::$catalog = json_decode(file_get_contents(self::$root . '/docs/database/model_catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    // ---- synthetic policies ------------------------------------------------------------------

    public static function eventPolicy(): EventPolicy
    {
        $states = [];
        foreach (['users', 'auth_grants', 'devices', 'events', 'sessions', 'registrations', 'lists', 'templates', 'composition', 'ministerial', 'departments', 'memberships'] as $kind) {
            $states[$kind] = ['SYNTHETIC_READY'];
        }
        $states['credentials'] = ['ISSUED'];
        return new EventPolicy('SYNTHETIC_V1', $states, true, [PHP_INT_MAX]);
    }

    public static function childPolicy(): DomainPolicy
    {
        $v = [];
        foreach (['child_profiles', 'guardian_authorizations', 'person_consents', 'child_emergency_contacts', 'child_custody_visits', 'outreach_campaigns', 'outreach_contacts', 'discipleship_tracks', 'discipleship_enrollments', 'discipleship_progress', 'age_band_rules', 'department_transition_recommendations'] as $kind) {
            $v[$kind] = ['SYNTHETIC_READY'];
        }
        $v['custody_closed'] = ['SYNTHETIC_COLLECTED'];
        $v['authorization_revoked'] = ['SYNTHETIC_REVOKED'];
        $v['consent_revoked'] = ['SYNTHETIC_REVOKED'];
        $v['followup_outcomes'] = ['SYNTHETIC_RESULT'];
        $v['decision_types'] = ['SYNTHETIC_DECISION'];
        $v['date_precisions'] = ['UNKNOWN', 'DAY'];
        $v['consent_purposes'] = ['SYNTHETIC_PARTICIPATION'];
        $v['authorization_kinds'] = ['SYNTHETIC_DELIVER', 'SYNTHETIC_PICKUP', 'SYNTHETIC_GUARDIAN'];
        return new DomainPolicy('SYNTHETIC_V1', $v, ['SYNTHETIC_IN_PERSON'], 'SYNTHETIC_DELIVER', 'SYNTHETIC_PICKUP', 'SYNTHETIC_GUARDIAN', 'SYNTHETIC_PARTICIPATION');
    }

    // Approved transitions here are a TEST vocabulary; 'pending' holds the transitions D-11 has not approved.
    public static function academyConfig(): array
    {
        return [
            'policy_version' => 'SYNTHETIC_ACADEMY_V1',
            'states' => [
                'enrollments' => ['initial' => 'S_ENR_PENDING', 'sets' => ['operational' => ['S_ENR_ACTIVE'], 'completed' => ['S_ENR_COMPLETED'], 'approval_targets' => ['S_ENR_ACTIVE']]],
                'classes' => ['initial' => 'S_CLS_DRAFT', 'sets' => ['enrollable' => ['S_CLS_OPEN'], 'teachable' => ['S_CLS_OPEN']]],
                'class_instructors' => ['initial' => 'S_CI_ACTIVE', 'sets' => ['active' => ['S_CI_ACTIVE'], 'ended' => ['S_CI_ENDED']]],
                'instructors' => ['initial' => 'S_INS_ACTIVE', 'sets' => ['active' => ['S_INS_ACTIVE']]],
                'class_sessions' => ['initial' => 'S_SES_PLANNED', 'sets' => ['attendable' => ['S_SES_PLANNED', 'S_SES_HELD']]],
                'academic_attendance' => ['sets' => ['recordable' => ['S_ATD_PRESENT', 'S_ATD_ABSENT'], 'attended' => ['S_ATD_PRESENT']]],
                'assessments' => ['initial' => 'S_ASM_OPEN', 'sets' => ['open' => ['S_ASM_OPEN']]],
                'assessment_attempts' => ['initial' => 'S_TRY_STARTED', 'sets' => ['gradable' => ['S_TRY_STARTED', 'S_TRY_SUBMITTED'], 'submitted' => ['S_TRY_SUBMITTED']]],
                'grades' => ['initial' => 'S_GRD_DRAFT', 'sets' => ['final' => ['S_GRD_FINAL']]],
                'progress' => ['initial' => 'S_PRG_OPEN', 'sets' => ['completed' => ['S_PRG_DONE']]],
                'resource_progress' => ['initial' => 'S_RPG_OPEN', 'sets' => ['verified' => ['S_RPG_DONE']]],
                'certificates' => ['initial' => 'S_CRT_ISSUED', 'sets' => ['revoked' => ['S_CRT_REVOKED']]],
                'transcripts' => ['initial' => 'S_TRN_ISSUED'],
                'curricula' => ['initial' => 'S_CUR_DRAFT', 'sets' => ['published' => ['S_CUR_PUBLISHED']]],
            ],
            'transitions' => [
                // [from, to, effect]: every approved transition DECLARES its business effect (NONE is explicit).
                'approved' => [
                    'enrollments' => [['S_ENR_PENDING', 'S_ENR_ACTIVE', self::NONE], ['S_ENR_ACTIVE', 'S_ENR_COMPLETED', self::COMPLETION], ['S_ENR_ACTIVE', 'S_ENR_WITHDRAWN', self::NONE]],
                    'class_instructors' => [['S_CI_ACTIVE', 'S_CI_ENDED', self::NONE]],
                    'class_sessions' => [['S_SES_PLANNED', 'S_SES_HELD', self::NONE]],
                    'assessment_attempts' => [['S_TRY_STARTED', 'S_TRY_SUBMITTED', self::NONE]],
                    'progress' => [['S_PRG_OPEN', 'S_PRG_DONE', self::NONE]],
                    'resource_progress' => [['S_RPG_OPEN', 'S_RPG_DONE', self::NONE]],
                    'certificates' => [['S_CRT_ISSUED', 'S_CRT_REVOKED', self::NONE]],
                    'curricula' => [['S_CUR_DRAFT', 'S_CUR_PUBLISHED', self::NONE]],
                ],
                'pending' => [
                    'grades' => [['S_GRD_DRAFT', 'S_GRD_FINAL']],
                    'enrollments' => [['S_ENR_ACTIVE', 'S_ENR_FAILED']],
                ],
            ],
        ];
    }

    public static function academyPolicy(?callable $tweak = null): AcademyPolicy
    {
        $config = self::academyConfig();
        if ($tweak !== null) {
            $config = $tweak($config);
        }
        return AcademyPolicy::fromConfig($config);
    }

    public static function runtime(Connection $db, ?AcademyPolicy $policy = null, ?AcademyAuditWriter $auditor = null): AcademyRuntime
    {
        return AcademyRuntime::make($db, $policy ?? self::academyPolicy(), self::eventPolicy(), self::childPolicy(), $auditor);
    }

    protected function rt(?AcademyPolicy $policy = null, ?AcademyAuditWriter $auditor = null): AcademyRuntime
    {
        return self::runtime($this->db(), $policy, $auditor);
    }

    // ---- fixtures ----------------------------------------------------------------------------

    protected function later(string $modify = '+6 hours'): string
    {
        return $this->now()->modify($modify)->format('Y-m-d H:i:s.u');
    }

    // One isolated academic world: org unit -> academic unit -> program -> curriculum -> cohort -> class.
    protected function world(array $classValues = []): array
    {
        $unit = $this->row('organizational_units');
        $academicUnit = $this->row('academic_units', ['unit_id' => $unit]);
        $program = $this->row('programs', ['academic_unit_id' => $academicUnit]);
        $curriculum = $this->row('curricula', ['program_id' => $program, 'version' => 1, 'status' => 'S_CUR_DRAFT']);
        $course = $this->row('courses');
        $version = $this->row('course_versions', ['course_id' => $course, 'version' => 1]);
        $cohort = $this->row('cohorts', ['academic_unit_id' => $academicUnit, 'curriculum_id' => $curriculum]);
        $class = $this->row('classes', $classValues + ['academic_unit_id' => $academicUnit, 'course_version_id' => $version, 'cohort_id' => $cohort, 'status' => 'S_CLS_OPEN']);
        $module = $this->row('course_modules', ['course_version_id' => $version]);
        $lesson = $this->row('lessons', ['module_id' => $module]);
        $this->row('curriculum_courses', ['curriculum_id' => $curriculum, 'course_id' => $course]);
        return compact('unit', 'academicUnit', 'program', 'curriculum', 'course', 'version', 'cohort', 'class', 'module', 'lesson');
    }

    // A user with a live session and one role grant of $permissions (data_type ACADEMY, action = code).
    protected function actor(int $unit, array $permissions, bool $descendants = false, string $dataType = 'ACADEMY'): array
    {
        $person = $this->row('people');
        $user = $this->row('users', ['person_id' => $person]);
        $session = $this->row('auth_sessions', ['user_id' => $user, 'expires_at' => $this->later()]);
        $role = $this->row('roles');
        $scope = $this->row('scopes', ['unit_id' => $unit, 'include_descendants' => $descendants ? 1 : 0]);
        foreach ($permissions as $code) {
            $permission = (int) $this->db()->table('permissions')->where('code', $code)->value('id');
            if (!$permission) {
                $permission = $this->row('permissions', ['code' => $code, 'action' => $code, 'data_type' => $dataType]);
            }
            $this->row('role_permissions', ['role_id' => $role, 'permission_id' => $permission]);
        }
        $link = $this->row('user_role_scopes', ['user_id' => $user, 'role_id' => $role, 'scope_id' => $scope, 'granted_by' => $user, 'ends_at' => null]);
        return ['person' => $person, 'user' => $user, 'session' => $session, 'role' => $role, 'scope' => $scope, 'link' => $link];
    }

    // An instructor profile plus an active assignment to $class for this actor's Person.
    protected function assign(array $actor, int $class, array $override = []): array
    {
        $instructor = (int) $this->db()->table('instructors')->where('person_id', $actor['person'])->value('id')
            ?: $this->row('instructors', ['person_id' => $actor['person'], 'status' => 'S_INS_ACTIVE']);
        $assignment = $this->row('class_instructors', $override + ['class_id' => $class, 'instructor_id' => $instructor, 'status' => 'S_CI_ACTIVE', 'starts_at' => $this->later('-1 hour'), 'ends_at' => null]);
        return ['instructor' => $instructor, 'assignment' => $assignment];
    }

    protected function enrollment(int $class, ?int $person = null, string $status = 'S_ENR_ACTIVE'): array
    {
        $person ??= $this->row('people');
        return ['person' => $person, 'id' => $this->row('enrollments', ['person_id' => $person, 'class_id' => $class, 'status' => $status])];
    }

    protected function assessment(int $version, array $values = []): int
    {
        return $this->row('assessments', $values + ['course_version_id' => $version, 'status' => 'S_ASM_OPEN', 'max_score' => '100', 'pass_score' => '50', 'max_attempts' => 3, 'weight' => '1']);
    }

    protected function attempt(int $assessment, int $enrollment, int $number = 1, string $status = 'S_TRY_STARTED'): int
    {
        return $this->row('assessment_attempts', ['assessment_id' => $assessment, 'enrollment_id' => $enrollment, 'attempt_number' => $number, 'status' => $status]);
    }

    protected function file(int $unit): int
    {
        return $this->row('files', ['owner_unit_id' => $unit, 'status' => 'AVAILABLE']);
    }

    // A grader who is also the class's assigned instructor, plus a gradable attempt (the common C3/C5 setup).
    protected function gradingWorld(): array
    {
        $w = $this->world();
        $teacher = $this->actor($w['unit'], ['ACADEMY_ASSESS']);
        $this->assign($teacher, $w['class']);
        $student = $this->enrollment($w['class']);
        $assessment = $this->assessment($w['version']);
        $attempt = $this->attempt($assessment, $student['id']);
        return $w + compact('teacher', 'student', 'assessment', 'attempt');
    }

    // Minor with the full Wave 4 cover (child profile + guardian relationship authorization + participation consent).
    protected function child(int $unit): array
    {
        $staff = $this->actor($unit, ['CHILD_WRITE', 'CHILD_AUTHORIZATION_WRITE', 'CHILD_CONSENT_WRITE'], false, 'CHILDREN');
        $child = $this->row('people');
        $guardian = $this->row('people');
        $service = new ChildrenService($this->db(), self::childPolicy(), self::eventPolicy());
        $profile = $service->register($staff['user'], $staff['session'], $child, $unit);
        $relationship = $this->row('person_relationships', ['subject_person_id' => $child, 'related_person_id' => $guardian, 'ends_at' => null]);
        $authorization = $service->authorizeGuardian($staff['user'], $staff['session'], $child, $guardian, 'SYNTHETIC_GUARDIAN', $this->now()->modify('-1 hour'), null, $relationship);
        $consent = $service->consent($staff['user'], $staff['session'], $child, $guardian, $authorization, 'SYNTHETIC_PARTICIPATION');
        return compact('staff', 'child', 'guardian', 'profile', 'authorization', 'consent') + ['children' => $service];
    }

    protected function denied(string $reason, callable $fn): \App\Domain\Academy\AcademyError
    {
        try {
            $fn();
        } catch (\App\Domain\Academy\AcademyError $e) {
            self::assertSame($reason, $e->reason);
            return $e;
        }
        self::fail('Expected AcademyError ' . $reason);
    }
}
