<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use DateTimeImmutable;

/**
 * Shared steps of the F1C application services (accrual subledgers, financial accounts, bank reconciliation, budget,
 * closes). Every write runs inside FinanceRuntime::write and follows the same order (ADR 0021 D07/D17/D20/D30):
 *   1. $guard->requires(): permissions held SOMEWHERE, before any target is resolved (403 depends only on grants);
 *   2. target by public id (unknown / malformed = TARGET_NOT_FOUND) and preauthorize() on its IMMUTABLE owner unit
 *      BEFORE any lock, state or period is revealed (F-06: out of scope = the same concealed 404);
 *   3. locks in the Finance order: period (+ unit close) FOR SHARE -> subledger document FOR UPDATE -> financial
 *      accounts -> journal header / idempotency claim;
 *   4. $guard->unit()/all(): the decision re-read FOR SHARE and recorded for the commit-time recheck;
 *   5. decision reads after a lock wait are LOCKING reads (F1B-P01).
 * Idempotency: the same Idempotency-Key + same payload replays (meta.replayed), a different payload is
 * IDEMPOTENCY_CONFLICT; idempotency_requests is the store (claimed FOR UPDATE, completed with the result public id).
 */
abstract class FinanceOperation
{
    protected LedgerPostingService $ledger;

    public function __construct(protected FinanceRuntime $rt)
    {
        $this->ledger = new LedgerPostingService($rt->db);
    }

    // ---- targets -------------------------------------------------------------------------------------------------------

    /** Row of $table by public id; unknown or malformed = TARGET_NOT_FOUND. $lock: '' | 'share' | 'update'. */
    protected function byPublicId(string $table, mixed $publicId, string $lock = ''): object
    {
        $query = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table($table)->where('public_id', $publicId) : null;
        if ($query !== null && $lock === 'update') {
            $query->lockForUpdate();
        } elseif ($query !== null && $lock === 'share') {
            $query->sharedLock();
        }
        $row = $query?->first();
        if ($row === null) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => $table]);
        }
        return $row;
    }

    protected function lockRow(string $table, int $id): object
    {
        return $this->rt->db->table($table)->where('id', $id)->lockForUpdate()->first();
    }

    /** F-06: scope decided on the immutable owner unit BEFORE locks, state or period checks. */
    protected function preauthorize(TerritorialActor $actor, array $permissions, int $unit): void
    {
        foreach ($permissions as $permission) {
            $this->rt->authority->forUnit($actor, $permission, $unit);
        }
    }

    protected function assertUnitActive(int $unit): void
    {
        if ($this->rt->db->table('organizational_units')->where('id', $unit)->sharedLock()->value('status') !== 'ACTIVE') {
            throw new FinanceError('UNIT_NOT_ACTIVE');
        }
    }

    protected function assertLockVersion(object $row, array $in): void
    {
        if (array_key_exists('lock_version', $in) && $in['lock_version'] !== null && (int) $in['lock_version'] !== (int) $row->lock_version) {
            throw new FinanceError('STALE_WRITE');
        }
    }

    protected function unitPublic(int $unit): ?string
    {
        $value = $this->rt->db->table('organizational_units')->where('id', $unit)->value('public_id');
        return $value === null ? null : (string) $value;
    }

    // ---- idempotency ---------------------------------------------------------------------------------------------------

    protected function payloadHash(string $operation, array $payload): string
    {
        return hash('sha256', json_encode([$operation, $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
    }

    /** Before any target is resolved: a completed replay returns its public id; a different payload is a conflict. */
    protected function prior(TerritorialActor $actor, string $operation, string $clientKey, string $hash): ?string
    {
        if ($clientKey === '' || strlen($clientKey) > 64) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'idempotency_key']);
        }
        $row = $this->rt->db->table('idempotency_requests')->where('actor_id', $actor->user)->where('operation', $operation)->where('client_key', $clientKey)->first();
        if ($row === null) {
            return null;
        }
        if (!hash_equals($row->request_hash, $hash)) {
            throw new FinanceError('IDEMPOTENCY_CONFLICT');
        }
        return $row->status === 'COMPLETED' ? (string) $row->result_public_id : null;
    }

    /** @return array{id: int, replay: ?string} the claim row, held FOR UPDATE until commit */
    protected function claim(TerritorialActor $actor, string $operation, string $clientKey, string $hash): array
    {
        $this->rt->db->table('idempotency_requests')->insertOrIgnore(['actor_id' => $actor->user, 'operation' => $operation, 'client_key' => $clientKey, 'request_hash' => $hash,
            'status' => 'PROCESSING', 'result_public_id' => null, 'expires_at' => null, 'created_at' => $this->rt->ts(), 'lock_version' => 0]);
        $row = $this->rt->db->table('idempotency_requests')->where('actor_id', $actor->user)->where('operation', $operation)->where('client_key', $clientKey)->lockForUpdate()->first();
        if (!hash_equals($row->request_hash, $hash)) {
            throw new FinanceError('IDEMPOTENCY_CONFLICT');
        }
        return ['id' => (int) $row->id, 'replay' => $row->status === 'COMPLETED' ? (string) $row->result_public_id : null];
    }

    protected function complete(int $claimId, string $publicId): void
    {
        $this->rt->db->table('idempotency_requests')->where('id', $claimId)->update(['status' => 'COMPLETED', 'result_public_id' => $publicId]);
    }

    // ---- input ---------------------------------------------------------------------------------------------------------

    protected function reason(mixed $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            throw new FinanceError('REASON_REQUIRED');
        }
        return $reason;
    }

    /** A civil date (YYYY-MM-DD); never in the future unless $future. */
    protected function date(mixed $value, string $field, bool $future = false): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => $field]);
        }
        if (!$future && $value > $this->rt->today()) {
            throw new FinanceError('ENTRY_DATE_IN_FUTURE');
        }
        return $value;
    }

    /** Optional free text (trimmed, <= $max chars) or null. */
    protected function text(mixed $value, string $field, int $max = 191, bool $required = false): ?string
    {
        $value = is_string($value) ? trim($value) : ($value === null ? '' : null);
        if ($value === null || mb_strlen($value) > $max || ($required && $value === '')) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => $field]);
        }
        return $value === '' ? null : $value;
    }

    /** Signed business amount ("-12.50", "0", "100.00") -> cents; scale and limit as Money (D02). */
    protected function signedCents(mixed $value, string $field): int
    {
        if (!is_string($value)) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => $field]);
        }
        $negative = str_starts_with($value, '-');
        $cents = Money::cents($negative ? substr($value, 1) : $value, true);
        return $negative ? -$cents : $cents;
    }

    /** Active postable rubric of one of $natures (by code). */
    protected function category(mixed $code, array $natures, string $field = 'category'): object
    {
        $row = is_string($code) ? $this->rt->db->table('financial_categories')->where('code', $code)->where('status', 'ACTIVE')->whereNotNull('ledger_account_id')->first() : null;
        if ($row !== null && $row->economic_nature === 'INTERNAL_TRANSFER') {
            // D09: a MEPA unit is never an external counterparty; internal funds move only by internal_transfers.
            throw new FinanceError('INTERNAL_COUNTERPARTY');
        }
        if ($row === null || !in_array($row->economic_nature, $natures, true)) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => $field]);
        }
        return $row;
    }

    /**
     * External counterparty (D09): a Person visible through People, or a named external party. A MEPA unit is NEVER a
     * supplier, debtor or contributor (INTERNAL_COUNTERPARTY): that is an internal transfer.
     */
    protected function party(TerritorialActor $actor, mixed $party): int
    {
        if (!is_array($party)) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'party']);
        }
        $kind = $party['kind'] ?? null;
        if ($kind === 'UNIT') {
            throw new FinanceError('INTERNAL_COUNTERPARTY');
        }
        $now = $this->rt->ts();
        if ($kind === 'PERSON') {
            $person = is_string($party['person'] ?? null) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $party['person']) === 1
                ? $this->rt->db->table('people')->where('public_id', $party['person'])->value('id') : null;
            if ($person === null || !$this->rt->canSeePerson($actor, (int) $person)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'people']);
            }
            $existing = $this->rt->db->table('financial_parties')->where('party_kind', 'PERSON')->where('person_id', $person)->value('id');
            return $existing !== null ? (int) $existing : (int) $this->rt->db->table('financial_parties')->insertGetId(['party_kind' => 'PERSON', 'person_id' => (int) $person,
                'household_id' => null, 'unit_id' => null, 'department_instance_id' => null, 'external_name' => null, 'status' => 'ACTIVE', 'created_at' => $now, 'lock_version' => 0]);
        }
        if ($kind === 'EXTERNAL') {
            $name = trim((string) ($party['name'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 191) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'party']);
            }
            return (int) $this->rt->db->table('financial_parties')->insertGetId(['party_kind' => 'EXTERNAL', 'person_id' => null, 'household_id' => null, 'unit_id' => null,
                'department_instance_id' => null, 'external_name' => $name, 'status' => 'ACTIVE', 'created_at' => $now, 'lock_version' => 0]);
        }
        throw new FinanceError('INVALID_INPUT', [], ['field' => 'party']);
    }

    protected function currencyId(): int
    {
        return (int) $this->rt->db->table('currencies')->where('code', FinanceCatalog::CURRENCY)->value('id');
    }

    protected function generalFund(): int
    {
        return (int) $this->rt->db->table('funds')->where('code', FinanceCatalog::FUND_GENERAL)->value('id');
    }

    protected function documentTypeCode(object $document): string
    {
        return (string) $this->rt->db->table('legal_document_types')->where('id', $document->document_type_id)->value('code');
    }

    /**
     * Balance of a financial account from a LOCKING read of the POSTED lines (latest committed), taken with the account
     * row already locked: the canonical Σ debit - Σ credit (D06), never a stored figure.
     */
    protected function lockedBalance(int $accountId): int
    {
        $row = $this->rt->db->selectOne("SELECT COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS b FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.financial_account_id = ? AND e.status = 'POSTED' FOR SHARE", [$accountId]);
        return Money::fromDecimal((string) $row->b);
    }
}
