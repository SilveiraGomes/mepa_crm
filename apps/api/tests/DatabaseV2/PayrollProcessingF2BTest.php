<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Finance\FinanceCatalog;
use App\Domain\Payroll\PayrollCatalog;
use App\Domain\Payroll\PayrollInputHash;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\DatabaseV2\Support\PayrollHttpCase;

/**
 * P0.10-F2B payroll engine + approval + Finance posting + payment (ADR 0021 D24-D30 + D-04A.13-15), MySQL 8.4 Wave 5 pool.
 * P01-P40 functional, X01 independent accounting cross-check, X02 DRE / DOAF integration, X03 production default,
 * X04 reversal / cancel, C5 (both orders), C6, C11, POST x close (both orders), PAY x close (both orders) with real
 * processes and a deterministic lock-wait barrier (performance_schema), never a sleep as the mechanism.
 *
 * EVERY rate, bracket and amount here is SYNTHETIC TEST DATA created through the API by the test (codes SYN_*); none is
 * an official Angolan figure and none is installed by any seed. payroll.production_enabled is turned on only inside the
 * tests that exercise approve / post / pay (config() of this test process); the shipped default stays false (X03).
 */
final class PayrollProcessingF2BTest extends PayrollHttpCase
{
    private const PERIOD = '2026-09';

    // ---- P01-P06 run creation, population, effective inputs, rule resolution -------------------------------------------

    public function test_p01_create_run_is_explicit_unique_and_idempotent(): void
    {
        $s = $this->scenario();
        $key = 'k-' . Str::ulid();
        $first = $this->hpostKey($s['hr'], 'payroll/runs', ['unit' => $s['w']['a1']['public_id'], 'period' => self::PERIOD], $key)->assertCreated()->json('data');
        $this->assertSame(['DRAFT', 1, false], [$first['status'], $first['sequence'], $first['replayed']]);
        $replay = $this->hpostKey($s['hr'], 'payroll/runs', ['unit' => $s['w']['a1']['public_id'], 'period' => self::PERIOD], $key)->assertOk()->json('data');
        $this->assertSame([$first['public_id'], true], [$replay['public_id'], $replay['replayed']], 'retry with the same key = the same run');
        $dup = $this->hpost($s['hr'], 'payroll/runs', ['unit' => $s['w']['a1']['public_id'], 'period' => self::PERIOD])->assertStatus(409);
        $this->assertSame(['PAYROLL_RUN_EXISTS', [$first['public_id']]], [$dup->json('error.code'), $dup->json('error.details.items')]);
        $this->hpost($s['hr'], 'payroll/runs', ['unit' => $s['w']['a1']['public_id'], 'period' => self::PERIOD, 'run_kind' => 'THIRTEENTH'])->assertStatus(422)->assertJsonPath('error.code', 'PAYROLL_RUN_KIND_POLICY_MISSING');
        $this->hpost($s['hr'], 'payroll/runs', ['unit' => $s['w']['a1']['public_id'], 'period' => self::PERIOD, 'status' => 'APPROVED'])->assertStatus(422);
        $this->assertSame(1, DB::table('payroll_runs')->where('employing_unit_id', $s['w']['a1']['id'])->count(), 'no equivalent duplicate run');
        $row = DB::table('payroll_runs')->where('public_id', $first['public_id'])->first();
        $this->assertSame([$s['w']['a1']['id'], 'REGULAR', 1, 'DRAFT', $s['hr']['user']], [(int) $row->employing_unit_id, $row->run_kind, (int) $row->sequence, $row->status, (int) $row->created_by]);
        $this->assertSame(1, $this->auditCount('payroll.created'));
        $this->assertNoInternalIds($this->hget($s['hr'], 'payroll/runs/' . $first['public_id'])->assertOk()->json('data'));
    }

    public function test_p02_population_comes_from_effective_employments_of_the_unit_and_month(): void
    {
        $s = $this->scenario();
        $hr = $s['hr'];
        $ended = $this->employ($hr, $this->person($s['w']['a1']), $s['w']['a1'], '2026-01-01');
        $this->setComponent($hr, $ended['public_id'], 'BASE_SALARY', '50000.00', '2026-01-01')->assertCreated();
        $this->hpost($hr, 'employments/' . $ended['public_id'] . '/end', ['ends_on' => '2026-08-31', 'end_reason' => 'Fim do contrato'])->assertOk();
        $later = $this->employ($hr, $this->person($s['w']['a1']), $s['w']['a1'], '2026-10-01');
        $this->setComponent($hr, $later['public_id'], 'BASE_SALARY', '50000.00', '2026-10-01')->assertCreated();
        $other = $this->employ($hr, $this->person($s['w']['a2']), $s['w']['a2'], '2026-01-01');
        $this->setComponent($hr, $other['public_id'], 'BASE_SALARY', '50000.00', '2026-01-01')->assertCreated();
        $run = $this->calculated($s);
        $this->assertSame(2, $run['headcount']);
        $population = DB::table('payroll_run_lines as l')->join('employments as e', 'e.id', '=', 'l.employment_id')->where('l.run_id', $this->runId($run['public_id']))->distinct()->orderBy('e.public_id')->pluck('e.public_id')->all();
        $expected = [$s['e1']['public_id'], $s['e2']['public_id']];
        sort($expected);
        $this->assertSame($expected, $population, 'ended before / starting after / other unit are excluded');
        // §8: an employment that ends inside the month needs a proration policy V1 does not have -> fail closed.
        $partialWorld = $this->scenario();
        $this->hpost($partialWorld['hr'], 'employments/' . $partialWorld['e2']['public_id'] . '/end', ['ends_on' => '2026-09-15', 'end_reason' => 'Saída a meio do mês'])->assertOk();
        $r = $this->createRun($partialWorld['hr'], $partialWorld['w']['a1']);
        $res = $this->hpost($partialWorld['hr'], 'payroll/runs/' . $r['public_id'] . '/calculate')->assertStatus(422);
        $this->assertSame(['PAYROLL_PRORATION_POLICY_MISSING', [$partialWorld['e2']['public_id']]], [$res->json('error.code'), $res->json('error.details.items')]);
        $this->assertSame(['DRAFT', 0], [DB::table('payroll_runs')->where('public_id', $r['public_id'])->value('status'), DB::table('payroll_run_lines')->where('run_id', $this->runId($r['public_id']))->count()],
            'never a full salary paid silently, never 30 / calendar / working days assumed');
    }

    public function test_p03_historical_compensation_is_the_one_effective_in_the_service_month(): void
    {
        $s = $this->scenario();
        $this->setComponent($s['hr'], $s['e1']['public_id'], 'BASE_SALARY', '175000.00', '2026-10-01')->assertCreated();
        $sept = $this->calculated($s);
        $this->assertSame('150000.50', $this->line($sept, $s['e1'], 'BASE_SALARY')['amount'], 'September uses the September salary, not today\'s');
        $oct = $this->calculated($s, '2026-10');
        $this->assertSame('175000.00', $this->line($oct, $s['e1'], 'BASE_SALARY')['amount']);
        // A mid-month change has no proration policy: fail closed, never one of the two amounts silently.
        $this->setComponent($s['hr'], $s['e2']['public_id'], 'BASE_SALARY', '90000.00', '2026-11-16')->assertCreated();
        $nov = $this->createRun($s['hr'], $s['w']['a1'], '2026-11');
        $this->hpost($s['hr'], 'payroll/runs/' . $nov['public_id'] . '/calculate')->assertStatus(422)->assertJsonPath('error.code', 'PAYROLL_PRORATION_POLICY_MISSING');
    }

    public function test_p04_rule_resolution_uses_the_version_effective_in_the_period(): void
    {
        $s = $this->scenario(rules: false);
        $this->flatRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_INSS_E', 'INSS_EMPLOYEE', '0.020000', '2026-01-01', '2026-06-30');
        $this->flatRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_INSS_E', 'INSS_EMPLOYEE', '0.030000', '2026-07-01');
        $this->otherRules($s);
        $may = $this->calculated($s, '2026-05');
        $sep = $this->calculated($s);
        $this->assertSame(['0.020000', ['code' => 'SYN_INSS_E', 'version' => 1], '3000.01'], [$this->line($may, $s['e1'], 'INSS_EMPLOYEE')['rate'], $this->line($may, $s['e1'], 'INSS_EMPLOYEE')['rule'], $this->line($may, $s['e1'], 'INSS_EMPLOYEE')['amount']]);
        $this->assertSame(['0.030000', ['code' => 'SYN_INSS_E', 'version' => 2], '4500.02'], [$this->line($sep, $s['e1'], 'INSS_EMPLOYEE')['rate'], $this->line($sep, $s['e1'], 'INSS_EMPLOYEE')['rule'], $this->line($sep, $s['e1'], 'INSS_EMPLOYEE')['amount']],
            'never the "latest" version outside its vigência');
    }

    public function test_p05_a_missing_rule_fails_closed_never_zero(): void
    {
        $s = $this->scenario(rules: false);
        $this->flatRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_INSS_E', 'INSS_EMPLOYEE', '0.030000', '2026-01-01');
        $this->flatRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_INSS_P', 'INSS_EMPLOYER', '0.080000', '2026-01-01');
        $run = $this->createRun($s['hr'], $s['w']['a1']);
        $res = $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertStatus(422);
        $this->assertSame(['PAYROLL_RULE_MISSING', ['INCOME_TAX_WITHHOLDING']], [$res->json('error.code'), $res->json('error.details.items')]);
        $this->assertSame(0, DB::table('payroll_run_lines')->where('run_id', $this->runId($run['public_id']))->count(), 'no line, no zero');
        // A typed amount for a rule-based component is refused (never a manual bypass of the missing rule).
        $this->setComponent($s['hr'], $s['e1']['public_id'], 'INCOME_TAX_WITHHOLDING', '0.00', '2026-10-01')->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_NOT_ALLOWED');
        // A DRAFT (unapproved) rule is never used.
        $this->hpost($s['manager'], 'payroll-rules', ['code' => 'SYN_IRT_DRAFT', 'component' => 'INCOME_TAX_WITHHOLDING', 'method' => 'BRACKET', 'rate' => null, 'starts_on' => '2026-01-01',
            'base_components' => ['BASE_SALARY'], 'brackets' => [['lower_bound' => '0.00', 'upper_bound' => null, 'rate' => '0.010000']], 'source_document' => $this->document($s['w']['g'], 'PAYROLL_RULE_SOURCE')['public_id']])->assertCreated();
        $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertStatus(422)->assertJsonPath('error.code', 'PAYROLL_RULE_MISSING');
    }

    public function test_p06_an_ambiguous_rule_fails_closed(): void
    {
        $s = $this->scenario(rules: false);
        $this->flatRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_INSS_E', 'INSS_EMPLOYEE', '0.030000', '2026-01-01', '2026-09-15');
        $this->flatRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_INSS_E2', 'INSS_EMPLOYEE', '0.035000', '2026-09-16');
        $this->otherRules($s);
        $run = $this->createRun($s['hr'], $s['w']['a1']);
        $res = $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertStatus(422);
        $this->assertSame('PAYROLL_RULE_AMBIGUOUS', $res->json('error.code'), 'two approved versions intersect the month: never pick one');
        $this->assertEqualsCanonicalizing(['SYN_INSS_E@1', 'SYN_INSS_E2@1'], $res->json('error.details.items'));
    }

    // ---- P07-P13 calculation ------------------------------------------------------------------------------------------------

    public function test_p07_synthetic_flat_rate_on_the_rule_base(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $inss = $this->line($run, $s['e1'], 'INSS_EMPLOYEE');
        $this->assertSame(['RULE', '150000.50', '0.030000', '4500.02'], [$inss['source'], $inss['base_amount'], $inss['rate'], $inss['amount']], 'base = BASE_SALARY only (rule base), 150000.50 x 3% = 4500.015 -> 4500.02');
        $irt = $this->line($run, $s['e1'], 'INCOME_TAX_WITHHOLDING');
        $this->assertSame(['150000.50', '0.100000', '5000.05'], [$irt['base_amount'], $irt['rate'], $irt['amount']], 'bracket: (150000.50 - 100000) x 10%');
        $this->assertSame(['FIXED', null, null, '20000.00'], [$this->line($run, $s['e1'], 'TRANSPORT_MEAL_ALLOWANCE')['source'], $this->line($run, $s['e1'], 'TRANSPORT_MEAL_ALLOWANCE')['base_amount'],
            $this->line($run, $s['e1'], 'TRANSPORT_MEAL_ALLOWANCE')['rate'], $this->line($run, $s['e1'], 'TRANSPORT_MEAL_ALLOWANCE')['amount']]);
        $this->assertSame('MANUAL', $this->line($run, $s['e2'], 'OTHER_DEDUCTION')['source']);
    }

    public function test_p08_half_up_rounding_per_component_line(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        // 87653.50 x 3% = 2629.605: half-up 2629.61 (half-even and truncation would give 2629.60).
        $this->assertSame('2629.61', $this->line($run, $s['e2'], 'INSS_EMPLOYEE')['amount']);
        // 150000.50 x 3% = 4500.015 -> 4500.02. Per line: 4500.02 + 2629.61 = 7129.63; rounding only the total
        // (4500.015 + 2629.605 = 7129.62) would differ by one cent.
        $sum = (string) DB::table('payroll_run_lines')->where('run_id', $this->runId($run['public_id']))->where('component_type_id', $this->componentId('INSS_EMPLOYEE'))->selectRaw('SUM(amount) AS s')->value('s');
        $this->assertSame('7129.6300', $sum);
        foreach (DB::table('payroll_run_lines')->where('run_id', $this->runId($run['public_id']))->pluck('amount') as $amount) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}00$/', (string) $amount, 'every stored line has 2 business decimals');
        }
    }

    public function test_p09_p12_gross_deductions_employer_charges_and_net_are_separate_classes(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $employees = collect($this->hget($s['hr'], 'payroll/runs/' . $run['public_id'] . '/employees')->assertOk()->json('data.employees'))->keyBy('employment');
        $e1 = $employees[$s['e1']['public_id']];
        $e2 = $employees[$s['e2']['public_id']];
        $this->assertSame(['170000.50', '9500.07', '12000.04', '160500.43'], [$e1['gross'], $e1['deductions'], $e1['employer_charges'], $e1['net']], 'P09-P12 employee 1');
        $this->assertSame(['87653.50', '3629.61', '7012.28', '84023.89'], [$e2['gross'], $e2['deductions'], $e2['employer_charges'], $e2['net']], 'P09-P12 employee 2');
        $this->assertNotContains('INSS_EMPLOYER', array_column(array_column(array_filter($e1['lines'], fn ($l) => $l['component']['nature'] === 'EMPLOYEE_DEDUCTION'), 'component'), 'code'), 'an employer charge is never an employee deduction');
        $this->assertSame(bcsub($e1['gross'], $e1['deductions'], 2), $e1['net'], 'net = gross - employee deductions (employer charges excluded)');
        // P12: a negative net has no approved policy -> fail closed (no automatic debt of the employee).
        $neg = $this->scenario();
        $this->setComponent($neg['hr'], $neg['e2']['public_id'], 'OTHER_DEDUCTION', '95000.00', '2026-09-01')->assertCreated();
        $r = $this->createRun($neg['hr'], $neg['w']['a1']);
        $res = $this->hpost($neg['hr'], 'payroll/runs/' . $r['public_id'] . '/calculate')->assertStatus(422);
        $this->assertSame(['PAYROLL_NET_NEGATIVE', [$neg['e2']['public_id']]], [$res->json('error.code'), $res->json('error.details.items') ?? [$neg['e2']['public_id']]]);
    }

    public function test_p13_run_totals_equal_the_sum_of_employee_results(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $detail = $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'])->assertOk()->json('data');
        $this->assertSame(['257654.00', '13129.68', '19012.32', '244524.32'], array_values($detail['totals']));
        $employees = $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'] . '/employees')->json('data.employees');
        foreach (['gross', 'deductions', 'employer_charges', 'net'] as $k) {
            $this->assertSame($detail['totals'][$k], array_reduce($employees, fn ($c, $e) => bcadd($c, $e[$k], 2), '0.00'), 'Σ employees = run ' . $k);
        }
        $byNature = DB::table('payroll_run_lines as l')->join('compensation_component_types as t', 't.id', '=', 'l.component_type_id')->where('l.run_id', $this->runId($run['public_id']))
            ->groupBy('t.nature')->selectRaw('t.nature, SUM(l.amount) AS s')->pluck('s', 'nature');
        $this->assertSame(['257654.0000', '13129.6800', '19012.3200'], [(string) $byNature['EARNING'], (string) $byNature['EMPLOYEE_DEDUCTION'], (string) $byNature['EMPLOYER_CHARGE']]);
        $this->assertSame(2, $detail['headcount']);
    }

    // ---- P14-P21 input hash, stale detection, SoD, production gate ------------------------------------------------------

    public function test_p14_the_input_hash_is_deterministic(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $again = $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertOk()->json('data');
        $this->assertSame($run['input_hash'], $again['input_hash'], 'same inputs, same hash');
        $this->assertSame(64, strlen($run['input_hash']));
        $preview = $this->hget($s['hr'], 'payroll/readiness?unit=' . $s['w']['a1']['public_id'] . '&period=' . self::PERIOD)->json('data.input_hash_preview.value');
        $this->assertSame($run['input_hash'], $preview, 'readiness preview = run hash (REGULAR #1)');
        $this->assertSame($run['input_hash'], PayrollInputHash::hash(PayrollInputHash::payload(DB::connection(), $s['w']['a1']['id'], self::PERIOD)));
        $this->assertSame($run['input_hash'], bin2hex((string) DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('input_hash')), 'stored with the calculation');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payroll.calculated')->whereRaw("JSON_EXTRACT(after_metadata, '$.recalculated') = true")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(after_metadata, '$.run')) = ?", [$run['public_id']])->count());
    }

    public function test_p15_a_changed_compensation_changes_the_hash(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $this->setComponent($s['hr'], $s['e2']['public_id'], 'OTHER_DEDUCTION', '1500.00', '2026-09-01')->assertCreated();
        $again = $this->recalculated($s, $run);
        $this->assertNotSame($run['input_hash'], $again['input_hash']);
        $this->assertSame('1500.00', $this->line($again, $s['e2'], 'OTHER_DEDUCTION')['amount']);
        // Adding a component (salary base class) and the employment set change the hash too.
        $before = $again['input_hash'];
        $this->setComponent($s['hr'], $s['e1']['public_id'], 'HEALTH_PLAN', '3000.00', '2026-09-01')->assertCreated();
        $this->assertNotSame($before, $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->json('data.input_hash'));
    }

    public function test_p16_a_changed_rule_or_bracket_changes_the_hash(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $this->hpost($s['rapprover'], 'payroll-rules/SYN_IRT/1/retire', ['reason' => 'Nova tabela sintética'])->assertOk();
        $this->bracketRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_IRT', [['lower_bound' => '0.00', 'upper_bound' => '100000.00', 'rate' => '0.000000'],
            ['lower_bound' => '100000.00', 'upper_bound' => null, 'rate' => '0.100000', 'fixed_amount' => '0.00', 'excess_over' => '90000.00']], '2026-01-01');
        $brackets = $this->recalculated($s, $run);
        $this->assertNotSame($run['input_hash'], $brackets['input_hash'], 'bracket parameters are inputs');
        $this->assertSame('6000.05', $this->line($brackets, $s['e1'], 'INCOME_TAX_WITHHOLDING')['amount']);
        // The canonical payload covers every bracket parameter even at an identical rule code / version (D-04A.14).
        $payload = PayrollInputHash::payload(DB::connection(), $s['w']['a1']['id'], self::PERIOD);
        $altered = $payload;
        $irt = array_search('INCOME_TAX_WITHHOLDING', array_column($altered['rules'], 'component'), true);
        $altered['rules'][$irt]['brackets'][1]['excess_over'] = '90000.01';
        $this->assertNotSame(PayrollInputHash::hash($payload), PayrollInputHash::hash($altered), 'brackets are hashed');
        $altered = $payload;
        $altered['compensations'][0]['amount'] = '1.00';
        $this->assertNotSame(PayrollInputHash::hash($payload), PayrollInputHash::hash($altered), 'compensation amounts are hashed');
        $this->hpost($s['rapprover'], 'payroll-rules/SYN_INSS_P/1/retire', ['reason' => 'Nova taxa sintética'])->assertOk();
        $this->flatRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_INSS_P', 'INSS_EMPLOYER', '0.090000', '2026-01-01');
        $rate = $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertOk()->json('data');
        $this->assertNotSame($brackets['input_hash'], $rate['input_hash'], 'rule version / rate are inputs');
    }

    public function test_p17_a_stale_calculation_is_never_approved(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->calculated($s);
        $this->setComponent($s['hr'], $s['e1']['public_id'], 'TRANSPORT_MEAL_ALLOWANCE', '22000.00', '2026-09-01')->assertCreated();
        $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_INPUT_STALE');
        $this->assertSame('CALCULATED', DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status'));
        $this->assertSame('STALE', $this->hget($s['approver'], 'payroll/runs/' . $run['public_id'])->json('data.input.status'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payroll.failed_transition')->where('after_metadata', 'like', '%PAYROLL_INPUT_STALE%')->count());
        // A rule retired after the calculation is also a change of input (never approved on vanished inputs).
        $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertOk();
        $this->hpost($s['rapprover'], 'payroll-rules/SYN_INSS_P/1/retire', ['reason' => 'Retirada sintética'])->assertOk();
        $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_INPUT_STALE');
        $this->flatRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_INSS_P', 'INSS_EMPLOYER', '0.080000', '2026-01-01');
        $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_INPUT_STALE');
        $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertOk();
        $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_p18_the_calculator_cannot_approve_its_own_run(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $both = $this->hr($s['w']['a'], [...self::HR_ALL, 'PAYROLL_APPROVE']);
        $run = $this->createRun($both, $s['w']['a1']);
        $this->hpost($both, 'payroll/runs/' . $run['public_id'] . '/calculate')->assertOk();
        $this->hpost($both, 'payroll/runs/' . $run['public_id'] . '/approve')->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_SEGREGATION_REQUIRED');
        $this->assertSame('PAYROLL_SEGREGATION_REQUIRED', $this->hget($both, 'payroll/runs/' . $run['public_id'])->json('data.blocked.approve'));
        $this->assertFalse($this->hget($both, 'payroll/runs/' . $run['public_id'])->json('data.actions.approve'));
        $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertOk();
        // Physical backstop: approved_by = calculated_by is refused by the CHECK.
        $this->assertSqlError(3819, fn () => DB::table('payroll_runs')->where('public_id', $run['public_id'])->update(['approved_by' => $both['user']]));
    }

    public function test_p19_production_disabled_blocks_approve_post_and_pay(): void
    {
        $s = $this->scenario();
        $this->assertFalse(config('payroll.production_enabled'), 'default off');
        $run = $this->calculated($s);
        $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_PRODUCTION_DISABLED');
        $this->production(true);
        $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertOk();
        $this->production(false);
        $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/post', ['entry_date' => '2026-09-30'])->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_PRODUCTION_DISABLED');
        $this->production(true);
        $this->postRun($s, $run);
        $this->production(false);
        $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/pay', ['account' => $s['w']['cash_a1']['public_id'], 'paid_on' => '2026-10-01'])->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_PRODUCTION_DISABLED');
        $this->assertSame(['POSTED', 1], [DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status'), DB::table('payroll_postings')->where('run_id', $this->runId($run['public_id']))->count()]);
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'payroll.failed_transition')->where('after_metadata', 'like', '%PAYROLL_PRODUCTION_DISABLED%')->count());
    }

    public function test_p20_calculation_is_allowed_while_production_is_disabled(): void
    {
        $s = $this->scenario();
        $readiness = $this->hget($s['hr'], 'payroll/readiness?unit=' . $s['w']['a1']['public_id'] . '&period=' . self::PERIOD)->assertOk()->json('data');
        $this->assertSame(['READY', 'DISABLED'], [$readiness['configuration']['status'], $readiness['production']['status']], 'configuration and production are separate answers');
        $this->assertSame(['calculate' => true, 'approve' => false, 'post' => false, 'pay' => false, 'reverse' => false], $readiness['production']['operations']);
        $run = $this->calculated($s);
        $this->assertSame('CALCULATED', $run['status']);
        $detail = $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'])->json('data');
        $this->assertSame(['CURRENT', 'DISABLED', false], [$detail['input']['status'], $detail['production']['status'], $detail['actions']['approve']]);
    }

    public function test_p21_approval_succeeds_when_production_is_explicitly_enabled_and_freezes_the_run(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->calculated($s);
        $before = json_encode(DB::table('payroll_run_lines')->where('run_id', $this->runId($run['public_id']))->orderBy('id')->get());
        $ok = $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertOk()->json('data');
        $this->assertSame(['APPROVED', $run['input_hash']], [$ok['status'], $ok['input_hash']]);
        $this->assertSame($before, json_encode(DB::table('payroll_run_lines')->where('run_id', $this->runId($run['public_id']))->orderBy('id')->get()), 'approval never recalculates');
        $row = DB::table('payroll_runs')->where('public_id', $run['public_id'])->first();
        $this->assertSame([$s['approver']['user'], $s['hr']['user']], [(int) $row->approved_by, (int) $row->calculated_by]);
        $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $this->assertSame('FROZEN', $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'])->json('data.input.status'));
    }

    // ---- P22-P26 Finance posting ------------------------------------------------------------------------------------------

    public function test_p22_p23_the_accrual_is_balanced_and_aggregated_by_rubric_and_liability(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->approved($s);
        $posted = $this->postRun($s, $run);
        $entry = DB::table('journal_entries')->where('public_id', $posted['entry'])->first();
        $this->assertSame(['PAYROLL_ACCRUAL', 'POSTED', $s['w']['a1']['id'], '2026-09-30'], [$entry->entry_kind, $entry->status, (int) $entry->unit_id, $entry->entry_date]);
        $lines = $this->entryLines((int) $entry->id);
        $this->assertSame([
            ['OPERATING_EXPENSE', 'PER_INSS', '19012.32', '0.00'],
            ['OPERATING_EXPENSE', 'PER_SALARY', '237654.00', '0.00'],
            ['OPERATING_EXPENSE', 'PER_TRANSPORT_MEAL', '20000.00', '0.00'],
            ['PAYROLL_EMPLOYER_CHARGES', null, '0.00', '19012.32'],
            ['PAYROLL_NET_PAYABLE', null, '0.00', '244524.32'],
            ['PAYROLL_WITHHOLDINGS', null, '0.00', '13129.68'],
        ], $lines, 'P23: one line per rubric / liability role, never per employee (2 employees -> 1 PER_SALARY line)');
        $this->assertSame('276666.32', array_reduce($lines, fn ($c, $l) => bcadd($c, $l[2], 2), '0.00'));
        $this->assertSame('276666.32', array_reduce($lines, fn ($c, $l) => bcadd($c, $l[3], 2), '0.00'), 'P22: Σ debit = gross + employer charges = Σ credit');
        $this->assertSame(['POSTED', $s['poster']['user']], [DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status'), (int) DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('posted_by')]);
        $this->assertLedgerInvariants();
    }

    public function test_p24_the_finance_journal_contains_no_employee_detail(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->approved($s);
        $posted = $this->postRun($s, $run);
        $entryId = (int) DB::table('journal_entries')->where('public_id', $posted['entry'])->value('id');
        $columns = array_map(fn ($c) => $c->c, DB::select("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('journal_entries','journal_lines')"));
        $this->assertSame([], array_values(array_intersect($columns, ['person_id', 'employment_id', 'employee_id', 'payroll_run_line_id'])), 'the journal has no person column');
        $text = json_encode([DB::table('journal_entries')->where('id', $entryId)->get(), DB::table('journal_lines')->where('entry_id', $entryId)->get()], JSON_UNESCAPED_UNICODE);
        foreach ([$s['e1']['public_id'], $s['e2']['public_id'], $s['p1']['public_id'], $s['p2']['public_id'], $s['p1']['name'], $s['p2']['name'], '150000.50', '87653.50', '160500.43', '84023.89'] as $needle) {
            $this->assertStringNotContainsString($needle, $text, 'Finance never receives ' . $needle);
        }
        $this->assertSame([], array_values(array_filter(DB::table('journal_lines')->where('entry_id', $entryId)->pluck('description')->all(), fn ($d) => preg_match('/Sintétic|Trabalhador/u', (string) $d) === 1)));
    }

    public function test_p25_posting_is_idempotent(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->approved($s);
        $first = $this->postRun($s, $run);
        $again = $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/post', ['entry_date' => '2026-09-30'])->assertOk()->json('data');
        $other = $this->hpost($this->poster($s['w']), 'payroll/runs/' . $run['public_id'] . '/post', ['entry_date' => '2026-09-30'])->assertOk()->json('data');
        $this->assertSame([$first['entry'], true, $first['entry'], true], [$again['entry'], $again['replayed'], $other['entry'], $other['replayed']]);
        $this->assertSame(1, DB::table('payroll_postings')->where('run_id', $this->runId($run['public_id']))->where('stage', 'ACCRUAL')->count());
        $this->assertSame(1, $this->payrollEntries($s, 'PAYROLL_ACCRUAL'), 'one journal, never two equivalent ones');
    }

    public function test_p26_payroll_postings_are_unique_per_run_and_stage(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->approved($s);
        $posted = $this->postRun($s, $run);
        $entryId = (int) DB::table('journal_entries')->where('public_id', $posted['entry'])->value('id');
        $runId = $this->runId($run['public_id']);
        $other = (int) DB::table('journal_entries')->where('id', '<>', $entryId)->value('id');
        $this->assertSqlError(1062, fn () => DB::table('payroll_postings')->insert(['run_id' => $runId, 'stage' => 'ACCRUAL', 'entry_id' => $other ?: $entryId, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]), 'UNIQUE (run, stage)');
        $this->assertSqlError(1062, fn () => DB::table('payroll_postings')->insert(['run_id' => $runId, 'stage' => 'PAYMENT', 'entry_id' => $entryId, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]), 'UNIQUE entry');
        $this->assertSqlError(3819, fn () => DB::table('payroll_postings')->insert(['run_id' => $runId, 'stage' => 'BONUS', 'entry_id' => $other, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]));
    }

    // ---- P27-P32 payment, statutory liabilities, FIN-D10, periods --------------------------------------------------------

    public function test_p27_p28_payment_settles_the_net_payable_and_leaves_statutory_liabilities(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->posted($s);
        $cash = $this->balance($s['w']['cash_a1']);
        $before = $this->roles($s);
        $paid = $this->payRun($s, $run);
        $after = $this->roles($s);
        $this->assertSame(['PAID', 'PAYROLL_PAYMENT'], [$paid['status'], DB::table('journal_entries')->where('public_id', $paid['entry'])->value('entry_kind')]);
        $this->assertSame([['PAYROLL_NET_PAYABLE', null, '244524.32', '0.00'], ['CASH', null, '0.00', '244524.32']],
            $this->entryLines((int) DB::table('journal_entries')->where('public_id', $paid['entry'])->value('id'), false), 'Dr net payable / Cr cash, exactly the POSTED net');
        $this->assertSame($cash - 24452432, $this->balance($s['w']['cash_a1']));
        $this->assertSame(['244524.32', '0.00'], [$before['PAYROLL_NET_PAYABLE'], $after['PAYROLL_NET_PAYABLE']], 'P27 net payable settled');
        $this->assertSame(['13129.68', '19012.32'], [$after['PAYROLL_WITHHOLDINGS'], $after['PAYROLL_EMPLOYER_CHARGES']], 'P28 INSS / IRT / employer charges stay owed');
        $this->assertSame([$before['PAYROLL_WITHHOLDINGS'], $before['PAYROLL_EMPLOYER_CHARGES']], [$after['PAYROLL_WITHHOLDINGS'], $after['PAYROLL_EMPLOYER_CHARGES']]);
        $this->assertSame('OPEN_UNTIL_LIABILITY_PAYMENT', $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'])->json('data.finance.statutory_liabilities'));
        $this->assertLedgerInvariants();
    }

    public function test_p29_insufficient_cash_denies_pay_through_fin_d10(): void
    {
        $s = $this->scenario(funding: '100000.00');
        $this->production(true);
        $run = $this->posted($s);
        $cash = $this->balance($s['w']['cash_a1']);
        $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/pay', ['account' => $s['w']['cash_a1']['public_id'], 'paid_on' => '2026-10-01'])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS');
        $this->assertSame(['POSTED', 0, $cash], [DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status'),
            DB::table('payroll_postings')->where('run_id', $this->runId($run['public_id']))->where('stage', 'PAYMENT')->count(), $this->balance($s['w']['cash_a1'])], 'all or nothing');
        $this->assertSame(0, $this->payrollEntries($s, 'PAYROLL_PAYMENT'));
    }

    public function test_p30_payment_is_idempotent(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->posted($s);
        $first = $this->payRun($s, $run);
        $cash = $this->balance($s['w']['cash_a1']);
        $again = $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/pay', ['account' => $s['w']['cash_a1']['public_id'], 'paid_on' => '2026-10-01'])->assertOk()->json('data');
        $this->assertSame([$first['entry'], true], [$again['entry'], $again['replayed']]);
        $this->assertSame([$cash, 1, 1], [$this->balance($s['w']['cash_a1']), $this->payrollEntries($s, 'PAYROLL_PAYMENT'), DB::table('payroll_postings')->where('run_id', $this->runId($run['public_id']))->where('stage', 'PAYMENT')->count()]);
    }

    public function test_p31_a_closed_finance_period_denies_post_and_d29_posts_in_the_first_open_period_with_a_reason(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->approved($s);
        $this->closeUnit($s, '2026-09');
        $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/post', ['entry_date' => '2026-09-30'])->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_CLOSED');
        $this->assertSame(['APPROVED', 0], [DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status'), $this->payrollEntries($s, 'PAYROLL_ACCRUAL')]);
        $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/post', ['entry_date' => '2026-10-01'])->assertStatus(422)->assertJsonPath('error.code', 'REASON_REQUIRED');
        $posted = $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/post', ['entry_date' => '2026-10-01', 'reason' => 'Mês de serviço 2026-09 já fechado (D29)'])->assertOk()->json('data');
        $entry = DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.period_id')->where('e.public_id', $posted['entry'])->first(['e.entry_date', 'p.code', 'e.reason']);
        $this->assertSame(['2026-10-01', '2026-10'], [$entry->entry_date, $entry->code]);
        $this->assertSame(self::PERIOD, DB::table('accounting_periods')->where('id', DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('period_id'))->value('code'), 'the service month is never moved');
        $this->assertSame(0, DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.period_id')->where('e.unit_id', $s['w']['a1']['id'])->where('p.code', '2026-09')->where('e.entry_kind', 'PAYROLL_ACCRUAL')->count());
    }

    public function test_p32_a_closed_finance_period_denies_pay(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->posted($s);
        $this->closeUnit($s, '2026-10');
        $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/pay', ['account' => $s['w']['cash_a1']['public_id'], 'paid_on' => '2026-10-01'])->assertStatus(409)->assertJsonPath('error.code', 'PERIOD_CLOSED');
        $this->assertSame(['POSTED', 0], [DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status'), $this->payrollEntries($s, 'PAYROLL_PAYMENT')]);
    }

    // ---- P33-P36 privacy, audit, F-06 / IDOR, summary -----------------------------------------------------------------------

    public function test_p33_the_employee_breakdown_needs_hr_compensation_view(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $approver = $s['approver'];
        $detail = $this->hget($approver, 'payroll/runs/' . $run['public_id'])->assertOk()->json('data');
        $this->assertFalse($detail['employee_detail_visible']);
        $hidden = $this->hget($approver, 'payroll/runs/' . $run['public_id'] . '/employees');
        $this->assertConcealed($hidden);
        $this->assertSame($this->hget($approver, 'payroll/runs/' . $this->ghost() . '/employees')->getContent(), $hidden->getContent(), 'byte-identical to an unknown run');
        $shown = $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'] . '/employees')->assertOk()->json('data.employees');
        $this->assertCount(2, $shown);
        $this->assertSame(['public_id', 'name'], array_keys($shown[0]['person']));
        $this->assertNoInternalIds($shown);
    }

    public function test_p34_every_individual_salary_read_is_audited_without_values(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $before = $this->auditCount('payroll.employee_detail_viewed');
        $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'] . '/employees')->assertOk();
        $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'] . '/employees')->assertOk();
        $this->assertSame($before + 2, $this->auditCount('payroll.employee_detail_viewed'));
        $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'])->assertOk();
        $this->assertGreaterThan(0, $this->auditCount('payroll.viewed'));
        $meta = DB::table('audit_logs')->where('action', 'like', 'payroll.%')->pluck('after_metadata')->implode("\n");
        foreach (['150000.50', '87653.50', '4500.02', '2629.61', '160500.43', '244524.32', '257654.00', 'amount', 'salary', 'gross', '"net', $s['p1']['name']] as $needle) {
            $this->assertStringNotContainsString($needle, $meta, 'no value / name in payroll audit metadata: ' . $needle);
        }
    }

    public function test_p35_f06_and_idor(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $nobody = $this->staff(['FINANCE_VIEW'], $s['w']['a1']['id']);
        $this->hget($nobody, 'payroll/runs/' . $run['public_id'])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->hpost($nobody, 'payroll/runs/' . $run['public_id'] . '/calculate')->assertStatus(403);
        $otherBranch = $this->hr($s['w']['b']);
        foreach (['', '/employees', '/summary'] as $suffix) {
            $concealed = $this->hget($otherBranch, 'payroll/runs/' . $run['public_id'] . $suffix);
            $this->assertConcealed($concealed);
            $this->assertSame($this->hget($otherBranch, 'payroll/runs/' . $this->ghost() . $suffix)->getContent(), $concealed->getContent());
        }
        $this->assertConcealed($this->hpost($otherBranch, 'payroll/runs/' . $run['public_id'] . '/calculate'));
        $this->assertConcealed($this->hpost($otherBranch, 'payroll/runs', ['unit' => $s['w']['a1']['public_id'], 'period' => '2026-08']));
        $this->assertConcealed($this->hget($s['hr'], 'payroll/runs/' . $this->runId($run['public_id'])));
        $this->hpost($s['hr'], 'payroll/runs', ['unit_id' => $s['w']['a1']['id'], 'period' => '2026-08'])->assertStatus(422);
        $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate', ['run_id' => 1])->assertStatus(422);
        $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/pay', ['account' => $s['w']['cash_a1']['public_id'], 'amount' => '1.00'])->assertStatus(422);
        $foreignAccount = $this->hpost($this->poster($s['w']), 'payroll/runs/' . $run['public_id'] . '/pay', ['account' => $s['w']['cash_b1']['public_id']]);
        $this->assertSame(409, $foreignAccount->status(), 'production gate first (no target oracle before it)');
        // FINANCE_POST alone (no PAYROLL_POST) and PAYROLL_POST alone (no FINANCE_POST) are both refused (cumulative, D31).
        $this->hpost($this->staff(['FINANCE_POST'], $s['w']['a1']['id']), 'payroll/runs/' . $run['public_id'] . '/post')->assertStatus(403);
        $this->hpost($this->staff(['PAYROLL_POST'], $s['w']['a1']['id']), 'payroll/runs/' . $run['public_id'] . '/post')->assertStatus(403);
    }

    public function test_p36_payroll_summary_and_export(): void
    {
        $s = $this->scenario();
        $run = $this->calculated($s);
        $summary = $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'] . '/summary')->assertOk()->json('data');
        $this->assertSame([2, 'CALCULATED', self::PERIOD, '257654.00', '13129.68', '19012.32', '244524.32'],
            [$summary['headcount'], $summary['status'], $summary['period'], ...array_values($summary['totals'])]);
        $this->assertSame('237654.00', collect($summary['components'])->firstWhere('component', 'BASE_SALARY')['amount']);
        $financeOnly = $this->staff(['FINANCE_VIEW', 'FINANCE_PAYROLL_SUMMARY_VIEW'], $s['w']['a1']['id']);
        $this->assertSame($summary['totals'], $this->hget($financeOnly, 'payroll/runs/' . $run['public_id'] . '/summary')->assertOk()->json('data.totals'), 'D28 Finance side: totals only');
        $this->hget($financeOnly, 'payroll/runs/' . $run['public_id'])->assertStatus(403);
        $financeSummary = json_encode($this->hget($financeOnly, 'payroll/runs/' . $run['public_id'] . '/summary')->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        foreach ([$s['p1']['name'], $s['p2']['name'], $s['e1']['public_id'], '150000.50', '87653.50', '160500.43'] as $needle) {
            $this->assertStringNotContainsString($needle, $financeSummary, 'the Finance side never receives a person or an individual amount');
        }
        $this->hget($financeOnly, 'payroll/runs/' . $run['public_id'] . '/employees')->assertStatus(403);
        $this->flushHeaders();
        $csv = $this->withHeaders(['Authorization' => 'Bearer ' . $s['hr']['token']])->get('/api/v1/hr/payroll/runs/' . $run['public_id'] . '/summary.csv');
        $csv->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $body = $csv->getContent();
        $this->assertStringContainsString('244524.32', $body);
        foreach ([$s['p1']['name'], $s['e1']['public_id'], '150000.50'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, 'no person in the summary export');
        }
        $this->assertSame([1, 3], [$this->auditCount('payroll.summary_exported'), $this->auditCount('payroll.summary_viewed')]);
    }

    // ---- P37-P40 history, seeds, schema -------------------------------------------------------------------------------------

    public function test_p37_current_configuration_never_rewrites_a_historical_run(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->approved($s);
        $snapshot = fn () => json_encode([DB::table('payroll_runs')->where('public_id', $run['public_id'])->first(['status', 'input_hash', 'gross_amount', 'deductions_amount', 'employer_charges_amount', 'net_amount', 'headcount']),
            DB::table('payroll_run_lines')->where('run_id', $this->runId($run['public_id']))->orderBy('id')->get()]);
        $before = $snapshot();
        $this->setComponent($s['hr'], $s['e1']['public_id'], 'BASE_SALARY', '999999.00', '2026-09-01')->assertCreated();
        $this->hpost($s['rapprover'], 'payroll-rules/SYN_INSS_E/1/retire', ['reason' => 'Mudança sintética'])->assertOk();
        $this->assertSame($before, $snapshot(), 'an APPROVED run keeps its lines, totals and hash');
        $this->assertSame('FROZEN', $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'])->json('data.input.status'));
        $this->assertSame('257654.00', $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'])->json('data.totals.gross'));
    }

    public function test_p38_a_posted_run_is_unaffected_by_later_changes_and_pay_uses_the_posted_net(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->posted($s);
        $this->setComponent($s['hr'], $s['e2']['public_id'], 'BASE_SALARY', '500000.00', '2026-09-01')->assertCreated();
        $this->hpost($s['rapprover'], 'payroll-rules/SYN_IRT/1/retire', ['reason' => 'Mudança sintética'])->assertOk();
        $paid = $this->payRun($s, $run);
        $amount = DB::table('journal_lines')->where('entry_id', DB::table('journal_entries')->where('public_id', $paid['entry'])->value('id'))->where('debit', '>', 0)->value('debit');
        $this->assertSame('244524.3200', (string) $amount, 'PAY settles what was posted; nothing is recalculated');
        $this->assertSame('244524.32', $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'])->json('data.totals.net'));
    }

    public function test_p39_no_official_rate_is_seeded(): void
    {
        PayrollCatalog::install(DB::connection());
        $this->assertSame([0, 0, 0], [DB::table('payroll_rules')->count(), DB::table('payroll_rule_brackets')->count(), DB::table('payroll_rule_base_components')->count()], 'the installer seeds no rule / bracket / base');
        $this->assertSame(0, DB::table('employment_compensations')->whereNotNull('amount')->whereNull('created_by')->count());
        $root = dirname(base_path(), 2);
        $sources = array_merge(glob($root . '/apps/api/app/Domain/Payroll/*.php'), glob($root . '/apps/api/database/migrations/2026_10_02_2000*.php'), [$root . '/apps/api/config/payroll.php']);
        foreach ($sources as $file) {
            // A rate-like literal (0.0..1..: a non-zero fraction); money zeros such as '0.00' are not rates.
            preg_match_all('/[\'"]0\.0*[1-9]\d*[\'"]|\b0\.0*[1-9]\d*\b/', (string) file_get_contents($file), $hits);
            $this->assertSame([], $hits[0], basename($file) . ' hard-codes no rate');
        }
        $this->assertFalse((bool) config('payroll.production_enabled'));
    }

    public function test_p40_the_p010_schema_remains_35_of_35_physical_tables(): void
    {
        $manifest = json_decode(file_get_contents(dirname(base_path(), 2) . '/docs/database/physical/p010_finance_delta_manifest.json'), true);
        $all = array_merge($manifest['materialized_tables_f1a'], $manifest['materialized_tables_f2a']);
        $live = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereIn('TABLE_NAME', $all)->count();
        $this->assertSame([26, 9, 35], [count($manifest['materialized_tables_f1a']), count($manifest['materialized_tables_f2a']), $live]);
        $after = array_filter(scandir(base_path('database/migrations')), fn ($f) => preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $f, $m) === 1 && $m[1] > '2026_10_02_200010');
        $this->assertSame([], array_values($after), 'F2B added no migration');
        $this->assertSame(count($manifest['migrations']), DB::table('migrations')->whereIn('migration', array_map(fn ($m) => substr($m, 0, -4), $manifest['migrations']))->count());
    }

    // ---- X01-X04 accounting cross-check, DRE / DOAF, production default, correction ---------------------------------------

    /** Independent cross-check: every expected figure below was computed BY HAND (never by the calculator). */
    public function test_x01_accounting_cross_check_against_a_hand_computed_dataset(): void
    {
        $expected = [
            // employee 1: BASE 150000.50 + TRANSPORT 20000.00; INSS 3% = 4500.015 -> 4500.02; IRT (150000.50-100000)x10% = 5000.05; INSS employer 8% = 12000.04
            'e1' => ['gross' => '170000.50', 'deductions' => '9500.07', 'employer_charges' => '12000.04', 'net' => '160500.43'],
            // employee 2: BASE 87653.50; INSS 3% = 2629.605 -> 2629.61; IRT bracket 0 -> 0.00; OTHER_DEDUCTION 1000.00; INSS employer 8% = 7012.28
            'e2' => ['gross' => '87653.50', 'deductions' => '3629.61', 'employer_charges' => '7012.28', 'net' => '84023.89'],
            'run' => ['gross' => '257654.00', 'deductions' => '13129.68', 'employer_charges' => '19012.32', 'net' => '244524.32'],
            'accrual' => ['PER_SALARY' => '237654.00', 'PER_TRANSPORT_MEAL' => '20000.00', 'PER_INSS' => '19012.32',
                'PAYROLL_WITHHOLDINGS' => '13129.68', 'PAYROLL_EMPLOYER_CHARGES' => '19012.32', 'PAYROLL_NET_PAYABLE' => '244524.32', 'total' => '276666.32'],
            'payment' => '244524.32',
            'after_payment' => ['PAYROLL_NET_PAYABLE' => '0.00', 'PAYROLL_WITHHOLDINGS' => '13129.68', 'PAYROLL_EMPLOYER_CHARGES' => '19012.32'],
        ];
        $s = $this->scenario();
        $this->production(true);
        $run = $this->posted($s);
        $employees = collect($this->hget($s['hr'], 'payroll/runs/' . $run['public_id'] . '/employees')->json('data.employees'))->keyBy('employment');
        foreach (['e1', 'e2'] as $k) {
            $e = $employees[$s[$k]['public_id']];
            $this->assertSame($expected[$k], ['gross' => $e['gross'], 'deductions' => $e['deductions'], 'employer_charges' => $e['employer_charges'], 'net' => $e['net']], $k);
        }
        $this->assertSame($expected['run'], $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'])->json('data.totals'));
        $accrual = DB::table('journal_entries')->where('public_id', DB::table('payroll_postings as pp')->join('journal_entries as e', 'e.id', '=', 'pp.entry_id')->where('pp.run_id', $this->runId($run['public_id']))->where('pp.stage', 'ACCRUAL')->value('e.public_id'))->first();
        $got = [];
        foreach ($this->entryLines((int) $accrual->id) as [$role, $rubric, $debit, $credit]) {
            $got[$rubric ?? $role] = bccomp($debit, '0', 2) > 0 ? $debit : $credit;
        }
        $debits = bcadd(bcadd($got['PER_SALARY'], $got['PER_TRANSPORT_MEAL'], 2), $got['PER_INSS'], 2);
        $credits = bcadd(bcadd($got['PAYROLL_WITHHOLDINGS'], $got['PAYROLL_EMPLOYER_CHARGES'], 2), $got['PAYROLL_NET_PAYABLE'], 2);
        $actualAccrual = $got + ['total' => $debits];
        $expectedAccrual = $expected['accrual'];
        ksort($actualAccrual);
        ksort($expectedAccrual);
        $this->assertSame($expectedAccrual, $actualAccrual);
        $this->assertSame($debits, $credits, 'debits = credits');
        $this->assertSame(bcadd($expected['run']['gross'], $expected['run']['employer_charges'], 2), $debits, 'Payroll totals = Finance aggregated posting');
        $paid = $this->payRun($s, $run);
        $this->assertSame($expected['payment'], bcadd((string) DB::table('journal_lines')->where('entry_id', DB::table('journal_entries')->where('public_id', $paid['entry'])->value('id'))->sum('debit'), '0', 2));
        $this->assertEquals($expected['after_payment'], array_intersect_key($this->roles($s), $expected['after_payment']));
        $text = json_encode(DB::table('journal_lines')->whereIn('entry_id', DB::table('journal_entries')->where('unit_id', $s['w']['a1']['id'])->pluck('id'))->get(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($s['p1']['name'], $text, 'Finance contains no Person');
        $this->evidence('X01', ['expected' => $expected, 'accrual' => $got, 'balanced' => $debits === $credits]);
    }

    public function test_x02_dre_and_doaf_integration(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $reader = $this->staff(['FINANCE_VIEW', 'FINANCE_REPORT'], $s['w']['a1']['id']);
        $dre = fn (string $p) => $this->api($reader, 'GET', 'finance/reports/OWN_DRE?unit=' . $s['w']['a1']['public_id'] . '&period_kind=MONTH&period=' . $p)->assertOk()->json('data.dre');
        $doaf = fn (string $p) => $this->api($reader, 'GET', 'finance/reports/OWN_DOAF?unit=' . $s['w']['a1']['public_id'] . '&period_kind=MONTH&period=' . $p)->assertOk()->json('data.doaf');
        $sepBefore = $dre('2026-09');
        $run = $this->posted($s);
        $sep = $dre('2026-09');
        $this->assertSame('276666.32', bcsub($sep['expenses'], $sepBefore['expenses'], 2), 'POST: the DRE recognises gross + employer charges in the service month (accrual)');
        $lines = collect($sep['expense_lines'])->keyBy('category.code');
        $this->assertSame(['237654.00', '20000.00', '19012.32'], [$lines['PER_SALARY']['amount'], $lines['PER_TRANSPORT_MEAL']['amount'], $lines['PER_INSS']['amount']]);
        $this->assertSame('0.00', bcsub($doaf('2026-09')['external_applications'], '0.00', 2), 'the accrual has no CASH/BANK line: not in the DOAF');
        $octBefore = $dre('2026-10');
        $doafBefore = $doaf('2026-10');
        $this->payRun($s, $run);
        $this->assertSame($octBefore['expenses'], $dre('2026-10')['expenses'], 'PAY never duplicates the expense in the DRE');
        $after = $doaf('2026-10');
        $this->assertSame('244524.32', bcsub($after['external_applications'], $doafBefore['external_applications'], 2), 'PAY is a treasury application in the DOAF');
        $this->assertTrue($after['balanced'], 'origins = applications');
        $payables = $this->api($reader, 'GET', 'finance/reports/FINANCE_PERIOD_SUMMARY?unit=' . $s['w']['a1']['public_id'] . '&period_kind=MONTH&period=2026-10')->assertOk()->json();
        $this->assertStringNotContainsString($s['p1']['name'], json_encode($payables, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['13129.68', '19012.32'], [$this->roles($s)['PAYROLL_WITHHOLDINGS'], $this->roles($s)['PAYROLL_EMPLOYER_CHARGES']], 'statutory liabilities stay on the balance sheet');
    }

    public function test_x03_production_stays_disabled_by_default(): void
    {
        $file = require base_path('config/payroll.php');
        $this->assertFalse($file['production_enabled'], 'the shipped configuration evaluates to false');
        $this->assertFalse(config('payroll.production_enabled'));
        $this->assertNotTrue(filter_var(getenv('PAYROLL_PRODUCTION_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN));
        $root = dirname(base_path(), 2);
        foreach (array_filter([base_path('.env'), base_path('.env.example'), base_path('phpunit.xml'), $root . '/.env', $root . '/.env.example'], 'is_file') as $file) {
            $this->assertDoesNotMatchRegularExpression('/PAYROLL_PRODUCTION_ENABLED\s*=\s*"?(1|true|on|yes)/i', (string) file_get_contents($file), basename($file) . ' never enables payroll production');
        }
        $this->assertSame('DISABLED', $this->hget($this->hr($this->world()['a']), 'payroll/status')->json('data.production.status'));
    }

    public function test_x04_reversal_before_payment_and_cancel_are_explicit_audited_commands(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->posted($s);
        $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/reverse', ['entry_date' => '2026-10-01'])->assertStatus(422);
        $rev = $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/reverse', ['reason' => 'Erro de configuração sintética', 'entry_date' => '2026-10-01'])->assertOk()->json('data');
        $entry = DB::table('journal_entries')->where('public_id', $rev['entry'])->first();
        $this->assertSame(['PAYROLL_REVERSAL', 'REVERSED'], [$entry->entry_kind, DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status')]);
        $this->assertSame(['0.00', '0.00', '0.00'], array_values(array_intersect_key($this->roles($s), array_flip(['PAYROLL_NET_PAYABLE', 'PAYROLL_WITHHOLDINGS', 'PAYROLL_EMPLOYER_CHARGES']))));
        $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/pay', ['account' => $s['w']['cash_a1']['public_id'], 'paid_on' => '2026-10-01'])->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_NOT_ALLOWED');
        $paidRun = $this->posted($this->scenario());
        $ps = $this->lastScenario;
        $this->payRun($ps, $paidRun);
        $this->hpost($ps['poster'], 'payroll/runs/' . $paidRun['public_id'] . '/reverse', ['reason' => 'Tarde demais'])->assertStatus(409)->assertJsonPath('error.code', 'PAYROLL_ALREADY_PAID');
        $draft = $this->createRun($s['hr'], $s['w']['a1'], '2026-08');
        $this->hpost($s['hr'], 'payroll/runs/' . $draft['public_id'] . '/cancel', ['reason' => 'Criada por engano'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame(1, DB::table('payroll_runs')->where('public_id', $draft['public_id'])->count(), 'never a hard delete');
        $again = $this->createRun($s['hr'], $s['w']['a1'], '2026-08');
        $this->assertSame(2, $again['sequence'], 'a cancelled REGULAR frees the month; history keeps sequence 1');
        foreach (['payroll.reversed', 'payroll.cancelled', 'payroll.paid', 'payroll.posted', 'payroll.approved', 'payroll.calculated', 'payroll.created'] as $action) {
            $this->assertGreaterThan(0, $this->auditCount($action), $action);
        }
        $this->assertSame([], array_values(array_filter(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with($r->uri(), 'api/v1/hr/payroll/runs') && array_intersect($r->methods(), ['PATCH', 'PUT', 'DELETE']) !== [])), 'no generic status write, no delete route');
    }

    // ---- C5 / C6 / C11 / POST x close / PAY x close (real processes) ---------------------------------------------------------

    public function test_c5a_compensation_change_committed_first_makes_the_approval_stale(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->calculated($s);
        $change = $this->spawn(['op' => 'compensation_change', 'user' => $s['hr']['user'], 'session' => $s['hr']['session'], 'employment' => $s['e1']['public_id'],
            'body' => ['component' => 'TRANSPORT_MEAL_ALLOWANCE', 'amount' => '25000.00', 'starts_on' => '2026-09-01', 'reason' => 'C5 concorrente'], 'hold_until_commit' => true]);
        $approve = $this->spawn(['op' => 'run_approve', 'user' => $s['approver']['user'], 'session' => $s['approver']['session'], 'run' => $run['public_id'], 'production' => true]);
        $this->send($change, 'go');
        $this->assertSame('HELD', $this->workerLine($change), 'the change holds the employment / compensation locks, not committed');
        $this->send($approve, 'go');
        $this->assertTrue($this->waitsForLock($approve['connection']), 'the approval waits on the inputs the change holds');
        $this->send($change, 'commit');
        $rc = $this->finish($change);
        $ra = $this->finish($approve);
        $this->assertSame(['OK', 'PAYROLL_INPUT_STALE'], [$rc['status'], $ra['status']]);
        $this->assertSame('CALCULATED', DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status'), 'never APPROVED on a mix of two input sets');
        $this->evidence('C5a', ['change' => $rc['status'], 'approve' => $ra['status'], 'run_status' => 'CALCULATED']);
    }

    public function test_c5b_approval_committed_first_freezes_the_inputs_and_the_change_counts_only_for_future_calculations(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->calculated($s);
        $lines = json_encode(DB::table('payroll_run_lines')->where('run_id', $this->runId($run['public_id']))->orderBy('id')->get());
        $approve = $this->spawn(['op' => 'run_approve', 'user' => $s['approver']['user'], 'session' => $s['approver']['session'], 'run' => $run['public_id'], 'production' => true, 'hold_until_commit' => true]);
        $change = $this->spawn(['op' => 'compensation_change', 'user' => $s['hr']['user'], 'session' => $s['hr']['session'], 'employment' => $s['e1']['public_id'],
            'body' => ['component' => 'TRANSPORT_MEAL_ALLOWANCE', 'amount' => '25000.00', 'starts_on' => '2026-09-01', 'reason' => 'C5 concorrente']]);
        $this->send($approve, 'go');
        $this->assertSame('HELD', $this->workerLine($approve), 'the approval holds the inputs FOR SHARE, not committed');
        $this->send($change, 'go');
        $this->assertTrue($this->waitsForLock($change['connection']), 'the change waits on the employment row the approval holds');
        $this->send($approve, 'commit');
        $ra = $this->finish($approve);
        $rc = $this->finish($change);
        $this->assertSame(['OK', 'OK'], [$ra['status'], $rc['status']]);
        $row = DB::table('payroll_runs')->where('public_id', $run['public_id'])->first();
        $this->assertSame(['APPROVED', $run['input_hash']], [$row->status, bin2hex((string) $row->input_hash)]);
        $this->assertSame($lines, json_encode(DB::table('payroll_run_lines')->where('run_id', $row->id)->orderBy('id')->get()), 'the approved run never changes');
        $this->assertNotSame($run['input_hash'], PayrollInputHash::hash(PayrollInputHash::payload(DB::connection(), $s['w']['a1']['id'], self::PERIOD)), 'the change exists for future calculations');
        $this->evidence('C5b', ['approve' => $ra['status'], 'change' => $rc['status'], 'run_status' => 'APPROVED', 'hash_unchanged' => true]);
    }

    public function test_c6_two_concurrent_posts_produce_one_accrual(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->approved($s);
        $other = $this->poster($s['w']);
        $a = $this->spawn(['op' => 'run_post', 'user' => $s['poster']['user'], 'session' => $s['poster']['session'], 'run' => $run['public_id'], 'body' => ['entry_date' => '2026-09-30'], 'production' => true, 'hold_until_commit' => true]);
        $b = $this->spawn(['op' => 'run_post', 'user' => $other['user'], 'session' => $other['session'], 'run' => $run['public_id'], 'body' => ['entry_date' => '2026-09-30'], 'production' => true]);
        $this->send($a, 'go');
        $this->assertSame('HELD', $this->workerLine($a));
        $this->send($b, 'go');
        $this->assertTrue($this->waitsForLock($b['connection']), 'B waits on the run lock');
        $this->send($a, 'commit');
        $ra = $this->finish($a);
        $rb = $this->finish($b);
        $this->assertSame(['OK', 'OK', false, true, $ra['result']['entry']], [$ra['status'], $rb['status'], $ra['result']['replayed'], $rb['result']['replayed'], $rb['result']['entry']]);
        $this->assertSame([1, 1], [DB::table('payroll_postings')->where('run_id', $this->runId($run['public_id']))->where('stage', 'ACCRUAL')->count(), $this->payrollEntries($s, 'PAYROLL_ACCRUAL')]);
        $this->evidence('C6', ['a' => 'POSTED', 'b' => 'REPLAY', 'accrual_postings' => 1, 'accrual_entries' => 1]);
    }

    public function test_c11_two_concurrent_payments_debit_the_bank_once(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->posted($s);
        $cash = $this->balance($s['w']['cash_a1']);
        $other = $this->poster($s['w']);
        $body = ['account' => $s['w']['cash_a1']['public_id'], 'paid_on' => '2026-10-01'];
        $a = $this->spawn(['op' => 'run_pay', 'user' => $s['poster']['user'], 'session' => $s['poster']['session'], 'run' => $run['public_id'], 'body' => $body, 'production' => true, 'hold_until_commit' => true]);
        $b = $this->spawn(['op' => 'run_pay', 'user' => $other['user'], 'session' => $other['session'], 'run' => $run['public_id'], 'body' => $body, 'production' => true]);
        $this->send($a, 'go');
        $this->assertSame('HELD', $this->workerLine($a));
        $this->send($b, 'go');
        $this->assertTrue($this->waitsForLock($b['connection']));
        $this->send($a, 'commit');
        $ra = $this->finish($a);
        $rb = $this->finish($b);
        $this->assertSame(['OK', 'OK', true], [$ra['status'], $rb['status'], $rb['result']['replayed']]);
        $this->assertSame([1, 1, $cash - 24452432], [DB::table('payroll_postings')->where('run_id', $this->runId($run['public_id']))->where('stage', 'PAYMENT')->count(), $this->payrollEntries($s, 'PAYROLL_PAYMENT'), $this->balance($s['w']['cash_a1'])]);
        $this->evidence('C11', ['a' => 'PAID', 'b' => 'REPLAY', 'payment_entries' => 1, 'cash_debited_once' => true]);
    }

    public function test_c_post_vs_close_post_first(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->approved($s);
        $closer = $this->closer($s);
        $post = $this->spawn(['op' => 'run_post', 'user' => $s['poster']['user'], 'session' => $s['poster']['session'], 'run' => $run['public_id'], 'body' => ['entry_date' => '2026-09-30'], 'production' => true, 'hold_until_commit' => true]);
        $close = $this->spawn(['op' => 'close_unit', 'user' => $closer['user'], 'session' => $closer['session'], 'period' => '2026-09', 'body' => ['unit' => $s['w']['a1']['public_id']]]);
        $this->send($post, 'go');
        $this->assertSame('HELD', $this->workerLine($post));
        $this->send($close, 'go');
        $this->assertTrue($this->waitsForLock($close['connection']), 'the close waits for the posting');
        $this->send($post, 'commit');
        $this->assertSame(['OK', 'OK'], [$this->finish($post)['status'], $this->finish($close)['status']]);
        $this->assertSame(1, $this->payrollEntries($s, 'PAYROLL_ACCRUAL'), 'the posting committed before the close and counts in it');
        $this->assertNoEntryAfterClose($s, '2026-09');
        $this->evidence('POSTxCLOSE_post_first', ['post' => 'OK', 'close' => 'OK']);
    }

    public function test_c_post_vs_close_close_first(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->approved($s);
        $closer = $this->closer($s);
        $close = $this->spawn(['op' => 'close_unit', 'user' => $closer['user'], 'session' => $closer['session'], 'period' => '2026-09', 'body' => ['unit' => $s['w']['a1']['public_id']], 'hold_until_commit' => true]);
        $post = $this->spawn(['op' => 'run_post', 'user' => $s['poster']['user'], 'session' => $s['poster']['session'], 'run' => $run['public_id'], 'body' => ['entry_date' => '2026-09-30'], 'production' => true]);
        $this->send($close, 'go');
        $this->assertSame('HELD', $this->workerLine($close));
        $this->send($post, 'go');
        $this->assertTrue($this->waitsForLock($post['connection']), 'the posting waits on the period the close holds');
        $this->send($close, 'commit');
        $this->assertSame(['OK', 'PERIOD_CLOSED'], [$this->finish($close)['status'], $this->finish($post)['status']]);
        $this->assertSame(['APPROVED', 0], [DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status'), $this->payrollEntries($s, 'PAYROLL_ACCRUAL')]);
        $this->evidence('POSTxCLOSE_close_first', ['close' => 'OK', 'post' => 'PERIOD_CLOSED']);
    }

    public function test_c_pay_vs_close_pay_first(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->posted($s);
        $closer = $this->closer($s);
        $pay = $this->spawn(['op' => 'run_pay', 'user' => $s['poster']['user'], 'session' => $s['poster']['session'], 'run' => $run['public_id'], 'body' => ['account' => $s['w']['cash_a1']['public_id'], 'paid_on' => '2026-10-01'],
            'production' => true, 'hold_until_commit' => true]);
        $close = $this->spawn(['op' => 'close_unit', 'user' => $closer['user'], 'session' => $closer['session'], 'period' => '2026-10', 'body' => ['unit' => $s['w']['a1']['public_id']]]);
        $this->send($pay, 'go');
        $this->assertSame('HELD', $this->workerLine($pay));
        $this->send($close, 'go');
        $this->assertTrue($this->waitsForLock($close['connection']));
        $this->send($pay, 'commit');
        $this->assertSame(['OK', 'OK'], [$this->finish($pay)['status'], $this->finish($close)['status']]);
        $this->assertSame(1, $this->payrollEntries($s, 'PAYROLL_PAYMENT'));
        $this->assertNoEntryAfterClose($s, '2026-10');
        $this->evidence('PAYxCLOSE_pay_first', ['pay' => 'OK', 'close' => 'OK']);
    }

    public function test_c_pay_vs_close_close_first(): void
    {
        $s = $this->scenario();
        $this->production(true);
        $run = $this->posted($s);
        $cash = $this->balance($s['w']['cash_a1']);
        $closer = $this->closer($s);
        $close = $this->spawn(['op' => 'close_unit', 'user' => $closer['user'], 'session' => $closer['session'], 'period' => '2026-10', 'body' => ['unit' => $s['w']['a1']['public_id']], 'hold_until_commit' => true]);
        $pay = $this->spawn(['op' => 'run_pay', 'user' => $s['poster']['user'], 'session' => $s['poster']['session'], 'run' => $run['public_id'], 'body' => ['account' => $s['w']['cash_a1']['public_id'], 'paid_on' => '2026-10-01'], 'production' => true]);
        $this->send($close, 'go');
        $this->assertSame('HELD', $this->workerLine($close));
        $this->send($pay, 'go');
        $this->assertTrue($this->waitsForLock($pay['connection']));
        $this->send($close, 'commit');
        $this->assertSame(['OK', 'PERIOD_CLOSED'], [$this->finish($close)['status'], $this->finish($pay)['status']]);
        $this->assertSame(['POSTED', 0, $cash], [DB::table('payroll_runs')->where('public_id', $run['public_id'])->value('status'), $this->payrollEntries($s, 'PAYROLL_PAYMENT'), $this->balance($s['w']['cash_a1'])]);
        $this->evidence('PAYxCLOSE_close_first', ['close' => 'OK', 'pay' => 'PERIOD_CLOSED']);
    }

    // ---- scenario + helpers ----------------------------------------------------------------------------------------------

    private ?array $lastScenario = null;

    /**
     * Synthetic world: unit A1 with two employments (EMPLOYEE), synthetic rules SYN_* (3% / 8% flat, a two-bracket table),
     * a funded cash account. Every figure is test data.
     */
    private function scenario(bool $rules = true, string $funding = '300000.00'): array
    {
        $w = $this->world();
        $s = ['w' => $w, 'hr' => $this->hr($w['a']), 'approver' => $this->hr($w['a'], ['PAYROLL_APPROVE', 'HR_EMPLOYMENT_VIEW']), 'poster' => $this->poster($w),
            'manager' => $this->national($w, ['PAYROLL_RULES_MANAGE']), 'rapprover' => $this->national($w, ['PAYROLL_RULES_APPROVE'])];
        $p1 = $this->person($w['a1'], 'Trabalhador Sintético Um ' . Str::random(5));
        $p2 = $this->person($w['a1'], 'Trabalhador Sintético Dois ' . Str::random(5));
        $s['p1'] = $p1 + ['name' => (string) DB::table('people')->where('id', $p1['id'])->value('full_name')];
        $s['p2'] = $p2 + ['name' => (string) DB::table('people')->where('id', $p2['id'])->value('full_name')];
        // Rules are national (global): a second scenario in the same test reuses the synthetic rules already approved.
        if ($rules && !DB::table('payroll_rules')->where('code', 'SYN_INSS_E')->exists()) {
            $this->flatRule($s['manager'], $s['rapprover'], $w, 'SYN_INSS_E', 'INSS_EMPLOYEE', '0.030000', '2026-01-01');
            $this->otherRules($s);
        }
        $s['e1'] = $this->employ($s['hr'], $p1, $w['a1'], '2026-01-01');
        $s['e2'] = $this->employ($s['hr'], $p2, $w['a1'], '2026-01-01');
        foreach ([['e1', 'BASE_SALARY', '150000.50'], ['e1', 'TRANSPORT_MEAL_ALLOWANCE', '20000.00'], ['e2', 'BASE_SALARY', '87653.50'], ['e2', 'OTHER_DEDUCTION', '1000.00']] as [$e, $c, $amount]) {
            $this->setComponent($s['hr'], $s[$e]['public_id'], $c, $amount, '2026-01-01')->assertCreated();
        }
        foreach (['e1', 'e2'] as $e) {
            foreach (['INSS_EMPLOYEE', 'INCOME_TAX_WITHHOLDING', 'INSS_EMPLOYER'] as $c) {
                $this->setComponent($s['hr'], $s[$e]['public_id'], $c, null, '2026-01-01')->assertCreated();
            }
        }
        $this->contribute($this->treasurer($w['a1']), $w['cash_a1'], $funding, '2026-09-10');
        return $this->lastScenario = $s;
    }

    private function otherRules(array $s): void
    {
        $this->flatRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_INSS_P', 'INSS_EMPLOYER', '0.080000', '2026-01-01');
        $this->bracketRule($s['manager'], $s['rapprover'], $s['w'], 'SYN_IRT', [['lower_bound' => '0.00', 'upper_bound' => '100000.00', 'rate' => '0.000000'],
            ['lower_bound' => '100000.00', 'upper_bound' => null, 'rate' => '0.100000', 'fixed_amount' => '0.00', 'excess_over' => '100000.00']], '2026-01-01');
    }

    private function poster(array $w): array
    {
        return $this->staff(['PAYROLL_POST', 'FINANCE_POST', 'FINANCE_VIEW'], $w['a']['id'], true);
    }

    private function closer(array $s): array
    {
        return $this->staff(['FINANCE_PERIOD_CLOSE', 'FINANCE_VIEW'], $s['w']['a1']['id'], false);
    }

    private function production(bool $on): void
    {
        config(['payroll.production_enabled' => $on]);
    }

    private function createRun(array $actor, array $unit, string $period = self::PERIOD): array
    {
        return $this->hpost($actor, 'payroll/runs', ['unit' => $unit['public_id'], 'period' => $period])->assertCreated()->json('data');
    }

    /** create + calculate; returns the calculate payload + the employee breakdown. */
    private function calculated(array $s, string $period = self::PERIOD): array
    {
        $run = $this->createRun($s['hr'], $s['w']['a1'], $period);
        $calc = $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertOk()->json('data');
        return $calc + ['employees' => $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'] . '/employees')->assertOk()->json('data.employees')];
    }

    private function recalculated(array $s, array $run): array
    {
        $calc = $this->hpost($s['hr'], 'payroll/runs/' . $run['public_id'] . '/calculate')->assertOk()->json('data');
        return $calc + ['employees' => $this->hget($s['hr'], 'payroll/runs/' . $run['public_id'] . '/employees')->assertOk()->json('data.employees')];
    }

    private function approved(array $s): array
    {
        $run = $this->calculated($s);
        $this->hpost($s['approver'], 'payroll/runs/' . $run['public_id'] . '/approve')->assertOk();
        return $run;
    }

    private function postRun(array $s, array $run): array
    {
        return $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/post', ['entry_date' => '2026-09-30'])->assertOk()->json('data');
    }

    private function posted(array $s): array
    {
        $run = $this->approved($s);
        $this->postRun($s, $run);
        return $run;
    }

    private function payRun(array $s, array $run): array
    {
        return $this->hpost($s['poster'], 'payroll/runs/' . $run['public_id'] . '/pay', ['account' => $s['w']['cash_a1']['public_id'], 'paid_on' => '2026-10-01'])->assertOk()->json('data');
    }

    private function closeUnit(array $s, string $period): void
    {
        $this->api($this->closer($s), 'POST', 'finance/periods/' . $period . '/close', ['unit' => $s['w']['a1']['public_id']])->assertOk();
    }

    /** The line of $employment / $component in a calculated run (from the employee breakdown). */
    private function line(array $run, array $employment, string $component): array
    {
        $employee = collect($run['employees'])->firstWhere('employment', $employment['public_id']);
        $this->assertNotNull($employee, 'employment in the run');
        $line = collect($employee['lines'])->firstWhere('component.code', $component);
        $this->assertNotNull($line, $component . ' line');
        return $line;
    }

    private function runId(string $publicId): int
    {
        return (int) DB::table('payroll_runs')->where('public_id', $publicId)->value('id');
    }

    /** @return list<array{0: ?string, 1: ?string, 2: string, 3: string}> [ledger role, rubric, debit, credit] sorted */
    private function entryLines(int $entryId, bool $sort = true): array
    {
        $lines = DB::table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->leftJoin('financial_categories as c', 'c.id', '=', 'l.category_id')
            ->where('l.entry_id', $entryId)->orderBy('l.line_number')->get(['a.system_role', 'c.code', 'l.debit', 'l.credit'])
            ->map(fn ($l) => [(string) $l->system_role, $l->code === null ? null : (string) $l->code, bcadd((string) $l->debit, '0', 2), bcadd((string) $l->credit, '0', 2)])->all();
        if ($sort) {
            usort($lines, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        }
        return $lines;
    }

    /** Credit balance of the payroll liability roles of unit A1 (POSTED ledger). */
    private function roles(array $s): array
    {
        $out = [];
        foreach (['PAYROLL_NET_PAYABLE', 'PAYROLL_WITHHOLDINGS', 'PAYROLL_EMPLOYER_CHARGES'] as $role) {
            $row = DB::selectOne("SELECT COALESCE(SUM(l.credit) - SUM(l.debit), 0) AS b FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN chart_of_accounts a ON a.id = l.ledger_account_id WHERE e.status = 'POSTED' AND l.unit_id = ? AND a.system_role = ?", [$s['w']['a1']['id'], $role]);
            $out[$role] = bcadd((string) $row->b, '0', 2);
        }
        return $out;
    }

    private function payrollEntries(array $s, string $kind): int
    {
        return DB::table('journal_entries')->where('unit_id', $s['w']['a1']['id'])->where('entry_kind', $kind)->where('status', 'POSTED')->count();
    }

    private function assertNoEntryAfterClose(array $s, string $period): void
    {
        $closedAt = (string) DB::table('accounting_period_unit_closes as c')->join('accounting_periods as p', 'p.id', '=', 'c.period_id')->where('p.code', $period)->where('c.unit_id', $s['w']['a1']['id'])->value('c.closed_at');
        $this->assertNotSame('', $closedAt);
        $this->assertSame(0, DB::table('journal_entries as e')->join('accounting_periods as p', 'p.id', '=', 'e.period_id')->where('p.code', $period)->where('e.unit_id', $s['w']['a1']['id'])
            ->where('e.posted_at', '>', $closedAt)->count(), 'no journal entry in the period after its close');
    }

    private function hpostKey(array $actor, string $uri, array $body, string $key): \Illuminate\Testing\TestResponse
    {
        $this->flushHeaders();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $actor['token'], 'Accept' => 'application/json', 'Idempotency-Key' => $key])->json('POST', '/api/v1/hr/' . ltrim($uri, '/'), $body);
    }

    private function assertSqlError(int $code, callable $fn, string $message = ''): void
    {
        try {
            $fn();
            $this->fail('expected SQL error ' . $code . ' ' . $message);
        } catch (QueryException $e) {
            $this->assertSame($code, (int) $e->errorInfo[1], $message . ' ' . $e->getMessage());
        }
    }

    private function spawn(array $job): array
    {
        $root = dirname(__DIR__, 4);
        $php = getenv('MEPA_PHP_BIN') ?: 'C:\\wamp64\\bin\\php\\php8.1.33\\php.exe';
        $env = array_merge(getenv(), ['WAVE5_ALLOW_SYNTHETIC' => '1', 'XDEBUG_MODE' => 'off', 'APP_KEY' => (string) config('app.key')]);
        $proc = proc_open([$php, $root . '/scripts/p010-f2b-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        $ready = $this->workerLine(['pipes' => $pipes]);
        if (!str_starts_with($ready, 'READY ')) {
            $this->fail('worker not ready: ' . $ready . stream_get_contents($pipes[2]));
        }
        return ['proc' => $proc, 'pipes' => $pipes, 'connection' => (int) substr($ready, 6)];
    }

    private function send(array $worker, string $word): void
    {
        fwrite($worker['pipes'][0], $word . "\n");
        fflush($worker['pipes'][0]);
    }

    private function workerLine(array $worker): string
    {
        return trim(str_replace("\r", '', (string) fgets($worker['pipes'][1])));
    }

    /** Deterministic barrier: the worker's connection is observed WAITING for a lock (bounded poll of performance_schema). */
    private function waitsForLock(int $connection): bool
    {
        for ($i = 0; $i < 400; $i++) {
            $n = (int) DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?', [$connection])->n;
            if ($n > 0) {
                return true;
            }
            usleep(25000);
        }
        return false;
    }

    private function finish(array $worker): array
    {
        fclose($worker['pipes'][0]);
        $out = stream_get_contents($worker['pipes'][1]);
        $err = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $this->assertSame(0, proc_close($worker['proc']), $err);
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', trim((string) $out)))));
        $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame('UNEXPECTED', $result['status'], json_encode($result));
        return $result;
    }

    private function evidence(string $name, array $data): void
    {
        $dir = getenv('P010_F2B_EVIDENCE_DIR');
        if ($dir === false || $dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'concurrency-' . $name . '.json', json_encode($data + ['at' => now('UTC')->format('Y-m-d\TH:i:s\Z')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
