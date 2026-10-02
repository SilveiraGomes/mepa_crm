<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinanceGuard;
use App\Domain\Territorial\TerritorialActor;

/**
 * Statutory rules (ADR 0021 D25 + D-04A.15). Nothing is seeded: every rule is loaded by a person from an official source.
 *
 * draft():   PAYROLL_RULES_MANAGE at national level. A new VERSION of `code` (versions are immutable once approved; a
 *            change is a new version). The component must be rule-based (RATE_RULE -> FLAT_RATE, BRACKET_RULE ->
 *            BRACKET); FLAT needs a rate in [0,1]; BRACKET needs contiguous brackets from 0; both need base components.
 * approve(): PAYROLL_RULES_APPROVE at national level, by someone other than the drafter (SEGREGATION_REQUIRED), with a
 *            PAYROLL_RULE_SOURCE document (Files authority, cumulative). Under the component row lock (serializes every
 *            approval of the component) no other APPROVED version may intersect the vigência -> RULE_OVERLAP.
 * retire():  APPROVED / DRAFT -> RETIRED (kept as history; a retired version is never resolved).
 */
final class PayrollRuleService extends PayrollService
{
    public function draft(int $user, int $session, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($in): array {
            $national = $this->nationalUnit($guard, $actor, PayrollCatalog::PAYROLL_RULES_MANAGE);
            $code = $in['code'] ?? null;
            if (!is_string($code) || preg_match(PayrollCatalog::RULE_CODE_PATTERN, $code) !== 1) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'code']);
            }
            $component = $this->component($in['component'] ?? null, true);
            $method = $in['method'] ?? null;
            $expected = [PayrollCatalog::RATE_RULE => PayrollCatalog::FLAT_RATE, PayrollCatalog::BRACKET_RULE => PayrollCatalog::BRACKET][$component->calculation_method] ?? null;
            if ($expected === null) {
                throw new FinanceError('COMPONENT_NOT_RULE_BASED', [(string) $component->code], ['field' => 'component']);
            }
            if ($method !== $expected) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'method']);
            }
            $startsOn = $this->date($in['starts_on'] ?? null, 'starts_on');
            $endsOn = ($in['ends_on'] ?? null) === null ? null : $this->date($in['ends_on'], 'ends_on');
            if ($endsOn !== null && $endsOn < $startsOn) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'ends_on']);
            }
            $rate = $method === PayrollCatalog::FLAT_RATE ? PayrollMoney::rate($in['rate'] ?? null) : null;
            if ($method === PayrollCatalog::BRACKET && ($in['rate'] ?? null) !== null) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'rate']);
            }
            $brackets = [];
            if ($method === PayrollCatalog::BRACKET) {
                if (!is_array($in['brackets'] ?? null) || !array_is_list($in['brackets']) || count($in['brackets']) > 50) {
                    throw new FinanceError('INVALID_INPUT', [], ['field' => 'brackets']);
                }
                foreach ($in['brackets'] as $i => $b) {
                    $brackets[] = ['lower_bound' => PayrollMoney::parse($b['lower_bound'] ?? null, "brackets.$i.lower_bound"),
                        'upper_bound' => ($b['upper_bound'] ?? null) === null ? null : PayrollMoney::parse($b['upper_bound'], "brackets.$i.upper_bound"),
                        'rate' => PayrollMoney::rate($b['rate'] ?? null, "brackets.$i.rate"), 'fixed_amount' => PayrollMoney::parse($b['fixed_amount'] ?? '0', "brackets.$i.fixed_amount"),
                        'excess_over' => PayrollMoney::parse($b['excess_over'] ?? '0', "brackets.$i.excess_over")];
                }
                usort($brackets, fn ($a, $b) => bccomp($a['lower_bound'], $b['lower_bound'], 4));
                PayrollRuleResolver::assertBrackets($brackets, $code);
            } elseif (($in['brackets'] ?? []) !== []) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'brackets']);
            }
            $base = $in['base_components'] ?? null;
            if (!is_array($base) || $base === [] || !array_is_list($base) || count($base) > 20 || count(array_unique($base)) !== count($base)) {
                throw new FinanceError('INVALID_INPUT', [], ['field' => 'base_components']);
            }
            $baseRows = [];
            foreach ($base as $baseCode) {
                $b = $this->component($baseCode);
                if ($b->nature !== PayrollCatalog::EARNING) {
                    throw new FinanceError('INVALID_INPUT', [], ['field' => 'base_components']);
                }
                $baseRows[] = $b;
            }
            $versions = $this->rt->db->table('payroll_rules')->where('code', $code)->lockForUpdate()->get(['version', 'component_type_id']);
            if ($versions->contains(fn ($v) => (int) $v->component_type_id !== (int) $component->id)) {
                throw new FinanceError('RULE_CODE_COMPONENT_MISMATCH', [$code]);
            }
            $version = (int) ($versions->max('version') ?? 0) + 1;
            $document = $this->rt->supportingDocument($guard, $actor, $in['source_document'] ?? null, $national);
            $this->assertSourceType($document);
            $now = $this->rt->ts();
            $id = (int) $this->rt->db->table('payroll_rules')->insertGetId(['code' => $code, 'version' => $version, 'component_type_id' => $component->id, 'method' => $method, 'rate' => $rate,
                'starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => PayrollCatalog::RULE_DRAFT, 'source_document_id' => $document?->id, 'created_by' => $actor->user, 'created_at' => $now,
                'approved_by' => null, 'approved_at' => null, 'retired_by' => null, 'retired_at' => null, 'lock_version' => 0]);
            foreach ($baseRows as $b) {
                $this->rt->db->table('payroll_rule_base_components')->insert(['rule_id' => $id, 'component_type_id' => $b->id, 'created_at' => $now]);
            }
            foreach ($brackets as $b) {
                $this->rt->db->table('payroll_rule_brackets')->insert(['rule_id' => $id] + $b + ['created_at' => $now]);
            }
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.rule_drafted', 'payroll_rules', $id, $national,
                ['rule' => $code, 'version' => $version, 'component' => (string) $component->code, 'method' => $method, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'brackets' => count($brackets)], null, $actor->session);
            return ['code' => $code, 'version' => $version, 'status' => PayrollCatalog::RULE_DRAFT];
        });
    }

    public function approve(int $user, int $session, string $code, int $version, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($code, $version, $in): array {
            $national = $this->nationalUnit($guard, $actor, PayrollCatalog::PAYROLL_RULES_APPROVE);
            $peek = $this->rule($code, $version);
            // Lock order: component (serializes all approvals of the component, HC2) -> rule.
            $this->rt->db->table('compensation_component_types')->where('id', $peek->component_type_id)->lockForUpdate()->first();
            $rule = $this->rt->db->table('payroll_rules')->where('id', $peek->id)->lockForUpdate()->first();
            if ($rule->status !== PayrollCatalog::RULE_DRAFT) {
                throw new FinanceError('TRANSITION_NOT_ALLOWED');
            }
            if ((int) $rule->created_by === $actor->user) {
                throw new FinanceError('SEGREGATION_REQUIRED');
            }
            $document = ($in['source_document'] ?? null) !== null ? $this->rt->supportingDocument($guard, $actor, $in['source_document'], $national)
                : ($rule->source_document_id === null ? null : $this->rt->db->table('legal_documents')->where('id', $rule->source_document_id)->sharedLock()->first());
            if ($document === null) {
                throw new FinanceError('RULE_DOCUMENT_REQUIRED', [], ['field' => 'source_document']);
            }
            if (($in['source_document'] ?? null) === null) {
                // The drafter's document is re-checked against the APPROVER's Files authority (cumulative).
                if ((int) $document->owner_unit_id !== $national) {
                    throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'legal_documents']);
                }
                $this->rt->documentAuthority($actor, $document, true);
                $guard->document($document);
            }
            $this->assertSourceType($document);
            (new PayrollRuleResolver($this->rt->db))->complete($rule);
            $overlap = $this->rt->db->table('payroll_rules')->where('component_type_id', $rule->component_type_id)->where('status', PayrollCatalog::RULE_APPROVED)->where('id', '<>', $rule->id)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $rule->starts_on))
                ->when($rule->ends_on !== null, fn ($q) => $q->where('starts_on', '<=', $rule->ends_on))->lockForUpdate()->get(['code', 'version']);
            if ($overlap->isNotEmpty()) {
                throw new FinanceError('RULE_OVERLAP', $overlap->map(fn ($r) => $r->code . '@' . $r->version)->all());
            }
            $changed = $this->rt->db->table('payroll_rules')->where('id', $rule->id)->where('lock_version', $rule->lock_version)
                ->update(['status' => PayrollCatalog::RULE_APPROVED, 'source_document_id' => $document->id, 'approved_by' => $actor->user, 'approved_at' => $this->rt->ts(), 'lock_version' => $rule->lock_version + 1]);
            if ($changed !== 1) {
                throw new FinanceError('STALE_WRITE');
            }
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.rule_approved', 'payroll_rules', (int) $rule->id, $national,
                ['rule' => $code, 'version' => $version, 'source_document' => (string) $document->public_id, 'starts_on' => (string) $rule->starts_on, 'ends_on' => $rule->ends_on], null, $actor->session);
            return ['code' => $code, 'version' => $version, 'status' => PayrollCatalog::RULE_APPROVED];
        });
    }

    public function retire(int $user, int $session, string $code, int $version, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($code, $version, $in): array {
            $national = $this->nationalUnit($guard, $actor, PayrollCatalog::PAYROLL_RULES_APPROVE);
            $peek = $this->rule($code, $version);
            $this->rt->db->table('compensation_component_types')->where('id', $peek->component_type_id)->lockForUpdate()->first();
            $rule = $this->rt->db->table('payroll_rules')->where('id', $peek->id)->lockForUpdate()->first();
            if ($rule->status === PayrollCatalog::RULE_RETIRED) {
                throw new FinanceError('TRANSITION_NOT_ALLOWED');
            }
            $reason = $this->text($in['reason'] ?? null, 'reason');
            $this->rt->db->table('payroll_rules')->where('id', $rule->id)->update(['status' => PayrollCatalog::RULE_RETIRED, 'retired_by' => $actor->user, 'retired_at' => $this->rt->ts(), 'lock_version' => $rule->lock_version + 1]);
            PayrollAudit::write($this->rt->db, $actor->user, 'payroll.rule_retired', 'payroll_rules', (int) $rule->id, $national, ['rule' => $code, 'version' => $version, 'previous_status' => (string) $rule->status], $reason, $actor->session);
            return ['code' => $code, 'version' => $version, 'status' => PayrollCatalog::RULE_RETIRED];
        });
    }

    private function rule(string $code, int $version): object
    {
        $row = preg_match(PayrollCatalog::RULE_CODE_PATTERN, $code) === 1 && $version >= 1 ? $this->rt->db->table('payroll_rules')->where('code', $code)->where('version', $version)->first() : null;
        if (!$row) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'payroll_rules']);
        }
        return $row;
    }

    private function assertSourceType(?object $document): void
    {
        if ($document !== null && $this->rt->db->table('legal_document_types')->where('id', $document->document_type_id)->value('code') !== PayrollCatalog::RULE_SOURCE_DOCUMENT) {
            throw new FinanceError('RULE_DOCUMENT_TYPE', [], ['field' => 'source_document']);
        }
    }
}
