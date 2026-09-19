<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Technical input validation only (shape and width). Never institutional policy: no minimum,
// maximum, scale or threshold value is decided here.
final class AcademyInput
{
    // DECIMAL(intDigits + scale, scale) as a canonical string; floats are rejected (no rounding).
    public static function decimal(mixed $value, int $intDigits = 5, int $scale = 4): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^\d{1,' . $intDigits . '}(\.\d{1,' . $scale . '})?$/', $value) !== 1) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'decimal']);
        }
        return $value;
    }

    public static function text(mixed $value, int $max = 191, bool $required = true): ?string
    {
        if ($value === null && !$required) {
            return null;
        }
        if (!is_string($value) || ($required && trim($value) === '') || mb_strlen($value) > $max) {
            throw new AcademyError(AcademyReason::INVALID_INPUT, ['field' => 'text']);
        }
        return $value;
    }

    public static function reason(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new AcademyError(AcademyReason::REASON_REQUIRED);
        }
        return $value;
    }

    public static function page(int $page, int $perPage): array
    {
        return [max(1, $page), min(100, max(1, $perPage))];
    }
}
