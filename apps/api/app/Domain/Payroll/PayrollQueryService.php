<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinanceGuard;
use App\Domain\Territorial\TerritorialActor;

/**
 * RH / payroll read models (ADR 0021 D28). Bounded, public ids only.
 *
 * Salary privacy: compensation values (amounts, history, components of a person) are returned ONLY by the HR routes and
 * ONLY to HR_COMPENSATION_VIEW on the employing unit; every such read writes `hr.compensation_viewed` in the same
 * transaction (a failed audit returns nothing). HR_EMPLOYMENT_VIEW alone sees the employment without any salary field,
 * and the compensation routes answer the concealed 404 (ADR 0021 D28 / D33). People, Membership, Departments and
 * Finance read models never read these tables.
 */
final class PayrollQueryService extends PayrollService
{
    private const VIEW_ANY = [PayrollCatalog::HR_EMPLOYMENT_VIEW, PayrollCatalog::HR_EMPLOYMENT_MANAGE, PayrollCatalog::HR_COMPENSATION_VIEW, PayrollCatalog::HR_COMPENSATION_MANAGE,
        PayrollCatalog::PAYROLL_MANAGE, PayrollCatalog::PAYROLL_APPROVE, PayrollCatalog::PAYROLL_POST, PayrollCatalog::PAYROLL_RULES_MANAGE, PayrollCatalog::PAYROLL_RULES_APPROVE];
    private const RULES_VIEW = [PayrollCatalog::PAYROLL_RULES_MANAGE, PayrollCatalog::PAYROLL_RULES_APPROVE, PayrollCatalog::PAYROLL_MANAGE, PayrollCatalog::HR_COMPENSATION_VIEW];

    public function context(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor): array {
            $this->requiresAny($actor, ...self::VIEW_ANY);
            $permissions = array_values(array_filter(array_keys(PayrollCatalog::PERMISSIONS), fn (string $p): bool => $this->rt->authority->holdsAnywhere($actor, $p)));
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
            return ['permissions' => $permissions, 'units' => $units, 'components' => $this->componentList(), 'production' => PayrollProduction::fromConfig()->status(),
                'relationship_kinds' => PayrollCatalog::RELATIONSHIP_KINDS, 'rounding' => PayrollCatalog::ROUNDING];
        });
    }

    public function employments(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $guard->requires(PayrollCatalog::HR_EMPLOYMENT_VIEW);
            $covered = array_keys($this->rt->authority->covered($actor, PayrollCatalog::HR_EMPLOYMENT_VIEW));
            $q = $this->rt->db->table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->join('organizational_units as u', 'u.id', '=', 'e.employing_unit_id')->whereIn('e.employing_unit_id', $covered);
            if (($in['unit'] ?? null) !== null) {
                $unit = $this->unitByPublicId($in['unit']);
                if (!in_array((int) $unit->id, $covered, true)) {
                    throw new FinanceError('OUT_OF_SCOPE');
                }
                $q->where('e.employing_unit_id', $unit->id);
            }
            if (($in['status'] ?? null) !== null) {
                if (!in_array($in['status'], [PayrollCatalog::EMPLOYMENT_ACTIVE, PayrollCatalog::EMPLOYMENT_ENDED], true)) {
                    throw new FinanceError('INVALID_INPUT', [], ['field' => 'status']);
                }
                $q->where('e.status', $in['status']);
            }
            $perPage = $this->rt->perPage($in['per_page'] ?? null);
            $page = max(1, (int) ($in['page'] ?? 1));
            $total = (clone $q)->count();
            $rows = $q->orderBy('p.full_name')->orderBy('e.starts_on')->orderBy('e.id')->forPage($page, $perPage)
                ->get(['e.public_id', 'e.relationship_kind', 'e.job_title', 'e.starts_on', 'e.ends_on', 'e.status', 'p.public_id as person', 'p.full_name', 'u.public_id as unit', 'u.name as unit_name']);
            return ['data' => $rows->map(fn ($r) => ['public_id' => (string) $r->public_id, 'person' => ['public_id' => (string) $r->person, 'name' => (string) $r->full_name],
                'unit' => ['public_id' => (string) $r->unit, 'name' => (string) $r->unit_name], 'relationship_kind' => (string) $r->relationship_kind, 'job_title' => $r->job_title,
                'starts_on' => (string) $r->starts_on, 'ends_on' => $r->ends_on, 'status' => (string) $r->status])->all(),
                'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
        });
    }

    /** Employment + Person projection + employment history of the Person (within scope); compensation only with HR_COMPENSATION_VIEW (audited). */
    public function employment(int $user, int $session, string $employment): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($employment): array {
            $guard->requires(PayrollCatalog::HR_EMPLOYMENT_VIEW);
            $e = $this->employmentByPublicId($employment);
            $guard->unit(PayrollCatalog::HR_EMPLOYMENT_VIEW, (int) $e->employing_unit_id);
            $covered = array_keys($this->rt->authority->covered($actor, PayrollCatalog::HR_EMPLOYMENT_VIEW));
            $history = $this->rt->db->table('employments as e')->join('organizational_units as u', 'u.id', '=', 'e.employing_unit_id')->where('e.person_id', $e->person_id)
                ->whereIn('e.employing_unit_id', $covered)->orderBy('e.starts_on')->orderBy('e.id')->limit(100)
                ->get(['e.public_id', 'e.relationship_kind', 'e.job_title', 'e.starts_on', 'e.ends_on', 'e.status', 'e.end_reason', 'u.public_id as unit', 'u.name as unit_name'])
                ->map(fn ($h) => ['public_id' => (string) $h->public_id, 'unit' => ['public_id' => (string) $h->unit, 'name' => (string) $h->unit_name], 'relationship_kind' => (string) $h->relationship_kind,
                    'job_title' => $h->job_title, 'starts_on' => (string) $h->starts_on, 'ends_on' => $h->ends_on, 'status' => (string) $h->status, 'end_reason' => $h->end_reason, 'current' => $h->public_id === $e->public_id])->all();
            $canCompensation = $this->rt->authority->holdsOn($actor, PayrollCatalog::HR_COMPENSATION_VIEW, (int) $e->employing_unit_id);
            $out = ['public_id' => (string) $e->public_id, 'person' => $this->personProjection((int) $e->person_id), 'unit' => $this->unitProjection((int) $e->employing_unit_id),
                'relationship_kind' => (string) $e->relationship_kind, 'job_title' => $e->job_title, 'starts_on' => (string) $e->starts_on, 'ends_on' => $e->ends_on, 'status' => (string) $e->status,
                'end_reason' => $e->end_reason, 'contract_document' => $this->rt->documentProjection($actor, $e->contract_document_id === null ? null : (int) $e->contract_document_id),
                'created_at' => (string) $e->created_at, 'history' => $history,
                'actions' => array_values(array_filter([
                    $e->status === PayrollCatalog::EMPLOYMENT_ACTIVE && $this->rt->authority->holdsOn($actor, PayrollCatalog::HR_EMPLOYMENT_MANAGE, (int) $e->employing_unit_id) ? 'end' : null,
                    $e->status === PayrollCatalog::EMPLOYMENT_ACTIVE && $this->rt->authority->holdsOn($actor, PayrollCatalog::HR_COMPENSATION_MANAGE, (int) $e->employing_unit_id) ? 'change_compensation' : null,
                ])),
                'compensation_visible' => $canCompensation];
            if ($canCompensation) {
                $out['compensation'] = $this->compensationOf($e, null);
                PayrollAudit::write($this->rt->db, $actor->user, 'hr.compensation_viewed', 'employments', (int) $e->id, (int) $e->employing_unit_id, ['employment' => (string) $e->public_id, 'view' => 'employment_detail'], null, $actor->session);
            }
            return $out;
        });
    }

    /** Compensation (current + history; optional as-of date). HR_COMPENSATION_VIEW on the unit, audited; otherwise concealed. */
    public function compensation(int $user, int $session, string $employment, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($employment, $in): array {
            $this->requiresAny($actor, PayrollCatalog::HR_COMPENSATION_VIEW, PayrollCatalog::HR_EMPLOYMENT_VIEW);
            $e = $this->employmentByPublicId($employment);
            if (!$this->rt->authority->holdsOn($actor, PayrollCatalog::HR_COMPENSATION_VIEW, (int) $e->employing_unit_id)) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'employments', 'reason' => 'compensation_view']);
            }
            $guard->unit(PayrollCatalog::HR_COMPENSATION_VIEW, (int) $e->employing_unit_id);
            $asOf = ($in['as_of'] ?? null) === null ? null : $this->date($in['as_of'], 'as_of');
            PayrollAudit::write($this->rt->db, $actor->user, 'hr.compensation_viewed', 'employments', (int) $e->id, (int) $e->employing_unit_id,
                ['employment' => (string) $e->public_id, 'view' => 'compensation', 'as_of' => $asOf], null, $actor->session);
            return ['employment' => (string) $e->public_id, 'person' => $this->personProjection((int) $e->person_id), 'unit' => $this->unitProjection((int) $e->employing_unit_id)] + $this->compensationOf($e, $asOf);
        });
    }

    /** Current base salary and earnings of the ACTIVE employments of a unit (HR_COMPENSATION_VIEW, one audit row). */
    public function compensationOverview(int $user, int $session, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $guard->requires(PayrollCatalog::HR_COMPENSATION_VIEW);
            $unit = $this->unitByPublicId($in['unit'] ?? null);
            $guard->unit(PayrollCatalog::HR_COMPENSATION_VIEW, (int) $unit->id);
            $asOf = ($in['as_of'] ?? null) === null ? $this->rt->today() : $this->date($in['as_of'], 'as_of');
            $rows = $this->rt->db->table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.employing_unit_id', $unit->id)
                ->where('e.starts_on', '<=', $asOf)->where(fn ($q) => $q->whereNull('e.ends_on')->orWhere('e.ends_on', '>=', $asOf))->orderBy('p.full_name')->limit(200)
                ->get(['e.id', 'e.public_id', 'e.job_title', 'p.public_id as person', 'p.full_name']);
            $data = [];
            foreach ($rows as $r) {
                $lines = $this->lines((int) $r->id, $asOf);
                $base = collect($lines)->firstWhere('component.code', 'BASE_SALARY');
                $earnings = '0.00';
                foreach ($lines as $l) {
                    if ($l['component']['nature'] === PayrollCatalog::EARNING && $l['amount'] !== null) {
                        $earnings = bcadd($earnings, $l['amount'], PayrollCatalog::MONEY_SCALE);
                    }
                }
                $data[] = ['employment' => (string) $r->public_id, 'person' => ['public_id' => (string) $r->person, 'name' => (string) $r->full_name], 'job_title' => $r->job_title,
                    'base_salary' => $base['amount'] ?? null, 'fixed_earnings' => $earnings, 'components' => count($lines)];
            }
            PayrollAudit::write($this->rt->db, $actor->user, 'hr.compensation_viewed', 'organizational_units', (int) $unit->id, (int) $unit->id,
                ['unit' => (string) $unit->public_id, 'view' => 'compensation_overview', 'as_of' => $asOf, 'employments' => count($data)], null, $actor->session);
            return ['unit' => ['public_id' => (string) $unit->public_id, 'name' => (string) $unit->name], 'as_of' => $asOf, 'data' => $data];
        });
    }

    public function components(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor): array {
            $this->requiresAny($actor, ...self::VIEW_ANY);
            return $this->componentList();
        });
    }

    public function rules(int $user, int $session, array $in): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $this->requiresAny($actor, ...self::RULES_VIEW);
            $q = $this->rt->db->table('payroll_rules as r')->join('compensation_component_types as c', 'c.id', '=', 'r.component_type_id');
            if (($in['status'] ?? null) !== null) {
                if (!in_array($in['status'], [PayrollCatalog::RULE_DRAFT, PayrollCatalog::RULE_APPROVED, PayrollCatalog::RULE_RETIRED], true)) {
                    throw new FinanceError('INVALID_INPUT', [], ['field' => 'status']);
                }
                $q->where('r.status', $in['status']);
            }
            $rules = $q->orderBy('c.code')->orderBy('r.code')->orderByDesc('r.version')->limit(200)->get(['r.*', 'c.code as component']);
            $ruleComponents = $this->rt->db->table('compensation_component_types')->whereIn('calculation_method', PayrollCatalog::RULE_METHODS)->orderBy('code')->get(['id', 'code', 'name', 'calculation_method']);
            $today = $this->rt->today();
            $coverage = $ruleComponents->map(function ($c) use ($today) {
                try {
                    $r = (new PayrollRuleResolver($this->rt->db))->onDate((int) $c->id, $today);
                    return ['component' => (string) $c->code, 'label' => (string) $c->name, 'status' => 'CONFIGURED', 'rule' => ['code' => $r['code'], 'version' => $r['version']]];
                } catch (FinanceError $e) {
                    return ['component' => (string) $c->code, 'label' => (string) $c->name, 'status' => $e->reason === 'PAYROLL_RULE_MISSING' ? 'PENDING_CONFIGURATION' : $e->reason, 'rule' => null];
                }
            })->all();
            return ['data' => $rules->map(fn ($r) => $this->ruleSummary($r, $actor))->all(), 'coverage_today' => ['date' => $today, 'components' => $coverage]];
        });
    }

    public function rule(int $user, int $session, string $code, int $version): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($code, $version): array {
            $this->requiresAny($actor, ...self::RULES_VIEW);
            $r = preg_match(PayrollCatalog::RULE_CODE_PATTERN, $code) === 1 ? $this->rt->db->table('payroll_rules as r')->join('compensation_component_types as c', 'c.id', '=', 'r.component_type_id')
                ->where('r.code', $code)->where('r.version', $version)->first(['r.*', 'c.code as component']) : null;
            if (!$r) {
                throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'payroll_rules']);
            }
            $base = $this->rt->db->table('payroll_rule_base_components as b')->join('compensation_component_types as c', 'c.id', '=', 'b.component_type_id')->where('b.rule_id', $r->id)->orderBy('c.code')->pluck('c.code')->all();
            $brackets = $this->rt->db->table('payroll_rule_brackets')->where('rule_id', $r->id)->orderBy('lower_bound')->get()
                ->map(fn ($b) => ['lower_bound' => PayrollMoney::fromStorage((string) $b->lower_bound), 'upper_bound' => PayrollMoney::fromStorage($b->upper_bound === null ? null : (string) $b->upper_bound),
                    'rate' => (string) $b->rate, 'fixed_amount' => PayrollMoney::fromStorage((string) $b->fixed_amount), 'excess_over' => PayrollMoney::fromStorage((string) $b->excess_over)])->all();
            return $this->ruleSummary($r, $actor) + ['base_components' => $base, 'brackets' => $brackets];
        });
    }

    public function status(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (FinanceGuard $guard, TerritorialActor $actor): array {
            $this->requiresAny($actor, ...self::VIEW_ANY);
            return ['production' => PayrollProduction::fromConfig()->status(), 'rounding' => PayrollCatalog::ROUNDING, 'input_hash' => ['algorithm' => 'SHA-256', 'version' => PayrollCatalog::INPUT_HASH_VERSION]];
        });
    }

    // ---- projections -------------------------------------------------------------------------------------------------

    private function componentList(): array
    {
        return $this->rt->db->table('compensation_component_types as t')->leftJoin('financial_categories as c', 'c.id', '=', 't.expense_category_id')->orderBy('t.id')
            ->get(['t.code', 't.name', 't.nature', 't.calculation_method', 't.liability_role', 't.is_active', 'c.code as rubric'])
            ->map(fn ($t) => ['code' => (string) $t->code, 'label' => (string) $t->name, 'nature' => (string) $t->nature, 'calculation_method' => (string) $t->calculation_method,
                'rule_based' => in_array($t->calculation_method, PayrollCatalog::RULE_METHODS, true), 'rubric' => $t->rubric, 'liability_role' => $t->liability_role, 'active' => (int) $t->is_active === 1])->all();
    }

    private function compensationOf(object $e, ?string $asOf): array
    {
        $date = $asOf ?? $this->rt->today();
        $history = $this->rt->db->table('employment_compensations as c')->join('compensation_component_types as t', 't.id', '=', 'c.component_type_id')->where('c.employment_id', $e->id)
            ->orderBy('t.code')->orderBy('c.starts_on')->limit(500)->get(['c.*', 't.code', 't.name', 't.nature', 't.calculation_method'])
            ->map(fn ($l) => $this->line($l, $date))->all();
        return ['as_of' => $date, 'current' => array_values(array_filter($history, fn ($l) => $l['effective'])), 'history' => $history];
    }

    private function lines(int $employmentId, string $date): array
    {
        return $this->rt->db->table('employment_compensations as c')->join('compensation_component_types as t', 't.id', '=', 'c.component_type_id')->where('c.employment_id', $employmentId)
            ->where('c.starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('c.ends_on')->orWhere('c.ends_on', '>=', $date))->orderBy('t.code')
            ->get(['c.*', 't.code', 't.name', 't.nature', 't.calculation_method'])->map(fn ($l) => $this->line($l, $date))->all();
    }

    private function line(object $l, string $date): array
    {
        return ['component' => ['code' => (string) $l->code, 'label' => (string) $l->name, 'nature' => (string) $l->nature, 'calculation_method' => (string) $l->calculation_method],
            'amount' => PayrollMoney::fromStorage($l->amount === null ? null : (string) $l->amount), 'currency' => 'AOA', 'rule_based' => in_array($l->calculation_method, PayrollCatalog::RULE_METHODS, true),
            'starts_on' => (string) $l->starts_on, 'ends_on' => $l->ends_on, 'reason' => (string) $l->reason, 'source_document' => $l->source_document_id === null ? null : ['public_id' => (string) $this->rt->db->table('legal_documents')->where('id', $l->source_document_id)->value('public_id')],
            'effective' => (string) $l->starts_on <= $date && ($l->ends_on === null || (string) $l->ends_on >= $date), 'closed' => $l->closed_at !== null, 'recorded_at' => (string) $l->created_at];
    }

    private function ruleSummary(object $r, TerritorialActor $actor): array
    {
        $doc = $r->source_document_id === null ? null : $this->rt->documentProjection($actor, (int) $r->source_document_id);
        return ['code' => (string) $r->code, 'version' => (int) $r->version, 'component' => (string) $r->component, 'method' => (string) $r->method, 'rate' => $r->rate === null ? null : (string) $r->rate,
            'starts_on' => (string) $r->starts_on, 'ends_on' => $r->ends_on, 'status' => (string) $r->status, 'source_document' => $doc,
            'provenance' => ['created_by' => $this->actorName((int) $r->created_by), 'created_at' => (string) $r->created_at, 'approved_by' => $this->actorName($r->approved_by === null ? null : (int) $r->approved_by),
                'approved_at' => $r->approved_at, 'retired_at' => $r->retired_at, 'drafted_by_me' => (int) $r->created_by === $actor->user],
            'actions' => array_values(array_filter([
                $r->status === PayrollCatalog::RULE_DRAFT && (int) $r->created_by !== $actor->user && $this->rt->authority->holdsAnywhere($actor, PayrollCatalog::PAYROLL_RULES_APPROVE) ? 'approve' : null,
                $r->status !== PayrollCatalog::RULE_RETIRED && $this->rt->authority->holdsAnywhere($actor, PayrollCatalog::PAYROLL_RULES_APPROVE) ? 'retire' : null,
            ]))];
    }
}
