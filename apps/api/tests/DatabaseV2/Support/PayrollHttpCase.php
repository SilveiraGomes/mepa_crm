<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

use App\Domain\Payroll\PayrollCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * P0.10-F2A RH / payroll HTTP test base (Test Infrastructure V2 Wave 5 pool). Reuses the Finance base (Territorial units,
 * staff grants on UNIT scopes, Files + Finance catalogs) and installs the Payroll catalog after the TRUNCATE reset exactly
 * as its migration does. Statutory rules are global: every test starts with no rule, so the rules a test drafts are the
 * only ones the resolver can see (synthetic rules created by the test, never by an installer — D-04A.15).
 */
abstract class PayrollHttpCase extends FinanceHttpCase
{
    protected const HR_ALL = ['HR_EMPLOYMENT_VIEW', 'HR_EMPLOYMENT_MANAGE', 'HR_COMPENSATION_VIEW', 'HR_COMPENSATION_MANAGE', 'PAYROLL_MANAGE', 'PEOPLE_VIEW', 'DOCUMENTS_VIEW'];

    protected function setUp(): void
    {
        parent::setUp();
        PayrollCatalog::install(DB::connection());
        DB::table('payroll_rule_brackets')->delete();
        DB::table('payroll_rule_base_components')->delete();
        DB::table('payroll_rules')->delete();
    }

    /** HR officer of $unit (descendants by default). */
    protected function hr(array $unit, array $permissions = self::HR_ALL, bool $descendants = true): array
    {
        return $this->staff($permissions, $unit['id'], $descendants);
    }

    /** National rule manager / approver: grants on the Direcção Geral root. */
    protected function national(array $w, array $permissions): array
    {
        return $this->staff(array_merge($permissions, ['DOCUMENTS_VIEW']), $w['g']['id'], true);
    }

    protected function hpost(array $actor, string $uri, array $body = []): TestResponse
    {
        $this->flushHeaders();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $actor['token'], 'Accept' => 'application/json'])->json('POST', '/api/v1/hr/' . ltrim($uri, '/'), $body);
    }

    protected function hget(array $actor, string $uri): TestResponse
    {
        $this->flushHeaders();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $actor['token'], 'Accept' => 'application/json'])->json('GET', '/api/v1/hr/' . ltrim($uri, '/'));
    }

    protected function employ(array $actor, array $person, array $unit, string $startsOn = '2026-01-01', array $extra = []): array
    {
        return $this->hpost($actor, 'employments', $extra + ['person' => $person['public_id'], 'unit' => $unit['public_id'], 'relationship_kind' => 'EMPLOYEE', 'job_title' => 'Secretária', 'starts_on' => $startsOn])
            ->assertCreated()->json('data');
    }

    protected function setComponent(array $actor, string $employment, string $component, ?string $amount, string $startsOn, array $extra = []): TestResponse
    {
        return $this->hpost($actor, 'employments/' . $employment . '/compensation', ['component' => $component, 'amount' => $amount, 'starts_on' => $startsOn, 'reason' => 'Configuração ' . $component] + $extra);
    }

    /** Draft (by $manager) + approve (by $approver) of a synthetic FLAT rule. Test data only: never an official value. */
    protected function flatRule(array $manager, array $approver, array $w, string $code, string $component, string $rate, string $from, ?string $to = null, array $base = ['BASE_SALARY']): array
    {
        $doc = $this->document($w['g'], 'PAYROLL_RULE_SOURCE');
        $draft = $this->hpost($manager, 'payroll-rules', ['code' => $code, 'component' => $component, 'method' => 'FLAT_RATE', 'rate' => $rate, 'starts_on' => $from, 'ends_on' => $to, 'base_components' => $base,
            'source_document' => $doc['public_id']])->assertCreated()->json('data');
        $this->hpost($approver, 'payroll-rules/' . $code . '/' . $draft['version'] . '/approve')->assertOk();
        return $draft;
    }

    protected function bracketRule(array $manager, array $approver, array $w, string $code, array $brackets, string $from, ?string $to = null): array
    {
        $doc = $this->document($w['g'], 'PAYROLL_RULE_SOURCE');
        $draft = $this->hpost($manager, 'payroll-rules', ['code' => $code, 'component' => 'INCOME_TAX_WITHHOLDING', 'method' => 'BRACKET', 'rate' => null, 'starts_on' => $from, 'ends_on' => $to,
            'base_components' => ['BASE_SALARY'], 'brackets' => $brackets, 'source_document' => $doc['public_id']])->assertCreated()->json('data');
        $this->hpost($approver, 'payroll-rules/' . $code . '/' . $draft['version'] . '/approve')->assertOk();
        return $draft;
    }

    protected function employmentId(string $publicId): int
    {
        return (int) DB::table('employments')->where('public_id', $publicId)->value('id');
    }

    protected function componentId(string $code): int
    {
        return (int) DB::table('compensation_component_types')->where('code', $code)->value('id');
    }

    protected function auditCount(string $action): int
    {
        return DB::table('audit_logs')->where('action', $action)->count();
    }

    protected function ghost(): string
    {
        return (string) Str::ulid();
    }
}
