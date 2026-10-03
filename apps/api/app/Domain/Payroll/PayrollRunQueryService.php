<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceCatalog;
use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinanceGuard;
use App\Domain\Territorial\TerritorialActor;

/**
 * Payroll run read models (ADR 0021 D26/D28). Public ids only; every read is audited in the same transaction.
 *
 *   list / detail   PAYROLL_MANAGE | PAYROLL_APPROVE | PAYROLL_POST | HR_COMPENSATION_VIEW on the run's unit
 *                   run header, totals, input hash + live input status, Finance postings, available actions
 *                   (audit payroll.viewed)
 *   employees       HR_COMPENSATION_VIEW on the run's unit ONLY (otherwise the concealed 404): per-person lines, base
 *                   salary, components, deductions, net (audit payroll.employee_detail_viewed; never an amount in it)
 *   summary / CSV   the run viewers above OR FINANCE_PAYROLL_SUMMARY_VIEW (Finance side, D28): totals per component and
 *                   class + headcount, never a person (audit payroll.summary_viewed / payroll.summary_exported)
 * Finance read models never read payroll_run_lines / employment_compensations: Finance sees the aggregated entry only.
 */
final class PayrollRunQueryService extends PayrollService
{
    public const RUN_VIEW = [PayrollCatalog::PAYROLL_MANAGE, PayrollCatalog::PAYROLL_APPROVE, PayrollCatalog::PAYROLL_POST, PayrollCatalog::HR_COMPENSATION_VIEW];

    public function runs(int $user, int $session, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $this->requiresAny($actor, ...self::RUN_VIEW);
            $covered = $this->viewUnits($actor);
            $q = $this->rt->db->table('payroll_runs as r')->join('accounting_periods as p', 'p.id', '=', 'r.period_id')->join('organizational_units as u', 'u.id', '=', 'r.employing_unit_id')
                ->whereIn('r.employing_unit_id', $covered);
            if (($in['unit'] ?? null) !== null) {
                $unit = $this->unitByPublicId($in['unit']);
                if (!in_array((int) $unit->id, $covered, true)) {
                    throw new FinanceError('OUT_OF_SCOPE');
                }
                $q->where('r.employing_unit_id', $unit->id);
            }
            if (($in['period'] ?? null) !== null) {
                [$code] = $this->month($in['period']);
                $q->where('p.code', $code);
            }
            if (($in['status'] ?? null) !== null) {
                if (!in_array($in['status'], PayrollCatalog::RUN_STATUSES, true)) {
                    throw new FinanceError('INVALID_INPUT', [], ['field' => 'status']);
                }
                $q->where('r.status', $in['status']);
            }
            $perPage = $this->rt->perPage($in['per_page'] ?? null);
            $page = max(1, (int) ($in['page'] ?? 1));
            $total = (clone $q)->count();
            $rows = $q->orderByDesc('p.code')->orderBy('u.name')->orderByDesc('r.sequence')->forPage($page, $perPage)
                ->get(['r.*', 'p.code as period_code', 'u.public_id as unit_public', 'u.name as unit_name']);
            $data = $rows->map(fn ($r) => $this->header($r, $actor))->all();
            $auditUnit = isset($unit) ? (int) $unit->id : $this->anyUnit($actor);
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.viewed', 'organizational_units', $auditUnit, $auditUnit, ['view' => 'run_list', 'runs' => count($data), 'page' => $page], null, $actor->session);
            return ['data' => $data, 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
        });
    }

    public function run(int $user, int $session, string $run): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($run): array {
            $this->requiresAny($actor, ...self::RUN_VIEW);
            $r = $this->visibleRun($actor, $run);
            $out = $this->header($r, $actor) + $this->detail($r, $actor);
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.viewed', 'payroll_runs', (int) $r->id, (int) $r->employing_unit_id, ['run' => (string) $r->public_id, 'view' => 'run_detail'], null, $actor->session);
            return $out;
        });
    }

    /** Per-person breakdown: HR_COMPENSATION_VIEW on the unit only; anyone else gets the same 404 as an unknown run. */
    public function employees(int $user, int $session, string $run): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($run): array {
            $this->requiresAny($actor, ...self::RUN_VIEW);
            $r = $this->visibleRun($actor, $run);
            if (!$this->rt->authority->holdsOn($actor, PayrollCatalog::HR_COMPENSATION_VIEW, (int) $r->employing_unit_id)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'payroll_runs', 'reason' => 'compensation_view']);
            }
            $guard->unit(PayrollCatalog::HR_COMPENSATION_VIEW, (int) $r->employing_unit_id);
            $lines = $this->rt->db->table('payroll_run_lines as l')->join('employments as e', 'e.id', '=', 'l.employment_id')->join('people as p', 'p.id', '=', 'e.person_id')
                ->join('compensation_component_types as t', 't.id', '=', 'l.component_type_id')->leftJoin('payroll_rules as pr', 'pr.id', '=', 'l.rule_id')->where('l.run_id', $r->id)
                ->orderBy('p.full_name')->orderBy('e.public_id')->orderBy('t.code')
                ->get(['e.public_id as employment', 'e.job_title', 'p.public_id as person', 'p.full_name', 't.code', 't.name', 't.nature', 'l.source', 'l.base_amount', 'l.rate', 'l.amount', 'pr.code as rule_code', 'pr.version as rule_version']);
            $employees = [];
            foreach ($lines as $l) {
                $key = (string) $l->employment;
                $employees[$key] ??= ['employment' => $key, 'person' => ['public_id' => (string) $l->person, 'name' => (string) $l->full_name], 'job_title' => $l->job_title,
                    'lines' => [], 'gross' => '0.00', 'deductions' => '0.00', 'employer_charges' => '0.00', 'net' => '0.00'];
                $amount = PayrollMoney::fromStorage((string) $l->amount);
                $employees[$key]['lines'][] = ['component' => ['code' => (string) $l->code, 'label' => (string) $l->name, 'nature' => (string) $l->nature], 'source' => (string) $l->source,
                    'base_amount' => PayrollMoney::fromStorage($l->base_amount === null ? null : (string) $l->base_amount), 'rate' => $l->rate === null ? null : (string) $l->rate, 'amount' => $amount,
                    'rule' => $l->rule_code === null ? null : ['code' => (string) $l->rule_code, 'version' => (int) $l->rule_version]];
                $field = match ((string) $l->nature) { PayrollCatalog::EARNING => 'gross', PayrollCatalog::EMPLOYEE_DEDUCTION => 'deductions', default => 'employer_charges' };
                $employees[$key][$field] = bcadd($employees[$key][$field], $amount, PayrollCatalog::MONEY_SCALE);
            }
            foreach ($employees as &$e) {
                $e['net'] = bcsub($e['gross'], $e['deductions'], PayrollCatalog::MONEY_SCALE);
            }
            unset($e);
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.employee_detail_viewed', 'payroll_runs', (int) $r->id, (int) $r->employing_unit_id,
                ['run' => (string) $r->public_id, 'employments' => count($employees)], null, $actor->session);
            return ['run' => (string) $r->public_id, 'status' => (string) $r->status, 'currency' => FinanceCatalog::CURRENCY, 'employees' => array_values($employees)];
        });
    }

    /** Totals per component + class and headcount (no person). Run viewers or FINANCE_PAYROLL_SUMMARY_VIEW. */
    public function summary(int $user, int $session, string $run, bool $export = false): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($run, $export): array {
            $finance = $this->financeGate();
            $hrViewer = array_filter(self::RUN_VIEW, fn ($p) => $this->rt->authority->holdsAnywhere($actor, $p)) !== [];
            if (!$hrViewer && !$finance->holdsAnywhere($actor, 'FINANCE_PAYROLL_SUMMARY_VIEW')) {
                throw new FinanceError('NOT_AUTHORIZED', [], ['permission' => 'PAYROLL_RUN_VIEW|FINANCE_PAYROLL_SUMMARY_VIEW']);
            }
            $r = $this->peek($run);
            $unit = (int) $r->employing_unit_id;
            if (!$this->canView($actor, $unit)) {
                // F-06: a run outside every HR and Finance scope of the actor is the same concealed 404 as an unknown run.
                if (!$finance->holdsOn($actor, 'FINANCE_PAYROLL_SUMMARY_VIEW', $unit)) {
                    throw new FinanceError('OUT_OF_SCOPE');
                }
                $finance->unit($actor, 'FINANCE_PAYROLL_SUMMARY_VIEW', $unit);
            }
            $components = $this->rt->db->table('payroll_run_lines as l')->join('compensation_component_types as t', 't.id', '=', 'l.component_type_id')->where('l.run_id', $r->id)
                ->groupBy('t.code', 't.name', 't.nature')->orderBy('t.nature')->orderBy('t.code')->selectRaw('t.code, t.name, t.nature, SUM(l.amount) AS total')->get()
                ->map(fn ($c) => ['component' => (string) $c->code, 'label' => (string) $c->name, 'nature' => (string) $c->nature, 'amount' => PayrollMoney::fromStorage((string) $c->total)])->all();
            $period = (string) $this->rt->db->table('accounting_periods')->where('id', $r->period_id)->value('code');
            PayrollAudit::write($this->rt->db, $actor->user, $export ? 'payroll.summary_exported' : 'payroll.summary_viewed', 'payroll_runs', (int) $r->id, $unit,
                ['run' => (string) $r->public_id, 'view' => $export ? 'summary_csv' : 'summary'], null, $actor->session);
            return ['run' => (string) $r->public_id, 'unit' => $this->unitProjection($unit), 'period' => $period, 'run_kind' => (string) $r->run_kind, 'sequence' => (int) $r->sequence,
                'status' => (string) $r->status, 'currency' => FinanceCatalog::CURRENCY, 'headcount' => (int) $r->headcount, 'totals' => $this->totals($r), 'components' => $components];
        });
    }

    public static function csv(array $summary): string
    {
        $rows = [['Folha', 'Unidade', 'Periodo', 'Tipo', 'Sequencia', 'Estado', 'Moeda', 'Empregados'],
            [$summary['run'], $summary['unit']['name'], $summary['period'], $summary['run_kind'], (string) $summary['sequence'], $summary['status'], $summary['currency'], (string) $summary['headcount']],
            [], ['Componente', 'Natureza', 'Montante']];
        foreach ($summary['components'] as $c) {
            $rows[] = [$c['component'], $c['nature'], $c['amount']];
        }
        $rows[] = [];
        foreach (['gross' => 'Bruto', 'deductions' => 'Descontos do trabalhador', 'employer_charges' => 'Encargos da entidade', 'net' => 'Liquido'] as $key => $label) {
            $rows[] = [$label, '', $summary['totals'][$key]];
        }
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn ($v) => preg_match('/^[=+\-@]/', (string) $v) === 1 && !is_numeric($v) ? "'" . $v : $v, $row), ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    // ---- projections ------------------------------------------------------------------------------------------------------

    private function header(object $r, TerritorialActor $actor): array
    {
        $period = $r->period_code ?? (string) $this->rt->db->table('accounting_periods')->where('id', $r->period_id)->value('code');
        return ['public_id' => (string) $r->public_id, 'unit' => $this->unitProjection((int) $r->employing_unit_id), 'period' => (string) $period, 'run_kind' => (string) $r->run_kind,
            'sequence' => (int) $r->sequence, 'status' => (string) $r->status, 'currency' => FinanceCatalog::CURRENCY, 'headcount' => (int) $r->headcount, 'totals' => $this->totals($r),
            'lock_version' => (int) $r->lock_version];
    }

    private function totals(object $r): array
    {
        return ['gross' => PayrollMoney::fromStorage((string) $r->gross_amount), 'deductions' => PayrollMoney::fromStorage((string) $r->deductions_amount),
            'employer_charges' => PayrollMoney::fromStorage((string) $r->employer_charges_amount), 'net' => PayrollMoney::fromStorage((string) $r->net_amount)];
    }

    private function detail(object $r, TerritorialActor $actor): array
    {
        $unit = (int) $r->employing_unit_id;
        $stored = $r->input_hash === null ? null : bin2hex((string) $r->input_hash);
        $inputStatus = match (true) {
            $stored === null => 'NOT_CALCULATED',
            in_array($r->status, ['APPROVED', 'POSTED', 'PAID', 'REVERSED'], true) => 'FROZEN',
            $r->status === 'CANCELLED' => 'CANCELLED',
            default => (new PayrollRunService($this->rt, $this->finance))->currentHash($r, false) === $stored ? 'CURRENT' : 'STALE',
        };
        $postings = [];
        foreach ($this->rt->db->table('payroll_postings as pp')->join('journal_entries as e', 'e.id', '=', 'pp.entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.period_id')->where('pp.run_id', $r->id)
            ->orderBy('pp.id')->get(['pp.stage', 'e.public_id', 'e.entry_date', 'e.entry_kind', 'e.status', 'p.code as period']) as $p) {
            $postings[strtolower((string) $p->stage)] = ['entry' => (string) $p->public_id, 'entry_kind' => (string) $p->entry_kind, 'entry_date' => (string) $p->entry_date, 'accounting_period' => (string) $p->period, 'status' => (string) $p->status];
        }
        $production = PayrollProduction::fromConfig();
        $holds = fn (string $p) => $this->rt->authority->holdsOn($actor, $p, $unit);
        $ledger = $holds(PayrollCatalog::PAYROLL_POST) && $this->financeGate()->holdsOn($actor, FinanceCatalog::PERMISSION_POST, $unit);
        $calculator = $r->calculated_by !== null && (int) $r->calculated_by === $actor->user;
        $actions = [
            'calculate' => in_array($r->status, ['DRAFT', 'CALCULATED'], true) && $holds(PayrollCatalog::PAYROLL_MANAGE),
            'cancel' => in_array($r->status, ['DRAFT', 'CALCULATED'], true) && $holds(PayrollCatalog::PAYROLL_MANAGE),
            'approve' => $r->status === 'CALCULATED' && $inputStatus === 'CURRENT' && $holds(PayrollCatalog::PAYROLL_APPROVE) && !$calculator && $production->enabled(),
            'post' => $r->status === 'APPROVED' && $ledger && $production->enabled(),
            'pay' => $r->status === 'POSTED' && $ledger && $production->enabled(),
            'reverse' => $r->status === 'POSTED' && $ledger && $production->enabled(),
        ];
        $blocked = [];
        if ($r->status === 'CALCULATED' && $holds(PayrollCatalog::PAYROLL_APPROVE)) {
            $blocked['approve'] = match (true) { !$production->enabled() => 'PAYROLL_PRODUCTION_DISABLED', $calculator => 'PAYROLL_SEGREGATION_REQUIRED', $inputStatus !== 'CURRENT' => 'PAYROLL_INPUT_STALE', default => null };
        }
        if (in_array($r->status, ['APPROVED', 'POSTED'], true) && $ledger && !$production->enabled()) {
            $blocked[$r->status === 'APPROVED' ? 'post' : 'pay'] = 'PAYROLL_PRODUCTION_DISABLED';
        }
        return [
            'input' => ['hash' => $stored, 'algorithm' => 'SHA-256', 'version' => PayrollCatalog::INPUT_HASH_VERSION, 'status' => $inputStatus],
            'provenance' => ['created_by' => $this->actorName((int) $r->created_by), 'created_at' => (string) $r->created_at,
                'calculated_by' => $this->actorName($r->calculated_by === null ? null : (int) $r->calculated_by), 'calculated_at' => $r->calculated_at,
                'approved_by' => $this->actorName($r->approved_by === null ? null : (int) $r->approved_by), 'approved_at' => $r->approved_at,
                'posted_by' => $this->actorName($r->posted_by === null ? null : (int) $r->posted_by), 'posted_at' => $r->posted_at,
                'paid_by' => $this->actorName($r->paid_by === null ? null : (int) $r->paid_by), 'paid_at' => $r->paid_at,
                'cancelled_at' => $r->cancelled_at, 'cancel_reason' => $r->cancel_reason, 'reversed_at' => $r->reversed_at, 'reversal_reason' => $r->reversal_reason,
                'calculated_by_me' => $calculator],
            'finance' => ['accrual' => $postings['accrual'] ?? null, 'payment' => $postings['payment'] ?? null, 'reversal' => $postings['reversal'] ?? null,
                'posting_state' => isset($postings['reversal']) ? 'REVERSED' : (isset($postings['accrual']) ? 'POSTED' : 'NOT_POSTED'),
                'payment_state' => isset($postings['payment']) ? 'PAID' : (isset($postings['accrual']) && !isset($postings['reversal']) ? 'NET_PAYABLE_OPEN' : 'NOT_APPLICABLE'),
                'statutory_liabilities' => isset($postings['accrual']) && !isset($postings['reversal']) ? 'OPEN_UNTIL_LIABILITY_PAYMENT' : 'NOT_APPLICABLE'],
            'production' => $production->status(),
            'actions' => $actions,
            'blocked' => array_filter($blocked),
            'employee_detail_visible' => $holds(PayrollCatalog::HR_COMPENSATION_VIEW),
        ];
    }

    private function visibleRun(TerritorialActor $actor, mixed $run): object
    {
        $r = $this->peek($run);
        if (!$this->canView($actor, (int) $r->employing_unit_id)) {
            throw new FinanceError('OUT_OF_SCOPE');
        }
        return $r;
    }

    private function canView(TerritorialActor $actor, int $unit): bool
    {
        foreach (self::RUN_VIEW as $p) {
            if ($this->rt->authority->holdsOn($actor, $p, $unit)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<int> */
    private function viewUnits(TerritorialActor $actor): array
    {
        $units = [];
        foreach (self::RUN_VIEW as $p) {
            $units += $this->rt->authority->covered($actor, $p);
        }
        $ids = array_map('intval', array_keys($units));
        sort($ids);
        return $ids;
    }

    private function anyUnit(TerritorialActor $actor): int
    {
        return $this->viewUnits($actor)[0] ?? 0;
    }

    private function peek(mixed $publicId): object
    {
        $row = is_string($publicId) && preg_match(PayrollCatalog::PUBLIC_ID_PATTERN, $publicId) === 1
            ? $this->rt->db->table('payroll_runs')->where('public_id', $publicId)->first() : null;
        if ($row === null) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'payroll_runs']);
        }
        return $row;
    }
}
