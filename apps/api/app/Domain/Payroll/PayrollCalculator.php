<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceError;

/**
 * The payroll calculation (ADR 0021 D24-D26 + D-04A.14/15): a PURE, deterministic function of the canonical input
 * payload (PayrollInputHash::payload, the very preimage of the run's input_hash). Nothing outside the payload reaches a
 * figure, so the hash covers every input of the calculation by construction.
 *
 * Per employment of the population (employments of the unit intersecting the service month):
 *   1. full-month coverage: the employment and every compensation line in use must cover the whole service month.
 *      A partial month needs a proration policy, and V1 has none (no 30-day / calendar-day / working-day assumption,
 *      never a full salary paid silently): PAYROLL_PRORATION_POLICY_MISSING (fail closed).
 *   2. FIXED_AMOUNT / MANUAL components: the line's amount (source FIXED / MANUAL).
 *   3. RATE_RULE / BRACKET_RULE components: ONLY the approved rule in the payload (source RULE; a typed amount is never
 *      accepted for them): base = Σ the employee's amounts of the rule's base components (an absent component is not
 *      configured for that person and contributes nothing); FLAT -> half_up(base x rate); BRACKET -> half_up(fixed +
 *      (base - excess_over) x rate) of the bracket lower <= base < upper. Rule components whose base contains another
 *      pending rule component are resolved in dependency order; a cycle is PAYROLL_RULE_INVALID.
 *   4. every component line is rounded HALF-UP to 2 decimals individually; totals are exact sums of rounded lines:
 *      gross = Σ EARNING, deductions = Σ EMPLOYEE_DEDUCTION, employer charges = Σ EMPLOYER_CHARGE (never mixed),
 *      net = gross - deductions. net < 0 has no approved policy (no automatic debt of the employee): PAYROLL_NET_NEGATIVE.
 * Run totals = Σ employee totals (= Σ lines by class); headcount = population size.
 */
final class PayrollCalculator
{
    private const S = PayrollCatalog::MONEY_SCALE;

    /**
     * @param array<string, array{nature: string, method: string}> $components catalog by code
     * @return array{employees: list<array{employment: string, lines: list<array>, gross: string, deductions: string, employer_charges: string, net: string}>,
     *               totals: array{gross: string, deductions: string, employer_charges: string, net: string}, headcount: int}
     */
    public static function calculate(array $payload, array $components): array
    {
        $from = (string) $payload['period']['from'];
        $to = (string) $payload['period']['to'];
        if ($payload['employments'] === []) {
            throw new FinanceError('PAYROLL_CONFIGURATION_NOT_READY', ['NO_ACTIVE_EMPLOYMENT']);
        }
        $rules = [];
        foreach ($payload['rules'] as $rule) {
            $rules[(string) $rule['component']] = $rule;
        }
        $byEmployment = [];
        foreach ($payload['compensations'] as $line) {
            $byEmployment[(string) $line['employment']][(string) $line['component']][] = $line;
        }
        $employees = [];
        $totals = ['gross' => self::zero(), 'deductions' => self::zero(), 'employer_charges' => self::zero(), 'net' => self::zero()];
        foreach ($payload['employments'] as $e) {
            $employment = (string) $e['public_id'];
            if ((string) $e['starts_on'] > $from || ($e['ends_on'] !== null && (string) $e['ends_on'] < $to)) {
                throw new FinanceError('PAYROLL_PRORATION_POLICY_MISSING', [$employment], ['reason' => 'employment_partial_month']);
            }
            $configured = $byEmployment[$employment] ?? [];
            $amounts = [];
            $lines = [];
            $pendingRules = [];
            foreach ($configured as $code => $group) {
                $type = $components[$code] ?? null;
                if ($type === null) {
                    throw new FinanceError('PAYROLL_CONFIGURATION_NOT_READY', ['UNKNOWN_COMPONENT:' . $code]);
                }
                if (count($group) !== 1 || (string) $group[0]['starts_on'] > $from || ($group[0]['ends_on'] !== null && (string) $group[0]['ends_on'] < $to)) {
                    // Two lines in one month (a mid-month change) or a line starting / ending inside the month.
                    throw new FinanceError('PAYROLL_PRORATION_POLICY_MISSING', [$employment . ':' . $code], ['reason' => 'compensation_partial_month']);
                }
                if (in_array($type['method'], PayrollCatalog::RULE_METHODS, true)) {
                    if (!isset($rules[$code])) {
                        throw new FinanceError('PAYROLL_RULE_MISSING', [$code], ['component' => $code]);
                    }
                    $pendingRules[$code] = $rules[$code];
                    continue;
                }
                if ($group[0]['amount'] === null) {
                    throw new FinanceError('PAYROLL_CONFIGURATION_NOT_READY', ['INVALID_EFFECTIVITY:' . $employment . ':' . $code]);
                }
                $amount = PayrollMoney::roundHalfUp((string) $group[0]['amount']);
                $amounts[$code] = $amount;
                $lines[$code] = ['component' => $code, 'nature' => $type['nature'], 'source' => $type['method'] === PayrollCatalog::MANUAL ? 'MANUAL' : 'FIXED',
                    'base_amount' => null, 'rate' => null, 'amount' => $amount, 'rule' => null];
            }
            if (!in_array(PayrollCatalog::EARNING, array_map(fn (string $c) => $components[$c]['nature'], array_keys($configured)), true)) {
                throw new FinanceError('PAYROLL_CONFIGURATION_NOT_READY', ['MISSING_COMPENSATION:' . $employment]);
            }
            ksort($pendingRules, SORT_STRING);
            while ($pendingRules !== []) {
                $progress = false;
                foreach ($pendingRules as $code => $rule) {
                    if (array_intersect($rule['base_components'], array_keys($pendingRules)) !== []) {
                        continue;
                    }
                    $base = self::zero();
                    foreach ($rule['base_components'] as $baseCode) {
                        if (isset($amounts[$baseCode])) {
                            $base = bcadd($base, $amounts[$baseCode], self::S);
                        }
                    }
                    [$amount, $rate] = self::applyRule($rule, $base);
                    $amounts[$code] = $amount;
                    $lines[$code] = ['component' => $code, 'nature' => $components[$code]['nature'], 'source' => 'RULE', 'base_amount' => $base, 'rate' => $rate, 'amount' => $amount,
                        'rule' => ['code' => (string) $rule['code'], 'version' => (int) $rule['version']]];
                    unset($pendingRules[$code]);
                    $progress = true;
                }
                if (!$progress) {
                    throw new FinanceError('PAYROLL_RULE_INVALID', array_keys($pendingRules), ['reason' => 'base_cycle']);
                }
            }
            ksort($lines, SORT_STRING);
            $gross = $deductions = $charges = self::zero();
            foreach ($lines as $line) {
                match ($line['nature']) {
                    PayrollCatalog::EARNING => $gross = bcadd($gross, $line['amount'], self::S),
                    PayrollCatalog::EMPLOYEE_DEDUCTION => $deductions = bcadd($deductions, $line['amount'], self::S),
                    PayrollCatalog::EMPLOYER_CHARGE => $charges = bcadd($charges, $line['amount'], self::S),
                };
            }
            $net = bcsub($gross, $deductions, self::S);
            if (bccomp($net, '0', self::S) < 0) {
                throw new FinanceError('PAYROLL_NET_NEGATIVE', [$employment], ['reason' => 'needs_policy']);
            }
            $employees[] = ['employment' => $employment, 'lines' => array_values($lines), 'gross' => $gross, 'deductions' => $deductions, 'employer_charges' => $charges, 'net' => $net];
            $totals['gross'] = bcadd($totals['gross'], $gross, self::S);
            $totals['deductions'] = bcadd($totals['deductions'], $deductions, self::S);
            $totals['employer_charges'] = bcadd($totals['employer_charges'], $charges, self::S);
            $totals['net'] = bcadd($totals['net'], $net, self::S);
        }
        return ['employees' => $employees, 'totals' => $totals, 'headcount' => count($employees)];
    }

    /** @return array{0: string, 1: string} [amount rounded half-up per line, rate applied] */
    private static function applyRule(array $rule, string $base): array
    {
        if ($rule['method'] === PayrollCatalog::FLAT_RATE) {
            return [PayrollMoney::applyRate($base, (string) $rule['rate']), (string) $rule['rate']];
        }
        foreach ($rule['brackets'] as $b) {
            if (bccomp($base, (string) $b['lower_bound'], 4) >= 0 && ($b['upper_bound'] === null || bccomp($base, (string) $b['upper_bound'], 4) < 0)) {
                return [PayrollMoney::applyBrackets($base, $rule['brackets']), (string) $b['rate']];
            }
        }
        throw new FinanceError('PAYROLL_RULE_INVALID', [(string) $rule['code']], ['reason' => 'no_bracket_for_base']);
    }

    private static function zero(): string
    {
        return bcadd('0', '0', self::S);
    }
}
