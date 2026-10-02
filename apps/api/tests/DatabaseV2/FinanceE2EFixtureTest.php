<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Payroll\PayrollCatalog;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\FinanceHttpCase;

/**
 * Seeds the P0.10-F1B and F1C browser fixtures THROUGH the Finance API (every contribution, transfer stage, account
 * opening, statement import and reconciliation is a real, audited operation) and writes the transient manifest .tmp/p010-e2e-fixtures.json (git-ignored; removed by
 * the runner). One independent data set per Playwright viewport project (the journeys change state). Financial accounts
 * are fixtures (account management is not an F1B flow). Credentials are random per run.
 */
final class FinanceE2EFixtureTest extends FinanceHttpCase
{
    private const PROJECTS = ['desktop-1440x900', 'laptop-1366x768', 'tablet-768x1024', 'mobile-390x844'];

    public function test_seed_finance_browser_fixture_only(): void
    {
        $sets = [];
        $w = $this->world();
        // F1C: the same browser user also manages accounts, reverses, reconciles, prepares AND approves budgets (never one
        // it submitted itself) and closes months; a second user submits the budgets the browser approves (segregation).
        $actor = $this->treasurer($w['m'], ['FINANCE_CONSOLIDATED_VIEW', 'FINANCE_REVERSE', 'FINANCE_ACCOUNT_MANAGE', 'FINANCE_BUDGET_MANAGE', 'FINANCE_BUDGET_APPROVE',
            'FINANCE_PERIOD_CLOSE', 'DOCUMENTS_VIEW', 'PEOPLE_VIEW'], true);
        $submitter = $this->staff(['FINANCE_VIEW', 'FINANCE_BUDGET_MANAGE'], $w['m']['id'], true);
        DB::table('organizational_units')->where('id', $w['a1']['id'])->update(['name' => 'Congregação A1 E2E']);
        DB::table('organizational_units')->where('id', $w['a']['id'])->update(['name' => 'Centro A E2E']);
        DB::table('organizational_units')->where('id', $w['m']['id'])->update(['name' => 'Município E2E']);
        $this->contribute($actor, $w['cash_a1'], '400000.00');
        foreach (self::PROJECTS as $index => $project) {
            $tag = strtoupper(substr($project, 0, 3)) . ($index + 1);
            $received = $this->transfer($actor, $w['cash_a1'], $w['a'], (60000 + $index) . '.00', $actor, $w['cash_a'], 'TRF_REMITTANCE');
            $transit = $this->transfer($actor, $w['cash_a1'], $w['m'], (4000 + $index) . '.50', null, null, 'TRF_SUPPORT');
            $sets[$project] = ['tag' => $tag, 'received' => $received['public_id'], 'received_amount' => $received['amount'], 'transit' => $transit['public_id'], 'transit_amount' => $transit['amount'],
                'new_amount' => (1000 + $index) . '.25'];
        }
        // F1C data sets (one per viewport project): a budget submitted by another user, a BANK account opened through the
        // API with an opening balance, its imported statement and an OPEN reconciliation, supporting documents.
        $budgetUnits = ['desktop-1440x900' => 'a1', 'laptop-1366x768' => 'a2', 'tablet-768x1024' => 'b1', 'mobile-390x844' => 'b'];
        $f1c = [];
        foreach (self::PROJECTS as $index => $project) {
            $n = $index + 1;
            $budgetUnit = $w[$budgetUnits[$project]];
            $budget = $this->fpost($submitter, 'finance/budgets', ['unit' => $budgetUnit['public_id'], 'year' => '2026', 'lines' => [['category' => 'REV_OTHER', 'requested_amount' => (1000 * $n) . '.00'],
                ['category' => 'ADM_ELECTRICITY', 'requested_amount' => (400 * $n) . '.00']]], $this->key())->assertCreated()->json('data');
            $this->fpost($submitter, 'finance/budgets/' . $budget['public_id'] . '/submit')->assertOk();
            $bank = $this->fpost($actor, 'finance/accounts', ['unit' => $w['a1']['public_id'], 'kind' => 'BANK', 'code' => 'E2E-BK-' . $n, 'name' => 'Banco E2E ' . $n, 'opened_on' => '2026-09-01',
                'opening_balance' => (2000 + $n) . '.00', 'bank_name' => 'Banco Exemplo', 'account_number' => '0040' . str_pad((string) $n, 8, '0', STR_PAD_LEFT) . '9876'], $this->key())->assertCreated()->json('data');
            $statement = $this->fpost($actor, 'finance/bank-statements', ['account' => $bank['public_id'], 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'opening_balance' => '0.00',
                'closing_balance' => (2000 + $n) . '.00', 'document' => $this->document($w['a1'], 'BANK_STATEMENT')['public_id'], 'lines' => [['occurred_on' => '2026-09-01', 'amount' => (2000 + $n) . '.00',
                'description' => 'Depósito de abertura ' . $n, 'reference' => 'DEP-' . $n]]], $this->key())->assertCreated()->json('data');
            $reconciliation = $this->fpost($actor, 'finance/reconciliations', ['account' => $bank['public_id'], 'period' => '2026-09', 'statement' => $statement['public_id']], $this->key())->assertCreated()->json('data');
            $f1c[$project] = ['budget' => $budget['public_id'], 'budget_unit' => (string) DB::table('organizational_units')->where('id', $budgetUnit['id'])->value('name'),
                'bank' => $bank['public_id'], 'bank_name' => 'Banco E2E ' . $n, 'opening' => (2000 + $n) . '.00', 'statement' => $statement['public_id'], 'reconciliation' => $reconciliation['public_id'],
                'invoice' => $this->document($w['a1'], 'INVOICE')['public_id'], 'statement_document' => $this->document($w['a1'], 'BANK_STATEMENT')['public_id'],
                'receivable_amount' => (300 + $n) . '.00', 'payable_amount' => (1500 + $n) . '.00', 'new_bank_code' => 'UI-BK-' . $n, 'month' => sprintf('2026-%02d', 2 + $n)];
        }
        $f1d = $this->reportingWorld();
        $f2a = $this->payrollWorld();
        $login = 'finance.e2e.' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(32));
        DB::table('users')->where('id', $actor['user'])->update(['login' => $login, 'password_hash' => (new BcryptHasher(['rounds' => 4]))->make($password), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
        $manifest = [
            'login' => $login, 'password' => $password, 'user_id' => $actor['user'],
            'a1' => ['public_id' => $w['a1']['public_id'], 'name' => 'Congregação A1 E2E', 'account' => $w['cash_a1']['public_id']],
            'a' => ['public_id' => $w['a']['public_id'], 'name' => 'Centro A E2E', 'account' => $w['cash_a']['public_id']],
            'm' => ['public_id' => $w['m']['public_id'], 'name' => 'Município E2E'],
            'sets' => $sets,
            'f1c' => $f1c,
            'f1d' => $f1d,
            'f2a' => $f2a,
        ];
        $dir = dirname(__DIR__, 4) . '/.tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/p010-e2e-fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $this->assertFileExists($dir . '/p010-e2e-fixtures.json');
        $this->assertLedgerInvariants();
    }

    /**
     * P0.10-F1D-E1: a second, independent world (historical parentage in unit_parent_periods) with the accounting cross-check
     * data set of August 2026 plus April / July revenue and an in-transit transfer, seeded through the API. Two browser
     * readers: one with FINANCE_CONSOLIDATED_VIEW and one own-only. Hand-computed figures (spec): own A1 Aug revenue 100000,
     * Q3 102000, H1 1000, year 103000; consolidated M Aug revenue 138000, expenses 35000, result 103000, external in 138000,
     * external out 25000, transit 7000, closing 116000, payables 10000; own M Aug revenue 30000, internal received 40000.
     */
    private function reportingWorld(): array
    {
        $v = $this->world();
        $names = ['m' => 'Município F1D E2E', 'a' => 'Centro A F1D E2E', 'b' => 'Centro B F1D E2E', 'a1' => 'Congregação A1 F1D E2E', 'a2' => 'Congregação A2 F1D E2E', 'b1' => 'Congregação B1 F1D E2E'];
        foreach ($names as $key => $name) {
            DB::table('organizational_units')->where('id', $v[$key]['id'])->update(['name' => $name]);
        }
        foreach (['r' => 'g', 'p' => 'r', 'm' => 'p', 'a' => 'm', 'b' => 'm', 'a1' => 'a', 'a2' => 'a', 'b1' => 'b'] as $child => $parent) {
            DB::table('unit_parent_periods')->insert(['unit_id' => $v[$child]['id'], 'parent_unit_id' => $v[$parent]['id'], 'status' => 'ACTIVE', 'starts_at' => '2025-01-01 00:00:00.000000', 'ends_at' => null,
                'reason' => 'F1D-E1 E2E fixture', 'source_document_id' => null, 'created_at' => '2025-01-01 00:00:00.000000', 'lock_version' => 0]);
        }
        $op = $this->treasurer($v['m'], ['DOCUMENTS_VIEW', 'PEOPLE_VIEW'], true);
        $payable = function (array $unit, string $amount, string $category, string $on) use ($op): string {
            return $this->fpost($op, 'finance/payables', ['unit' => $unit['public_id'], 'party' => ['kind' => 'EXTERNAL', 'name' => 'Fornecedor E2E'], 'category' => $category, 'amount' => $amount,
                'due_on' => '2026-12-31', 'recognized_on' => $on, 'document' => $this->document($unit, 'INVOICE')['public_id']], $this->key())->assertCreated()->json('data.public_id');
        };
        $settle = fn (string $payableId, array $account, string $amount, string $on) => $this->fpost($op, 'finance/payables/' . $payableId . '/settlements', ['account' => $account['public_id'], 'amount' => $amount, 'settled_on' => $on], $this->key())->assertCreated();
        $move = function (array $fromAccount, array $destination, ?array $toAccount, string $amount, string $on) use ($op): string {
            $t = $this->requestTransfer($op, $fromAccount, $destination, $amount, 'TRF_REMITTANCE');
            $this->sendTransfer($op, $t['public_id'], $on);
            if ($toAccount !== null) {
                $this->receiveTransfer($op, $t['public_id'], $toAccount, $on);
            }
            return $t['public_id'];
        };
        $this->contribute($op, $v['cash_a1'], '1000.00', '2026-04-10');
        $this->contribute($op, $v['cash_a1'], '2000.00', '2026-07-15');
        $this->contribute($op, $v['cash_a1'], '100000.00', '2026-08-02');
        $settle($payable($v['a1'], '20000.00', 'ADM_ELECTRICITY', '2026-08-03'), $v['cash_a1'], '15000.00', '2026-08-04');
        $move($v['cash_a1'], $v['a'], $v['cash_a'], '60000.00', '2026-08-05');
        $settle($payable($v['a'], '10000.00', 'ADM_TAXI', '2026-08-06'), $v['cash_a'], '10000.00', '2026-08-07');
        $move($v['cash_a'], $v['m'], $v['cash_m'], '40000.00', '2026-08-08');
        $this->contribute($op, $v['cash_m'], '30000.00', '2026-08-09');
        $payable($v['m'], '5000.00', 'ADM_INTERNET', '2026-08-10');
        $this->contribute($op, $v['cash_a2'], '8000.00', '2026-08-11');
        $transit = $move($v['cash_a2'], $v['a'], null, '7000.00', '2026-08-12');
        $gift = $this->fpost($op, 'finance/contributions', ['kind' => 'IN_KIND', 'identification' => 'ANONYMOUS', 'unit' => $v['a1']['public_id'], 'category' => 'REV_IN_KIND', 'description' => 'Cadeiras doadas'], $this->key())
            ->assertCreated()->json('data.public_id');
        $submitter = $this->staff(['FINANCE_VIEW', 'FINANCE_BUDGET_MANAGE'], $v['a1']['id'], false);
        $approver = $this->staff(['FINANCE_VIEW', 'FINANCE_BUDGET_APPROVE'], $v['a']['id'], true);
        $budget = $this->fpost($submitter, 'finance/budgets', ['unit' => $v['a1']['public_id'], 'year' => '2026', 'lines' => [['category' => 'REV_TITHES', 'requested_amount' => '90000.00'], ['category' => 'ADM_ELECTRICITY', 'requested_amount' => '25000.00']]], $this->key())
            ->assertCreated()->json('data.public_id');
        $this->fpost($submitter, 'finance/budgets/' . $budget . '/submit')->assertOk();
        $this->fpost($approver, 'finance/budgets/' . $budget . '/review')->assertOk();
        $this->fpost($approver, 'finance/budgets/' . $budget . '/approve')->assertOk();
        $readers = [];
        foreach (['cons' => ['FINANCE_CONSOLIDATED_VIEW'], 'own' => []] as $key => $extra) {
            $reader = $this->staff(array_merge(['FINANCE_VIEW', 'FINANCE_REPORT', 'DOCUMENTS_VIEW', 'PEOPLE_VIEW'], $extra), $v['m']['id'], true);
            $login = 'finance.f1d.' . $key . '.' . bin2hex(random_bytes(5));
            $password = bin2hex(random_bytes(32));
            DB::table('users')->where('id', $reader['user'])->update(['login' => $login, 'password_hash' => (new BcryptHasher(['rounds' => 4]))->make($password), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
            $readers[$key] = ['login' => $login, 'password' => $password, 'user_id' => $reader['user']];
        }
        $units = [];
        foreach ($names as $key => $name) {
            $units[$key] = ['public_id' => $v[$key]['public_id'], 'name' => $name];
        }
        return ['readers' => $readers, 'units' => $units, 'gift' => $gift, 'transit' => $transit, 'budget' => $budget];
    }

    /**
     * P0.10-F2A: a third independent world for RH / Folha Salarial, seeded through the HR API. The statutory rule here is
     * SYNTHETIC test data (code SINTETICO_E2E_*, created by this fixture through the API; never by an installer and never an
     * official value). Readers: "hr" (employment + compensation, no rule approval) and "viewer" (HR_EMPLOYMENT_VIEW only:
     * must never see a salary). One employee per viewport project for the mobile action journey.
     */
    private function payrollWorld(): array
    {
        PayrollCatalog::install(DB::connection());
        $v = $this->world();
        $names = ['m' => 'Município F2A E2E', 'a' => 'Centro A F2A E2E', 'a1' => 'Congregação A1 F2A E2E'];
        foreach ($names as $key => $name) {
            DB::table('organizational_units')->where('id', $v[$key]['id'])->update(['name' => $name]);
        }
        $hrPermissions = ['HR_EMPLOYMENT_VIEW', 'HR_EMPLOYMENT_MANAGE', 'HR_COMPENSATION_VIEW', 'HR_COMPENSATION_MANAGE', 'PAYROLL_MANAGE', 'PEOPLE_VIEW', 'DOCUMENTS_VIEW'];
        $hr = $this->staff($hrPermissions, $v['m']['id'], true);
        $viewer = $this->staff(['HR_EMPLOYMENT_VIEW', 'PEOPLE_VIEW'], $v['m']['id'], true);
        $manager = $this->staff(['PAYROLL_RULES_MANAGE', 'DOCUMENTS_VIEW'], $v['g']['id'], true);
        $approver = $this->staff(['PAYROLL_RULES_APPROVE', 'DOCUMENTS_VIEW'], $v['g']['id'], true);
        $employ = fn (array $person, array $unit, string $on, string $job) => $this->fpost($hr, 'hr/employments', ['person' => $person['public_id'], 'unit' => $unit['public_id'], 'relationship_kind' => 'EMPLOYEE',
            'job_title' => $job, 'starts_on' => $on])->assertCreated()->json('data.public_id');
        $pay = fn (string $employment, string $component, ?string $amount, string $on) => $this->fpost($hr, 'hr/employments/' . $employment . '/compensation',
            ['component' => $component, 'amount' => $amount, 'starts_on' => $on, 'reason' => 'Configuração E2E'])->assertCreated();
        $ana = $this->person($v['a1'], 'Ana Funcionária F2A');
        $bruno = $this->person($v['a1'], 'Bruno Motorista F2A');
        DB::table('person_unit_contexts')->insert(['person_id' => $bruno['id'], 'unit_id' => $v['a']['id'], 'context_kind' => 'ONBOARDING', 'status' => 'ACTIVE', 'starts_at' => now('UTC')->subDays(400)->format('Y-m-d H:i:s.u'),
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
        $anaJob = $employ($ana, $v['a1'], '2026-01-01', 'Secretária administrativa');
        $pay($anaJob, 'BASE_SALARY', '150000.00', '2026-01-01');
        $pay($anaJob, 'BASE_SALARY', '165000.00', '2026-07-01');
        $pay($anaJob, 'TRANSPORT_MEAL_ALLOWANCE', '10000.00', '2026-01-01');
        $pay($anaJob, 'INSS_EMPLOYEE', null, '2026-01-01');
        $brunoOld = $employ($bruno, $v['a'], '2025-02-01', 'Motorista');
        $pay($brunoOld, 'BASE_SALARY', '80000.00', '2025-02-01');
        $this->fpost($hr, 'hr/employments/' . $brunoOld . '/end', ['ends_on' => '2025-12-31', 'end_reason' => 'Transferência para a Congregação A1'])->assertOk();
        $brunoJob = $employ($bruno, $v['a1'], '2026-01-01', 'Motorista');
        $pay($brunoJob, 'BASE_SALARY', '90000.00', '2026-01-01');
        $projects = [];
        foreach (self::PROJECTS as $index => $project) {
            $p = $this->person($v['a1'], 'Funcionário E2E ' . strtoupper(substr($project, 0, 3)) . ($index + 1));
            $job = $employ($p, $v['a1'], '2026-03-01', 'Auxiliar');
            $pay($job, 'BASE_SALARY', (70000 + $index) . '.00', '2026-03-01');
            $projects[$project] = ['employment' => $job, 'name' => 'Funcionário E2E ' . strtoupper(substr($project, 0, 3)) . ($index + 1), 'allowance' => (5000 + $index) . '.00'];
        }
        $rule = $this->fpost($manager, 'hr/payroll-rules', ['code' => 'SINTETICO_E2E_INSS', 'component' => 'INSS_EMPLOYEE', 'method' => 'FLAT_RATE', 'rate' => '0.010000', 'starts_on' => '2026-01-01',
            'base_components' => ['BASE_SALARY'], 'source_document' => $this->document($v['g'], 'PAYROLL_RULE_SOURCE')['public_id']])->assertCreated()->json('data');
        $this->fpost($approver, 'hr/payroll-rules/SINTETICO_E2E_INSS/' . $rule['version'] . '/approve')->assertOk();
        $this->fpost($manager, 'hr/payroll-rules', ['code' => 'SINTETICO_E2E_IRT', 'component' => 'INCOME_TAX_WITHHOLDING', 'method' => 'BRACKET', 'rate' => null, 'starts_on' => '2027-01-01',
            'base_components' => ['BASE_SALARY'], 'brackets' => [['lower_bound' => '0.00', 'upper_bound' => null, 'rate' => '0.000000']]])->assertCreated();
        $readers = [];
        foreach (['hr' => $hr, 'viewer' => $viewer] as $key => $reader) {
            $login = 'payroll.f2a.' . $key . '.' . bin2hex(random_bytes(5));
            $password = bin2hex(random_bytes(32));
            DB::table('users')->where('id', $reader['user'])->update(['login' => $login, 'password_hash' => (new BcryptHasher(['rounds' => 4]))->make($password), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
            $readers[$key] = ['login' => $login, 'password' => $password, 'user_id' => $reader['user']];
        }
        $units = [];
        foreach ($names as $key => $name) {
            $units[$key] = ['public_id' => $v[$key]['public_id'], 'name' => $name];
        }
        return ['readers' => $readers, 'units' => $units, 'ana' => ['employment' => $anaJob, 'name' => 'Ana Funcionária F2A'], 'bruno' => ['employment' => $brunoJob, 'old' => $brunoOld, 'name' => 'Bruno Motorista F2A'],
            'projects' => $projects];
    }
}
