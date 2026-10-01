<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

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
        ];
        $dir = dirname(__DIR__, 4) . '/.tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/p010-e2e-fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $this->assertFileExists($dir . '/p010-e2e-fixtures.json');
        $this->assertLedgerInvariants();
    }
}
