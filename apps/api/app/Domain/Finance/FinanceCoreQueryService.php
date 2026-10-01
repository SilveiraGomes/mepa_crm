<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Finance read side of F1C (ADR 0021 D06, D13, D20, D21; F1C). Authority is decided on the OWNER unit of every object
 * (account unit, receivable / payable unit, statement / reconciliation account unit, budget unit); collections are
 * filtered in SQL by the covered units and always paginated. F-06: permission first; unknown, malformed and out-of-scope
 * targets are the same TARGET_NOT_FOUND. Every figure is derived from the ledger or the subledger rows:
 *   balance       Σ debit - Σ credit of POSTED journal lines of the financial account (no stored balance exists);
 *   outstanding   amount - Σ allocations of POSTED settlements;
 *   match state   Σ matched_amount of a line vs its amount: UNMATCHED | PARTIALLY_MATCHED | MATCHED (never stored);
 *   actual        POSTED journal lines of the unit, rubric and fund in the budget year (never a dashboard or a cache).
 */
final class FinanceCoreQueryService
{
    public function __construct(private FinanceRuntime $rt)
    {
    }

    // ---- financial accounts ---------------------------------------------------------------------------------------------

    public function accounts(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            [$page, $per] = $this->paging($in);
            $query = $this->rt->db->table('accounts as a')->join('organizational_units as u', 'u.id', '=', 'a.unit_id')->whereIn('a.unit_id', $this->units($actor, $in, [FinanceCatalog::PERMISSION_VIEW]));
            if (isset($in['status']) && $in['status'] !== '') {
                $this->enum($in['status'], ['OPEN', 'CLOSED'], 'status');
                $query->where('a.status', $in['status']);
            }
            if (isset($in['kind']) && $in['kind'] !== '') {
                $this->enum($in['kind'], FinanceCatalog::ACCOUNT_KINDS, 'kind');
                $query->where('a.account_kind', $in['kind']);
            }
            $total = (clone $query)->count();
            $rows = $query->orderBy('u.name')->orderBy('a.code')->forPage($page, $per)->get(['a.*', 'u.public_id as unit_public', 'u.name as unit_name']);
            $queries = new LedgerQueries($this->rt->db);
            return ['items' => $rows->map(fn ($a) => $this->accountItem($a) + ['balance' => Money::format($queries->financialAccountBalance((int) $a->id))])->all(),
                'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function account(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $a = $this->owned('accounts', $publicId, 'unit_id', $actor, [FinanceCatalog::PERMISSION_VIEW]);
            $unit = $this->rt->db->table('organizational_units')->where('id', $a->unit_id)->first(['public_id', 'name']);
            $a->unit_public = $unit->public_id;
            $a->unit_name = $unit->name;
            $item = $this->accountItem($a) + ['balance' => Money::format((new LedgerQueries($this->rt->db))->financialAccountBalance((int) $a->id)), 'lock_version' => (int) $a->lock_version,
                'balance_source' => 'POSTED_JOURNAL_LINES'];
            if ($a->account_kind === 'BANK') {
                $details = $this->rt->db->table('bank_account_details')->where('account_id', $a->id)->first();
                $item['bank'] = $details === null ? null : ['bank_name' => (string) $details->bank_name, 'account_number' => FinanceAccountService::maskedNumber($this->rt, $details, (string) $a->public_id)];
            } else {
                $register = $this->rt->db->table('cash_registers as c')->join('people as p', 'p.id', '=', 'c.custodian_person_id')->where('c.account_id', $a->id)->first(['c.custodian_person_id', 'p.public_id', 'c.status']);
                $item['cash_register'] = $register === null ? null : ['status' => (string) $register->status,
                    'custodian' => $this->rt->canSeePerson($actor, (int) $register->custodian_person_id) ? (string) $register->public_id : null];
            }
            $opening = $this->rt->db->table('journal_entries as e')->join('journal_lines as l', 'l.entry_id', '=', 'e.id')->where('l.financial_account_id', $a->id)
                ->where('e.entry_kind', 'OPENING_BALANCE')->where('e.status', FinanceCatalog::POSTED)->orderBy('e.id')->first(['e.public_id', 'l.debit']);
            $item['opening_entry'] = $opening === null ? null : ['entry' => (string) $opening->public_id, 'amount' => Money::format(Money::fromDecimal((string) $opening->debit))];
            $item['actions'] = $a->status === 'OPEN' && $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_ACCOUNT_MANAGE, (int) $a->unit_id) ? ['close'] : [];
            return $item;
        });
    }

    /** POSTED movements of one financial account, newest first (the ledger is the history; nothing is deleted). */
    public function accountHistory(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $a = $this->owned('accounts', $publicId, 'unit_id', $actor, [FinanceCatalog::PERMISSION_VIEW]);
            [$page, $per] = $this->paging($in);
            $query = $this->rt->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('l.financial_account_id', $a->id)->where('e.status', FinanceCatalog::POSTED);
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('e.entry_date')->orderByDesc('e.id')->orderBy('l.line_number')->forPage($page, $per)
                ->get(['e.public_id', 'e.entry_kind', 'e.entry_date', 'e.description', 'e.posted_at', 'l.line_number', 'l.debit', 'l.credit']);
            return ['items' => $rows->map(fn ($r) => ['entry' => (string) $r->public_id, 'entry_line' => (int) $r->line_number, 'kind' => (string) $r->entry_kind, 'entry_date' => (string) $r->entry_date,
                'description' => (string) $r->description, 'debit' => Money::format(Money::fromDecimal((string) $r->debit)), 'credit' => Money::format(Money::fromDecimal((string) $r->credit)),
                'posted_at' => (string) $r->posted_at])->all(), 'page' => $page, 'per_page' => $per, 'total' => $total,
                'balance' => Money::format((new LedgerQueries($this->rt->db))->financialAccountBalance((int) $a->id))];
        });
    }

    // ---- receivables / payables / settlements ---------------------------------------------------------------------------

    public function subledgerList(string $kind, int $user, int $session, array $in): array
    {
        $this->kind($kind);
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($kind, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            [$page, $per] = $this->paging($in);
            $query = $this->subledgerQuery($kind)->whereIn('d.unit_id', $this->units($actor, $in, [FinanceCatalog::PERMISSION_VIEW]));
            if (isset($in['status']) && $in['status'] !== '') {
                $this->enum($in['status'], FinanceCatalog::SUBLEDGER_STATUSES, 'status');
                $query->where('d.status', $in['status']);
            }
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('d.id')->forPage($page, $per)->get();
            return ['items' => $rows->map(fn ($r) => $this->subledgerItem($kind, $r, $actor))->all(), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function subledger(string $kind, int $user, int $session, string $publicId): array
    {
        $this->kind($kind);
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($kind, $publicId): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $row = preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->subledgerQuery($kind)->where('d.public_id', $publicId)->first() : null;
            if ($row === null || !$this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_VIEW, (int) $row->unit_id)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => $kind]);
            }
            $item = $this->subledgerItem($kind, $row, $actor) + ['lock_version' => (int) $row->lock_version, 'document' => $this->rt->documentProjection($actor, $row->document_id === null ? null : (int) $row->document_id)];
            $column = $kind === 'receivables' ? 'receivable_id' : 'payable_id';
            $item['settlements'] = $this->rt->db->table('settlement_allocations as x')->join('settlements as s', 's.id', '=', 'x.settlement_id')->join('accounts as a', 'a.id', '=', 's.account_id')
                ->join('journal_entries as e', 'e.id', '=', 's.entry_id')->leftJoin('journal_entries as r', 'r.reversal_of_id', '=', 's.entry_id')->where('x.' . $column, $row->id)->orderBy('s.id')
                ->get(['s.public_id', 's.amount', 's.status', 's.settled_at', 's.direction', 'a.public_id as account', 'a.name as account_name', 'e.public_id as entry', 'r.public_id as cancellation_entry'])
                ->map(fn ($s) => ['public_id' => (string) $s->public_id, 'amount' => Money::format(Money::fromDecimal((string) $s->amount)), 'status' => (string) $s->status, 'direction' => (string) $s->direction,
                    'settled_at' => (string) $s->settled_at, 'account' => ['public_id' => (string) $s->account, 'name' => (string) $s->account_name], 'entry' => (string) $s->entry,
                    'cancellation_entry' => $s->cancellation_entry === null ? null : (string) $s->cancellation_entry])->all();
            $cancellation = $this->rt->db->table('journal_entries')->where('reversal_of_id', $row->recognition_entry_id)->value('public_id');
            $item['cancellation_entry'] = $cancellation === null ? null : (string) $cancellation;
            $item['actions'] = $this->subledgerActions($actor, $row, Money::fromDecimal($item['outstanding']));
            return $item;
        });
    }

    public function settlement(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $s = preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('settlements as s')->join('accounts as a', 'a.id', '=', 's.account_id')
                ->join('journal_entries as e', 'e.id', '=', 's.entry_id')->leftJoin('journal_entries as r', 'r.reversal_of_id', '=', 's.entry_id')->where('s.public_id', $publicId)
                ->first(['s.*', 'a.unit_id', 'a.public_id as account', 'a.name as account_name', 'e.public_id as entry', 'r.public_id as cancellation_entry', 'r.reason as cancel_reason']) : null;
            if ($s === null || !$this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_VIEW, (int) $s->unit_id)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'settlements']);
            }
            $allocation = $this->rt->db->table('settlement_allocations as x')->leftJoin('receivables as rv', 'rv.id', '=', 'x.receivable_id')->leftJoin('payables as pv', 'pv.id', '=', 'x.payable_id')
                ->where('x.settlement_id', $s->id)->first(['rv.public_id as receivable', 'pv.public_id as payable', 'x.amount']);
            return ['public_id' => (string) $s->public_id, 'direction' => (string) $s->direction, 'status' => (string) $s->status, 'amount' => Money::format(Money::fromDecimal((string) $s->amount)),
                'settled_at' => (string) $s->settled_at, 'account' => ['public_id' => (string) $s->account, 'name' => (string) $s->account_name], 'entry' => (string) $s->entry,
                'cancellation_entry' => $s->cancellation_entry === null ? null : (string) $s->cancellation_entry, 'cancel_reason' => $s->cancel_reason,
                'receivable' => $allocation?->receivable === null ? null : (string) $allocation->receivable, 'payable' => $allocation?->payable === null ? null : (string) $allocation->payable,
                'lock_version' => (int) $s->lock_version,
                'actions' => $s->status === 'POSTED' && $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_REVERSE, (int) $s->unit_id) ? ['cancel'] : []];
        });
    }

    // ---- bank statements / reconciliations --------------------------------------------------------------------------------

    public function statements(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            [$page, $per] = $this->paging($in);
            $query = $this->rt->db->table('bank_statements as s')->join('accounts as a', 'a.id', '=', 's.account_id')->whereIn('a.unit_id', $this->units($actor, $in, [FinanceCatalog::PERMISSION_VIEW]));
            if (isset($in['account']) && $in['account'] !== '') {
                $query->where('a.public_id', is_string($in['account']) ? $in['account'] : '');
            }
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('s.ends_on')->orderByDesc('s.id')->forPage($page, $per)->get(['s.*', 'a.public_id as account', 'a.name as account_name', 'a.unit_id']);
            return ['items' => $rows->map(fn ($s) => $this->statementItem($s))->all(), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function statement(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $s = preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('bank_statements as s')->join('accounts as a', 'a.id', '=', 's.account_id')
                ->where('s.public_id', $publicId)->first(['s.*', 'a.public_id as account', 'a.name as account_name', 'a.unit_id']) : null;
            if ($s === null || !$this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_VIEW, (int) $s->unit_id)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'bank_statements']);
            }
            $document = $this->rt->db->table('document_versions')->where('file_id', $s->file_id)->orderByDesc('version')->value('document_id');
            return $this->statementItem($s) + ['lines' => $this->statementLines((int) $s->id, null), 'document' => $this->rt->documentProjection($actor, $document === null ? null : (int) $document),
                'source_hash' => bin2hex((string) $s->source_hash)];
        });
    }

    public function reconciliations(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            [$page, $per] = $this->paging($in);
            $query = $this->reconciliationQuery()->whereIn('a.unit_id', $this->units($actor, $in, [FinanceCatalog::PERMISSION_VIEW]));
            if (isset($in['account']) && $in['account'] !== '') {
                $query->where('a.public_id', is_string($in['account']) ? $in['account'] : '');
            }
            if (isset($in['period']) && $in['period'] !== '') {
                $query->where('p.code', is_string($in['period']) ? $in['period'] : '');
            }
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('p.starts_on')->orderByDesc('r.version')->forPage($page, $per)->get();
            return ['items' => $rows->map(fn ($r) => $this->reconciliationItem($r))->all(), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    /**
     * Reconciliation workspace: statement lines and the account's POSTED ledger bank lines dated up to the period end,
     * each with its derived state; the ledger candidates are bounded (lines not fully matched, newest first, max 300,
     * plus every line matched in this reconciliation).
     */
    public function reconciliation(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(FinanceCatalog::PERMISSION_VIEW);
            $r = preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->reconciliationQuery()->where('r.public_id', $publicId)->first() : null;
            if ($r === null || !$this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_VIEW, (int) $r->unit_id)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'reconciliations']);
            }
            $statementLines = $this->statementLines((int) $r->statement_id, (int) $r->id);
            $matchedHere = $this->rt->db->table('reconciliation_matches')->where('reconciliation_id', $r->id)->pluck('journal_line_id')->all();
            $ledger = $this->rt->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('l.financial_account_id', $r->account_id)
                ->where('e.status', FinanceCatalog::POSTED)->where('e.entry_date', '<=', $r->ends_on)
                ->selectRaw('l.id, l.line_number, l.debit, l.credit, e.public_id, e.entry_date, e.description, e.entry_kind, (SELECT COALESCE(SUM(m.matched_amount), 0) FROM reconciliation_matches m WHERE m.journal_line_id = l.id) AS matched')
                ->where(fn ($q) => $q->whereRaw('(SELECT COALESCE(SUM(m.matched_amount), 0) FROM reconciliation_matches m WHERE m.journal_line_id = l.id) < GREATEST(l.debit, l.credit)')->orWhereIn('l.id', $matchedHere === [] ? [0] : $matchedHere))
                ->orderByDesc('e.entry_date')->orderByDesc('l.id')->limit(300)->get();
            $ledgerItems = $ledger->map(function ($l) use ($r) {
                $amount = max(Money::fromDecimal((string) $l->debit), Money::fromDecimal((string) $l->credit));
                $matched = Money::fromDecimal((string) $l->matched);
                $here = $this->rt->db->table('reconciliation_matches')->where('reconciliation_id', $r->id)->where('journal_line_id', $l->id)->sum('matched_amount');
                return ['entry' => (string) $l->public_id, 'entry_line' => (int) $l->line_number, 'entry_date' => (string) $l->entry_date, 'kind' => (string) $l->entry_kind,
                    'description' => (string) $l->description, 'direction' => Money::fromDecimal((string) $l->debit) > 0 ? 'IN' : 'OUT', 'amount' => Money::format($amount),
                    'matched_total' => Money::format($matched), 'matched_here' => Money::format(Money::fromDecimal((string) $here)), 'state' => self::state($matched, $amount)];
            })->all();
            $matches = $this->rt->db->table('reconciliation_matches as m')->join('bank_statement_lines as s', 's.id', '=', 'm.statement_line_id')->join('journal_lines as l', 'l.id', '=', 'm.journal_line_id')
                ->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('m.reconciliation_id', $r->id)->orderBy('m.id')
                ->get(['s.line_number', 'e.public_id', 'l.line_number as entry_line', 'm.matched_amount'])
                ->map(fn ($m) => ['statement_line' => (int) $m->line_number, 'entry' => (string) $m->public_id, 'entry_line' => (int) $m->entry_line, 'amount' => Money::format(Money::fromDecimal((string) $m->matched_amount))])->all();
            $counts = array_count_values(array_column($statementLines, 'state')) + array_fill_keys(FinanceCatalog::MATCH_STATES, 0);
            $ledgerBalance = (new LedgerQueries($this->rt->db))->financialAccountBalance((int) $r->account_id, (string) $r->ends_on);
            $closing = Money::fromDecimal((string) $r->closing_balance);
            $canReconcile = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_RECONCILE, (int) $r->unit_id);
            return $this->reconciliationItem($r) + ['lock_version' => (int) $r->lock_version, 'statement_lines' => $statementLines, 'ledger_lines' => $ledgerItems, 'matches' => $matches,
                'summary' => ['statement_lines' => count($statementLines), 'matched' => $counts['MATCHED'], 'partially_matched' => $counts['PARTIALLY_MATCHED'], 'unmatched' => $counts['UNMATCHED'],
                    'statement_closing_balance' => Money::format($closing), 'ledger_balance_at_period_end' => Money::format($ledgerBalance), 'difference' => Money::format($closing - $ledgerBalance)],
                'actions' => $r->status === 'OPEN' && $canReconcile ? ['match', 'unmatch', 'close'] : []];
        });
    }

    // ---- budgets ---------------------------------------------------------------------------------------------------------

    private const BUDGET_READ = [FinanceCatalog::PERMISSION_VIEW, FinanceCatalog::PERMISSION_BUDGET_MANAGE, FinanceCatalog::PERMISSION_BUDGET_APPROVE];

    public function budgets(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $this->requiresAny($actor, self::BUDGET_READ);
            [$page, $per] = $this->paging($in);
            $query = $this->budgetQuery()->whereIn('b.unit_id', $this->units($actor, $in, self::BUDGET_READ));
            if (isset($in['year']) && $in['year'] !== '') {
                $query->where('p.code', (string) $in['year']);
            }
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('p.code')->orderBy('u.name')->orderByDesc('b.version')->forPage($page, $per)->get();
            return ['items' => $rows->map(fn ($b) => $this->budgetItem($b, $actor))->all(), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function budget(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $this->requiresAny($actor, self::BUDGET_READ);
            $b = $this->budgetRow($actor, $publicId);
            $lines = $this->rt->db->table('budget_lines as l')->join('financial_categories as c', 'c.id', '=', 'l.category_id')->where('l.budget_id', $b->id)->orderBy('c.id')
                ->get(['c.code', 'c.name', 'c.economic_nature', 'l.requested_amount', 'l.approved_amount'])
                ->map(fn ($l) => ['category' => ['code' => (string) $l->code, 'label' => (string) $l->name, 'nature' => (string) $l->economic_nature],
                    'requested_amount' => Money::format(Money::fromDecimal((string) $l->requested_amount)), 'approved_amount' => Money::format(Money::fromDecimal((string) $l->approved_amount))])->all();
            $versions = $this->rt->db->table('budgets')->where('unit_id', $b->unit_id)->where('period_id', $b->period_id)->where('fund_id', $b->fund_id)->orderBy('version')
                ->get(['public_id', 'version', 'status'])->map(fn ($v) => ['public_id' => (string) $v->public_id, 'version' => (int) $v->version, 'status' => (string) $v->status])->all();
            return $this->budgetItem($b, $actor) + ['lines' => $lines, 'versions' => $versions, 'lock_version' => (int) $b->lock_version,
                'submitted_by_me' => (int) $b->submitted_by === $actor->user, 'actions' => $this->budgetActions($actor, $b)];
        });
    }

    /**
     * D13 actual vs budget foundation (the final report is F1D): per rubric of the budget (and every rubric with actuals
     * in the year), budget = approved amount, actual = POSTED journal lines of the unit / fund / rubric from 1 January to
     * $to (YTD, accrual), variance = actual - budget, variance % = variance / budget (NULL when the budget is 0).
     */
    public function actualVsBudget(int $user, int $session, string $publicId, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($publicId, $in): array {
            $this->requiresAny($actor, self::BUDGET_READ);
            $b = $this->budgetRow($actor, $publicId);
            $to = $in['to'] ?? min($this->rt->today(), (string) $b->ends_on);
            if (!is_string($to) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $to)?->format('Y-m-d') !== $to || $to < $b->starts_on || $to > $b->ends_on) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'to']);
            }
            $budget = [];
            foreach ($this->rt->db->table('budget_lines')->where('budget_id', $b->id)->get(['category_id', 'approved_amount']) as $line) {
                $budget[(int) $line->category_id] = Money::fromDecimal((string) $line->approved_amount);
            }
            $actual = [];
            foreach (self::actuals($this->rt->db, (int) $b->unit_id, (int) $b->fund_id, (string) $b->starts_on, $to) as $category => $net) {
                $actual[$category] = $net;
            }
            $ids = array_values(array_unique([...array_keys($budget), ...array_keys($actual)]));
            $categories = $ids === [] ? collect() : $this->rt->db->table('financial_categories')->whereIn('id', $ids)->orderBy('id')->get(['id', 'code', 'name', 'economic_nature']);
            $rows = [];
            $totals = ['REVENUE' => [0, 0], 'COST' => [0, 0]];
            foreach ($categories as $c) {
                $plan = $budget[(int) $c->id] ?? 0;
                $real = $actual[(int) $c->id] ?? 0;
                $side = in_array($c->economic_nature, FinanceCatalog::REVENUE_NATURES, true) ? 'REVENUE' : 'COST';
                $totals[$side][0] += $plan;
                $totals[$side][1] += $real;
                $rows[] = ['category' => ['code' => (string) $c->code, 'label' => (string) $c->name, 'nature' => (string) $c->economic_nature], 'side' => $side, 'budgeted' => isset($budget[(int) $c->id]),
                    'budget_amount' => Money::format($plan), 'actual_amount' => Money::format($real), 'variance' => Money::format($real - $plan), 'variance_percent' => self::percent($real - $plan, $plan)];
            }
            return ['budget' => $this->budgetItem($b, $actor), 'interval' => ['from' => (string) $b->starts_on, 'to' => $to], 'fund' => (string) $b->fund_code, 'source' => 'POSTED_JOURNAL_LINES',
                'basis' => 'APPROVED_AMOUNT', 'rows' => $rows, 'totals' => array_map(fn ($t) => ['budget_amount' => Money::format($t[0]), 'actual_amount' => Money::format($t[1]),
                    'variance' => Money::format($t[1] - $t[0]), 'variance_percent' => self::percent($t[1] - $t[0], $t[0])], $totals)];
        });
    }

    /**
     * Actual per rubric from POSTED journal lines only: revenue natures = credits - debits, every other budgetable nature
     * = debits - credits (FIXED_ASSETS acquisitions count for their investment rubric). @return array<int, int>
     */
    public static function actuals(\Illuminate\Database\Connection $db, int $unit, int $fund, string $from, string $to): array
    {
        $out = [];
        $rows = $db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->join('financial_categories as c', 'c.id', '=', 'l.category_id')
            ->where('e.status', FinanceCatalog::POSTED)->where('l.unit_id', $unit)->where('l.fund_id', $fund)->whereBetween('e.entry_date', [$from, $to])
            ->whereIn('c.economic_nature', FinanceCatalog::BUDGET_NATURES)->groupBy('c.id', 'c.economic_nature')->selectRaw('c.id, c.economic_nature, SUM(l.debit) AS d, SUM(l.credit) AS c')->get();
        foreach ($rows as $row) {
            $net = Money::fromDecimal((string) $row->d) - Money::fromDecimal((string) $row->c);
            $out[(int) $row->id] = in_array($row->economic_nature, FinanceCatalog::REVENUE_NATURES, true) ? -$net : $net;
        }
        return $out;
    }

    // ---- periods ------------------------------------------------------------------------------------------------------------

    private const PERIOD_READ = [FinanceCatalog::PERMISSION_VIEW, FinanceCatalog::PERMISSION_PERIOD_CLOSE, FinanceCatalog::PERMISSION_PERIOD_REOPEN];

    /** Months of a year with the national status and the unit's close row (closed / reopened, by me or not). */
    public function periods(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $this->requiresAny($actor, self::PERIOD_READ);
            $unit = $this->unitIn($actor, $in['unit'] ?? null, self::PERIOD_READ);
            $year = is_string($in['year'] ?? null) && preg_match('/^\d{4}$/D', $in['year']) === 1 ? $in['year'] : substr($this->rt->today(), 0, 4);
            $closes = $this->rt->db->table('accounting_period_unit_closes')->where('unit_id', $unit)->get()->keyBy('period_id');
            $canClose = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_PERIOD_CLOSE, $unit);
            $canReopen = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_PERIOD_REOPEN, $unit);
            try {
                $root = PeriodCloseService::nationalRoot($this->rt->db);
                $national = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_PERIOD_CLOSE, $root);
            } catch (FinanceError) {
                $national = false;
            }
            $items = [];
            foreach ($this->rt->db->table('accounting_periods')->where('period_kind', FinanceCatalog::PERIOD_MONTH)->where('code', 'like', $year . '-%')->orderBy('code')->get() as $p) {
                $close = $closes[$p->id] ?? null;
                $unitStatus = $p->status === FinanceCatalog::PERIOD_CLOSED ? 'NATIONALLY_CLOSED' : ($close === null ? 'OPEN' : (string) $close->status);
                $pending = (int) $this->rt->db->table('journal_entries')->where('period_id', $p->id)->where('unit_id', $unit)->whereIn('status', [FinanceCatalog::DRAFT, FinanceCatalog::SUBMITTED])->count();
                $items[] = ['code' => (string) $p->code, 'starts_on' => (string) $p->starts_on, 'ends_on' => (string) $p->ends_on, 'national_status' => (string) $p->status,
                    'national_closed_at' => $p->closed_at === null ? null : (string) $p->closed_at, 'unit_status' => $unitStatus,
                    'closed_at' => $close?->closed_at === null ? null : (string) $close->closed_at, 'closed_by_me' => $close !== null && (int) $close->closed_by === $actor->user,
                    'reopened_at' => $close?->reopened_at === null ? null : (string) $close->reopened_at, 'reopen_reason' => $close?->reason, 'pending_entries' => $pending,
                    'actions' => array_values(array_filter([
                        $p->status === 'OPEN' && $canClose && ($close === null || $close->status === 'REOPENED') ? 'close' : null,
                        $p->status === 'OPEN' && $canReopen && $close !== null && $close->status === 'CLOSED' && (int) $close->closed_by !== $actor->user ? 'reopen' : null,
                        $p->status === 'OPEN' && $national ? 'national_close' : null,
                    ]))];
            }
            $u = $this->rt->db->table('organizational_units')->where('id', $unit)->first(['public_id', 'name']);
            return ['unit' => ['public_id' => (string) $u->public_id, 'name' => (string) $u->name], 'year' => $year, 'national_closer' => $national, 'items' => $items];
        });
    }

    // ---- projections / helpers ----------------------------------------------------------------------------------------------

    public static function state(int $matched, int $amount): string
    {
        return $matched <= 0 ? 'UNMATCHED' : ($matched >= $amount ? 'MATCHED' : 'PARTIALLY_MATCHED');
    }

    /** variance / budget as a percentage with 2 decimals (half away from zero), integer arithmetic; NULL when budget = 0. */
    public static function percent(int $variance, int $budget): ?string
    {
        if ($budget === 0) {
            return null;
        }
        $scaled = intdiv(abs($variance) * 10000 * 2 + abs($budget), 2 * abs($budget));
        $negative = ($variance < 0) !== ($budget < 0) && $scaled !== 0;
        return ($negative ? '-' : '') . intdiv($scaled, 100) . '.' . str_pad((string) ($scaled % 100), 2, '0', STR_PAD_LEFT);
    }

    private function accountItem(object $a): array
    {
        return ['public_id' => (string) $a->public_id, 'unit' => ['public_id' => (string) $a->unit_public, 'name' => (string) $a->unit_name], 'code' => (string) $a->code, 'name' => (string) $a->name,
            'kind' => (string) $a->account_kind, 'status' => (string) $a->status, 'opened_on' => (string) $a->opened_on, 'closed_on' => $a->closed_on === null ? null : (string) $a->closed_on,
            'currency' => FinanceCatalog::CURRENCY];
    }

    private function subledgerQuery(string $kind): Builder
    {
        return $this->rt->db->table($kind . ' as d')->join('organizational_units as u', 'u.id', '=', 'd.unit_id')->join('journal_entries as e', 'e.id', '=', 'd.recognition_entry_id')
            ->join('financial_parties as fp', 'fp.id', '=', 'd.party_id')->leftJoin('people as pe', 'pe.id', '=', 'fp.person_id')
            ->leftJoin('journal_lines as rl', fn ($j) => $j->on('rl.entry_id', '=', 'e.id')->whereNotNull('rl.category_id'))->leftJoin('financial_categories as c', 'c.id', '=', 'rl.category_id')
            ->leftJoin('chart_of_accounts as ca', 'ca.id', '=', 'rl.ledger_account_id')
            ->select(['d.*', 'u.public_id as unit_public', 'u.name as unit_name', 'e.public_id as entry_public', 'e.entry_date', 'e.description', 'fp.party_kind', 'fp.external_name', 'fp.person_id',
                'pe.public_id as person_public', 'c.code as category_code', 'c.name as category_label', 'c.economic_nature', 'ca.system_role as economic_role'])
            ->selectRaw("(SELECT COALESCE(SUM(a.amount), 0) FROM settlement_allocations a JOIN settlements s ON s.id = a.settlement_id WHERE a." . ($kind === 'receivables' ? 'receivable_id' : 'payable_id') . " = d.id AND s.status = 'POSTED') AS settled");
    }

    private function subledgerItem(string $kind, object $r, TerritorialActor $actor): array
    {
        $amount = Money::fromDecimal((string) $r->amount);
        $settled = Money::fromDecimal((string) $r->settled);
        $party = ['kind' => (string) $r->party_kind, 'name' => $r->party_kind === 'EXTERNAL' ? (string) $r->external_name : null,
            'person' => $r->party_kind === 'PERSON' && $r->person_id !== null && $this->rt->canSeePerson($actor, (int) $r->person_id) ? (string) $r->person_public : null];
        return ['public_id' => (string) $r->public_id, 'kind' => $kind === 'receivables' ? 'RECEIVABLE' : 'PAYABLE', 'unit' => ['public_id' => (string) $r->unit_public, 'name' => (string) $r->unit_name],
            'status' => (string) $r->status, 'amount' => Money::format($amount), 'settled' => Money::format($settled), 'outstanding' => Money::format($r->status === 'CANCELLED' ? 0 : $amount - $settled),
            'currency' => FinanceCatalog::CURRENCY, 'due_on' => (string) $r->due_on, 'recognized_on' => (string) $r->entry_date, 'description' => (string) $r->description,
            'category' => $r->category_code === null ? null : ['code' => (string) $r->category_code, 'label' => (string) $r->category_label, 'nature' => (string) $r->economic_nature],
            'economic_account' => $r->economic_role, 'capitalized' => $r->economic_role === 'FIXED_ASSETS', 'party' => $party, 'recognition_entry' => (string) $r->entry_public,
            'created_at' => (string) $r->created_at];
    }

    private function subledgerActions(TerritorialActor $actor, object $r, int $outstanding): array
    {
        $unit = (int) $r->unit_id;
        $post = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_MANAGE, $unit) && $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_POST, $unit);
        $reverse = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_REVERSE, $unit);
        return array_values(array_filter([
            $r->status === 'RECOGNIZED' && $outstanding > 0 && $post ? 'settle' : null,
            $r->status === 'RECOGNIZED' && Money::fromDecimal((string) $r->settled) === 0 && $reverse ? 'cancel' : null,
        ]));
    }

    private function statementItem(object $s): array
    {
        return ['public_id' => (string) $s->public_id, 'account' => ['public_id' => (string) $s->account, 'name' => (string) $s->account_name], 'starts_on' => (string) $s->starts_on,
            'ends_on' => (string) $s->ends_on, 'opening_balance' => Money::format(Money::fromDecimal((string) $s->opening_balance)),
            'closing_balance' => Money::format(Money::fromDecimal((string) $s->closing_balance)), 'lines_count' => (int) $this->rt->db->table('bank_statement_lines')->where('statement_id', $s->id)->count(),
            'created_at' => (string) $s->created_at, 'origin' => 'EXTERNAL_BANK_FACT'];
    }

    /** Statement lines with their derived state (Σ over EVERY reconciliation) and, for a workspace, the part matched here. */
    private function statementLines(int $statementId, ?int $reconciliationId): array
    {
        return $this->rt->db->table('bank_statement_lines as s')->where('s.statement_id', $statementId)->orderBy('s.line_number')
            ->selectRaw('s.*, (SELECT COALESCE(SUM(m.matched_amount), 0) FROM reconciliation_matches m WHERE m.statement_line_id = s.id) AS matched'
                . ($reconciliationId === null ? '' : ', (SELECT COALESCE(SUM(m2.matched_amount), 0) FROM reconciliation_matches m2 WHERE m2.statement_line_id = s.id AND m2.reconciliation_id = ' . $reconciliationId . ') AS matched_here'))
            ->get()->map(function ($l) use ($reconciliationId) {
                $amount = Money::fromDecimal((string) $l->amount_signed);
                $matched = Money::fromDecimal((string) $l->matched);
                $item = ['line_number' => (int) $l->line_number, 'occurred_on' => (string) $l->occurred_on, 'amount' => Money::format($amount), 'direction' => $amount > 0 ? 'IN' : 'OUT',
                    'description' => (string) $l->description, 'reference' => $l->external_reference, 'matched_total' => Money::format($matched), 'state' => self::state($matched, abs($amount))];
                if ($reconciliationId !== null) {
                    $item['matched_here'] = Money::format(Money::fromDecimal((string) $l->matched_here));
                }
                return $item;
            })->all();
    }

    private function reconciliationQuery(): Builder
    {
        return $this->rt->db->table('reconciliations as r')->join('accounts as a', 'a.id', '=', 'r.account_id')->join('accounting_periods as p', 'p.id', '=', 'r.period_id')
            ->leftJoin('bank_statements as s', 's.id', '=', 'r.statement_id')
            ->select(['r.*', 'a.unit_id', 'a.public_id as account', 'a.name as account_name', 'p.code as period_code', 'p.starts_on', 'p.ends_on', 's.public_id as statement', 's.closing_balance']);
    }

    private function reconciliationItem(object $r): array
    {
        return ['public_id' => (string) $r->public_id, 'account' => ['public_id' => (string) $r->account, 'name' => (string) $r->account_name], 'period' => (string) $r->period_code,
            'version' => (int) $r->version, 'status' => (string) $r->status, 'statement' => $r->statement === null ? null : (string) $r->statement,
            'closed_at' => $r->closed_at === null ? null : (string) $r->closed_at, 'created_at' => (string) $r->created_at];
    }

    private function budgetQuery(): Builder
    {
        return $this->rt->db->table('budgets as b')->join('organizational_units as u', 'u.id', '=', 'b.unit_id')->join('accounting_periods as p', 'p.id', '=', 'b.period_id')
            ->join('funds as f', 'f.id', '=', 'b.fund_id')
            ->select(['b.*', 'u.public_id as unit_public', 'u.name as unit_name', 'p.code as year', 'p.starts_on', 'p.ends_on', 'f.code as fund_code'])
            ->selectRaw('(SELECT COALESCE(SUM(l.requested_amount), 0) FROM budget_lines l WHERE l.budget_id = b.id) AS requested_total, (SELECT COALESCE(SUM(l.approved_amount), 0) FROM budget_lines l WHERE l.budget_id = b.id) AS approved_total');
    }

    private function budgetRow(TerritorialActor $actor, string $publicId): object
    {
        $b = preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->budgetQuery()->where('b.public_id', $publicId)->first() : null;
        if ($b === null || !$this->holdsAnyOn($actor, self::BUDGET_READ, (int) $b->unit_id)) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'budgets']);
        }
        return $b;
    }

    private function budgetItem(object $b, TerritorialActor $actor): array
    {
        $year = (string) $b->year;
        return ['public_id' => (string) $b->public_id, 'unit' => ['public_id' => (string) $b->unit_public, 'name' => (string) $b->unit_name], 'year' => $year, 'fund' => (string) $b->fund_code,
            'version' => (int) $b->version, 'status' => (string) $b->status, 'in_execution' => $b->status === 'APPROVED' && $year === substr($this->rt->today(), 0, 4),
            'requested_total' => Money::format(Money::fromDecimal((string) $b->requested_total)), 'approved_total' => Money::format(Money::fromDecimal((string) $b->approved_total)),
            'approved_at' => $b->approved_at === null ? null : (string) $b->approved_at, 'created_at' => (string) $b->created_at];
    }

    private function budgetActions(TerritorialActor $actor, object $b): array
    {
        $manage = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_BUDGET_MANAGE, (int) $b->unit_id);
        $approve = $this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_BUDGET_APPROVE, (int) $b->unit_id);
        $mine = (int) $b->submitted_by === $actor->user;
        return array_values(array_filter([
            $b->status === 'DRAFT' && $manage ? 'edit_lines' : null,
            $b->status === 'DRAFT' && $manage ? 'submit' : null,
            $b->status === 'DRAFT' && $manage ? 'cancel' : null,
            $b->status === 'SUBMITTED' && $approve ? 'review' : null,
            in_array($b->status, ['SUBMITTED', 'REVIEWED'], true) && $approve ? 'return' : null,
            $b->status === 'REVIEWED' && $approve && !$mine ? 'approve' : null,
            $b->status === 'APPROVED' && $manage ? 'revise' : null,
        ]));
    }

    /** Row of $table by public id whose owner unit ($column) the actor covers with one of $permissions. */
    private function owned(string $table, string $publicId, string $column, TerritorialActor $actor, array $permissions): object
    {
        $row = preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table($table)->where('public_id', $publicId)->first() : null;
        if ($row === null || !$this->holdsAnyOn($actor, $permissions, (int) $row->{$column})) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => $table]);
        }
        return $row;
    }

    /** Covered units of any of $permissions, optionally narrowed to one requested unit (must be covered). @return list<int> */
    private function units(TerritorialActor $actor, array $in, array $permissions): array
    {
        $covered = [];
        foreach ($permissions as $permission) {
            $covered += $this->rt->authority->covered($actor, $permission);
        }
        if (isset($in['unit']) && $in['unit'] !== '') {
            return [$this->unitIn($actor, $in['unit'], $permissions)];
        }
        return array_keys($covered) === [] ? [0] : array_keys($covered);
    }

    private function unitIn(TerritorialActor $actor, mixed $publicId, array $permissions): int
    {
        $id = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('organizational_units')->where('public_id', $publicId)->value('id') : null;
        if ($id === null || !$this->holdsAnyOn($actor, $permissions, (int) $id)) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);
        }
        return (int) $id;
    }

    private function holdsAnyOn(TerritorialActor $actor, array $permissions, int $unit): bool
    {
        foreach ($permissions as $permission) {
            if ($this->rt->authority->holdsOn($actor, $permission, $unit)) {
                return true;
            }
        }
        return false;
    }

    private function requiresAny(TerritorialActor $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($this->rt->authority->holdsAnywhere($actor, $permission)) {
                return;
            }
        }
        throw new FinanceError('NOT_AUTHORIZED', [], ['permissions' => $permissions]);
    }

    private function paging(array $in): array
    {
        return [max(1, (int) ($in['page'] ?? 1)), $this->rt->perPage($in['per_page'] ?? null)];
    }

    private function enum(mixed $value, array $allowed, string $field): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => $field]);
        }
    }

    private function kind(string $kind): void
    {
        if (!in_array($kind, ['receivables', 'payables'], true)) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'kind']);
        }
    }
}
