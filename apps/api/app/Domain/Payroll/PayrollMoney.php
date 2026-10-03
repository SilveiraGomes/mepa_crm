<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceError;

/**
 * Payroll decimal arithmetic (ADR 0021 D02 + D-04A.14): AOA, business scale 2, one rounding mode — HALF-UP per component
 * line, away from zero, on exact decimal strings (bcmath). No float ever takes part in a payroll value.
 */
final class PayrollMoney
{
    private const WORK_SCALE = 12;

    /** A business amount: non-negative decimal string, at most 2 decimals (more decimals => AMOUNT_SCALE). */
    public static function parse(mixed $value, string $field = 'amount'): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^(\d{1,15})(?:\.(\d{1,12}))?$/D', $value, $m) !== 1) {
            throw new FinanceError('AMOUNT_INVALID', [], ['field' => $field]);
        }
        $fraction = $m[2] ?? '';
        if (strlen(rtrim($fraction, '0')) > PayrollCatalog::MONEY_SCALE) {
            throw new FinanceError('AMOUNT_SCALE', [], ['field' => $field]);
        }
        return bcadd($m[1] . '.' . ($fraction === '' ? '0' : $fraction), '0', PayrollCatalog::MONEY_SCALE);
    }

    /** A rate fraction in [0, 1] with at most 6 decimals (DECIMAL(9,6)). */
    public static function rate(mixed $value, string $field = 'rate'): string
    {
        if (!is_string($value) || preg_match('/^(\d)(?:\.(\d{1,12}))?$/D', $value, $m) !== 1) {
            throw new FinanceError('RATE_INVALID', [], ['field' => $field]);
        }
        if (strlen(rtrim($m[2] ?? '', '0')) > PayrollCatalog::RATE_SCALE) {
            throw new FinanceError('RATE_INVALID', [], ['field' => $field]);
        }
        $rate = bcadd($value, '0', PayrollCatalog::RATE_SCALE);
        if (bccomp($rate, '1', PayrollCatalog::RATE_SCALE) > 0) {
            throw new FinanceError('RATE_INVALID', [], ['field' => $field]);
        }
        return $rate;
    }

    /** Half-up (away from zero) to $scale decimals of an exact decimal string. */
    public static function roundHalfUp(string $value, int $scale = PayrollCatalog::MONEY_SCALE): string
    {
        if (preg_match('/^-?\d+(\.\d+)?$/D', $value) !== 1) {
            throw new FinanceError('INVARIANT_VIOLATION', [], ['reason' => 'not_a_decimal']);
        }
        $half = '0.' . str_repeat('0', $scale) . '5';
        $rounded = str_starts_with($value, '-') ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);
        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $rounded;
    }

    /** One RATE component line: round_half_up(base x rate). */
    public static function applyRate(string $base, string $rate): string
    {
        return self::roundHalfUp(bcmul($base, $rate, self::WORK_SCALE));
    }

    /**
     * One BRACKET component line: the bracket with lower <= base < upper (upper NULL = open), then
     * round_half_up(fixed_amount + (base - excess_over) x rate).
     *
     * @param list<array{lower_bound: string, upper_bound: ?string, rate: string, fixed_amount: string, excess_over: string}> $brackets
     */
    public static function applyBrackets(string $base, array $brackets): string
    {
        foreach ($brackets as $b) {
            if (bccomp($base, (string) $b['lower_bound'], self::WORK_SCALE) >= 0 && ($b['upper_bound'] === null || bccomp($base, (string) $b['upper_bound'], self::WORK_SCALE) < 0)) {
                $taxable = bcsub($base, (string) $b['excess_over'], self::WORK_SCALE);
                if (bccomp($taxable, '0', self::WORK_SCALE) < 0) {
                    $taxable = '0';
                }
                return self::roundHalfUp(bcadd((string) $b['fixed_amount'], bcmul($taxable, (string) $b['rate'], self::WORK_SCALE), self::WORK_SCALE));
            }
        }
        throw new FinanceError('PAYROLL_RULE_INVALID', [], ['reason' => 'no_bracket_for_base']);
    }

    /** Storage (DECIMAL(19,4)) => business string with 2 decimals. */
    public static function fromStorage(?string $stored): ?string
    {
        return $stored === null ? null : bcadd($stored, '0', PayrollCatalog::MONEY_SCALE);
    }
}
