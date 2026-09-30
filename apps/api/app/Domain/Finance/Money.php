<?php

declare(strict_types=1);

namespace App\Domain\Finance;

/**
 * ADR 0021 D02: money is a validated decimal STRING (or an integer number of kwanzas) converted to integer cents. No float
 * is ever accepted or produced. More than 2 decimals is REJECTED (AMOUNT_SCALE), never rounded. Storage is DECIMAL(19,4);
 * values read back must carry zeros beyond the business scale.
 */
final class Money
{
    /** |value| <= 999 999 999 999,99 (D02). */
    public const MAX_CENTS = 99_999_999_999_999;
    public const SCALE = 2;

    /** Business input -> cents. Accepts "1234", "1234.5", "1234.56"; rejects signs, exponents, separators, > 2 decimals. */
    public static function cents(string|int $value, bool $allowZero = false): int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > intdiv(self::MAX_CENTS, 100)) {
                throw new FinanceError($value < 0 ? 'AMOUNT_NOT_POSITIVE' : 'AMOUNT_LIMIT');
            }
            $cents = $value * 100;
        } else {
            if (preg_match('/^(\d+)(?:\.(\d+))?$/D', $value, $m) !== 1) {
                throw new FinanceError(str_starts_with(trim($value), '-') ? 'AMOUNT_NOT_POSITIVE' : 'AMOUNT_INVALID');
            }
            $fraction = $m[2] ?? '';
            if (strlen($fraction) > self::SCALE) {
                throw new FinanceError('AMOUNT_SCALE');
            }
            $integer = ltrim($m[1], '0');
            if (strlen($integer) > 12) {
                throw new FinanceError('AMOUNT_LIMIT');
            }
            $cents = (int) ($integer === '' ? '0' : $integer) * 100 + (int) str_pad($fraction, self::SCALE, '0');
        }
        if ($cents > self::MAX_CENTS) {
            throw new FinanceError('AMOUNT_LIMIT');
        }
        if ($cents === 0 && !$allowZero) {
            throw new FinanceError('AMOUNT_NOT_POSITIVE');
        }
        return $cents;
    }

    /** A DECIMAL(19,4) column value -> cents; non-zero digits beyond the business scale are a data error. */
    public static function fromDecimal(string|int $stored): int
    {
        $stored = (string) $stored;
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,4}))?$/D', $stored, $m) !== 1) {
            throw new FinanceError('AMOUNT_INVALID');
        }
        $fraction = str_pad($m[3] ?? '', 4, '0');
        if (substr($fraction, self::SCALE) !== '00') {
            throw new FinanceError('AMOUNT_SCALE');
        }
        $cents = (int) $m[2] * 100 + (int) substr($fraction, 0, self::SCALE);
        return $m[1] === '-' ? -$cents : $cents;
    }

    /** cents -> canonical decimal string with 2 places (what is written to DECIMAL columns). */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
