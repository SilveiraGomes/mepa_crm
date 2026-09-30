<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseV2\Support\FinanceHttpCase;

/**
 * Seeds the P0.10-F1B browser fixture THROUGH the Finance API (every contribution, transfer stage and reconciliation
 * is a real, audited posting) and writes the transient manifest .tmp/p010-e2e-fixtures.json (git-ignored; removed by
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
        $actor = $this->treasurer($w['m'], ['FINANCE_CONSOLIDATED_VIEW'], true);
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
        $login = 'finance.e2e.' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(32));
        DB::table('users')->where('id', $actor['user'])->update(['login' => $login, 'password_hash' => (new BcryptHasher(['rounds' => 4]))->make($password), 'status' => 'SYNTHETIC_READY', 'mfa_required' => 0]);
        $manifest = [
            'login' => $login, 'password' => $password, 'user_id' => $actor['user'],
            'a1' => ['public_id' => $w['a1']['public_id'], 'name' => 'Congregação A1 E2E', 'account' => $w['cash_a1']['public_id']],
            'a' => ['public_id' => $w['a']['public_id'], 'name' => 'Centro A E2E', 'account' => $w['cash_a']['public_id']],
            'm' => ['public_id' => $w['m']['public_id'], 'name' => 'Município E2E'],
            'sets' => $sets,
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
