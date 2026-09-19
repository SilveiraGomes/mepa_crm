<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Academy\AcademyError;
use App\Domain\Academy\AcademyInput;
use App\Domain\Academy\AcademyOperation;
use App\Domain\Academy\AcademyPolicy;
use App\Domain\Academy\AcademyReason;
use App\Domain\Academy\AcademyRuntime;
use App\Domain\Academy\AcademyStateMachine;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

/**
 * P0.3.5-A2 UNIT tests (mission section 56): pure decisions with no database -- policy resolution,
 * state-transition validation, the permission matrix and error mapping.
 */
final class AcademyDomainUnitTest extends TestCase
{
    private function policy(array $approved = [], array $pending = []): AcademyPolicy
    {
        return new AcademyPolicy('V', ['enrollments' => ['initial' => 'A', 'sets' => ['operational' => ['B'], 'completed' => ['C']]]], ['approved' => ['enrollments' => $approved], 'pending' => ['enrollments' => $pending]]);
    }

    private function reason(callable $fn): string
    {
        try {
            $fn();
        } catch (AcademyError $e) {
            return $e->reason;
        }
        self::fail('Expected AcademyError');
    }

    // ---- policy resolution (D-09 / D-11) ----------------------------------------------------

    public function test_the_shipped_production_config_approves_nothing_so_everything_fails_closed(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/academy.php';
        self::assertSame([], $config['states'], 'D-11 is open: no state is institutionally approved');
        self::assertSame(['approved' => [], 'pending' => []], $config['transitions']);
        $policy = AcademyPolicy::fromConfig($config);
        self::assertSame(AcademyReason::POLICY_NOT_CONFIGURED, $this->reason(fn () => $policy->version()));
        foreach (['enrollments', 'classes', 'grades', 'certificates', 'anything'] as $kind) {
            self::assertSame(AcademyReason::POLICY_NOT_CONFIGURED, $this->reason(fn () => $policy->initial($kind)));
            self::assertSame(AcademyReason::POLICY_NOT_CONFIGURED, $this->reason(fn () => $policy->set($kind, 'operational')));
            self::assertSame(AcademyReason::POLICY_NOT_CONFIGURED, $this->reason(fn () => $policy->inSet($kind, 'operational', 'X')));
        }
        self::assertSame(AcademyPolicy::UNKNOWN, $policy->transition('enrollments', 'A', 'B'));
    }

    public function test_an_empty_or_blank_configuration_entry_is_never_read_as_allow_all(): void
    {
        $policy = new AcademyPolicy(' ', ['k' => ['initial' => '', 'sets' => ['empty' => [], 'bad' => 'x']]], []);
        self::assertSame(AcademyReason::POLICY_NOT_CONFIGURED, $this->reason(fn () => $policy->version()));
        self::assertSame(AcademyReason::POLICY_NOT_CONFIGURED, $this->reason(fn () => $policy->initial('k')));
        self::assertSame(AcademyReason::POLICY_NOT_CONFIGURED, $this->reason(fn () => $policy->set('k', 'empty')));
        self::assertSame(AcademyReason::POLICY_NOT_CONFIGURED, $this->reason(fn () => $policy->set('k', 'bad')));
        self::assertFalse($policy->inOptionalSet('k', 'absent', 'X'), 'An optional role that is absent means none');
        self::assertFalse($policy->inOptionalSet('k', 'bad', 'x'));
    }

    public function test_roles_resolve_to_configured_names_only(): void
    {
        $policy = $this->policy();
        self::assertSame('A', $policy->initial('enrollments'));
        self::assertSame('C', $policy->target('enrollments', 'completed'));
        self::assertTrue($policy->inSet('enrollments', 'operational', 'B'));
        self::assertFalse($policy->inSet('enrollments', 'operational', 'A'));
        self::assertSame('V', $policy->version());
    }

    // ---- state machine: approved vs pending vs unknown --------------------------------------

    public function test_only_approved_transitions_pass_pending_is_explicit_and_unknown_is_invalid(): void
    {
        $machine = new AcademyStateMachine($this->createMock(Connection::class), $this->policy([['A', 'B'], ['B', 'C']], [['B', 'X']]));
        $machine->assertTransition('enrollments', 'A', 'B');
        $machine->assertTransition('enrollments', 'B', 'C');
        self::assertSame(AcademyReason::STATE_POLICY_PENDING, $this->reason(fn () => $machine->assertTransition('enrollments', 'B', 'X')));
        self::assertSame(AcademyReason::INVALID_TRANSITION, $this->reason(fn () => $machine->assertTransition('enrollments', 'A', 'C')), 'Transitivity is not assumed');
        self::assertSame(AcademyReason::INVALID_TRANSITION, $this->reason(fn () => $machine->assertTransition('enrollments', 'B', 'A')), 'Direction matters');
        self::assertSame(AcademyReason::INVALID_TRANSITION, $this->reason(fn () => $machine->assertTransition('enrollments', 'A', 'A')), 'A no-op is not a transition');
        self::assertSame(AcademyReason::INVALID_TRANSITION, $this->reason(fn () => $machine->assertTransition('unconfigured_kind', 'A', 'B')));
    }

    public function test_a_transition_listed_both_ways_is_approved_first_never_silently_pending(): void
    {
        $machine = new AcademyStateMachine($this->createMock(Connection::class), $this->policy([['A', 'B']], [['A', 'B']]));
        $machine->assertTransition('enrollments', 'A', 'B');
        self::assertSame(AcademyPolicy::APPROVED, $this->policy([['A', 'B']], [['A', 'B']])->transition('enrollments', 'A', 'B'));
    }

    // ---- permission matrix (ADR-0015 Decision B, mission sections 9-12) -----------------------

    public function test_every_operation_uses_a_known_permission_and_admin_is_never_a_direct_permission(): void
    {
        foreach (AcademyOperation::keys() as $key) {
            $op = AcademyOperation::get($key);
            self::assertContains($op->permission, AcademyOperation::PERMISSIONS, $key);
            self::assertNotSame(AcademyOperation::ADMIN, $op->permission, $key . ': ACADEMY_ADMIN acts only as an explicit override');
        }
        self::assertSame(AcademyReason::NOT_AUTHORIZED, $this->reason(fn () => AcademyOperation::get('no.such.operation')));
    }

    public function test_pedagogical_operations_require_class_assignment_and_certification_is_distinct_from_assessment(): void
    {
        foreach (AcademyOperation::keys() as $key) {
            $op = AcademyOperation::get($key);
            if (in_array($op->permission, [AcademyOperation::TEACH, AcademyOperation::ATTENDANCE, AcademyOperation::ASSESS], true)) {
                self::assertTrue($op->classAssignment, $key . ' needs an active class_instructors assignment');
                self::assertTrue($op->adminOverride, $key . ' allows only an explicit audited override');
            }
            if ($op->permission === AcademyOperation::CERTIFY) {
                self::assertFalse($op->classAssignment, $key . ': institutional homologation, not tied to teaching');
            }
            if (in_array($op->permission, [AcademyOperation::VIEW, AcademyOperation::GRADES_VIEW], true)) {
                self::assertFalse($op->audit, $key . ' is a read');
                self::assertFalse($op->adminOverride, $key);
            }
        }
        self::assertNotSame(AcademyOperation::get('certificate.issue')->permission, AcademyOperation::get('grade.record')->permission);
        self::assertNotSame(AcademyOperation::get('certificate.revoke')->permission, AcademyOperation::get('grade.revise')->permission);
        self::assertSame(AcademyOperation::MANAGE, AcademyOperation::get('instructor.assign')->permission, 'A teacher cannot self-assign: assignment needs MANAGE');
        self::assertSame(AcademyOperation::GRADES_VIEW, AcademyOperation::get('grade.view')->permission);
    }

    public function test_every_state_changing_or_sensitive_write_requires_an_audit_record(): void
    {
        foreach (['enrollment.create', 'enrollment.transition', 'instructor.assign', 'instructor.end', 'session.manage', 'attendance.record', 'attempt.record', 'grade.record', 'grade.revise', 'grade.finalize', 'enrollment.complete', 'certificate.issue', 'certificate.revoke', 'transcript.issue', 'structure.manage'] as $key) {
            self::assertTrue(AcademyOperation::get($key)->audit, $key);
        }
    }

    // ---- input + error contract ---------------------------------------------------------------

    public function test_technical_input_validation_is_shape_only(): void
    {
        self::assertSame('7.5', AcademyInput::decimal('7.5'));
        self::assertSame('12', AcademyInput::decimal(12));
        foreach ([1.5, '1,5', '-1', '', 'abc', '100000', '1.23456', null, []] as $bad) {
            self::assertSame(AcademyReason::INVALID_INPUT, $this->reason(fn () => AcademyInput::decimal($bad)), var_export($bad, true));
        }
        self::assertSame(AcademyReason::INVALID_INPUT, $this->reason(fn () => AcademyInput::text('  ')));
        self::assertSame(AcademyReason::INVALID_INPUT, $this->reason(fn () => AcademyInput::text(str_repeat('x', 192))));
        self::assertNull(AcademyInput::text(null, 191, false));
        self::assertSame(AcademyReason::REASON_REQUIRED, $this->reason(fn () => AcademyInput::reason('   ')));
        self::assertSame(AcademyReason::REASON_REQUIRED, $this->reason(fn () => AcademyInput::reason(null)));
        self::assertSame([1, 100], AcademyInput::page(-5, 100000));
        self::assertSame([3, 7], AcademyInput::page(3, 7));
    }

    public function test_error_contract_is_deterministic_and_carries_ids_not_data(): void
    {
        $constants = AcademyReason::all();
        self::assertSame(count($constants), count(array_unique($constants)));
        foreach ((new \ReflectionClass(AcademyReason::class))->getConstants() as $name => $value) {
            self::assertSame($name, $value, 'The reason string is the constant name');
        }
        foreach (['NOT_AUTHORIZED', 'OUT_OF_SCOPE', 'CLASS_ASSIGNMENT_REQUIRED', 'ALREADY_ENROLLED', 'STALE_WRITE', 'POLICY_NOT_CONFIGURED', 'INVALID_TRANSITION', 'STATE_POLICY_PENDING', 'CONSENT_REQUIRED', 'CERTIFICATE_ALREADY_EXISTS'] as $required) {
            self::assertContains($required, $constants);
        }
        $error = new AcademyError(AcademyReason::STALE_WRITE, ['latest_version' => 2]);
        self::assertSame('STALE_WRITE', $error->getMessage());
        self::assertSame(['latest_version' => 2], $error->context);
    }

    public function test_database_errors_translate_to_domain_errors_and_keep_the_original(): void
    {
        $runtime = (new \ReflectionClass(AcademyRuntime::class))->newInstanceWithoutConstructor();
        $translate = new \ReflectionMethod(AcademyRuntime::class, 'translate');
        $translate->setAccessible(true);
        $make = static function (int $code): QueryException {
            $pdo = new \PDOException('driver detail must not leak');
            $pdo->errorInfo = ['23000', $code, 'driver detail must not leak'];
            return new QueryException('insert into t values (?)', ['secret binding'], $pdo);
        };
        $cases = [[1062, 'ALREADY_ENROLLED', 'ALREADY_ENROLLED'], [1062, null, 'STORAGE_CONFLICT'], [1452, null, 'REFERENCE_NOT_FOUND'], [3819, null, 'INVARIANT_VIOLATION'], [4025, null, 'INVARIANT_VIOLATION'], [1213, 'ALREADY_ENROLLED', 'STORAGE_CONFLICT'], [1205, null, 'STORAGE_CONFLICT']];
        foreach ($cases as [$code, $duplicateAs, $expected]) {
            $error = $translate->invoke($runtime, $make($code), $duplicateAs);
            self::assertSame($expected, $error->reason, (string) $code);
            self::assertStringNotContainsString('driver detail', $error->getMessage());
            self::assertStringNotContainsString('secret binding', json_encode($error->context));
            self::assertInstanceOf(QueryException::class, $error->getPrevious(), 'The real database error is never hidden');
            self::assertSame($code, $error->context['db_error_code']);
        }
    }
}
