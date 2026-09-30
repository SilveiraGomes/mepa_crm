<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;

/**
 * Finance read side of F1B (ADR 0021 D15, D17, D20, D21 + D-04A). Every read decides authority on the OWNER unit(s) of
 * the object; the counterpart of a transfer is shown only as institutional metadata (unit public id + name + type),
 * never its accounts, balances or other finances. F-06: permission first; unknown, malformed and out-of-scope targets
 * are the same TARGET_NOT_FOUND (404).
 *
 * Economic result and custody are DIFFERENT dimensions (D-04A.2): custody() reports the funds under the unit's
 * management (cash/bank) by origin and destination; the economic result (income - expense) is reported beside it and
 * internal transfers never change it.
 */
final class FinanceQueryService
{
    private const TRANSFER_STAGE_KINDS = ['TRANSFER_SEND', 'TRANSFER_RECEIVE', 'TRANSFER_REVERSE_SEND'];

    public function __construct(private FinanceRuntime $rt)
    {
    }

    // ---- context ------------------------------------------------------------------------------------------------------

    public function context(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor): array {
            $permissions = $this->rt->authority->effectivePermissions($actor);
            $unitPermissions = [];
            foreach ($permissions as $permission) {
                foreach (array_keys($this->rt->authority->covered($actor, $permission)) as $unit) {
                    $unitPermissions[$unit][] = $permission;
                }
            }
            $units = [];
            if ($unitPermissions !== []) {
                foreach ($this->rt->db->table('organizational_units as u')->join('organizational_unit_types as t', 't.id', '=', 'u.unit_type_id')->whereIn('u.id', array_keys($unitPermissions))
                    ->where('u.status', 'ACTIVE')->orderBy('u.name')->limit(500)->get(['u.id', 'u.public_id', 'u.name', 't.code as type']) as $row) {
                    $units[] = ['public_id' => (string) $row->public_id, 'name' => (string) $row->name, 'type' => (string) $row->type, 'permissions' => $unitPermissions[(int) $row->id]];
                }
            }
            $accountUnits = array_keys(array_filter($unitPermissions, fn (array $p): bool => array_intersect($p, [FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_MANAGE, FinanceCatalog::PERMISSION_VIEW]) !== []));
            $accounts = $accountUnits === [] ? [] : $this->rt->db->table('accounts as a')->join('organizational_units as u', 'u.id', '=', 'a.unit_id')->whereIn('a.unit_id', $accountUnits)
                ->where('a.status', 'OPEN')->orderBy('u.name')->orderBy('a.name')->limit(500)->get(['a.public_id', 'a.name', 'a.account_kind', 'u.public_id as unit'])
                ->map(fn ($a) => ['public_id' => (string) $a->public_id, 'name' => (string) $a->name, 'kind' => (string) $a->account_kind, 'unit' => (string) $a->unit])->all();
            $purposes = $this->rt->db->table('financial_categories')->whereIn('code', FinanceCatalog::TRANSFER_PURPOSES)->orderBy('id')->get(['code', 'name'])
                ->map(fn ($c) => ['code' => (string) $c->code, 'label' => (string) $c->name, 'regular' => in_array($c->code, FinanceCatalog::REGULAR_TRANSFER_PURPOSES, true)])->all();
            $categories = $this->rt->db->table('financial_categories')->whereIn('economic_nature', FinanceCatalog::CONTRIBUTION_NATURES)->where('status', 'ACTIVE')->orderBy('id')->get(['code', 'name'])
                ->map(fn ($c) => ['code' => (string) $c->code, 'label' => (string) $c->name])->all();
            return ['permissions' => $permissions, 'units' => $units, 'accounts' => $accounts, 'purposes' => $purposes, 'contribution_categories' => $categories,
                'currency' => FinanceCatalog::CURRENCY, 'today' => $this->rt->today()];
        });
    }

    /** Institutional destination search (ACTIVE units by name/code, max 20): unit metadata only, no finances. */
    public function units(int $user, int $session, mixed $search): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard) use ($search): array {
            $guard->requires(FinanceCatalog::PERMISSION_TRANSFER);
            $term = is_string($search) ? trim($search) : '';
            if (mb_strlen($term) < 2 || mb_strlen($term) > 100) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'search']);
            }
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            return $this->rt->db->table('organizational_units as u')->join('organizational_unit_types as t', 't.id', '=', 'u.unit_type_id')->where('u.status', 'ACTIVE')
                ->where(fn ($q) => $q->where('u.name', 'like', $like)->orWhere('u.code', 'like', $like))->orderBy('u.name')->limit(20)
                ->get(['u.public_id', 'u.name', 't.code as type'])->map(fn ($u) => ['public_id' => (string) $u->public_id, 'name' => (string) $u->name, 'type' => (string) $u->type])->all();
        });
    }

    // ---- transfers ----------------------------------------------------------------------------------------------------

    public function transfer(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $row = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->transfers()->where('t.public_id', $publicId)->first() : null;
            if ($row === null) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'internal_transfers']);
            }
            $originSide = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_VIEW, (int) $row->origin_unit_id);
            $destinationSide = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_VIEW, (int) $row->destination_unit_id);
            if (!$originSide && !$destinationSide) {
                throw new FinanceError('OUT_OF_SCOPE', [], ['entity' => 'internal_transfers']);
            }
            $item = $this->transferItem($row, $this->rt->today());
            $item['side'] = $originSide && $destinationSide ? 'BOTH' : ($originSide ? 'ORIGIN' : 'DESTINATION');
            $item['lock_version'] = (int) $row->lock_version;
            $item['origin_account'] = $originSide ? $this->accountProjection((int) $row->origin_account_id) : null;
            $item['destination_account'] = $destinationSide && $row->destination_account_id !== null ? $this->accountProjection((int) $row->destination_account_id) : null;
            $item['cancel_reason'] = $row->cancel_reason;
            $item['document'] = $this->rt->documentProjection($actor, $row->document_id === null ? null : (int) $row->document_id);
            $stages = [];
            foreach ($this->rt->db->table('transfer_postings as p')->join('journal_entries as e', 'e.id', '=', 'p.entry_id')->where('p.transfer_id', $row->id)->orderBy('p.id')
                ->get(['p.posting_stage', 'e.public_id', 'e.entry_date', 'e.posted_at', 'e.unit_id']) as $p) {
                $visible = ($p->posting_stage === 'RECEIVE' ? $destinationSide : $originSide);
                $stages[] = ['stage' => (string) $p->posting_stage, 'entry' => $visible ? (string) $p->public_id : null, 'entry_date' => (string) $p->entry_date, 'posted_at' => (string) $p->posted_at];
            }
            $item['stages'] = $stages;
            $item['pairing'] = $row->status === 'RECEIVED' ? InternalTransferService::pairing($this->rt->db, $row) : null;
            $item['actions'] = $this->actions($actor, $row);
            return $item;
        });
    }

    /** direction: sent | received | in_transit; optional unit filter (must be covered). */
    public function transferList(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $direction = $in['direction'] ?? 'sent';
            if (!in_array($direction, ['sent', 'received', 'in_transit'], true)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'direction']);
            }
            $per = $this->rt->perPage($in['per_page'] ?? null);
            $page = max(1, (int) ($in['page'] ?? 1));
            $covered = array_keys($this->rt->authority->covered($actor, FinanceCatalog::PERMISSION_VIEW));
            if (isset($in['unit']) && $in['unit'] !== '') {
                $unit = $this->unitIdOrNull($in['unit']);
                if ($unit === null || !in_array($unit, $covered, true)) {
                    throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);
                }
                $covered = [$unit];
            }
            $query = $this->transfers();
            match ($direction) {
                'sent' => $query->whereIn('t.origin_unit_id', $covered),
                'received' => $query->whereIn('t.destination_unit_id', $covered)->whereIn('t.status', ['SENT', 'RECEIVED']),
                'in_transit' => $query->where('t.status', 'SENT')->where(fn ($q) => $q->whereIn('t.origin_unit_id', $covered)->orWhereIn('t.destination_unit_id', $covered)),
            };
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('t.id')->forPage($page, $per)->get();
            $today = $this->rt->today();
            return ['items' => $rows->map(fn ($r) => $this->transferItem($r, $today))->all(), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    // ---- custody (D-04A.1 / F1B §13) ---------------------------------------------------------------------------------

    public function custody(int $user, int $session, string $unitPublicId, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($unitPublicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $unit = $this->unitIdOrNull($unitPublicId);
            if ($unit === null || !$this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_VIEW, $unit)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);
            }
            [$from, $to] = $this->interval($in);
            return self::custodyOf($this->rt->db, $unit, $from, $to, $this->rt->today()) + ['unit' => $this->unitMeta($unit)];
        });
    }

    /**
     * The custody identity a + b + c - e - f = h over CASH/BANK lines of POSTED entries of one unit (D-04A.1):
     * opening (a), external in (b), internal received (c), external out (e), internal sent net of REVERSE_SEND (f),
     * closing (h). ACCOUNT_TRANSFER is neutral inside the unit. Grouped by counterpart unit and purpose.
     */
    public static function custodyOf(\Illuminate\Database\Connection $db, int $unit, string $from, string $to, string $asOf): array
    {
        $treasury = fn (): Builder => $db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('e.status', FinanceCatalog::POSTED)->where('l.unit_id', $unit)->whereIn('a.system_role', FinanceCatalog::TREASURY_ROLES);
        $sum = fn (Builder $q, string $col): int => Money::fromDecimal((string) ($q->sum($col) ?? '0'));
        $opening = $sum($treasury()->where('e.entry_date', '<', $from), 'l.debit') - $sum($treasury()->where('e.entry_date', '<', $from), 'l.credit');
        $inRange = fn (): Builder => $treasury()->whereBetween('e.entry_date', [$from, $to]);
        $externalIn = $sum($inRange()->whereNotIn('e.entry_kind', [...self::TRANSFER_STAGE_KINDS, 'ACCOUNT_TRANSFER']), 'l.debit');
        $externalOut = $sum($inRange()->whereNotIn('e.entry_kind', [...self::TRANSFER_STAGE_KINDS, 'ACCOUNT_TRANSFER']), 'l.credit');
        $internalReceived = $sum($inRange()->where('e.entry_kind', 'TRANSFER_RECEIVE'), 'l.debit');
        $internalSent = $sum($inRange()->where('e.entry_kind', 'TRANSFER_SEND'), 'l.credit') - $sum($inRange()->where('e.entry_kind', 'TRANSFER_REVERSE_SEND'), 'l.debit');
        $closing = $sum($treasury()->where('e.entry_date', '<=', $to), 'l.debit') - $sum($treasury()->where('e.entry_date', '<=', $to), 'l.credit');

        $byStage = fn (string $stage, string $counterpart) => $db->table('transfer_postings as p')->join('journal_entries as e', 'e.id', '=', 'p.entry_id')
            ->join('internal_transfers as t', 't.id', '=', 'p.transfer_id')->join('organizational_units as u', 'u.id', '=', 't.' . $counterpart)
            ->join('financial_categories as c', 'c.id', '=', 't.category_id')->where('e.status', FinanceCatalog::POSTED)->where('p.posting_stage', $stage)
            ->where('e.unit_id', $unit)->whereBetween('e.entry_date', [$from, $to])->groupBy('u.public_id', 'u.name', 'c.code', 'c.name')
            ->orderBy('u.name')->orderBy('c.code')->selectRaw('u.public_id, u.name, c.code, c.name AS label, COUNT(*) AS n, SUM(t.amount) AS total')->get();
        $group = fn ($rows, string $key) => $rows->map(fn ($r) => [$key => ['public_id' => (string) $r->public_id, 'name' => (string) $r->name],
            'purpose' => ['code' => (string) $r->code, 'label' => (string) $r->label], 'transfers' => (int) $r->n, 'amount' => Money::format(Money::fromDecimal((string) $r->total))])->all();
        $returned = $byStage('REVERSE_SEND', 'destination_unit_id');

        $transit = fn (string $side) => $db->table('internal_transfers as t')->join('transfer_postings as s', fn ($j) => $j->on('s.transfer_id', '=', 't.id')->where('s.posting_stage', '=', 'SEND'))
            ->join('journal_entries as se', 'se.id', '=', 's.entry_id')->where('se.status', FinanceCatalog::POSTED)->where('se.entry_date', '<=', $to)->where('t.' . $side, $unit)
            ->whereNotExists(fn ($q) => $q->from('transfer_postings as r')->join('journal_entries as re', 're.id', '=', 'r.entry_id')->whereColumn('r.transfer_id', 't.id')
                ->whereIn('r.posting_stage', ['RECEIVE', 'REVERSE_SEND'])->where('re.status', FinanceCatalog::POSTED)->where('re.entry_date', '<=', $to))
            ->join('organizational_units as u', 'u.id', '=', $side === 'origin_unit_id' ? 't.destination_unit_id' : 't.origin_unit_id')
            ->orderBy('t.sent_at')->limit(200)->get(['t.public_id', 't.amount', 't.sent_at', 'u.public_id as counterpart', 'u.name']);
        $transitItems = fn ($rows) => $rows->map(fn ($r) => ['transfer' => (string) $r->public_id, 'counterpart' => ['public_id' => (string) $r->counterpart, 'name' => (string) $r->name],
            'amount' => Money::format(Money::fromDecimal((string) $r->amount)), 'sent_at' => (string) $r->sent_at, 'age_days' => self::ageDays((string) $r->sent_at, min($asOf, $to))])->all();
        $outgoing = $transitItems($transit('origin_unit_id'));
        $incoming = $transitItems($transit('destination_unit_id'));
        $result = (new LedgerQueries($db))->economicResult($unit, $from, $to);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'opening_balance' => Money::format($opening),
            'external_funds_received' => Money::format($externalIn),
            'internal_funds_received' => Money::format($internalReceived),
            'external_applications' => Money::format($externalOut),
            'internal_funds_sent' => Money::format($internalSent),
            'closing_balance' => Money::format($closing),
            'balanced' => $opening + $externalIn + $internalReceived - $externalOut - $internalSent === $closing,
            'received_by_origin' => $group($byStage('RECEIVE', 'origin_unit_id'), 'origin'),
            'sent_by_destination' => $group($byStage('SEND', 'destination_unit_id'), 'destination'),
            'returned_by_destination' => $group($returned, 'destination'),
            'in_transit_outgoing' => $outgoing,
            'in_transit_incoming' => $incoming,
            'in_transit_outgoing_total' => Money::format(array_sum(array_map(fn ($i) => Money::fromDecimal($i['amount']), $outgoing))),
            'economic_result' => ['income' => Money::format($result['income']), 'expense' => Money::format($result['expense']), 'result' => Money::format($result['result']),
                'note' => 'Internal transfers never change the economic result (D-04A.2).'],
        ];
    }

    // ---- subtree base (D15 / F1B §18-19) ---------------------------------------------------------------------------

    public function subtree(int $user, int $session, string $unitPublicId, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($unitPublicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_CONSOLIDATED_VIEW);
            $unit = $this->unitIdOrNull($unitPublicId);
            $covered = $this->rt->authority->covered($actor, FinanceCatalog::PERMISSION_CONSOLIDATED_VIEW);
            if ($unit === null || !isset($covered[$unit])) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);
            }
            $perimeter = self::perimeter($this->rt->db, $unit);
            if (array_diff($perimeter, array_keys($covered)) !== []) {
                // D17: the grant must cover U AND every current descendant of U.
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units', 'reason' => 'subtree_not_covered']);
            }
            [$from, $to] = $this->interval($in);
            $per = $this->rt->perPage($in['per_page'] ?? null);
            $page = max(1, (int) ($in['page'] ?? 1));
            $query = $this->transfers()->whereIn('t.status', ['SENT', 'RECEIVED'])->where(fn ($q) => $q->whereIn('t.origin_unit_id', $perimeter)->orWhereIn('t.destination_unit_id', $perimeter))
                ->whereRaw('DATE(t.sent_at) <= ?', [$to])->where(fn ($q) => $q->whereRaw('DATE(t.sent_at) >= ?', [$from])->orWhere('t.status', 'SENT'));
            $all = (clone $query)->orderBy('t.id')->get();
            $totals = array_fill_keys(FinanceCatalog::PERIMETER_CLASSES, ['transfers' => 0, 'amount' => 0]);
            foreach ($all as $row) {
                $class = self::perimeterClass($perimeter, (int) $row->origin_unit_id, (int) $row->destination_unit_id);
                $totals[$class]['transfers']++;
                $totals[$class]['amount'] += Money::fromDecimal((string) $row->amount);
            }
            $today = $this->rt->today();
            $items = (clone $query)->orderByDesc('t.id')->forPage($page, $per)->get()->map(function ($r) use ($perimeter, $today) {
                return $this->transferItem($r, $today) + ['perimeter_class' => self::perimeterClass($perimeter, (int) $r->origin_unit_id, (int) $r->destination_unit_id), 'economic_effect' => '0.00'];
            })->all();
            return ['unit' => $this->unitMeta($unit), 'period' => ['from' => $from, 'to' => $to], 'perimeter_units' => count($perimeter),
                'totals' => array_map(fn ($t) => ['transfers' => $t['transfers'], 'amount' => Money::format($t['amount']), 'economic_effect' => '0.00'], $totals),
                'items' => $items, 'page' => $page, 'per_page' => $per, 'total' => count($all)];
        });
    }

    /** U + every CURRENT descendant (F1D will evaluate the tree as of the period end, D15). @return list<int> */
    public static function perimeter(\Illuminate\Database\Connection $db, int $unit): array
    {
        $rows = $db->select('WITH RECURSIVE s AS (SELECT id, 0 AS depth FROM organizational_units WHERE id = ? UNION ALL SELECT ou.id, s.depth + 1 FROM organizational_units ou JOIN s ON ou.parent_id = s.id WHERE s.depth < 64) SELECT DISTINCT id FROM s', [$unit]);
        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    public static function perimeterClass(array $perimeter, int $origin, int $destination): string
    {
        $in = in_array($origin, $perimeter, true);
        $out = in_array($destination, $perimeter, true);
        return $in && $out ? 'INTERNAL_TO_PERIMETER' : ($in ? 'OUT_OF_PERIMETER' : 'INTO_PERIMETER');
    }

    // ---- contributions --------------------------------------------------------------------------------------------------

    public function contributions(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $per = $this->rt->perPage($in['per_page'] ?? null);
            $page = max(1, (int) ($in['page'] ?? 1));
            $covered = array_keys($this->rt->authority->covered($actor, FinanceCatalog::PERMISSION_VIEW));
            if (isset($in['unit']) && $in['unit'] !== '') {
                $unit = $this->unitIdOrNull($in['unit']);
                if ($unit === null || !in_array($unit, $covered, true)) {
                    throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);
                }
                $covered = [$unit];
            }
            $query = $this->contributionQuery()->whereIn('c.receiving_unit_id', $covered);
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('c.id')->forPage($page, $per)->get();
            return ['items' => $rows->map(fn ($r) => $this->contributionItem($r, false))->all(), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function contribution(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $row = preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->contributionQuery()->where('c.public_id', $publicId)->first() : null;
            if ($row === null || !$this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_VIEW, (int) $row->receiving_unit_id)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'contributions']);
            }
            $identity = $row->party_id !== null && $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_CONTRIBUTOR_VIEW, (int) $row->receiving_unit_id);
            $item = $this->contributionItem($row, $identity) + ['lock_version' => (int) $row->lock_version, 'document' => $this->rt->documentProjection($actor, $row->document_id === null ? null : (int) $row->document_id)];
            if ($identity) {
                FinanceAudit::write($this->rt->db, $actor->user, 'finance.contributor_detail_viewed', 'contributions', (int) $row->id, (int) $row->receiving_unit_id, FinanceAudit::correlation(),
                    ['contribution' => (string) $row->public_id], null, $actor->session);
            }
            return $item;
        });
    }

    // ---- projections --------------------------------------------------------------------------------------------------

    private function transfers(): Builder
    {
        return $this->rt->db->table('internal_transfers as t')->join('organizational_units as o', 'o.id', '=', 't.origin_unit_id')->join('organizational_units as d', 'd.id', '=', 't.destination_unit_id')
            ->join('financial_categories as c', 'c.id', '=', 't.category_id')
            ->select(['t.*', 'o.public_id as origin_public', 'o.name as origin_name', 'd.public_id as destination_public', 'd.name as destination_name', 'c.code as purpose_code', 'c.name as purpose_label']);
    }

    private function transferItem(object $r, string $today): array
    {
        $returned = $r->status === 'CANCELLED' && $r->sent_at !== null;
        $state = match (true) {
            $r->status === 'SENT' => 'IN_TRANSIT',
            $r->status === 'RECEIVED' && $r->reconciled_at === null => 'AWAITING_RECONCILIATION',
            $r->status === 'RECEIVED' => 'RECONCILED',
            $returned => 'RETURNED',
            default => 'NOT_APPLICABLE',
        };
        return [
            'public_id' => (string) $r->public_id,
            'origin' => ['public_id' => (string) $r->origin_public, 'name' => (string) $r->origin_name],
            'destination' => ['public_id' => (string) $r->destination_public, 'name' => (string) $r->destination_name],
            'amount' => Money::format(Money::fromDecimal((string) $r->amount)),
            'currency' => FinanceCatalog::CURRENCY,
            'purpose' => ['code' => (string) $r->purpose_code, 'label' => (string) $r->purpose_label, 'regular' => in_array($r->purpose_code, FinanceCatalog::REGULAR_TRANSFER_PURPOSES, true)],
            'status' => (string) $r->status,
            'reconciliation_state' => $state,
            'created_at' => (string) $r->created_at,
            'sent_at' => $r->sent_at === null ? null : (string) $r->sent_at,
            'received_at' => $r->received_at === null ? null : (string) $r->received_at,
            'reconciled_at' => $r->reconciled_at === null ? null : (string) $r->reconciled_at,
            'age_days' => $r->status === 'SENT' ? self::ageDays((string) $r->sent_at, $today) : null,
            'economic_effect' => '0.00',
        ];
    }

    /** Stage actions the actor may attempt now (presentation only; the server decides every request). */
    private function actions(TerritorialActor $actor, object $r): array
    {
        $on = fn (array $perms, int $unit) => array_reduce($perms, fn ($ok, $p) => $ok && $this->rt->authority->holdsOn($actor, $p, $unit), true);
        $origin = (int) $r->origin_unit_id;
        $destination = (int) $r->destination_unit_id;
        $post = [FinanceCatalog::PERMISSION_TRANSFER, FinanceCatalog::PERMISSION_POST];
        return array_values(array_filter([
            $r->status === 'DRAFT' && $on($post, $origin) ? 'send' : null,
            $r->status === 'DRAFT' && $on([FinanceCatalog::PERMISSION_TRANSFER], $origin) ? 'cancel' : null,
            $r->status === 'SENT' && $on($post, $destination) ? 'receive' : null,
            $r->status === 'SENT' && $on($post, $origin) ? 'reverse_send' : null,
            $r->status === 'RECEIVED' && $r->reconciled_at === null && ($on([FinanceCatalog::PERMISSION_RECONCILE], $origin) || $on([FinanceCatalog::PERMISSION_RECONCILE], $destination)) ? 'reconcile' : null,
        ]));
    }

    private function accountProjection(int $id): ?array
    {
        $a = $this->rt->db->table('accounts')->where('id', $id)->first(['public_id', 'name', 'account_kind']);
        return $a === null ? null : ['public_id' => (string) $a->public_id, 'name' => (string) $a->name, 'kind' => (string) $a->account_kind];
    }

    private function contributionQuery(): Builder
    {
        return $this->rt->db->table('contributions as c')->join('organizational_units as u', 'u.id', '=', 'c.receiving_unit_id')->join('financial_categories as k', 'k.id', '=', 'c.category_id')
            ->leftJoin('journal_entries as e', 'e.id', '=', 'c.journal_entry_id')
            ->select(['c.*', 'u.public_id as unit_public', 'u.name as unit_name', 'k.code as category_code', 'k.name as category_label', 'e.public_id as entry_public']);
    }

    private function contributionItem(object $r, bool $identity): array
    {
        $party = null;
        if ($identity) {
            $p = $this->rt->db->table('financial_parties as f')->leftJoin('people as pe', 'pe.id', '=', 'f.person_id')->where('f.id', $r->party_id)->first(['f.party_kind', 'f.external_name', 'pe.public_id']);
            $party = ['kind' => (string) $p->party_kind, 'person' => $p->public_id === null ? null : (string) $p->public_id, 'name' => $p->external_name];
        }
        return [
            'public_id' => (string) $r->public_id, 'origin' => 'EXTERNAL', 'unit' => ['public_id' => (string) $r->unit_public, 'name' => (string) $r->unit_name],
            'kind' => (string) $r->contribution_kind, 'identification' => (string) $r->identification_kind, 'category' => ['code' => (string) $r->category_code, 'label' => (string) $r->category_label],
            'amount' => $r->amount === null ? null : Money::format(Money::fromDecimal((string) $r->amount)),
            'valuation_status' => $r->valuation_status, 'valuation_amount' => $r->valuation_amount === null ? null : Money::format(Money::fromDecimal((string) $r->valuation_amount)),
            'description' => $r->in_kind_description, 'received_at' => (string) $r->received_at, 'status' => (string) $r->status,
            'entry' => $r->entry_public === null ? null : (string) $r->entry_public, 'party' => $party,
        ];
    }

    private function unitIdOrNull(mixed $publicId): ?int
    {
        $id = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('organizational_units')->where('public_id', $publicId)->value('id') : null;
        return $id === null ? null : (int) $id;
    }

    private function unitMeta(int $unit): array
    {
        $u = $this->rt->db->table('organizational_units')->where('id', $unit)->first(['public_id', 'name']);
        return ['public_id' => (string) $u->public_id, 'name' => (string) $u->name];
    }

    /** from/to as dates (YYYY-MM-DD), default: the current month; max 366 days. @return array{0: string, 1: string} */
    private function interval(array $in): array
    {
        $today = $this->rt->today();
        $from = $in['from'] ?? substr($today, 0, 8) . '01';
        $to = $in['to'] ?? $today;
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => $field]);
            }
        }
        if ($from > $to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'to']);
        }
        return [$from, $to];
    }

    /** Age of an in-transit transfer in days: as_of (Luanda date) - send date (Luanda). The journal is never touched. */
    public static function ageDays(string $sentAtUtc, string $asOf): int
    {
        $sent = new DateTimeImmutable(FinanceRuntime::luandaDate($sentAtUtc), new DateTimeZone('UTC'));
        return max(0, (int) $sent->diff(new DateTimeImmutable($asOf, new DateTimeZone('UTC')))->format('%r%a'));
    }
}
