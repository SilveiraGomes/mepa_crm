<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Finance\FinanceError;
use App\Domain\Payroll\PayrollCatalog;
use App\Domain\Payroll\PayrollInputHash;
use App\Domain\Payroll\PayrollMoney;
use App\Domain\Payroll\PayrollProduction;
use App\Domain\Payroll\PayrollRuleResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\DatabaseV2\Support\PayrollHttpCase;

/**
 * P0.10-F2A RH / compensation foundation (ADR 0021 D22-D28, D32/D33 + D-04A.14/15), MySQL 8.4 Wave 5 pool.
 * H01-H24 domain + HTTP, HC1-HC2 real concurrency (two processes, deterministic lock-wait barrier), S01-S09 schema.
 * Every rule here is SYNTHETIC test data created through the API by the test (never by an installer); no figure is an
 * official Angolan rate.
 */
final class PayrollFoundationF2ATest extends PayrollHttpCase
{
    // ---- H01-H05 Person, separation, ownership, history ---------------------------------------------------------------

    public function test_h01_the_person_is_the_root_of_the_employee(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $p = $this->person($w['a1'], 'Ana Funcionária ' . Str::random(4));
        $people = DB::table('people')->count();
        $e = $this->employ($hr, $p, $w['a1']);
        $this->assertSame($people, DB::table('people')->count(), 'no parallel Person is created');
        $this->assertSame($p['id'], (int) DB::table('employments')->where('public_id', $e['public_id'])->value('person_id'));
        $columns = array_map(fn ($c) => $c->c, DB::select("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employments'"));
        $this->assertSame([], array_values(array_intersect($columns, ['full_name', 'name', 'sex_id', 'birth_date', 'phone', 'email', 'document_number', 'address', 'person_employment_id'])), 'no personal attribute duplicated');
        $detail = $this->hget($hr, 'employments/' . $e['public_id'])->assertOk()->json('data');
        $this->assertSame(['public_id', 'name'], array_keys($detail['person']));
        $this->assertSame($p['public_id'], $detail['person']['public_id']);
        $this->assertNoInternalIds($detail);
        $this->assertSame(1, DB::table('person_unit_contexts')->where('person_id', $p['id'])->where('unit_id', $w['a1']['id'])->where('context_kind', 'EMPLOYMENT')->where('status', 'ACTIVE')->count(), 'People context EMPLOYMENT opened');
        // An unknown Person and a Person the actor cannot see through People are the same concealed 404.
        $hidden = $this->person($w['b1']);
        $this->assertConcealed($this->hpost($hr, 'employments', ['person' => $this->ghost(), 'unit' => $w['a1']['public_id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-01-01']));
        $this->assertConcealed($this->hpost($hr, 'employments', ['person' => $hidden['public_id'], 'unit' => $w['a1']['public_id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-01-01']));
        $this->assertConcealed($this->hpost($this->hr($w['a'], array_diff(self::HR_ALL, ['PEOPLE_VIEW'])), 'employments', ['person' => $p['public_id'], 'unit' => $w['a2']['public_id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-01-01']));
    }

    public function test_h02_person_employment_is_never_the_mepa_employment(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $p = $this->person($w['a1']);
        $this->row('person_employment', ['person_id' => $p['id'], 'profession' => 'Professora', 'status' => 'ACTIVE', 'starts_at' => '2020-01-01 00:00:00.000000', 'ends_at' => null]);
        $before = json_encode(DB::table('person_employment')->orderBy('id')->get());
        $types = json_encode(DB::table('employment_types')->orderBy('id')->get());
        $e = $this->employ($hr, $p, $w['a1']);
        $this->hpost($hr, 'employments/' . $e['public_id'] . '/end', ['ends_on' => '2026-05-31', 'end_reason' => 'Fim'])->assertOk();
        $this->assertSame($before, json_encode(DB::table('person_employment')->orderBy('id')->get()), 'person_employment is never written');
        $this->assertSame($types, json_encode(DB::table('employment_types')->orderBy('id')->get()));
        $refs = DB::select("SELECT TABLE_NAME AS t, REFERENCED_TABLE_NAME AS r FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IN ('person_employment','employment_types') AND TABLE_NAME IN ('" . implode("','", $this->payrollTables()) . "')");
        $this->assertSame([], $refs, 'no payroll table references person_employment / employment_types');
        $this->assertSame(1, DB::table('employments')->where('person_id', $p['id'])->count(), 'the MEPA employment is its own row');
    }

    public function test_h03_an_ecclesiastical_appointment_is_never_an_employment(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $p = $this->person($w['a1']);
        $forbidden = ['ministerial_assignments', 'organizational_posts', 'department_posts', 'department_instances', 'departments', 'memberships', 'post_appointments', 'person_employment'];
        $refs = DB::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS r FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . implode("','", $this->payrollTables()) . "') AND REFERENCED_TABLE_NAME IN ('" . implode("','", $forbidden) . "')");
        $this->assertSame([], $refs, 'no FK between payroll and appointments / posts / departments / membership');
        $counts = fn () => array_map(fn ($t) => DB::table($t)->count(), array_filter(['ministerial_assignments', 'memberships', 'organizational_posts'], fn ($t) => DB::getSchemaBuilder()->hasTable($t)));
        $before = $counts();
        $e = $this->employ($hr, $p, $w['a1'], '2026-01-01', ['job_title' => 'Motorista']);
        $this->hpost($hr, 'employments/' . $e['public_id'] . '/end', ['ends_on' => '2026-03-31', 'end_reason' => 'Fim'])->assertOk();
        $this->assertSame($before, $counts(), 'creating / ending an employment never creates or ends an appointment, post or membership');
        $this->assertSame('Motorista', $this->hget($hr, 'employments/' . $e['public_id'])->json('data.job_title'), 'job_title is free text, not a post');
    }

    public function test_h04_the_employing_unit_owns_the_employment(): void
    {
        $w = $this->world();
        $hrA = $this->hr($w['a']);
        $hrB = $this->hr($w['b']);
        $p = $this->person($w['a1']);
        $this->employ($this->hr($w['a1'], self::HR_ALL, false), $p, $w['a1']);
        $row = DB::table('employments')->where('person_id', $p['id'])->first();
        $this->assertSame($w['a1']['id'], (int) $row->employing_unit_id);
        $this->assertFalse(property_exists($row, 'department_id'), 'a department is never the owner');
        $this->assertSame($w['a1']['id'], (int) DB::table('audit_logs')->where('action', 'hr.employment_created')->orderByDesc('id')->value('unit_id'), 'audit unit = employing unit');
        $this->assertCount(1, $this->hget($hrA, 'employments?unit=' . $w['a1']['public_id'])->json('data'), 'the parent unit with descendants sees it');
        $this->assertSame([], $this->hget($hrB, 'employments')->json('data'), 'another branch does not');
        $this->assertConcealed($this->hpost($hrB, 'employments', ['person' => $p['public_id'], 'unit' => $w['a1']['public_id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-01-01']));
    }

    public function test_h05_employment_history_is_preserved_and_queryable_by_period(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $p = $this->person($w['a1']);
        $p2 = $this->person($w['a2']);
        DB::table('person_unit_contexts')->insert(['person_id' => $p['id'], 'unit_id' => $w['a2']['id'], 'context_kind' => 'ONBOARDING', 'status' => 'ACTIVE', 'starts_at' => now('UTC')->subHour()->format('Y-m-d H:i:s.u'), 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
        $e1 = $this->employ($hr, $p, $w['a1'], '2025-03-01');
        $this->setComponent($hr, $e1['public_id'], 'BASE_SALARY', '90000.00', '2025-03-01')->assertCreated();
        $this->hpost($hr, 'employments/' . $e1['public_id'] . '/end', ['ends_on' => '2026-02-28', 'end_reason' => 'Transferência para A2'])->assertOk();
        $e2 = $this->employ($hr, $p, $w['a2'], '2026-03-01');
        $this->hpost($hr, 'employments/' . $e1['public_id'] . '/end', ['ends_on' => '2026-03-31', 'end_reason' => 'Outra vez'])->assertStatus(409)->assertJsonPath('error.code', 'EMPLOYMENT_ENDED');
        $this->hpost($hr, 'employments', ['person' => $p['public_id'], 'unit' => $w['a2']['public_id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-04-01'])->assertStatus(409)->assertJsonPath('error.code', 'EMPLOYMENT_ALREADY_OPEN');
        $history = $this->hget($hr, 'employments/' . $e2['public_id'])->json('data.history');
        $this->assertSame([[$e1['public_id'], $w['a1']['public_id'], '2025-03-01', '2026-02-28', 'ENDED', false], [$e2['public_id'], $w['a2']['public_id'], '2026-03-01', null, 'ACTIVE', true]],
            array_map(fn ($h) => [$h['public_id'], $h['unit']['public_id'], $h['starts_on'], $h['ends_on'], $h['status'], $h['current']], $history));
        // "Which employment did this Person have in period P?" — answered from the rows, not from the current state.
        $inPeriod = fn (string $from, string $to) => DB::table('employments')->where('person_id', $p['id'])->where('starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))->pluck('public_id')->all();
        $this->assertSame([$e1['public_id']], $inPeriod('2025-12-01', '2025-12-31'));
        $this->assertSame([$e2['public_id']], $inPeriod('2026-06-01', '2026-06-30'));
        $this->assertSame(['ENDED', '2026-02-28'], [DB::table('employments')->where('public_id', $e1['public_id'])->value('status'), DB::table('employment_compensations')->where('employment_id', $this->employmentId($e1['public_id']))->value('ends_on')],
            'the ended employment and its salary line stay as history');
        $this->assertSame(['INACTIVE', 'ACTIVE'], [DB::table('person_unit_contexts')->where('person_id', $p['id'])->where('unit_id', $w['a1']['id'])->where('context_kind', 'EMPLOYMENT')->value('status'),
            DB::table('person_unit_contexts')->where('person_id', $p['id'])->where('unit_id', $w['a2']['id'])->where('context_kind', 'EMPLOYMENT')->value('status')]);
        foreach (['DELETE', 'PATCH', 'PUT'] as $method) {
            $this->assertContains($this->api($hr, $method, 'hr/employments/' . $e1['public_id'], ['status' => 'ACTIVE'])->status(), [404, 405], 'no generic status change / hard delete');
        }
        try {
            DB::table('employments')->insert(['public_id' => (string) Str::ulid(), 'person_id' => $p['id'], 'employing_unit_id' => $w['a2']['id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-05-01', 'status' => 'ACTIVE',
                'created_by' => $hr['user'], 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
            $this->fail('two open employments of one Person in one unit');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) $e->errorInfo[1]);
        }
        $this->assertSame(0, DB::table('employments')->where('person_id', $p2['id'])->count());
    }

    // ---- H06-H10 compensation -----------------------------------------------------------------------------------------

    public function test_h06_h07_salary_base_history_is_append_only_and_effective_dated(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100000.00', '2026-01-01')->assertCreated();
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '120000.00', '2026-07-01')->assertCreated();
        $lines = DB::table('employment_compensations')->where('employment_id', $this->employmentId($e['public_id']))->orderBy('starts_on')->get();
        $this->assertSame([['100000.0000', '2026-01-01', '2026-06-30', true], ['120000.0000', '2026-07-01', null, false]],
            $lines->map(fn ($l) => [(string) $l->amount, (string) $l->starts_on, $l->ends_on, $l->closed_by !== null])->all(), 'H06: the old line is closed, never overwritten');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '110000.00', '2026-03-01')->assertStatus(409)->assertJsonPath('error.code', 'COMPENSATION_RETROACTIVE');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '130000.00', '2026-07-01')->assertStatus(409)->assertJsonPath('error.code', 'COMPENSATION_OVERLAP');
        $this->assertSame('100000.0000', (string) DB::table('employment_compensations')->where('id', $lines[0]->id)->value('amount'));
        $meta = DB::table('audit_logs')->where('action', 'hr.compensation_changed')->orderBy('id')->pluck('after_metadata')->implode(' ');
        $this->assertStringNotContainsString('100000', $meta);
        $this->assertDoesNotMatchRegularExpression('/"(amount|salary|value)"\s*:/', $meta, 'no salary value in audit metadata (only the changed-field indicator)');
        // H07: the value effective at a date.
        $at = fn (string $date) => collect($this->hget($hr, 'employments/' . $e['public_id'] . '/compensation?as_of=' . $date)->assertOk()->json('data.current'))->firstWhere('component.code', 'BASE_SALARY')['amount'] ?? null;
        $this->assertSame(['100000.00', '100000.00', '120000.00', null], [$at('2026-01-01'), $at('2026-06-30'), $at('2026-07-01'), $at('2025-12-31')]);
        $this->setComponent($hr, $e['public_id'], 'TRANSPORT_MEAL_ALLOWANCE', '5000.00', '2025-12-01')->assertStatus(422)->assertJsonPath('error.code', 'OUTSIDE_EMPLOYMENT');
        $this->assertCheckViolation(fn () => DB::table('employment_compensations')->where('id', $lines[0]->id)->update(['ends_on' => '2025-12-31']));
    }

    public function test_h08_components_are_versioned_and_rule_based_components_never_take_an_amount(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e['public_id'], 'TRANSPORT_MEAL_ALLOWANCE', '15000.00', '2026-01-01')->assertCreated();
        $this->hpost($hr, 'employments/' . $e['public_id'] . '/compensation/stop', ['component' => 'TRANSPORT_MEAL_ALLOWANCE', 'ends_on' => '2026-03-31', 'reason' => 'Suspenso'])->assertOk();
        $this->setComponent($hr, $e['public_id'], 'TRANSPORT_MEAL_ALLOWANCE', '18000.00', '2026-05-01')->assertCreated();
        $history = collect($this->hget($hr, 'employments/' . $e['public_id'] . '/compensation')->json('data.history'))->where('component.code', 'TRANSPORT_MEAL_ALLOWANCE')->values();
        $this->assertSame([['15000.00', '2026-01-01', '2026-03-31'], ['18000.00', '2026-05-01', null]], $history->map(fn ($h) => [$h['amount'], $h['starts_on'], $h['ends_on']])->all());
        $this->setComponent($hr, $e['public_id'], 'INSS_EMPLOYEE', '100.00', '2026-01-01')->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_NOT_ALLOWED');
        $this->setComponent($hr, $e['public_id'], 'INSS_EMPLOYEE', null, '2026-01-01')->assertCreated();
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', null, '2026-01-01')->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_REQUIRED');
        $this->setComponent($hr, $e['public_id'], 'NOT_A_COMPONENT', '1.00', '2026-01-01')->assertStatus(422);
        $components = collect($this->hget($hr, 'components')->assertOk()->json('data'));
        $this->assertSame(array_keys(PayrollCatalog::COMPONENTS), $components->pluck('code')->all());
        $this->assertSame(['EARNING', 'FIXED_AMOUNT', 'PER_SALARY', null], [$components[0]['nature'], $components[0]['calculation_method'], $components[0]['rubric'], $components[0]['liability_role']]);
        $this->assertSame(['EMPLOYER_CHARGE', 'RATE_RULE', 'PER_INSS', 'PAYROLL_EMPLOYER_CHARGES'], array_values(array_intersect_key($components->firstWhere('code', 'INSS_EMPLOYER'), array_flip(['nature', 'calculation_method', 'rubric', 'liability_role']))));
    }

    public function test_h09_h10_aoa_only_and_at_most_two_business_decimals(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100.00', '2026-01-01', ['currency' => 'USD'])->assertStatus(422)->assertJsonPath('error.code', 'CURRENCY_NOT_SUPPORTED');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100.005', '2026-01-01')->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_SCALE');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '-5.00', '2026-01-01')->assertStatus(422)->assertJsonPath('error.code', 'AMOUNT_INVALID');
        $this->hpost($hr, 'employments/' . $e['public_id'] . '/compensation', ['component' => 'BASE_SALARY', 'amount' => 100, 'starts_on' => '2026-01-01', 'reason' => 'número JSON'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100.5', '2026-01-01', ['currency' => 'AOA'])->assertCreated();
        $line = $this->hget($hr, 'employments/' . $e['public_id'] . '/compensation')->json('data.current.0');
        $this->assertSame(['100.50', 'AOA'], [$line['amount'], $line['currency']]);
        $currencyColumns = DB::select("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . implode("','", $this->payrollTables()) . "') AND (COLUMN_NAME LIKE '%currency%' OR DATA_TYPE IN ('float','double','real'))");
        $this->assertSame([], $currencyColumns, 'AOA by construction; no float');
        $this->assertCheckViolation(fn () => DB::table('employment_compensations')->where('employment_id', $this->employmentId($e['public_id']))->update(['amount' => '1.2345']));
    }

    // ---- H11-H15 legal rules ------------------------------------------------------------------------------------------

    public function test_h11_no_statutory_rate_is_ever_seeded(): void
    {
        $this->assertSame([], PayrollCatalog::install(DB::connection()), 'idempotent');
        $this->assertSame([0, 0, 0], [DB::table('payroll_rules')->count(), DB::table('payroll_rule_brackets')->count(), DB::table('payroll_rule_base_components')->count()]);
        foreach (PayrollCatalog::COMPONENTS as $code => $definition) {
            foreach ($definition as $value) {
                $this->assertFalse(is_int($value) || is_float($value) || (is_string($value) && preg_match('/\d/', $value) === 1 && !str_starts_with($value, '13')), $code . ' carries a number');
            }
        }
        $source = file_get_contents(base_path('app/Domain/Payroll/PayrollCatalog.php')) . file_get_contents(base_path('database/migrations/2026_10_02_200010_p010_install_payroll_catalog.php'));
        $this->assertStringNotContainsString("table('payroll_rules')", $source);
        $this->assertStringNotContainsString("table('payroll_rule_brackets')", $source);
        $this->assertSame(['production_enabled', 'pagination'], array_keys(config('payroll')), 'no statutory value in configuration');
        $columns = array_map(fn ($c) => $c->c, DB::select("SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compensation_component_types'"));
        $this->assertSame([], array_values(array_intersect($columns, ['amount', 'rate', 'default_amount', 'default_rate', 'percentage'])));
    }

    public function test_h12_an_approved_rule_requires_its_official_source_document(): void
    {
        $w = $this->world();
        $manager = $this->national($w, ['PAYROLL_RULES_MANAGE']);
        $approver = $this->national($w, ['PAYROLL_RULES_APPROVE']);
        $draft = $this->hpost($manager, 'payroll-rules', ['code' => 'SYN_INSS_EMP', 'component' => 'INSS_EMPLOYEE', 'method' => 'FLAT_RATE', 'rate' => '0.050000', 'starts_on' => '2026-01-01', 'base_components' => ['BASE_SALARY']])
            ->assertCreated()->json('data');
        $this->assertSame(['SYN_INSS_EMP', 1, 'DRAFT'], [$draft['code'], $draft['version'], $draft['status']]);
        $this->hpost($approver, 'payroll-rules/SYN_INSS_EMP/1/approve')->assertStatus(422)->assertJsonPath('error.code', 'RULE_DOCUMENT_REQUIRED');
        $this->hpost($approver, 'payroll-rules/SYN_INSS_EMP/1/approve', ['source_document' => $this->document($w['g'], 'INVOICE')['public_id']])->assertStatus(422)->assertJsonPath('error.code', 'RULE_DOCUMENT_TYPE');
        $this->assertConcealed($this->hpost($approver, 'payroll-rules/SYN_INSS_EMP/1/approve', ['source_document' => $this->document($w['a'], 'PAYROLL_RULE_SOURCE')['public_id']]));
        $this->hpost($manager, 'payroll-rules/SYN_INSS_EMP/1/approve', ['source_document' => $this->document($w['g'], 'PAYROLL_RULE_SOURCE')['public_id']])->assertStatus(403);
        $both = $this->national($w, ['PAYROLL_RULES_MANAGE', 'PAYROLL_RULES_APPROVE']);
        $this->hpost($both, 'payroll-rules', ['code' => 'SYN_SELF', 'component' => 'INSS_EMPLOYER', 'method' => 'FLAT_RATE', 'rate' => '0.010000', 'starts_on' => '2030-01-01', 'base_components' => ['BASE_SALARY']])->assertCreated();
        $this->hpost($both, 'payroll-rules/SYN_SELF/1/approve', ['source_document' => $this->document($w['g'], 'PAYROLL_RULE_SOURCE')['public_id']])->assertStatus(409)->assertJsonPath('error.code', 'SEGREGATION_REQUIRED');
        $doc = $this->document($w['g'], 'PAYROLL_RULE_SOURCE');
        $this->hpost($approver, 'payroll-rules/SYN_INSS_EMP/1/approve', ['source_document' => $doc['public_id']])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $rule = $this->hget($approver, 'payroll-rules/SYN_INSS_EMP/1')->assertOk()->json('data');
        $this->assertSame([$doc['public_id'], 'APPROVED', false], [$rule['source_document']['public_id'], $rule['status'], $rule['provenance']['drafted_by_me']]);
        $this->assertNotNull($rule['provenance']['approved_at']);
        $this->assertCheckViolation(fn () => DB::table('payroll_rules')->where('code', 'SYN_SELF')->update(['status' => 'APPROVED', 'approved_by' => $approver['user'], 'approved_at' => now('UTC')->format('Y-m-d H:i:s.u')]));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payroll.rule_approved')->where('after_metadata', 'like', '%SYN_INSS_EMP%')->count());
    }

    public function test_h13_the_approved_rule_version_effective_in_the_period_is_resolved(): void
    {
        $w = $this->world();
        $manager = $this->national($w, ['PAYROLL_RULES_MANAGE']);
        $approver = $this->national($w, ['PAYROLL_RULES_APPROVE']);
        $this->flatRule($manager, $approver, $w, 'SYN_INSS', 'INSS_EMPLOYEE', '0.020000', '2026-01-01', '2026-06-30');
        $this->flatRule($manager, $approver, $w, 'SYN_INSS', 'INSS_EMPLOYEE', '0.025000', '2026-07-01');
        $this->hpost($manager, 'payroll-rules', ['code' => 'SYN_INSS', 'component' => 'INSS_EMPLOYEE', 'method' => 'FLAT_RATE', 'rate' => '0.900000', 'starts_on' => '2026-08-01', 'base_components' => ['BASE_SALARY']])->assertCreated();
        $resolver = new PayrollRuleResolver(DB::connection());
        $inss = $this->componentId('INSS_EMPLOYEE');
        $this->assertSame([1, '0.020000'], array_values(array_intersect_key($resolver->forPeriod($inss, '2026-03-01', '2026-03-31'), array_flip(['version', 'rate']))));
        $this->assertSame([2, '0.025000'], array_values(array_intersect_key($resolver->forPeriod($inss, '2026-08-01', '2026-08-31'), array_flip(['version', 'rate']))), 'the DRAFT v3 is never used');
        $this->assertSame(['BASE_SALARY'], $resolver->forPeriod($inss, '2026-08-01', '2026-08-31')['base_components']);
        $this->hpost($approver, 'payroll-rules/SYN_INSS/2/retire', ['reason' => 'Substituída'])->assertOk();
        $this->assertResolverError('PAYROLL_RULE_MISSING', fn () => $resolver->forPeriod($inss, '2026-08-01', '2026-08-31'), 'a RETIRED version is never used');
        $this->assertSame(1, $resolver->forPeriod($inss, '2026-03-01', '2026-03-31')['version']);
        $coverage = collect($this->hget($approver, 'payroll-rules')->assertOk()->json('data.coverage_today.components'))->keyBy('component');
        $this->assertSame('PENDING_CONFIGURATION', $coverage['INCOME_TAX_WITHHOLDING']['status'], 'no rule => configuration pending, never a value');
    }

    public function test_h14_a_missing_rule_fails_closed_never_zero(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100000.00', '2026-01-01')->assertCreated();
        $this->setComponent($hr, $e['public_id'], 'INSS_EMPLOYEE', null, '2026-01-01')->assertCreated();
        $this->assertResolverError('PAYROLL_RULE_MISSING', fn () => (new PayrollRuleResolver(DB::connection()))->forPeriod($this->componentId('INSS_EMPLOYEE'), '2026-09-01', '2026-09-30'));
        $this->assertResolverError('PAYROLL_RULE_MISSING', fn () => PayrollInputHash::payload(DB::connection(), $w['a1']['id'], '2026-09'), 'no payload / no hash without the rule');
        $ready = $this->hget($hr, 'payroll/readiness?unit=' . $w['a1']['public_id'] . '&period=2026-09')->assertOk()->json('data');
        $this->assertSame(['NOT_READY', [['code' => 'MISSING_RULE', 'component' => 'INSS_EMPLOYEE', 'reason' => 'PAYROLL_RULE_MISSING']], null],
            [$ready['configuration']['status'], $ready['configuration']['issues'], $ready['input_hash_preview']]);
    }

    public function test_h15_two_effective_rules_for_one_component_fail_closed(): void
    {
        $w = $this->world();
        $manager = $this->national($w, ['PAYROLL_RULES_MANAGE']);
        $approver = $this->national($w, ['PAYROLL_RULES_APPROVE']);
        $this->flatRule($manager, $approver, $w, 'SYN_A', 'INSS_EMPLOYER', '0.080000', '2026-01-01');
        $doc = $this->document($w['g'], 'PAYROLL_RULE_SOURCE');
        $this->hpost($manager, 'payroll-rules', ['code' => 'SYN_B', 'component' => 'INSS_EMPLOYER', 'method' => 'FLAT_RATE', 'rate' => '0.090000', 'starts_on' => '2026-06-01', 'base_components' => ['BASE_SALARY'], 'source_document' => $doc['public_id']])->assertCreated();
        $this->hpost($approver, 'payroll-rules/SYN_B/1/approve')->assertStatus(409)->assertJsonPath('error.code', 'RULE_OVERLAP')->assertJsonPath('error.details.items', ['SYN_A@1']);
        // Even if two APPROVED versions were forced to coexist (bypassing the service), resolution refuses to choose.
        DB::table('payroll_rules')->where('code', 'SYN_B')->update(['status' => 'APPROVED', 'approved_by' => $approver['user'], 'approved_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        $this->assertResolverError('PAYROLL_RULE_AMBIGUOUS', fn () => (new PayrollRuleResolver(DB::connection()))->forPeriod($this->componentId('INSS_EMPLOYER'), '2026-07-01', '2026-07-31'));
        $hr = $this->hr($w['a']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100000.00', '2026-01-01')->assertCreated();
        $this->setComponent($hr, $e['public_id'], 'INSS_EMPLOYER', null, '2026-01-01')->assertCreated();
        $this->assertSame([['code' => 'AMBIGUOUS_RULE', 'component' => 'INSS_EMPLOYER', 'reason' => 'PAYROLL_RULE_AMBIGUOUS']], $this->hget($hr, 'payroll/readiness?unit=' . $w['a1']['public_id'] . '&period=2026-07')->json('data.configuration.issues'));
        $this->assertSame('READY', $this->hget($hr, 'payroll/readiness?unit=' . $w['a1']['public_id'] . '&period=2026-03')->json('data.configuration.status'), 'March has exactly one applicable rule');
    }

    // ---- H16-H20 privacy, scope, ids, production, readiness -----------------------------------------------------------

    public function test_h16_every_salary_read_is_audited_and_hidden_without_permission(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '175000.00', '2026-01-01')->assertCreated();
        $before = $this->auditCount('hr.compensation_viewed');
        $this->hget($hr, 'employments/' . $e['public_id'] . '/compensation')->assertOk();
        $this->hget($hr, 'employments/' . $e['public_id'])->assertOk()->assertJsonPath('data.compensation_visible', true);
        $this->hget($hr, 'compensations?unit=' . $w['a1']['public_id'])->assertOk()->assertJsonPath('data.data.0.base_salary', '175000.00');
        $this->assertSame($before + 3, $this->auditCount('hr.compensation_viewed'));
        $this->assertStringNotContainsString('175000', (string) DB::table('audit_logs')->where('action', 'like', 'hr.%')->pluck('after_metadata')->implode(' '));
        $viewer = $this->hr($w['a'], ['HR_EMPLOYMENT_VIEW', 'PEOPLE_VIEW']);
        $detail = $this->hget($viewer, 'employments/' . $e['public_id'])->assertOk()->json('data');
        $this->assertFalse($detail['compensation_visible']);
        $this->assertArrayNotHasKey('compensation', $detail);
        $this->assertStringNotContainsString('175000', json_encode($detail));
        $this->assertConcealed($this->hget($viewer, 'employments/' . $e['public_id'] . '/compensation'));
        $this->assertConcealed($this->hget($viewer, 'employments/' . $this->ghost() . '/compensation'));
        $this->hget($viewer, 'compensations?unit=' . $w['a1']['public_id'])->assertStatus(403);
        $this->assertSame($before + 3, $this->auditCount('hr.compensation_viewed'), 'no salary read => no salary audit');
        // Generic People / Finance APIs never carry salary data.
        $people = $this->staff(['PEOPLE_VIEW', 'FINANCE_VIEW', 'FINANCE_REPORT'], $w['a']['id'], true);
        $person = DB::table('people')->where('id', DB::table('employments')->where('public_id', $e['public_id'])->value('person_id'))->value('public_id');
        $body = $this->api($people, 'GET', 'people/' . $person)->getContent() . $this->api($people, 'GET', 'people?per_page=50')->getContent()
            . $this->api($people, 'GET', 'finance/reports/OWN_DRE?unit=' . $w['a1']['public_id'] . '&period_kind=YEAR&period=2026')->getContent();
        $this->assertStringNotContainsString('175000', $body);
        $this->assertDoesNotMatchRegularExpression('/BASE_SALARY|compensation|salary/i', $body);
    }

    public function test_h17_wrong_hr_scope_and_unknown_targets_are_one_concealed_answer(): void
    {
        $w = $this->world();
        $hrA = $this->hr($w['a']);
        $e = $this->employ($hrA, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $hrB = $this->hr($w['b']);
        $bodies = [];
        foreach ([$e['public_id'], $this->ghost(), 'not-a-ulid'] as $target) {
            $bodies[] = $this->hget($hrB, 'employments/' . $target)->assertStatus(404)->getContent();
            $bodies[] = $this->hget($hrB, 'employments/' . $target . '/compensation')->assertStatus(404)->getContent();
            $bodies[] = $this->hpost($hrB, 'employments/' . $target . '/end', ['ends_on' => '2026-05-01', 'end_reason' => 'Intruso'])->assertStatus(404)->getContent();
            $bodies[] = $this->setComponent($hrB, $target, 'BASE_SALARY', '1.00', '2026-02-01')->assertStatus(404)->getContent();
        }
        foreach ([$w['a1']['public_id'], $this->ghost()] as $unit) {
            $bodies[] = $this->hget($hrB, 'payroll/readiness?unit=' . $unit . '&period=2026-09')->assertStatus(404)->getContent();
            $bodies[] = $this->hget($hrB, 'compensations?unit=' . $unit)->assertStatus(404)->getContent();
            $bodies[] = $this->hget($hrB, 'employments?unit=' . $unit)->assertStatus(404)->getContent();
        }
        $this->assertCount(1, array_unique($bodies), 'one byte-identical concealed body');
        $nobody = $this->staff(['PEOPLE_VIEW'], $w['a']['id'], true);
        $this->assertSame([403, 403], [$this->hget($nobody, 'employments/' . $e['public_id'])->status(), $this->hget($nobody, 'employments/' . $this->ghost())->status()], 'no permission: 403 whatever the target');
        $this->assertSame($this->hget($nobody, 'employments/' . $e['public_id'])->getContent(), $this->hget($nobody, 'employments/' . $this->ghost())->getContent());
        $this->hget($this->hr($w['b'], ['HR_EMPLOYMENT_VIEW']), 'payroll-rules/NOT_A_RULE/1')->assertStatus(403);
        $this->assertConcealed($this->hget($this->national($w, ['PAYROLL_RULES_MANAGE']), 'payroll-rules/NOT_A_RULE/1'));
        $this->assertConcealed($this->hget($this->national($w, ['PAYROLL_RULES_MANAGE']), 'payroll-rules/SYN/0'));
    }

    public function test_h18_internal_primary_keys_are_never_accepted_or_returned(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $p = $this->person($w['a1']);
        $this->hpost($hr, 'employments', ['person_id' => $p['id'], 'unit_id' => $w['a1']['id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-01-01'])->assertStatus(422);
        $this->hpost($hr, 'employments', ['person' => (string) $p['id'], 'unit' => (string) $w['a1']['id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-01-01'])->assertStatus(404);
        $e = $this->employ($hr, $p, $w['a1'], '2026-01-01');
        $this->assertConcealed($this->hget($hr, 'employments/' . $this->employmentId($e['public_id'])));
        $this->hpost($hr, 'employments/' . $e['public_id'] . '/compensation', ['component' => 'BASE_SALARY', 'component_type_id' => 1, 'amount' => '1.00', 'starts_on' => '2026-01-01', 'reason' => 'x'])->assertStatus(422);
        $this->hpost($hr, 'employments/' . $e['public_id'] . '/compensation', ['component' => 'BASE_SALARY', 'amount' => '1.00', 'starts_on' => '2026-01-01', 'reason' => 'x', 'source_document_id' => 1])->assertStatus(422);
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '1.00', '2026-01-01', ['source_document' => '1'])->assertStatus(404);
        foreach (['context', 'employments', 'employments/' . $e['public_id'], 'employments/' . $e['public_id'] . '/compensation', 'components', 'payroll/status', 'payroll/readiness?unit=' . $w['a1']['public_id'] . '&period=2026-01'] as $uri) {
            $this->assertNoInternalIds($this->hget($hr, $uri)->assertOk()->json());
        }
    }

    public function test_h19_payroll_production_is_disabled_by_default_and_fails_closed(): void
    {
        $this->assertFalse(config('payroll.production_enabled'));
        $this->assertFalse(PayrollProduction::fromConfig()->enabled());
        foreach (['approve', 'post', 'pay'] as $operation) {
            $this->assertResolverError('PAYROLL_PRODUCTION_DISABLED', fn () => PayrollProduction::fromConfig()->assertEnabled($operation));
        }
        $this->assertFalse((new PayrollProduction([]))->enabled(), 'absent => disabled');
        $this->assertFalse((new PayrollProduction(['production_enabled' => 'true']))->enabled(), 'only a boolean true enables (the config file casts)');
        $this->assertFalse((new PayrollProduction(['production_enabled' => 1]))->enabled());
        $this->assertTrue((new PayrollProduction(['production_enabled' => true]))->enabled(), 'the gate is a real switch, not a constant');
        $w = $this->world();
        $status = $this->hget($this->hr($w['a']), 'payroll/status')->assertOk()->json('data.production');
        // Phase-aware (P0.10-F2B added the run pipeline): with production disabled every ledger-relevant transition stays
        // refused; only the validation of the configuration (calculate) is available.
        $this->assertSame([false, 'DISABLED', 'PAYROLL_PRODUCTION_DISABLED'], [$status['enabled'], $status['status'], $status['code']]);
        $this->assertSame(['approve' => false, 'post' => false, 'pay' => false], array_intersect_key($status['operations'], ['approve' => 1, 'post' => 1, 'pay' => 1]));
        $runRoutes = array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with($r->uri(), 'api/v1/hr') && preg_match('#/runs?\b|/calculate|/approve$|/post$|/pay$#', $r->uri()));
        $this->assertSame([], array_values(array_filter($runRoutes, fn ($r) => array_intersect($r->methods(), ['PATCH', 'PUT', 'DELETE']) !== [])), 'no generic status write / delete route for runs');
        if ($runRoutes === []) {
            $this->assertSame('F2A_FOUNDATION', $status['engine'], 'F2A exposes no run / calculate / approve / post / pay route');
        }
    }

    public function test_h20_readiness_separates_configuration_from_production(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $manager = $this->national($w, ['PAYROLL_RULES_MANAGE']);
        $approver = $this->national($w, ['PAYROLL_RULES_APPROVE']);
        $e1 = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $e2 = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e1['public_id'], 'BASE_SALARY', '100000.00', '2026-01-01')->assertCreated();
        $this->setComponent($hr, $e1['public_id'], 'INSS_EMPLOYEE', null, '2026-01-01')->assertCreated();
        $url = 'payroll/readiness?unit=' . $w['a1']['public_id'] . '&period=2026-09';
        $not = $this->hget($hr, $url)->assertOk()->json('data');
        $this->assertSame('NOT_READY', $not['configuration']['status']);
        $this->assertEqualsCanonicalizing([['code' => 'MISSING_COMPENSATION', 'employment' => $e2['public_id']], ['code' => 'MISSING_RULE', 'component' => 'INSS_EMPLOYEE', 'reason' => 'PAYROLL_RULE_MISSING']], $not['configuration']['issues']);
        $this->setComponent($hr, $e2['public_id'], 'BASE_SALARY', '80000.00', '2026-01-01')->assertCreated();
        $this->flatRule($manager, $approver, $w, 'SYN_INSS_R', 'INSS_EMPLOYEE', '0.030000', '2026-01-01');
        $ready = $this->hget($hr, $url)->assertOk()->json('data');
        $this->assertSame(['READY', [], 2, [['component' => 'INSS_EMPLOYEE', 'rule' => ['code' => 'SYN_INSS_R', 'version' => 1]]]],
            [$ready['configuration']['status'], $ready['configuration']['issues'], $ready['configuration']['employments'], $ready['configuration']['rules']]);
        $this->assertSame(['DISABLED', false, [['code' => 'PRODUCTION_DISABLED']]], [$ready['production']['status'], $ready['production']['enabled'], $ready['production']['issues']], 'configuration READY, production DISABLED');
        $this->assertSame(64, strlen($ready['input_hash_preview']['value']));
        $this->assertSame([[ 'code' => 'NO_ACTIVE_EMPLOYMENT']], $this->hget($hr, 'payroll/readiness?unit=' . $w['a2']['public_id'] . '&period=2026-09')->json('data.configuration.issues'));
        $this->hget($this->hr($w['a'], ['HR_EMPLOYMENT_VIEW']), $url)->assertStatus(403);
    }

    // ---- H21-H24 hash, rounding, Files, as-of -------------------------------------------------------------------------

    public function test_h21_the_input_hash_is_canonical_and_covers_every_input_class(): void
    {
        $this->assertSame(PayrollInputHash::canonical(['b' => ['y' => '1', 'x' => null], 'a' => [3, 2]]), PayrollInputHash::canonical(['a' => [3, 2], 'b' => ['x' => null, 'y' => '1']]), 'key order is irrelevant');
        $this->assertSame('{"a":[3,2],"b":{"x":null,"y":"1"}}', PayrollInputHash::canonical(['b' => ['y' => '1', 'x' => null], 'a' => [3, 2]]));
        $this->assertResolverError('INVARIANT_VIOLATION', fn () => PayrollInputHash::canonical(['amount' => 1.5]), 'a float is refused');
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $manager = $this->national($w, ['PAYROLL_RULES_MANAGE']);
        $approver = $this->national($w, ['PAYROLL_RULES_APPROVE']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100000.00', '2026-01-01')->assertCreated();
        $this->setComponent($hr, $e['public_id'], 'INCOME_TAX_WITHHOLDING', null, '2026-01-01')->assertCreated();
        $this->bracketRule($manager, $approver, $w, 'SYN_IRT', [['lower_bound' => '0.00', 'upper_bound' => '70000.00', 'rate' => '0.000000'], ['lower_bound' => '70000.00', 'upper_bound' => null, 'rate' => '0.100000', 'fixed_amount' => '0.00', 'excess_over' => '70000.00']], '2026-01-01', '2026-10-31');
        $db = DB::connection();
        $unit = $w['a1']['id'];
        $h = fn (string $period = '2026-09', string $kind = 'REGULAR', int $seq = 1) => PayrollInputHash::hash(PayrollInputHash::payload($db, $unit, $period, $kind, $seq));
        $base = $h();
        $this->assertSame($base, $h(), 'deterministic');
        $readiness = $this->hget($hr, 'payroll/readiness?unit=' . $w['a1']['public_id'] . '&period=2026-09')->json('data.input_hash_preview.value');
        $this->assertSame($base, $readiness, 'the readiness preview is the contract hash');
        $payload = PayrollInputHash::payload($db, $unit, '2026-09');
        $this->assertSame(['compensations', 'employments', 'period', 'rounding', 'rules', 'run', 'unit', 'version'], array_keys(json_decode(PayrollInputHash::canonical($payload), true)));
        $this->assertSame(['mode' => 'HALF_UP', 'scale' => 2, 'unit' => 'COMPONENT_LINE', 'currency' => 'AOA'], $payload['rounding']);
        $this->assertCount(2, $payload['rules'][0]['brackets']);
        $changes = [
            'period' => fn () => $h('2026-08'),
            'run_kind' => fn () => $h('2026-09', 'ADJUSTMENT'),
            'sequence' => fn () => $h('2026-09', 'REGULAR', 2),
            'rounding' => fn () => PayrollInputHash::hash(['rounding' => ['mode' => 'HALF_EVEN'] + $payload['rounding']] + $payload),
        ];
        foreach ($changes as $label => $fn) {
            $this->assertNotSame($base, $fn(), 'input class ' . $label);
        }
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100500.00', '2026-09-15')->assertCreated();
        $afterSalary = $h();
        $this->assertNotSame($base, $afterSalary, 'salary base');
        $this->setComponent($hr, $e['public_id'], 'TRANSPORT_MEAL_ALLOWANCE', '5000.00', '2026-09-01')->assertCreated();
        $afterComponent = $h();
        $this->assertNotSame($afterSalary, $afterComponent, 'component / subsidy');
        $this->setComponent($hr, $e['public_id'], 'OTHER_DEDUCTION', '1000.00', '2026-09-01')->assertCreated();
        $afterDeduction = $h();
        $this->assertNotSame($afterComponent, $afterDeduction, 'deduction');
        DB::table('payroll_rule_brackets')->where('rule_id', DB::table('payroll_rules')->where('code', 'SYN_IRT')->value('id'))->where('lower_bound', '70000.0000')->update(['rate' => '0.110000']);
        $afterBracket = $h();
        $this->assertNotSame($afterDeduction, $afterBracket, 'rule bracket / parameter');
        $this->bracketRule($manager, $approver, $w, 'SYN_IRT_V', [['lower_bound' => '0.00', 'upper_bound' => null, 'rate' => '0.200000']], '2026-11-01');
        $this->assertSame($afterBracket, $h(), 'a rule of another period is not an input');
        $this->hpost($hr, 'employments/' . $e['public_id'] . '/end', ['ends_on' => '2026-09-30', 'end_reason' => 'Fim'])->assertOk();
        $this->assertNotSame($afterBracket, $h(), 'employment');
    }

    public function test_h22_rounding_is_half_up_per_component_on_decimals(): void
    {
        $cases = [['0.005', '0.01'], ['0.004', '0.00'], ['2.675', '2.68'], ['2.665', '2.67'], ['1.005', '1.01'], ['-1.005', '-1.01'], ['10', '10.00'], ['999999999999.995', '1000000000000.00']];
        foreach ($cases as [$in, $out]) {
            $this->assertSame($out, PayrollMoney::roundHalfUp($in), $in);
        }
        $this->assertSame(1.0, floor(1.005 * 100 + 0.5) / 100, 'binary floating point gets 1.005 wrong (the contract reason)');
        $this->assertSame('1.01', PayrollMoney::roundHalfUp('1.005'));
        $this->assertSame(['30.00', '5.00', '4.99', '0.00'], [PayrollMoney::applyRate('1000.00', '0.030000'), PayrollMoney::applyRate('333.33', '0.015000'), PayrollMoney::applyRate('332.99', '0.015000'), PayrollMoney::applyRate('0.10', '0.010000')]);
        $brackets = [['lower_bound' => '0.00', 'upper_bound' => '70000.00', 'rate' => '0.000000', 'fixed_amount' => '0.00', 'excess_over' => '0.00'],
            ['lower_bound' => '70000.00', 'upper_bound' => null, 'rate' => '0.100000', 'fixed_amount' => '500.00', 'excess_over' => '70000.00']];
        $this->assertSame(['0.00', '500.00', '3500.01'], [PayrollMoney::applyBrackets('69999.99', $brackets), PayrollMoney::applyBrackets('70000.00', $brackets), PayrollMoney::applyBrackets('100000.05', $brackets)]);
        $this->assertSame(['100.00', '0.10', '100.12'], [PayrollMoney::parse('100'), PayrollMoney::parse('0.1'), PayrollMoney::parse('100.1200')]);
        $this->assertSame('999999999999999.99', PayrollMoney::parse('999999999999999.99'), 'exact decimal at the DECIMAL(19,4) business limit (a float would round it)');
        $this->assertSame('1000000.01', PayrollMoney::applyRate('1000000.01', '1.000000'));
        $this->assertResolverError('AMOUNT_SCALE', fn () => PayrollMoney::parse('1.001'));
        $this->assertResolverError('AMOUNT_INVALID', fn () => PayrollMoney::parse(1.5));
        $this->assertResolverError('RATE_INVALID', fn () => PayrollMoney::rate('1.5'));
        $this->assertResolverError('RATE_INVALID', fn () => PayrollMoney::rate('0.1234567'));
    }

    public function test_h23_documents_require_files_authority_cumulatively(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $p = $this->person($w['a1']);
        $contract = $this->document($w['a1'], 'CONTRACT');
        $noFiles = $this->hr($w['a'], array_values(array_diff(self::HR_ALL, ['DOCUMENTS_VIEW'])));
        $this->assertConcealed($this->hpost($noFiles, 'employments', ['person' => $p['public_id'], 'unit' => $w['a1']['public_id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-01-01', 'contract_document' => $contract['public_id']]));
        $secret = $this->document($w['a1'], 'CONTRACT', 'HIGHLY_SENSITIVE');
        $this->assertConcealed($this->hpost($hr, 'employments', ['person' => $p['public_id'], 'unit' => $w['a1']['public_id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-01-01', 'contract_document' => $secret['public_id']]));
        $this->hpost($hr, 'employments', ['person' => $p['public_id'], 'unit' => $w['a1']['public_id'], 'relationship_kind' => 'EMPLOYEE', 'starts_on' => '2026-01-01', 'contract_document' => $this->document($w['a1'], 'INVOICE')['public_id']])
            ->assertStatus(422)->assertJsonPath('error.code', 'CONTRACT_DOCUMENT_TYPE');
        $e = $this->employ($hr, $p, $w['a1'], '2026-01-01', ['contract_document' => $contract['public_id']]);
        $this->assertSame($contract['public_id'], $this->hget($hr, 'employments/' . $e['public_id'])->json('data.contract_document.public_id'));
        $viewer = $this->hr($w['a'], ['HR_EMPLOYMENT_VIEW']);
        $this->assertNull($this->hget($viewer, 'employments/' . $e['public_id'])->json('data.contract_document.public_id'), 'no Files authority: the document stays hidden');
        // Rule source: the APPROVER's Files authority is re-checked (the drafter's is not enough).
        $manager = $this->national($w, ['PAYROLL_RULES_MANAGE']);
        $approverNoFiles = $this->staff(['PAYROLL_RULES_APPROVE'], $w['g']['id'], true);
        $this->hpost($manager, 'payroll-rules', ['code' => 'SYN_DOC', 'component' => 'INSS_EMPLOYEE', 'method' => 'FLAT_RATE', 'rate' => '0.010000', 'starts_on' => '2031-01-01', 'base_components' => ['BASE_SALARY'],
            'source_document' => $this->document($w['g'], 'PAYROLL_RULE_SOURCE')['public_id']])->assertCreated();
        $this->assertConcealed($this->hpost($approverNoFiles, 'payroll-rules/SYN_DOC/1/approve'));
        $this->assertSame('DRAFT', DB::table('payroll_rules')->where('code', 'SYN_DOC')->value('status'));
    }

    public function test_h24_historical_compensation_is_answered_as_of_the_period(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100000.00', '2026-01-01')->assertCreated();
        $this->setComponent($hr, $e['public_id'], 'HEALTH_PLAN', '7000.00', '2026-02-01')->assertCreated();
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '115000.00', '2026-06-01')->assertCreated();
        $db = DB::connection();
        $march = PayrollInputHash::payload($db, $w['a1']['id'], '2026-03');
        $july = PayrollInputHash::payload($db, $w['a1']['id'], '2026-07');
        $this->assertSame([['BASE_SALARY', '100000.00'], ['HEALTH_PLAN', '7000.00']], array_map(fn ($c) => [$c['component'], $c['amount']], $march['compensations']), 'March uses the values valid in March');
        $this->assertSame([['BASE_SALARY', '115000.00'], ['HEALTH_PLAN', '7000.00']], array_map(fn ($c) => [$c['component'], $c['amount']], $july['compensations']));
        $january = PayrollInputHash::payload($db, $w['a1']['id'], '2026-01');
        $this->assertSame([['BASE_SALARY', '100000.00']], array_map(fn ($c) => [$c['component'], $c['amount']], $january['compensations']));
        $this->hpost($hr, 'employments/' . $e['public_id'] . '/end', ['ends_on' => '2026-08-31', 'end_reason' => 'Fim'])->assertOk();
        $this->assertSame('115000.00', collect($this->hget($hr, 'employments/' . $e['public_id'] . '/compensation?as_of=2026-07-15')->json('data.current'))->firstWhere('component.code', 'BASE_SALARY')['amount']);
        $this->assertSame([], $this->hget($hr, 'employments/' . $e['public_id'] . '/compensation?as_of=2026-09-15')->json('data.current'), 'nothing is effective after the end');
        $this->assertSame([], PayrollInputHash::payload($db, $w['a1']['id'], '2026-09')['employments']);
    }

    // ---- HC1 / HC2 real concurrency -------------------------------------------------------------------------------------

    public function test_hc1_concurrent_compensation_changes_for_the_same_vigencia_leave_one_line(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $this->setComponent($hr, $e['public_id'], 'BASE_SALARY', '100000.00', '2026-01-01')->assertCreated();
        $body = fn (string $amount) => ['component' => 'BASE_SALARY', 'amount' => $amount, 'starts_on' => '2026-07-01', 'reason' => 'Revisão concorrente'];
        $a = $this->spawn(['op' => 'compensation_change', 'user' => $hr['user'], 'session' => $hr['session'], 'employment' => $e['public_id'], 'body' => $body('120000.00'), 'hold_until_commit' => true]);
        $b = $this->spawn(['op' => 'compensation_change', 'user' => $hr['user'], 'session' => $hr['session'], 'employment' => $e['public_id'], 'body' => $body('130000.00')]);
        $this->send($a, 'go');
        $this->assertSame('HELD', $this->line($a), 'A holds the employment lock, line written, not committed');
        $this->send($b, 'go');
        $this->assertTrue($this->waitsForLock($b['connection']), 'B is blocked on the employment row lock (observed in performance_schema)');
        $this->send($a, 'commit');
        $ra = $this->finish($a);
        $rb = $this->finish($b);
        $this->assertSame(['OK', 'COMPENSATION_OVERLAP'], [$ra['status'], $rb['status']]);
        $lines = DB::table('employment_compensations')->where('employment_id', $this->employmentId($e['public_id']))->where('component_type_id', $this->componentId('BASE_SALARY'))->orderBy('starts_on')->get();
        $this->assertSame([['100000.0000', '2026-06-30'], ['120000.0000', null]], $lines->map(fn ($l) => [(string) $l->amount, $l->ends_on])->all(), 'no ambiguous history');
        $this->evidence('HC1', ['a' => $ra['status'], 'b' => $rb['status'], 'lines' => $lines->count(), 'open_lines' => $lines->whereNull('ends_on')->count()]);
    }

    public function test_hc2_concurrent_approvals_of_overlapping_rule_versions_leave_one_effective_rule(): void
    {
        $w = $this->world();
        $manager = $this->national($w, ['PAYROLL_RULES_MANAGE']);
        $approver1 = $this->national($w, ['PAYROLL_RULES_APPROVE']);
        $approver2 = $this->national($w, ['PAYROLL_RULES_APPROVE']);
        foreach (['2027-01-01', '2027-06-01'] as $i => $from) {
            $this->hpost($manager, 'payroll-rules', ['code' => 'SYN_HC2', 'component' => 'INSS_EMPLOYEE', 'method' => 'FLAT_RATE', 'rate' => '0.0' . ($i + 3) . '0000', 'starts_on' => $from, 'base_components' => ['BASE_SALARY'],
                'source_document' => $this->document($w['g'], 'PAYROLL_RULE_SOURCE')['public_id']])->assertCreated();
        }
        $a = $this->spawn(['op' => 'rule_approve', 'user' => $approver1['user'], 'session' => $approver1['session'], 'code' => 'SYN_HC2', 'version' => 1, 'hold_until_commit' => true]);
        $b = $this->spawn(['op' => 'rule_approve', 'user' => $approver2['user'], 'session' => $approver2['session'], 'code' => 'SYN_HC2', 'version' => 2]);
        $this->send($a, 'go');
        $this->assertSame('HELD', $this->line($a));
        $this->send($b, 'go');
        $this->assertTrue($this->waitsForLock($b['connection']), 'B is blocked on the component lock');
        $this->send($a, 'commit');
        $ra = $this->finish($a);
        $rb = $this->finish($b);
        $this->assertSame(['OK', 'RULE_OVERLAP'], [$ra['status'], $rb['status']]);
        $this->assertSame(['1:APPROVED', '2:DRAFT'], DB::table('payroll_rules')->where('code', 'SYN_HC2')->orderBy('version')->get()->map(fn ($r) => $r->version . ':' . $r->status)->all());
        $this->assertSame(1, (new PayrollRuleResolver(DB::connection()))->forPeriod($this->componentId('INSS_EMPLOYEE'), '2027-07-01', '2027-07-31')['version']);
        $this->evidence('HC2', ['a' => $ra['status'], 'b' => $rb['status'], 'approved' => 1]);
    }

    // ---- S01-S09 schema --------------------------------------------------------------------------------------------------

    public function test_s01_s02_fresh_migrations_materialize_35_of_35_p010_tables(): void
    {
        $manifest = json_decode(file_get_contents(dirname(base_path(), 2) . '/docs/database/physical/p010_finance_delta_manifest.json'), true);
        $f2a = array_values(array_filter($manifest['migrations'], fn ($m) => str_starts_with($m, '2026_10_02_2000')));
        $this->assertCount(10, $f2a);
        $ran = DB::table('migrations')->whereIn('migration', array_map(fn ($m) => substr($m, 0, -4), $f2a))->count();
        $this->assertSame(10, $ran, 'S01: the 10 F2A migrations ran on the fresh pool');
        $all = array_merge($manifest['materialized_tables_f1a'], $manifest['materialized_tables_f2a']);
        $live = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::getDatabaseName())->whereIn('TABLE_NAME', $all)->count();
        $this->assertSame([26, 9, 35, 35], [count($manifest['materialized_tables_f1a']), count($manifest['materialized_tables_f2a']), count($all), $live], 'S02: Finance 26 + Payroll 9 = 35');
        $this->assertSame($this->payrollTables(), $manifest['plan']['payroll_tables_reserved_f2']);
    }

    public function test_s03_the_payroll_catalog_install_is_idempotent_and_refuses_conflicts(): void
    {
        $this->assertSame([], PayrollCatalog::install(DB::connection()));
        $this->assertSame([9, 13], [DB::table('permissions')->where('data_type', 'HR')->count(), DB::table('compensation_component_types')->count()]);
        $this->assertSame(0, DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('p.data_type', 'HR')->whereNotIn('rp.role_id', DB::table('user_role_scopes')->pluck('role_id'))->count(), 'no role is installed');
        DB::table('compensation_component_types')->where('code', 'HEALTH_PLAN')->update(['calculation_method' => 'MANUAL']);
        try {
            PayrollCatalog::install(DB::connection());
            $this->fail('a conflicting component must abort the installer');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('P010_PAYROLL_CATALOG_CONFLICT: compensation_component_types:HEALTH_PLAN', $e->getMessage());
        } finally {
            DB::table('compensation_component_types')->where('code', 'HEALTH_PLAN')->update(['calculation_method' => 'FIXED_AMOUNT']);
        }
        DB::table('permissions')->where('code', 'PAYROLL_POST')->update(['maximum_classification' => 'RESTRICTED']);
        try {
            PayrollCatalog::install(DB::connection());
            $this->fail('a conflicting permission must abort the installer');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('permissions:PAYROLL_POST', $e->getMessage());
        } finally {
            DB::table('permissions')->where('code', 'PAYROLL_POST')->update(['maximum_classification' => 'HIGHLY_SENSITIVE']);
        }
        $this->assertSame(['CONFIDENTIAL', 'CONFIDENTIAL', 'HIGHLY_SENSITIVE'], DB::table('permissions')->whereIn('code', ['HR_EMPLOYMENT_VIEW', 'HR_EMPLOYMENT_MANAGE', 'HR_COMPENSATION_VIEW'])->orderBy('code')->pluck('maximum_classification')->sort()->values()->all());
    }

    public function test_s04_s05_s07_identifiers_foreign_keys_and_no_cascade(): void
    {
        $tables = $this->payrollTables();
        $in = "('" . implode("','", $tables) . "')";
        $pks = DB::select("SELECT c.TABLE_NAME AS t, c.COLUMN_TYPE AS ty, c.EXTRA AS ex FROM information_schema.COLUMNS c JOIN information_schema.KEY_COLUMN_USAGE k ON k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME AND k.COLUMN_NAME = c.COLUMN_NAME AND k.CONSTRAINT_NAME = 'PRIMARY' WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME IN $in");
        $this->assertCount(9, $pks);
        foreach ($pks as $pk) {
            $this->assertSame(['bigint unsigned', 'auto_increment'], [$pk->ty, $pk->ex], 'S04 D-01 PK ' . $pk->t);
        }
        $fkTypes = DB::select("SELECT CONCAT(k.TABLE_NAME, '.', k.COLUMN_NAME) AS c, col.COLUMN_TYPE AS ty, k.REFERENCED_COLUMN_NAME AS rc FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.COLUMNS col ON col.TABLE_SCHEMA = k.TABLE_SCHEMA AND col.TABLE_NAME = k.TABLE_NAME AND col.COLUMN_NAME = k.COLUMN_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN $in AND k.REFERENCED_TABLE_NAME IS NOT NULL");
        $this->assertGreaterThan(20, count($fkTypes));
        foreach ($fkTypes as $fk) {
            $this->assertSame(['bigint unsigned', 'id'], [$fk->ty, $fk->rc], 'S04 FK ' . $fk->c);
        }
        $public = DB::select("SELECT TABLE_NAME AS t, COLUMN_TYPE AS ty, CHARACTER_SET_NAME AS cs FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN $in AND COLUMN_NAME = 'public_id' ORDER BY TABLE_NAME");
        $this->assertSame([['employments', 'char(26)', 'ascii'], ['payroll_runs', 'char(26)', 'ascii']], array_map(fn ($r) => [$r->t, $r->ty, $r->cs], $public), 'S05');
        foreach (['employments', 'payroll_runs'] as $t) {
            $this->assertSame(1, DB::table('information_schema.STATISTICS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $t)->where('INDEX_NAME', 'uq_' . $t . '_public_id')->where('NON_UNIQUE', 0)->count());
        }
        $rules = DB::select("SELECT CONSTRAINT_NAME AS n, UPDATE_RULE AS u, DELETE_RULE AS d FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN $in");
        $this->assertSame(count($fkTypes), count($rules));
        $this->assertSame([], array_values(array_filter($rules, fn ($r) => $r->u !== 'RESTRICT' || $r->d !== 'RESTRICT')), 'S07 no CASCADE / SET NULL');
    }

    public function test_s06_check_constraints_match_the_approved_manifest(): void
    {
        $manifest = json_decode(file_get_contents(dirname(base_path(), 2) . '/docs/database/physical/p010_finance_delta_manifest.json'), true);
        foreach ($this->payrollTables() as $t) {
            $live = DB::table('information_schema.TABLE_CONSTRAINTS')->where('TABLE_SCHEMA', DB::getDatabaseName())->where('TABLE_NAME', $t)->where('CONSTRAINT_TYPE', 'CHECK')->orderBy('CONSTRAINT_NAME')->pluck('CONSTRAINT_NAME')->all();
            $expected = $manifest['checks'][$t];
            sort($expected);
            $this->assertSame($expected, $live, 'S06 ' . $t);
        }
    }

    public function test_s08_s09_uniqueness_and_temporal_invariants_are_physical(): void
    {
        $w = $this->world();
        $hr = $this->hr($w['a']);
        $e = $this->employ($hr, $this->person($w['a1']), $w['a1'], '2026-01-01');
        $eid = $this->employmentId($e['public_id']);
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        $line = ['employment_id' => $eid, 'component_type_id' => $this->componentId('BASE_SALARY'), 'amount' => '1.0000', 'reason' => 'S09', 'created_by' => $hr['user'], 'created_at' => $now, 'lock_version' => 0];
        DB::table('employment_compensations')->insert($line + ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $this->assertSqlError(1062, fn () => DB::table('employment_compensations')->insert($line + ['starts_on' => '2026-02-01', 'ends_on' => null]), 'S09 two open lines for one component');
        $this->assertSqlError(1062, fn () => DB::table('employment_compensations')->insert($line + ['starts_on' => '2026-01-01', 'ends_on' => '2026-01-31']), 'S08 two lines starting the same day');
        $this->assertCheckViolation(fn () => DB::table('employment_compensations')->insert($line + ['starts_on' => '2026-05-01', 'ends_on' => '2026-04-30']));
        $this->assertCheckViolation(fn () => DB::table('employments')->where('id', $eid)->update(['status' => 'ENDED']), 'ENDED needs end date, actor and reason');
        $rule = ['code' => 'SYN_S08', 'version' => 1, 'component_type_id' => $this->componentId('INSS_EMPLOYEE'), 'method' => 'FLAT_RATE', 'rate' => '0.010000', 'starts_on' => '2032-01-01', 'status' => 'DRAFT', 'created_by' => $hr['user'], 'created_at' => $now, 'lock_version' => 0];
        DB::table('payroll_rules')->insert($rule);
        $this->assertSqlError(1062, fn () => DB::table('payroll_rules')->insert($rule), 'S08 (code, version)');
        $this->assertCheckViolation(fn () => DB::table('payroll_rules')->insert(['version' => 2, 'rate' => null] + $rule), 'FLAT_RATE needs a rate');
        $this->assertCheckViolation(fn () => DB::table('payroll_rules')->insert(['version' => 3, 'rate' => '1.500000'] + $rule));
        $ruleId = (int) DB::table('payroll_rules')->where('code', 'SYN_S08')->value('id');
        $this->assertCheckViolation(fn () => DB::table('payroll_rule_brackets')->insert(['rule_id' => $ruleId, 'lower_bound' => '10.0000', 'upper_bound' => '5.0000', 'rate' => '0.1', 'fixed_amount' => 0, 'excess_over' => 0, 'created_at' => $now]));
        $period = (int) DB::table('accounting_periods')->where('code', '2026-09')->value('id');
        $run = ['employing_unit_id' => $w['a1']['id'], 'period_id' => $period, 'run_kind' => 'REGULAR', 'status' => 'DRAFT', 'gross_amount' => 0, 'deductions_amount' => 0, 'employer_charges_amount' => 0, 'net_amount' => 0,
            'headcount' => 0, 'created_by' => $hr['user'], 'created_at' => $now, 'lock_version' => 0];
        DB::table('payroll_runs')->insert($run + ['public_id' => (string) Str::ulid(), 'sequence' => 1]);
        $this->assertSqlError(1062, fn () => DB::table('payroll_runs')->insert($run + ['public_id' => (string) Str::ulid(), 'sequence' => 2]), 'S08 one non-cancelled REGULAR run per unit and month');
        DB::table('payroll_runs')->where('employing_unit_id', $w['a1']['id'])->update(['status' => 'CANCELLED', 'cancelled_by' => $hr['user'], 'cancelled_at' => $now, 'cancel_reason' => 'S08']);
        DB::table('payroll_runs')->insert($run + ['public_id' => (string) Str::ulid(), 'sequence' => 2]);
        $this->assertCheckViolation(fn () => DB::table('payroll_runs')->insert(['public_id' => (string) Str::ulid(), 'sequence' => 3, 'run_kind' => 'ADJUSTMENT', 'gross_amount' => 100, 'net_amount' => 50] + $run), 'net = gross - deductions');
        $this->assertSame(0, DB::table('payroll_runs')->where('status', 'CALCULATED')->count(), 'F2A never calculates');
        DB::table('payroll_runs')->delete();
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function payrollTables(): array
    {
        return ['employments', 'compensation_component_types', 'employment_compensations', 'payroll_rules', 'payroll_rule_base_components', 'payroll_rule_brackets', 'payroll_runs', 'payroll_run_lines', 'payroll_postings'];
    }

    private function assertResolverError(string $reason, callable $fn, string $message = ''): void
    {
        try {
            $fn();
            $this->fail('expected ' . $reason . ' ' . $message);
        } catch (FinanceError $e) {
            $this->assertSame($reason, $e->reason, $message);
        }
    }

    private function assertCheckViolation(callable $fn, string $message = ''): void
    {
        $this->assertSqlError(3819, $fn, $message);
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
        $proc = proc_open([$php, $root . '/scripts/p010-f2a-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        $this->assertIsResource($proc);
        $ready = $this->line(['pipes' => $pipes]);
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

    private function line(array $worker): string
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
        $dir = getenv('P010_EVIDENCE_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'f2a-' . $name . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }
}
