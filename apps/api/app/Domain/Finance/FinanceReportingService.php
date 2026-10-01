<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Finance V1 read models. Every number is derived from POSTED journal lines/subledgers in one repeatable-read snapshot;
 * no dashboard, mutable balance or persisted report is a source (ADR 0021 D15/D16/D21/D30 and D-04A).
 */
final class FinanceReportingService
{
    public const REPORTS = [
        'OWN_DRE' => 'DRE própria',
        'CONSOLIDATED_DRE' => 'DRE consolidada',
        'OWN_DOAF' => 'Demonstrativo de Origem e Aplicação de Fundos próprio',
        'CONSOLIDATED_DOAF' => 'Demonstrativo de Origem e Aplicação de Fundos consolidado',
        'REVENUE_SUMMARY' => 'Resumo de receitas',
        'EXPENSE_SUMMARY' => 'Resumo de despesas',
        'INTERNAL_FUNDS_RECEIVED' => 'Fundos internos recebidos',
        'INTERNAL_FUNDS_SENT' => 'Fundos internos enviados',
        'INTERUNIT_RECONCILIATION' => 'Reconciliação interunidades',
        'INTERUNIT_POSITION' => 'Posição interunidades e fundos em trânsito',
        'FINANCIAL_ACCOUNT_MOVEMENTS' => 'Movimentos por conta financeira',
        'CASH_BANK_BALANCES' => 'Saldos de Caixa e Banco',
        'BUDGET_VS_ACTUAL' => 'Orçamento versus realizado',
        'RECEIVABLES' => 'Valores a receber em aberto',
        'PAYABLES' => 'Valores a pagar em aberto',
        'FINANCE_PERIOD_SUMMARY' => 'Resumo Finance do período e fecho',
    ];

    private const TRANSFER_KINDS = ['TRANSFER_SEND', 'TRANSFER_RECEIVE', 'TRANSFER_REVERSE_SEND'];

    public function __construct(private FinanceRuntime $rt)
    {
    }

    public function catalog(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard): array {
            $guard->requires(FinanceCatalog::PERMISSION_REPORT);
            return ['reports' => array_map(static fn (string $code, string $name): array => ['code' => $code, 'name' => $name], array_keys(self::REPORTS), self::REPORTS),
                'period_kinds' => ['MONTH', 'QUARTER', 'SEMESTER', 'YEAR', 'RANGE'], 'views' => ['OWN', 'CONSOLIDATED']];
        });
    }

    public function report(int $user, int $session, string $type, array $in, string $auditAction = 'finance.report.generated'): array
    {
        $type = strtoupper($type);
        if (!isset(self::REPORTS[$type])) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'finance_reports']);
        }
        return $this->rt->snapshot($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($type, $in, $auditAction): array {
            [$unit, $unitMeta] = $this->unit($in['unit'] ?? null);
            [$from, $to, $kind, $period] = $this->period($in);
            $forcedConsolidated = str_starts_with($type, 'CONSOLIDATED_');
            $view = $forcedConsolidated ? 'CONSOLIDATED' : strtoupper((string) ($in['view'] ?? 'OWN'));
            if (!in_array($view, ['OWN', 'CONSOLIDATED'], true)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'view']);
            }
            $guard->unit(FinanceCatalog::PERMISSION_REPORT, $unit);
            $units = [$unit];
            if ($view === 'CONSOLIDATED') {
                $guard->unit(FinanceCatalog::PERMISSION_CONSOLIDATED_VIEW, $unit);
                $current = FinanceQueryService::perimeter($this->rt->db, $unit);
                $covered = $this->rt->authority->covered($actor, FinanceCatalog::PERMISSION_CONSOLIDATED_VIEW);
                if (array_diff($current, array_keys($covered)) !== []) {
                    throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);
                }
                $units = (new ReportPerimeterResolver($this->rt->db))->resolve($unit, $to);
            }
            $header = $this->header($type, $view, $unitMeta, $from, $to, $kind, $period, count($units));
            $body = $this->body($type, $units, $unit, $from, $to, $view, $actor, $in);
            FinanceAudit::write($this->rt->db, $actor->user, $auditAction, 'finance_reports', $unit, $unit, FinanceAudit::correlation(), [
                'report_type' => $type, 'view' => $view, 'period_from' => $from, 'period_to' => $to, 'parameters_hash' => $header['parameters_hash'],
            ], null, $actor->session);
            return $header + $body;
        });
    }

    public function dashboard(int $user, int $session, array $in): array
    {
        $in['view'] = strtoupper((string) ($in['view'] ?? 'OWN'));
        return $this->report($user, $session, 'FINANCE_PERIOD_SUMMARY', $in, 'finance.dashboard.viewed');
    }

    private function body(string $type, array $units, int $root, string $from, string $to, string $view, TerritorialActor $actor, array $in): array
    {
        return match ($type) {
            'OWN_DRE', 'CONSOLIDATED_DRE' => ['dre' => $this->dre($units, $from, $to)],
            'OWN_DOAF', 'CONSOLIDATED_DOAF' => ['doaf' => $this->doaf($units, $from, $to, $view)],
            'REVENUE_SUMMARY' => ['summary' => $this->dre($units, $from, $to)['revenue_lines']],
            'EXPENSE_SUMMARY' => ['summary' => $this->dre($units, $from, $to)['expense_lines']],
            'INTERNAL_FUNDS_RECEIVED' => ['transfers' => $this->transfers($units, $from, $to, 'RECEIVE', $view)],
            'INTERNAL_FUNDS_SENT' => ['transfers' => $this->transfers($units, $from, $to, 'SEND', $view)],
            'INTERUNIT_RECONCILIATION' => ['reconciliation' => $this->reconciliation($units, $to)],
            'INTERUNIT_POSITION' => ['position' => $this->interunit($units, $to)],
            'FINANCIAL_ACCOUNT_MOVEMENTS' => ['movements' => $this->movements($units, $from, $to, (int) ($in['per_page'] ?? 100))],
            'CASH_BANK_BALANCES' => ['treasury' => $this->treasury($units, $from, $to)],
            'BUDGET_VS_ACTUAL' => ['budget_vs_actual' => $this->budget($units, $from, $to)],
            'RECEIVABLES' => ['receivables' => $this->subledger('receivables', $units, $to)],
            'PAYABLES' => ['payables' => $this->subledger('payables', $units, $to)],
            'FINANCE_PERIOD_SUMMARY' => $this->summary($units, $root, $from, $to, $view, $actor),
        };
    }

    private function dre(array $units, string $from, string $to): array
    {
        $rows = $this->posted($units, $from, $to)->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->leftJoin('financial_categories as c', 'c.id', '=', 'l.category_id')->whereIn('a.account_kind', [FinanceCatalog::INCOME, FinanceCatalog::EXPENSE])
            ->groupBy('a.account_kind', 'a.system_role', 'c.code', 'c.name', 'c.economic_nature')
            ->orderBy('c.code')->selectRaw('a.account_kind AS class, a.system_role AS role, c.code, c.name, c.economic_nature, SUM(l.debit) AS d, SUM(l.credit) AS c')->get();
        $revenue = $expense = $investments = $returns = 0;
        $revenueLines = $expenseLines = [];
        foreach ($rows as $r) {
            $net = Money::fromDecimal((string) $r->d) - Money::fromDecimal((string) $r->c);
            $amount = $r->class === FinanceCatalog::INCOME ? -$net : $net;
            $line = ['category' => ['code' => (string) ($r->code ?? $r->role), 'label' => (string) ($r->name ?? $r->role), 'nature' => (string) ($r->economic_nature ?? '')], 'amount' => Money::format($amount)];
            if ($r->class === FinanceCatalog::INCOME) {
                $revenue += $amount; $revenueLines[] = $line;
                if ($r->code === 'REV_INVESTMENT_RETURN') { $returns += $amount; }
            } else {
                $expense += $amount; $expenseLines[] = $line;
                if ($r->role === 'INVESTMENT_EXPENSE') { $investments += $amount; }
            }
        }
        $capitalized = $this->roleAmount($units, $from, $to, 'FIXED_ASSETS');
        return ['basis' => 'ACCRUAL_POSTED_LEDGER', 'revenue' => Money::format($revenue), 'expenses' => Money::format($expense), 'economic_result' => Money::format($revenue - $expense),
            'investments_consumed' => Money::format($investments), 'investments_capitalized' => Money::format($capitalized), 'investment_returns' => Money::format($returns),
            'revenue_lines' => $revenueLines, 'expense_lines' => $expenseLines, 'internal_transfer_effect' => '0.00'];
    }

    private function doaf(array $units, string $from, string $to, string $view): array
    {
        $openingTreasury = $this->treasuryNet($units, null, (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'));
        $closingTreasury = $this->treasuryNet($units, null, $to);
        $base = fn (): Builder => $this->posted($units, $from, $to)->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->whereIn('a.system_role', FinanceCatalog::TREASURY_ROLES);
        $externalIn = $this->sum($base()->whereNotIn('e.entry_kind', [...self::TRANSFER_KINDS, 'ACCOUNT_TRANSFER']), 'l.debit');
        $externalOut = $this->sum($base()->whereNotIn('e.entry_kind', [...self::TRANSFER_KINDS, 'ACCOUNT_TRANSFER']), 'l.credit');
        if ($view === 'OWN') {
            $received = $this->sum($base()->where('e.entry_kind', 'TRANSFER_RECEIVE'), 'l.debit');
            $sent = $this->sum($base()->where('e.entry_kind', 'TRANSFER_SEND'), 'l.credit') - $this->sum($base()->where('e.entry_kind', 'TRANSFER_REVERSE_SEND'), 'l.debit');
            $transitCustody = 0;
        } else {
            $received = $this->boundaryTransfer($units, $from, $to, 'RECEIVE', false);
            $sent = $this->boundaryTransfer($units, $from, $to, 'SEND', true) - $this->boundaryTransfer($units, $from, $to, 'REVERSE_SEND', true);
            $transitCustody = $this->internalTransit($units, $to);
        }
        $closing = $closingTreasury + $transitCustody;
        $origins = $openingTreasury + $externalIn + $received;
        $applications = $externalOut + $sent + $closing;
        return ['basis' => 'FUND_CUSTODY_POSTED_LEDGER', 'opening_balance' => Money::format($openingTreasury), 'external_funds_received' => Money::format($externalIn),
            'internal_funds_received' => Money::format($received), 'external_applications' => Money::format($externalOut), 'internal_funds_sent' => Money::format($sent),
            'funds_in_transit_under_custody' => Money::format($transitCustody), 'treasury_closing' => Money::format($closingTreasury), 'closing_balance' => Money::format($closing),
            'total_origins' => Money::format($origins), 'total_applications' => Money::format($applications), 'balanced' => $origins === $applications,
            'identity' => 'opening + external received + internal received - external applications - internal sent = closing custody',
            'internal_transfers_eliminated' => $view === 'CONSOLIDATED', 'memo_internal_transfers' => $view === 'CONSOLIDATED' ? $this->transfers($units, $from, $to, null, $view) : []];
    }

    private function summary(array $units, int $root, string $from, string $to, string $view, TerritorialActor $actor): array
    {
        $dre = $this->dre($units, $from, $to); $doaf = $this->doaf($units, $from, $to, $view);
        $receivable = $this->subledgerTotal('receivables', $units, $to); $payable = $this->subledgerTotal('payables', $units, $to);
        $budget = $this->budget($units, $from, $to);
        $drill = [];
        if ($view === 'CONSOLIDATED') {
            foreach ($this->rt->db->table('organizational_units')->whereIn('id', $units)->orderBy('name')->limit(100)->get(['id', 'public_id', 'name']) as $u) {
                if (!$this->rt->authority->holdsOn($actor, FinanceCatalog::PERMISSION_VIEW, (int) $u->id)) { continue; }
                $econ = (new LedgerQueries($this->rt->db))->economicResult((int) $u->id, $from, $to);
                $custody = FinanceQueryService::custodyOf($this->rt->db, (int) $u->id, $from, $to, $this->rt->today());
                $drill[] = ['unit' => ['public_id' => (string) $u->public_id, 'name' => (string) $u->name], 'own_result' => Money::format($econ['result']),
                    'funds_received' => $custody['internal_funds_received'], 'funds_sent' => $custody['internal_funds_sent'], 'closing_balance' => $custody['closing_balance']];
            }
            FinanceAudit::write($this->rt->db, $actor->user, 'finance.report.drilldown', 'organizational_units', $root, $root, FinanceAudit::correlation(), ['period_from' => $from, 'period_to' => $to, 'visible_units' => count($drill)], null, $actor->session);
        }
        return ['dashboard' => ['revenue' => $dre['revenue'], 'expenses' => $dre['expenses'], 'economic_result' => $dre['economic_result'], 'internal_received' => $doaf['internal_funds_received'],
            'internal_sent' => $doaf['internal_funds_sent'], 'in_transit' => $doaf['funds_in_transit_under_custody'], 'cash_bank_position' => $doaf['closing_balance'],
            'receivables' => Money::format($receivable), 'payables' => Money::format($payable), 'budget_execution' => $budget['totals']], 'dre' => $dre, 'doaf' => $doaf, 'drill_down' => $drill];
    }

    private function treasury(array $units, string $from, string $to): array
    {
        $rows = $this->rt->db->table('accounts as fa')->join('organizational_units as u', 'u.id', '=', 'fa.unit_id')->whereIn('fa.unit_id', $units)->orderBy('u.name')->orderBy('fa.code')->get(['fa.id', 'fa.public_id', 'fa.code', 'fa.name', 'fa.account_kind', 'u.public_id as unit_public', 'u.name as unit_name']);
        $out = [];
        foreach ($rows as $a) {
            $q = fn (): Builder => $this->rt->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('e.status', FinanceCatalog::POSTED)->where('l.financial_account_id', $a->id);
            $opening = $this->sum($q()->where('e.entry_date', '<', $from), 'l.debit') - $this->sum($q()->where('e.entry_date', '<', $from), 'l.credit');
            $in = $this->sum($q()->whereBetween('e.entry_date', [$from, $to]), 'l.debit'); $outflow = $this->sum($q()->whereBetween('e.entry_date', [$from, $to]), 'l.credit');
            $out[] = ['account' => ['public_id' => (string) $a->public_id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'kind' => (string) $a->account_kind],
                'unit' => ['public_id' => (string) $a->unit_public, 'name' => (string) $a->unit_name], 'opening' => Money::format($opening), 'inflows' => Money::format($in), 'outflows' => Money::format($outflow), 'closing' => Money::format($opening + $in - $outflow)];
        }
        return $out;
    }

    private function budget(array $units, string $from, string $to): array
    {
        $year = substr($to, 0, 4);
        $budgets = $this->rt->db->table('budgets as b')->join('accounting_periods as p', 'p.id', '=', 'b.period_id')->whereIn('b.unit_id', $units)->where('b.status', 'APPROVED')->where('p.code', $year)->pluck('b.id')->all();
        $plan = $budgets === [] ? 0 : Money::fromDecimal((string) $this->rt->db->table('budget_lines')->whereIn('budget_id', $budgets)->sum('approved_amount'));
        $actual = 0;
        foreach ($this->posted($units, max($from, $year . '-01-01'), $to)->join('financial_categories as c', 'c.id', '=', 'l.category_id')->whereIn('c.economic_nature', FinanceCatalog::BUDGET_NATURES)->selectRaw('c.economic_nature, SUM(l.debit) d, SUM(l.credit) c')->groupBy('c.economic_nature')->get() as $r) {
            $net = Money::fromDecimal((string) $r->d) - Money::fromDecimal((string) $r->c);
            $actual += in_array($r->economic_nature, FinanceCatalog::REVENUE_NATURES, true) ? -$net : $net;
        }
        return ['year' => $year, 'basis' => 'CURRENT_APPROVED_BUDGET_AND_POSTED_LEDGER', 'approved_budget' => Money::format($plan), 'actual' => Money::format($actual),
            'variance' => Money::format($actual - $plan), 'variance_percent' => $plan === 0 ? null : number_format((($actual - $plan) * 100) / $plan, 2, '.', '') . '%',
            'totals' => ['approved' => Money::format($plan), 'actual' => Money::format($actual)]];
    }

    private function subledger(string $table, array $units, string $to): array
    {
        $fk = $table === 'receivables' ? 'receivable_id' : 'payable_id';
        return $this->rt->db->table($table . ' as d')->join('organizational_units as u', 'u.id', '=', 'd.unit_id')->whereIn('d.unit_id', $units)->whereIn('d.status', ['RECOGNIZED', 'SETTLED'])->where('d.due_on', '<=', $to)
            ->select(['d.public_id', 'd.amount', 'd.due_on', 'd.status', 'u.public_id as unit_public', 'u.name as unit_name'])
            ->selectRaw("(SELECT COALESCE(SUM(sa.amount),0) FROM settlement_allocations sa JOIN settlements s ON s.id=sa.settlement_id WHERE sa.$fk=d.id AND s.status='POSTED') AS settled")
            ->orderBy('d.due_on')->limit(100)->get()->map(static function (object $r): array { $amount = Money::fromDecimal((string) $r->amount); $settled = Money::fromDecimal((string) $r->settled);
                return ['public_id' => (string) $r->public_id, 'unit' => ['public_id' => (string) $r->unit_public, 'name' => (string) $r->unit_name], 'due_on' => (string) $r->due_on, 'status' => (string) $r->status, 'amount' => Money::format($amount), 'outstanding' => Money::format($amount - $settled)]; })->all();
    }

    private function subledgerTotal(string $table, array $units, string $to): int
    {
        $fk = $table === 'receivables' ? 'receivable_id' : 'payable_id';
        $row = $this->rt->db->table($table . ' as d')->whereIn('d.unit_id', $units)->whereIn('d.status', ['RECOGNIZED', 'SETTLED'])->where('d.due_on', '<=', $to)
            ->selectRaw("COALESCE(SUM(d.amount - (SELECT COALESCE(SUM(sa.amount),0) FROM settlement_allocations sa JOIN settlements s ON s.id=sa.settlement_id WHERE sa.$fk=d.id AND s.status='POSTED')),0) AS outstanding")->first();
        return Money::fromDecimal((string) ($row->outstanding ?? '0'));
    }

    private function interunit(array $units, string $to): array
    {
        $ledger = new LedgerQueries($this->rt->db); $sent = $received = 0;
        foreach ($units as $unit) { $p = $ledger->interunitPosition($unit, $to); $sent += $p['sent']; $received += $p['received']; }
        $transit = $ledger->inTransit($to, $units);
        return ['sent' => Money::format($sent), 'received' => Money::format($received), 'in_transit' => Money::format($transit['amount']), 'net_control_position' => Money::format($received - $sent), 'account_class' => FinanceCatalog::INTERUNIT_CONTROL, 'transfers' => $transit['transfers']];
    }

    private function reconciliation(array $units, string $to): array
    {
        $rows = $this->rt->db->table('internal_transfers as t')->join('organizational_units as o', 'o.id', '=', 't.origin_unit_id')->join('organizational_units as d', 'd.id', '=', 't.destination_unit_id')
            ->where(fn ($q) => $q->whereIn('t.origin_unit_id', $units)->orWhereIn('t.destination_unit_id', $units))->whereRaw('DATE(t.created_at) <= ?', [$to])->orderByDesc('t.id')->limit(100)
            ->get(['t.public_id', 't.amount', 't.status', 't.sent_at', 't.received_at', 't.reconciled_at', 'o.public_id as origin_public', 'o.name as origin_name', 'd.public_id as destination_public', 'd.name as destination_name']);
        return $rows->map(static fn (object $r): array => ['transfer' => (string) $r->public_id, 'origin' => ['public_id' => (string) $r->origin_public, 'name' => (string) $r->origin_name], 'destination' => ['public_id' => (string) $r->destination_public, 'name' => (string) $r->destination_name],
            'amount' => Money::format(Money::fromDecimal((string) $r->amount)), 'status' => (string) $r->status, 'sent_at' => $r->sent_at, 'received_at' => $r->received_at, 'reconciled' => $r->reconciled_at !== null])->all();
    }

    private function transfers(array $units, string $from, string $to, ?string $stage, string $view): array
    {
        $q = $this->rt->db->table('internal_transfers as t')->join('organizational_units as o', 'o.id', '=', 't.origin_unit_id')->join('organizational_units as d', 'd.id', '=', 't.destination_unit_id')
            ->join('financial_categories as c', 'c.id', '=', 't.category_id')->where(fn ($x) => $x->whereIn('t.origin_unit_id', $units)->orWhereIn('t.destination_unit_id', $units))
            ->whereRaw('DATE(t.sent_at) BETWEEN ? AND ?', [$from, $to]);
        if ($stage === 'SEND') { $q->whereIn('t.origin_unit_id', $units); }
        if ($stage === 'RECEIVE') { $q->whereIn('t.destination_unit_id', $units)->whereNotNull('t.received_at'); }
        return $q->orderByDesc('t.id')->limit(100)->get(['t.public_id', 't.amount', 't.status', 't.sent_at', 't.received_at', 't.origin_unit_id as origin_internal', 't.destination_unit_id as destination_internal', 'o.public_id as origin_public', 'o.name as origin_name', 'd.public_id as destination_public', 'd.name as destination_name', 'c.code as purpose_code', 'c.name as purpose_name'])
            ->map(fn (object $r): array => ['transfer' => (string) $r->public_id, 'origin' => ['public_id' => (string) $r->origin_public, 'name' => (string) $r->origin_name], 'destination' => ['public_id' => (string) $r->destination_public, 'name' => (string) $r->destination_name],
                'amount' => Money::format(Money::fromDecimal((string) $r->amount)), 'purpose' => ['code' => (string) $r->purpose_code, 'label' => (string) $r->purpose_name], 'status' => (string) $r->status,
                'sent_at' => $r->sent_at, 'received_at' => $r->received_at, 'perimeter_class' => $view === 'CONSOLIDATED' ? FinanceQueryService::perimeterClass($units, (int) $r->origin_internal, (int) $r->destination_internal) : null])->all();
    }

    private function movements(array $units, string $from, string $to, int $limit): array
    {
        return $this->posted($units, $from, $to)->join('accounts as fa', 'fa.id', '=', 'l.financial_account_id')->join('organizational_units as u', 'u.id', '=', 'l.unit_id')
            ->orderByDesc('e.entry_date')->orderByDesc('e.id')->limit(max(1, min(100, $limit)))->get(['e.public_id as entry', 'e.entry_date', 'e.entry_kind', 'e.description', 'l.debit', 'l.credit', 'fa.public_id as account_public', 'fa.name as account_name', 'u.public_id as unit_public', 'u.name as unit_name'])
            ->map(static fn (object $r): array => ['entry' => (string) $r->entry, 'date' => (string) $r->entry_date, 'kind' => (string) $r->entry_kind, 'description' => (string) $r->description,
                'account' => ['public_id' => (string) $r->account_public, 'name' => (string) $r->account_name], 'unit' => ['public_id' => (string) $r->unit_public, 'name' => (string) $r->unit_name], 'debit' => (string) $r->debit, 'credit' => (string) $r->credit])->all();
    }

    private function header(string $type, string $view, array $unit, string $from, string $to, string $kind, string $period, int $count): array
    {
        $params = ['report_type' => $type, 'view' => $view, 'unit' => $unit['public_id'], 'from' => $from, 'to' => $to, 'period_kind' => $kind, 'period' => $period];
        return ['institution' => 'MEPA', 'report_type' => $type, 'report_name' => self::REPORTS[$type], 'view' => $view, 'unit' => $unit, 'period' => ['kind' => $kind, 'label' => $period, 'from' => $from, 'to' => $to],
            'generated_at' => $this->rt->ts() . 'Z', 'perimeter_units' => $count, 'source' => 'POSTED_JOURNAL_LINES', 'snapshot' => 'REPEATABLE_READ', 'parameters_hash' => hash('sha256', json_encode($params, JSON_THROW_ON_ERROR))];
    }

    private function unit(mixed $publicId): array
    {
        $row = is_string($publicId) && preg_match(FinanceCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('organizational_units')->where('public_id', $publicId)->first(['id', 'public_id', 'name']) : null;
        if (!$row) { throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']); }
        return [(int) $row->id, ['public_id' => (string) $row->public_id, 'name' => (string) $row->name]];
    }

    private function period(array $in): array
    {
        $today = $this->rt->today();
        if (isset($in['from'], $in['to'])) { $from = (string) $in['from']; $to = (string) $in['to']; $kind = 'RANGE'; $label = $from . '/' . $to; }
        else {
            $kind = strtoupper((string) ($in['period_kind'] ?? 'MONTH')); $year = (string) ($in['year'] ?? substr($today, 0, 4)); $period = (string) ($in['period'] ?? ($kind === 'MONTH' ? substr($today, 0, 7) : $year));
            if ($kind === 'MONTH' && preg_match('/^(\\d{4})-(0[1-9]|1[0-2])$/D', $period, $m)) { $from = $period . '-01'; $to = date('Y-m-t', strtotime($from)); }
            elseif ($kind === 'QUARTER' && preg_match('/^(\\d{4})-Q([1-4])$/D', $period, $m)) { $month = ((int) $m[2] - 1) * 3 + 1; $from = sprintf('%s-%02d-01', $m[1], $month); $to = date('Y-m-t', strtotime('+2 months', strtotime($from))); }
            elseif ($kind === 'SEMESTER' && preg_match('/^(\\d{4})-H([12])$/D', $period, $m)) { $from = $m[1] . ($m[2] === '1' ? '-01-01' : '-07-01'); $to = $m[1] . ($m[2] === '1' ? '-06-30' : '-12-31'); }
            elseif ($kind === 'YEAR' && preg_match('/^\\d{4}$/D', $period)) { $from = $period . '-01-01'; $to = $period . '-12-31'; }
            else { throw new FinanceError('INVALID_INPUT', [], ['field' => 'period']); }
            $label = $period;
        }
        if (!isset($from, $to) || DateTimeImmutable::createFromFormat('!Y-m-d', $from)?->format('Y-m-d') !== $from || DateTimeImmutable::createFromFormat('!Y-m-d', $to)?->format('Y-m-d') !== $to || $from > $to) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'period']);
        }
        if ($to > $today) { $to = $today; }
        return [$from, $to, $kind, $label];
    }

    private function posted(array $units, string $from, string $to): Builder
    {
        return $this->rt->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('e.status', FinanceCatalog::POSTED)->whereIn('l.unit_id', $units)->whereBetween('e.entry_date', [$from, $to]);
    }

    private function roleAmount(array $units, string $from, string $to, string $role): int
    {
        $q = $this->posted($units, $from, $to)->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('a.system_role', $role);
        return $this->sum($q, 'l.debit') - $this->sum($this->posted($units, $from, $to)->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('a.system_role', $role), 'l.credit');
    }

    private function treasuryNet(array $units, ?string $from, string $to): int
    {
        $q = fn (): Builder => $this->rt->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('e.status', FinanceCatalog::POSTED)->whereIn('l.unit_id', $units)->whereIn('a.system_role', FinanceCatalog::TREASURY_ROLES)->when($from !== null, fn ($x) => $x->where('e.entry_date', '>=', $from))->where('e.entry_date', '<=', $to);
        return $this->sum($q(), 'l.debit') - $this->sum($q(), 'l.credit');
    }

    private function boundaryTransfer(array $units, string $from, string $to, string $stage, bool $originInside): int
    {
        $q = $this->rt->db->table('transfer_postings as p')->join('journal_entries as e', 'e.id', '=', 'p.entry_id')->join('internal_transfers as t', 't.id', '=', 'p.transfer_id')
            ->where('p.posting_stage', $stage)->where('e.status', FinanceCatalog::POSTED)->whereBetween('e.entry_date', [$from, $to]);
        $q->whereIn($originInside ? 't.origin_unit_id' : 't.destination_unit_id', $units)->whereNotIn($originInside ? 't.destination_unit_id' : 't.origin_unit_id', $units);
        return Money::fromDecimal((string) ($q->sum('t.amount') ?? '0'));
    }

    private function internalTransit(array $units, string $to): int
    {
        $q = $this->rt->db->table('internal_transfers as t')->join('transfer_postings as p', fn ($j) => $j->on('p.transfer_id', '=', 't.id')->where('p.posting_stage', 'SEND'))->join('journal_entries as e', 'e.id', '=', 'p.entry_id')
            ->where('e.status', FinanceCatalog::POSTED)->where('e.entry_date', '<=', $to)->whereIn('t.origin_unit_id', $units)->whereIn('t.destination_unit_id', $units)
            ->whereNotExists(fn ($x) => $x->from('transfer_postings as z')->join('journal_entries as ze', 'ze.id', '=', 'z.entry_id')->whereColumn('z.transfer_id', 't.id')->whereIn('z.posting_stage', ['RECEIVE', 'REVERSE_SEND'])->where('ze.status', FinanceCatalog::POSTED)->where('ze.entry_date', '<=', $to));
        return Money::fromDecimal((string) ($q->sum('t.amount') ?? '0'));
    }

    private function sum(Builder $query, string $column): int { return Money::fromDecimal((string) ($query->sum($column) ?? '0')); }
}
