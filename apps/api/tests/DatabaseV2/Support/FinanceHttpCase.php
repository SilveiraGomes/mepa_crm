<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

use App\Domain\Files\FilesCatalog;
use App\Domain\Finance\FinanceCatalog;
use App\Domain\Finance\FinancePeriods;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * P0.10-F1B Finance HTTP test base (Test Infrastructure V2 Wave 5 pool). Reuses the Territorial base (canonical unit
 * types, staff grants on UNIT scopes), installs the Files and Finance catalogs after the TRUNCATE reset exactly as their
 * migrations do, and the 2026 calendar periods through FinancePeriods::ensureYear. Units, grants, financial accounts and
 * legal documents are synthetic fixtures (account management is not an F1B flow).
 */
abstract class FinanceHttpCase extends TerritorialHttpCase
{
    /** F1C: per-process Files key ring (bank account numbers, ADR 0019 D06) in the OS temp directory, removed at exit. */
    protected static ?string $financeSandbox = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$financeSandbox === null) {
            self::$financeSandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mepa-finance-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir(self::$financeSandbox, 0700, true);
            file_put_contents(self::$financeSandbox . DIRECTORY_SEPARATOR . 'keyring.json', json_encode(['active_version' => 1, 'keys' => ['1' => ['kek' => base64_encode(random_bytes(32))]]], JSON_THROW_ON_ERROR));
            $dir = self::$financeSandbox;
            register_shutdown_function(static function () use ($dir): void {
                @unlink($dir . DIRECTORY_SEPARATOR . 'keyring.json');
                @rmdir($dir);
            });
        }
        config(['files.keyring_path' => getenv('P010_E2E_KEYRING_PATH') ?: self::$financeSandbox . DIRECTORY_SEPARATOR . 'keyring.json']);
        FilesCatalog::install(DB::connection());
        FinanceCatalog::install(DB::connection());
        (new FinancePeriods(DB::connection()))->ensureYear(2026);
        if (!DB::table('organizational_structure_lock')->where('code', 'NATIONAL_TREE')->exists()) {
            $this->row('organizational_structure_lock', ['code' => 'NATIONAL_TREE']);
        }
        if ((string) config('app.key') === '') {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
    }

    /** Operational Finance permissions of F1B (no catalog management, no payroll summary). */
    protected function financePermissions(): array
    {
        return ['FINANCE_VIEW', 'FINANCE_MANAGE', 'FINANCE_POST', 'FINANCE_TRANSFER', 'FINANCE_RECONCILE', 'FINANCE_REPORT'];
    }

    /** Treasurer of $unit (descendants by default) with the F1B permissions (+ extra). */
    protected function treasurer(array $unit, array $extra = [], bool $descendants = false): array
    {
        return $this->staff(array_merge($this->financePermissions(), $extra), $unit['id'], $descendants) + ['unit_public' => $unit['public_id']];
    }

    /** direction -> region -> province -> municipality M -> centers A, B -> congregations A1, A2 (under A), B1 (under B). */
    protected function world(): array
    {
        $g = $this->unit('GENERAL_DIRECTION', null, 'ACTIVE', 'Direcção Geral F1B');
        $r = $this->unit('REGIONAL_DIRECTION', $g['id'], 'ACTIVE', 'Região F1B');
        $p = $this->unit('PROVINCIAL_DIRECTION', $r['id'], 'ACTIVE', 'Província F1B');
        $m = $this->unit('MUNICIPAL_DIRECTION', $p['id'], 'ACTIVE', 'Município ' . Str::random(4));
        $a = $this->unit('CENTER', $m['id'], 'ACTIVE', 'Centro A ' . Str::random(4));
        $b = $this->unit('CENTER', $m['id'], 'ACTIVE', 'Centro B ' . Str::random(4));
        $a1 = $this->unit('CONGREGATION', $a['id'], 'ACTIVE', 'Congregação A1 ' . Str::random(4));
        $a2 = $this->unit('CONGREGATION', $a['id'], 'ACTIVE', 'Congregação A2 ' . Str::random(4));
        $b1 = $this->unit('CONGREGATION', $b['id'], 'ACTIVE', 'Congregação B1 ' . Str::random(4));
        $w = compact('g', 'r', 'p', 'm', 'a', 'b', 'a1', 'a2', 'b1');
        foreach (['m', 'a', 'b', 'a1', 'a2', 'b1'] as $key) {
            $w['cash_' . $key] = $this->account($w[$key]);
        }
        return $w;
    }

    /** Synthetic OPEN financial account (fixture: account management is out of F1B). */
    protected function account(array $unit, string $kind = 'CASH'): array
    {
        $public = (string) Str::ulid();
        $id = (int) DB::table('accounts')->insertGetId(['public_id' => $public, 'unit_id' => $unit['id'], 'ledger_account_id' => FinanceCatalog::roleIds(DB::connection())[$kind],
            'currency_id' => (int) DB::table('currencies')->where('code', 'AOA')->value('id'), 'code' => $kind . '-' . Str::random(8), 'name' => ($kind === 'CASH' ? 'Caixa ' : 'Banco ') . Str::random(4),
            'account_kind' => $kind, 'status' => 'OPEN', 'opened_on' => '2026-01-01', 'closed_on' => null, 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'lock_version' => 0]);
        return ['id' => $id, 'public_id' => $public, 'unit' => $unit['id']];
    }

    /** Existing adult Person with an ACTIVE People context at $unit (Finance never creates a Person). */
    protected function person(array $unit, ?string $name = null): array
    {
        $statusId = (int) DB::table('person_statuses')->where('code', 'ACTIVE')->value('id');
        $id = $this->row('people', ['full_name' => $name ?? 'Custodiante ' . Str::random(8), 'status_id' => $statusId, 'birth_date' => '1985-03-01', 'birth_precision' => 'EXACT', 'lock_version' => 0]);
        $at = now('UTC')->subHour()->format('Y-m-d H:i:s.u');
        DB::table('person_unit_contexts')->insert(['person_id' => $id, 'unit_id' => $unit['id'], 'context_kind' => 'ONBOARDING', 'status' => 'ACTIVE', 'starts_at' => $at, 'ends_at' => null, 'reason' => null,
            'source_document_id' => null, 'created_at' => $at, 'lock_version' => 0]);
        return ['id' => $id, 'public_id' => (string) DB::table('people')->where('id', $id)->value('public_id')];
    }

    protected function key(): string
    {
        return 'k-' . Str::ulid();
    }

    protected function fpost(array $actor, string $uri, array $body = [], ?string $key = null): TestResponse
    {
        $this->flushHeaders();
        $headers = ['Authorization' => 'Bearer ' . $actor['token'], 'Accept' => 'application/json'];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }
        return $this->withHeaders($headers)->json('POST', '/api/v1/' . ltrim($uri, '/'), $body);
    }

    /** External monetary contribution (the external origin of funds): POST /finance/contributions. */
    protected function contribute(array $actor, array $account, string $amount, ?string $on = null, string $category = 'REV_TITHES'): array
    {
        return $this->fpost($actor, 'finance/contributions', ['kind' => 'MONETARY', 'identification' => 'AGGREGATED', 'account' => $account['public_id'], 'category' => $category,
            'amount' => $amount] + ($on === null ? [] : ['received_on' => $on]), $this->key())->assertCreated()->json('data');
    }

    protected function requestTransfer(array $actor, array $account, array $destination, string $amount, string $purpose = 'TRF_SUPPORT', array $extra = []): array
    {
        return $this->fpost($actor, 'finance/transfers', ['origin_account' => $account['public_id'], 'destination_unit' => $destination['public_id'], 'amount' => $amount, 'purpose' => $purpose] + $extra, $this->key())
            ->assertCreated()->json('data');
    }

    protected function sendTransfer(array $actor, string $transfer, ?string $on = null): array
    {
        return $this->fpost($actor, 'finance/transfers/' . $transfer . '/send', $on === null ? [] : ['sent_on' => $on])->assertOk()->json('data');
    }

    protected function receiveTransfer(array $actor, string $transfer, array $account, ?string $on = null): array
    {
        return $this->fpost($actor, 'finance/transfers/' . $transfer . '/receive', ['destination_account' => $account['public_id']] + ($on === null ? [] : ['received_on' => $on]))->assertOk()->json('data');
    }

    /** request + send (+ receive) in one go. */
    protected function transfer(array $from, array $account, array $destination, string $amount, ?array $to = null, ?array $toAccount = null, string $purpose = 'TRF_SUPPORT'): array
    {
        $t = $this->requestTransfer($from, $account, $destination, $amount, $purpose);
        $this->sendTransfer($from, $t['public_id']);
        return $to === null ? $this->detail($from, $t['public_id']) : $this->receiveTransfer($to, $t['public_id'], $toAccount);
    }

    protected function detail(array $actor, string $transfer): array
    {
        return $this->api($actor, 'GET', 'finance/transfers/' . $transfer)->assertOk()->json('data');
    }

    protected function custody(array $actor, array $unit, string $from = '2026-01-01', ?string $to = null): array
    {
        return $this->api($actor, 'GET', 'finance/units/' . $unit['public_id'] . '/custody?from=' . $from . '&to=' . ($to ?? now('Africa/Luanda')->format('Y-m-d')))->assertOk()->json('data');
    }

    protected function transferId(string $publicId): int
    {
        return (int) DB::table('internal_transfers')->where('public_id', $publicId)->value('id');
    }

    /** Raw ACTIVE legal document with one version owned by $unit (Files fixture, no storage involved). */
    protected function document(array $unit, string $type = 'TRANSFER_PROOF', string $classification = 'RESTRICTED'): array
    {
        $typeId = (int) DB::table('legal_document_types')->where('code', $type)->value('id');
        $doc = $this->row('legal_documents', ['document_type_id' => $typeId, 'owner_unit_id' => $unit['id'], 'reference' => 'FIN-' . Str::random(6), 'title' => 'Comprovativo', 'status' => 'ACTIVE']);
        $file = $this->row('files', ['owner_unit_id' => $unit['id'], 'owner_department_id' => null, 'classification' => $classification, 'disk' => 'files_private', 'storage_key' => 'v1/test/' . Str::random(20),
            'original_name' => 'prova.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'checksum' => random_bytes(32), 'status' => 'AVAILABLE']);
        $this->row('document_versions', ['document_id' => $doc, 'version' => 1, 'file_id' => $file, 'supersedes_id' => null]);
        return ['id' => $doc, 'public_id' => (string) DB::table('legal_documents')->where('id', $doc)->value('public_id')];
    }

    protected function assertConcealed(TestResponse $response): void
    {
        $response->assertStatus(404)->assertExactJson(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.']]);
    }

    protected function assertNoInternalIds(array $payload): void
    {
        array_walk_recursive($payload, function ($value, $key): void {
            if (is_string($key)) {
                $this->assertFalse($key === 'id' || (str_ends_with($key, '_id') && !str_ends_with($key, 'public_id')), 'internal id exposed: ' . $key);
            }
        });
    }

    /** Economic result (income) of one unit over 2026 from the ledger (POSTED only). */
    protected function income(array $unit): int
    {
        return (new \App\Domain\Finance\LedgerQueries(DB::connection()))->economicResult($unit['id'], '2026-01-01', '2026-12-31')['income'];
    }

    protected function resultOf(array $unit): int
    {
        return (new \App\Domain\Finance\LedgerQueries(DB::connection()))->economicResult($unit['id'], '2026-01-01', '2026-12-31')['result'];
    }

    protected function balance(array $account): int
    {
        return (new \App\Domain\Finance\LedgerQueries(DB::connection()))->financialAccountBalance($account['id']);
    }

    /** Global accounting invariants over the pool (the validator's SQL). */
    protected function assertLedgerInvariants(): void
    {
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM (SELECT e.id FROM journal_entries e JOIN journal_lines l ON l.entry_id = e.id WHERE e.status = 'POSTED' GROUP BY e.id HAVING SUM(l.debit) <> SUM(l.credit)) x")->n, 'every POSTED entry balanced');
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id JOIN chart_of_accounts a ON a.id = l.ledger_account_id WHERE e.status = 'POSTED' AND e.entry_kind IN ('TRANSFER_SEND','TRANSFER_RECEIVE','TRANSFER_REVERSE_SEND') AND a.account_kind IN ('INCOME','EXPENSE')")->n, 'I1: no transfer stage touches the result');
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM (SELECT transfer_id, posting_stage FROM transfer_postings GROUP BY transfer_id, posting_stage HAVING COUNT(*) > 1) x")->n);
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM internal_transfers t WHERE (t.status IN ('SENT','RECEIVED') AND NOT EXISTS (SELECT 1 FROM transfer_postings p WHERE p.transfer_id = t.id AND p.posting_stage = 'SEND')) OR (t.status = 'RECEIVED' AND NOT EXISTS (SELECT 1 FROM transfer_postings p WHERE p.transfer_id = t.id AND p.posting_stage = 'RECEIVE')) OR (t.status <> 'RECEIVED' AND EXISTS (SELECT 1 FROM transfer_postings p WHERE p.transfer_id = t.id AND p.posting_stage = 'RECEIVE'))")->n, 'status <=> postings');
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM transfer_postings p JOIN internal_transfers t ON t.id = p.transfer_id WHERE p.posting_stage = 'RECEIVE' AND EXISTS (SELECT 1 FROM transfer_postings r WHERE r.transfer_id = t.id AND r.posting_stage = 'REVERSE_SEND')")->n, 'never RECEIVE + REVERSE_SEND');
    }
}
