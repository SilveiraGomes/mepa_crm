<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

/**
 * Financial accounts lifecycle (ADR 0021 D06; F1C). accounts.status OPEN | CLOSED (CHECK); no reopen (a new account is
 * opened instead).
 *
 *   open   FINANCE_ACCOUNT_MANAGE (+ FINANCE_POST when an opening balance is given) on the owner unit.
 *          CASH: cash_registers with a custodian Person visible through People. BANK: bank_account_details with the
 *          account number encrypted (Files key mechanism, ADR 0019 D06); only the last 4 characters are ever shown.
 *          Opening balance = one OPENING_BALANCE journal entry Dr CASH|BANK / Cr OPENING_NET_ASSETS, never a column.
 *   close  FINANCE_ACCOUNT_MANAGE. D06 preconditions, decided on LOCKING reads with the account held FOR UPDATE:
 *          canonical balance zero and no DRAFT / SUBMITTED entry touching the account. A CLOSED account keeps its
 *          history and its (derived) balance and refuses every new posting (LedgerPostingService, FINANCIAL_ACCOUNT_CLOSED).
 * The canonical balance is always Σ debit - Σ credit of POSTED journal lines (LedgerQueries); no balance column exists.
 */
final class FinanceAccountService extends FinanceOperation
{
    public const OP_OPEN = 'FINANCE_ACCOUNT_OPEN';
    private const AD = 'MEPA-FIN-BANK-V1|';

    /** @return array{public_id: string, replayed: bool} */
    public function open(int $user, int $session, string $clientKey, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($clientKey, $in): array {
            $withOpening = isset($in['opening_balance']) && $in['opening_balance'] !== null && $in['opening_balance'] !== '' && $in['opening_balance'] !== '0' && $in['opening_balance'] !== '0.00';
            $permissions = $withOpening ? [FinanceCatalog::PERMISSION_ACCOUNT_MANAGE, FinanceCatalog::PERMISSION_POST] : [FinanceCatalog::PERMISSION_ACCOUNT_MANAGE];
            $guard->requires(...$permissions);
            $hash = $this->payloadHash(self::OP_OPEN, $in);
            if (($replay = $this->prior($actor, self::OP_OPEN, $clientKey, $hash)) !== null) {
                return ['public_id' => $replay, 'replayed' => true];
            }
            $unit = (int) $this->byPublicId('organizational_units', $in['unit'] ?? null)->id;
            $this->preauthorize($actor, $permissions, $unit);
            $kind = $in['kind'] ?? null;
            if (!in_array($kind, FinanceCatalog::ACCOUNT_KINDS, true)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'kind']);
            }
            $code = is_string($in['code'] ?? null) ? strtoupper(trim($in['code'])) : '';
            if (preg_match('/^[A-Z0-9][A-Z0-9._-]{1,63}$/D', $code) !== 1) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'code']);
            }
            $name = $this->text($in['name'] ?? null, 'name', 191, true);
            $openedOn = $this->date($in['opened_on'] ?? $this->rt->today(), 'opened_on');
            $opening = $withOpening ? Money::cents(is_string($in['opening_balance']) ? $in['opening_balance'] : '') : 0;
            if ($withOpening) {
                $this->ledger->lockPostingPeriod($unit, $openedOn);
            }
            $decision = $guard->all($permissions, $unit);
            $this->assertUnitActive($unit);
            if ($this->rt->db->table('accounts')->where('unit_id', $unit)->where('code', $code)->sharedLock()->exists()) {
                throw new FinanceError('ACCOUNT_CODE_TAKEN');
            }
            $custodian = null;
            $bank = null;
            if ($kind === 'CASH') {
                $person = is_string($in['custodian'] ?? null) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $in['custodian']) === 1
                    ? $this->rt->db->table('people')->where('public_id', $in['custodian'])->value('id') : null;
                if ($person === null || !$this->rt->canSeePerson($actor, (int) $person)) {
                    throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'people']);
                }
                $custodian = (int) $person;
            } else {
                $bankName = $this->text($in['bank_name'] ?? null, 'bank_name', 191, true);
                $number = is_string($in['account_number'] ?? null) ? preg_replace('/[\s-]+/', '', $in['account_number']) : '';
                if (preg_match('/^[A-Za-z0-9]{4,40}$/D', $number) !== 1) {
                    throw new FinanceError('INVALID_INPUT', [], ['field' => 'account_number']);
                }
                $bank = [$bankName, strtoupper($number)];
            }

            $claim = $this->claim($actor, self::OP_OPEN, $clientKey, $hash);
            if ($claim['replay'] !== null) {
                return ['public_id' => $claim['replay'], 'replayed' => true];
            }
            $publicId = (string) Str::ulid();
            $now = $this->rt->ts();
            $id = (int) $this->rt->db->table('accounts')->insertGetId(['public_id' => $publicId, 'unit_id' => $unit, 'ledger_account_id' => FinanceCatalog::roleIds($this->rt->db)[FinanceCatalog::ACCOUNT_KIND_ROLE[$kind]],
                'currency_id' => $this->currencyId(), 'code' => $code, 'name' => $name, 'account_kind' => $kind, 'status' => 'OPEN', 'opened_on' => $openedOn, 'closed_on' => null,
                'created_at' => $now, 'lock_version' => 0]);
            if ($kind === 'CASH') {
                $this->rt->db->table('cash_registers')->insert(['account_id' => $id, 'custodian_person_id' => $custodian, 'status' => 'ACTIVE', 'created_at' => $now, 'lock_version' => 0]);
            } else {
                $ring = $this->rt->bankKeyRing();
                $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
                $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($bank[1], self::AD . $publicId, $nonce, $ring->kek($ring->activeVersion()));
                $this->rt->db->table('bank_account_details')->insert(['account_id' => $id, 'bank_name' => $bank[0], 'account_number_ciphertext' => $nonce . $cipher,
                    'key_version' => $ring->activeVersion(), 'created_at' => $now, 'lock_version' => 0]);
            }
            $entry = null;
            if ($opening > 0) {
                // D06: the initial balance is a journal entry, never a column.
                $draft = $this->ledger->createDraft($actor->user, 'AO-' . $publicId, ['unit_id' => $unit, 'entry_kind' => 'OPENING_BALANCE', 'entry_date' => $openedOn,
                    'description' => 'Saldo inicial ' . $code, 'lines' => [
                        ['account' => FinanceCatalog::ACCOUNT_KIND_ROLE[$kind], 'financial_account_id' => $id, 'debit' => Money::format($opening)],
                        ['account' => 'OPENING_NET_ASSETS', 'credit' => Money::format($opening)],
                    ]]);
                $this->ledger->post($actor->user, $draft['public_id'], 0);
                $entry = $draft['public_id'];
            }
            $this->complete($claim['id'], $publicId);
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.account_opened', 'accounts', $id, $decision->unit, FinanceAudit::correlation(), [
                'account' => $publicId, 'kind' => $kind, 'code' => $code, 'opening_entry' => $entry,
            ], null, $actor->session);
            return ['public_id' => $publicId, 'replayed' => false];
        });
    }

    /** OPEN -> CLOSED (D06: canonical balance zero, nothing pending). @return array{replayed: bool} */
    public function close(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_ACCOUNT_MANAGE);
            $peek = $this->byPublicId('accounts', $publicId);
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_ACCOUNT_MANAGE], (int) $peek->unit_id);
            $account = $this->lockRow('accounts', (int) $peek->id);
            $decision = $guard->unit(FinanceCatalog::PERMISSION_ACCOUNT_MANAGE, (int) $account->unit_id);
            if ($account->status === 'CLOSED') {
                return ['replayed' => true];
            }
            $this->assertLockVersion($account, $in);
            $closedOn = $this->date($in['closed_on'] ?? $this->rt->today(), 'closed_on');
            if ($closedOn < (string) $account->opened_on) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'closed_on']);
            }
            $pending = $this->rt->db->table('journal_entries as e')->join('journal_lines as l', 'l.entry_id', '=', 'e.id')->where('l.financial_account_id', $account->id)
                ->whereIn('e.status', [FinanceCatalog::DRAFT, FinanceCatalog::SUBMITTED])->distinct()->orderBy('e.public_id')->sharedLock()->pluck('e.public_id')->all();
            if ($pending !== []) {
                throw new FinanceError('ACCOUNT_HAS_PENDING_ENTRIES', $pending);
            }
            if ($this->lockedBalance((int) $account->id) !== 0) {
                throw new FinanceError('ACCOUNT_BALANCE_NOT_ZERO');
            }
            $later = $this->rt->db->selectOne("SELECT COUNT(*) AS n FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE l.financial_account_id = ? AND e.status = 'POSTED' AND e.entry_date > ? FOR SHARE", [$account->id, $closedOn]);
            if ((int) $later->n > 0) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'closed_on', 'reason' => 'movements_after']);
            }
            $this->rt->db->table('accounts')->where('id', $account->id)->where('status', 'OPEN')->update(['status' => 'CLOSED', 'closed_on' => $closedOn, 'lock_version' => $account->lock_version + 1]);
            if ($account->account_kind === 'CASH') {
                $this->rt->db->table('cash_registers')->where('account_id', $account->id)->update(['status' => 'CLOSED']);
            }
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.account_closed', 'accounts', (int) $account->id, $decision->unit, FinanceAudit::correlation(), [
                'account' => (string) $account->public_id, 'closed_on' => $closedOn,
            ], null, $actor->session);
            return ['replayed' => false];
        });
    }

    /** Masked bank account number (last 4 characters); null when the ring cannot decrypt (fail closed, never the raw value). */
    public static function maskedNumber(FinanceRuntime $rt, object $details, string $accountPublicId): ?string
    {
        try {
            $ring = $rt->bankKeyRing();
            if (!$ring->has((int) $details->key_version)) {
                return null;
            }
            $raw = (string) $details->account_number_ciphertext;
            $nonceBytes = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceBytes), self::AD . $accountPublicId, substr($raw, 0, $nonceBytes), $ring->kek((int) $details->key_version));
            return $plain === false ? null : '•••• ' . substr($plain, -4);
        } catch (FinanceError|\App\Domain\Files\FilesError) {
            return null;
        }
    }
}
