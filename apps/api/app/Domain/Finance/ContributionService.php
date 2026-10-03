<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

/**
 * Contributions (ADR 0021 D04, D09; F1B §3): the institutional classification of funds that enter a unit FROM OUTSIDE
 * MEPA (tithes, offerings, donations...). A contribution is always an EXTERNAL origin: a MEPA unit as counterparty is an
 * internal transfer (INTERNAL_COUNTERPARTY), and an INTERNAL_TRANSFER rubric is refused. External revenue is therefore
 * recognised exactly once, in the unit that received it from outside.
 *
 *   record MONETARY   FINANCE_MANAGE + FINANCE_POST on the receiving account's unit; posts one CONTRIBUTION entry
 *                     Dr CASH|BANK / Cr the rubric's income account, in the same transaction
 *   record IN_KIND    FINANCE_MANAGE on the receiving unit; UNVALUED, never in the result (D04)
 *   valuate           FINANCE_MANAGE; VALUED with a VALUATION_REPORT document (mandatory)
 *   approve valuation FINANCE_POST; APPROVED + one CONTRIBUTION entry Dr IN_KIND_ASSETS / Cr REV_IN_KIND
 * The contributor identity (a Person, a household or an external name) is shown only with FINANCE_CONTRIBUTOR_VIEW
 * (FinanceQueryService); person amounts never enter audit metadata.
 */
final class ContributionService
{
    public const OP_RECORD = 'FINANCE_CONTRIBUTION_RECORD';

    private LedgerPostingService $ledger;

    public function __construct(private FinanceRuntime $rt)
    {
        $this->ledger = new LedgerPostingService($rt->db);
    }

    /** @return array{public_id: string, replayed: bool} */
    public function record(int $user, int $session, string $clientKey, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($clientKey, $in): array {
            $kind = $in['kind'] ?? null;
            if (!in_array($kind, FinanceCatalog::CONTRIBUTION_KINDS, true)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'kind']);
            }
            $monetary = $kind === 'MONETARY';
            $guard->requires(...($monetary ? [FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST] : [FinanceCatalog::PERMISSION_MANAGE]));
            if ($clientKey === '' || strlen($clientKey) > 64) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'idempotency_key']);
            }
            $hash = hash('sha256', json_encode([self::OP_RECORD, $in], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
            $prior = $this->rt->db->table('idempotency_requests')->where('actor_id', $actor->user)->where('operation', self::OP_RECORD)->where('client_key', $clientKey)->first();
            if ($prior !== null) {
                if (!hash_equals($prior->request_hash, $hash)) {
                    throw new FinanceError('IDEMPOTENCY_CONFLICT');
                }
                if ($prior->status === 'COMPLETED') {
                    return ['public_id' => (string) $prior->result_public_id, 'replayed' => true];
                }
            }
            $account = null;
            if ($monetary) {
                $account = $this->account($in['account'] ?? null);
                $unit = (int) $account->unit_id;
            } else {
                $unit = $this->unitId($in['unit'] ?? null);
            }
            // F-06: scope on the owner unit before any other target or state is revealed.
            foreach ($monetary ? [FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST] : [FinanceCatalog::PERMISSION_MANAGE] as $permission) {
                $this->rt->authority->forUnit($actor, $permission, $unit);
            }
            [$receivedOn, $receivedAt] = $this->rt->instantFor($in['received_on'] ?? null);
            if ($monetary) {
                $this->ledger->lockPostingPeriod($unit, $receivedOn);
                $account = $this->rt->db->table('accounts')->where('id', $account->id)->lockForUpdate()->first();
                if ($account->status !== 'OPEN') {
                    throw new FinanceError('ACCOUNT_NOT_OPEN');
                }
            }
            $decision = $guard->all($monetary ? [FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_POST] : [FinanceCatalog::PERMISSION_MANAGE], $unit);
            if ($this->rt->db->table('organizational_units')->where('id', $unit)->value('status') !== 'ACTIVE') {
                throw new FinanceError('UNIT_NOT_ACTIVE');
            }
            $identification = $in['identification'] ?? null;
            if (!in_array($identification, FinanceCatalog::IDENTIFICATION_KINDS, true)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'identification']);
            }
            $party = $identification === 'IDENTIFIED' ? $this->party($actor, $in['party'] ?? null) : null;
            if ($identification !== 'IDENTIFIED' && isset($in['party'])) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'party']);
            }
            $category = $this->category($in['category'] ?? null, $monetary);
            $document = $this->rt->supportingDocument($guard, $actor, $in['document'] ?? null, $unit);
            $amount = $monetary ? Money::cents((string) ($in['amount'] ?? '')) : null;
            $description = $monetary ? null : trim((string) ($in['description'] ?? ''));
            if (!$monetary && ($description === '' || mb_strlen($description) > 2000)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'description']);
            }

            $now = $this->rt->ts();
            $this->rt->db->table('idempotency_requests')->insertOrIgnore(['actor_id' => $actor->user, 'operation' => self::OP_RECORD, 'client_key' => $clientKey, 'request_hash' => $hash,
                'status' => 'PROCESSING', 'result_public_id' => null, 'expires_at' => null, 'created_at' => $now, 'lock_version' => 0]);
            $claim = $this->rt->db->table('idempotency_requests')->where('actor_id', $actor->user)->where('operation', self::OP_RECORD)->where('client_key', $clientKey)->lockForUpdate()->first();
            if (!hash_equals($claim->request_hash, $hash)) {
                throw new FinanceError('IDEMPOTENCY_CONFLICT');
            }
            if ($claim->status === 'COMPLETED') {
                return ['public_id' => (string) $claim->result_public_id, 'replayed' => true];
            }
            $publicId = (string) Str::ulid();
            $entry = null;
            if ($monetary) {
                $entry = $this->ledger->postSubledgerEntry($actor->user, 'CT-' . $publicId, [
                    'unit_id' => $unit, 'entry_kind' => 'CONTRIBUTION', 'entry_date' => $receivedOn, 'description' => 'Contribuição externa ' . $publicId,
                    'document_id' => $document?->id === null ? null : (int) $document->id, 'lines' => [
                        ['account' => FinanceCatalog::ACCOUNT_KIND_ROLE[$account->account_kind], 'financial_account_id' => (int) $account->id, 'debit' => Money::format($amount)],
                        ['ledger_account_id' => (int) $category->ledger_account_id, 'category' => (string) $category->code, 'credit' => Money::format($amount)],
                    ]]);
            }
            $id = (int) $this->rt->db->table('contributions')->insertGetId([
                'public_id' => $publicId, 'receiving_unit_id' => $unit, 'party_id' => $party, 'category_id' => (int) $category->id, 'fund_id' => $this->generalFund(),
                'currency_id' => (int) $this->rt->db->table('currencies')->where('code', FinanceCatalog::CURRENCY)->value('id'), 'contribution_kind' => $kind,
                'identification_kind' => $identification, 'amount' => $amount === null ? null : Money::format($amount), 'valuation_amount' => null,
                'in_kind_description' => $description, 'valuation_status' => $monetary ? null : 'UNVALUED', 'valued_by' => null, 'valuation_approved_by' => null,
                'valuation_document_id' => null, 'received_at' => $receivedAt, 'journal_entry_id' => $entry === null ? null : (int) $entry['id'],
                'document_id' => $document?->id === null ? null : (int) $document->id, 'status' => 'RECORDED', 'created_at' => $now, 'lock_version' => 0,
            ]);
            $this->rt->db->table('idempotency_requests')->where('id', $claim->id)->update(['status' => 'COMPLETED', 'result_public_id' => $publicId]);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.contribution_recorded', 'contributions', $id, $decision->unit, FinanceAudit::correlation(), [
                'contribution' => $publicId, 'kind' => $kind, 'identification' => $identification, 'category' => (string) $category->code, 'entry' => $entry['public_id'] ?? null,
            ], null, $actor->session);
            return ['public_id' => $publicId, 'replayed' => false];
        });
    }

    /** IN_KIND UNVALUED -> VALUED: amount + mandatory VALUATION_REPORT document (D04). */
    public function valuate(int $user, int $session, string $publicId, array $in): void
    {
        $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): void {
            $guard->requires(FinanceCatalog::PERMISSION_MANAGE);
            $row = $this->contribution($publicId);
            $this->rt->authority->forUnit($actor, FinanceCatalog::PERMISSION_MANAGE, (int) $row->receiving_unit_id);
            $row = $this->rt->db->table('contributions')->where('id', $row->id)->lockForUpdate()->first();
            $decision = $guard->unit(FinanceCatalog::PERMISSION_MANAGE, (int) $row->receiving_unit_id);
            if ($row->contribution_kind !== 'IN_KIND' || $row->valuation_status !== 'UNVALUED') {
                throw new FinanceError('TRANSITION_NOT_ALLOWED');
            }
            $amount = Money::cents((string) ($in['valuation_amount'] ?? ''));
            $document = $this->rt->supportingDocument($guard, $actor, $in['document'] ?? null, (int) $row->receiving_unit_id);
            if ($document === null || $this->rt->db->table('legal_document_types')->where('id', $document->document_type_id)->value('code') !== 'VALUATION_REPORT') {
                throw new FinanceError('VALUATION_DOCUMENT_REQUIRED');
            }
            $this->rt->db->table('contributions')->where('id', $row->id)->where('valuation_status', 'UNVALUED')->update(['valuation_status' => 'VALUED', 'valuation_amount' => Money::format($amount),
                'valued_by' => $actor->user, 'valuation_document_id' => (int) $document->id, 'lock_version' => $row->lock_version + 1]);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.contribution_valued', 'contributions', (int) $row->id, $decision->unit, FinanceAudit::correlation(),
                ['contribution' => (string) $row->public_id, 'document' => (string) $document->public_id], null, $actor->session);
        });
    }

    /** IN_KIND VALUED -> APPROVED: enters the result only now (Dr IN_KIND_ASSETS / Cr REV_IN_KIND). */
    public function approveValuation(int $user, int $session, string $publicId): void
    {
        $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId): void {
            $guard->requires(FinanceCatalog::PERMISSION_POST);
            $row = $this->contribution($publicId);
            $this->rt->authority->forUnit($actor, FinanceCatalog::PERMISSION_POST, (int) $row->receiving_unit_id);
            $today = $this->rt->today();
            $this->ledger->lockPostingPeriod((int) $row->receiving_unit_id, $today);
            $row = $this->rt->db->table('contributions')->where('id', $row->id)->lockForUpdate()->first();
            $decision = $guard->unit(FinanceCatalog::PERMISSION_POST, (int) $row->receiving_unit_id);
            if ($row->contribution_kind !== 'IN_KIND' || $row->valuation_status !== 'VALUED') {
                throw new FinanceError('TRANSITION_NOT_ALLOWED');
            }
            $amount = Money::format(Money::fromDecimal((string) $row->valuation_amount));
            $entry = $this->ledger->postSubledgerEntry($actor->user, 'CV-' . $row->public_id, [
                'unit_id' => (int) $row->receiving_unit_id, 'entry_kind' => 'CONTRIBUTION', 'entry_date' => $today, 'description' => 'Contribuição em espécie valorizada ' . $row->public_id,
                'document_id' => (int) $row->valuation_document_id, 'lines' => [
                    ['account' => 'IN_KIND_ASSETS', 'debit' => $amount],
                    ['account' => 'OPERATING_INCOME', 'category' => 'REV_IN_KIND', 'credit' => $amount],
                ]]);
            $this->rt->db->table('contributions')->where('id', $row->id)->where('valuation_status', 'VALUED')->update(['valuation_status' => 'APPROVED', 'valuation_approved_by' => $actor->user,
                'journal_entry_id' => (int) $entry['id'], 'lock_version' => $row->lock_version + 1]);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.contribution_valuation_approved', 'contributions', (int) $row->id, $decision->unit, FinanceAudit::correlation(),
                ['contribution' => (string) $row->public_id, 'entry' => $entry['public_id']], null, $actor->session);
        });
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------

    private function contribution(mixed $publicId): object
    {
        $row = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('contributions')->where('public_id', $publicId)->first() : null;
        if ($row === null) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'contributions']);
        }
        return $row;
    }

    private function account(mixed $publicId): object
    {
        $row = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('accounts')->where('public_id', $publicId)->first() : null;
        if ($row === null) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'accounts']);
        }
        return $row;
    }

    private function unitId(mixed $publicId): int
    {
        $id = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('organizational_units')->where('public_id', $publicId)->value('id') : null;
        if ($id === null) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);
        }
        return (int) $id;
    }

    /** External party only: a Person visible in People, or a named external party. A MEPA unit is never a contributor. */
    private function party(TerritorialActor $actor, mixed $party): int
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
            if ($name === '' || mb_strlen($name) > 191) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'party']);
            }
            return (int) $this->rt->db->table('financial_parties')->insertGetId(['party_kind' => 'EXTERNAL', 'person_id' => null, 'household_id' => null, 'unit_id' => null,
                'department_instance_id' => null, 'external_name' => $name, 'status' => 'ACTIVE', 'created_at' => $now, 'lock_version' => 0]);
        }
        throw new FinanceError('INVALID_INPUT', [], ['field' => 'party']);
    }

    private function category(mixed $code, bool $monetary): object
    {
        $row = is_string($code) ? $this->rt->db->table('financial_categories')->where('code', $code)->where('status', 'ACTIVE')->whereNotNull('ledger_account_id')->first() : null;
        if ($row !== null && $row->economic_nature === 'INTERNAL_TRANSFER') {
            throw new FinanceError('INTERNAL_COUNTERPARTY');
        }
        if ($row === null || !in_array($row->economic_nature, FinanceCatalog::CONTRIBUTION_NATURES, true) || ($monetary === ($row->code === 'REV_IN_KIND'))) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'category']);
        }
        return $row;
    }

    private function generalFund(): int
    {
        return (int) $this->rt->db->table('funds')->where('code', FinanceCatalog::FUND_GENERAL)->value('id');
    }
}
