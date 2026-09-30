<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use DateTimeImmutable;

// Institutional dates with precision (P0.5 vocabulary EXACT/MONTH/YEAR/UNKNOWN), used by admitted_on (D02) and the
// ecclesiastical milestones (D08). A day or month is never invented: MONTH is sent as YYYY-MM and stored on day 01,
// YEAR as YYYY stored on 01-01, UNKNOWN stores NULL. A date after $today (Africa/Luanda civil date) is rejected at its
// own precision (a MONTH value is future only when the whole month is).
final class PrecisionDate
{
    /** @return array{0: ?string, 1: string} [stored DATE or null, precision] */
    public static function parse(mixed $value, mixed $precision, DateTimeImmutable $today, string $field): array
    {
        $precision = is_string($precision) ? $precision : '';
        if (!in_array($precision, MembershipCatalog::PRECISIONS, true)) {
            throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => $field . '_precision']);
        }
        if ($precision === 'UNKNOWN') {
            if ($value !== null && $value !== '') {
                throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => $field]);
            }
            return [null, 'UNKNOWN'];
        }
        $value = is_string($value) ? trim($value) : '';
        [$pattern, $format, $suffix] = match ($precision) {
            'EXACT' => ['/^\d{4}-\d{2}-\d{2}$/D', 'Y-m-d', ''],
            'MONTH' => ['/^\d{4}-\d{2}$/D', 'Y-m-d', '-01'],
            'YEAR' => ['/^\d{4}$/D', 'Y-m-d', '-01-01'],
        };
        if (preg_match($pattern, $value) !== 1) {
            throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => $field]);
        }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value . $suffix);
        if ($date === false || $date->format($format) !== $value . $suffix || (int) $date->format('Y') < 1900) {
            throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => $field]);
        }
        $limit = match ($precision) {
            'EXACT' => $today->format('Y-m-d'),
            'MONTH' => $today->format('Y-m') . '-01',
            'YEAR' => $today->format('Y') . '-01-01',
        };
        if ($date->format('Y-m-d') > $limit) {
            throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => $field, 'reason' => 'future']);
        }
        return [$date->format('Y-m-d'), $precision];
    }

    /** External representation: the date at its precision only (YYYY-MM-DD, YYYY-MM, YYYY or null). */
    public static function present(?string $stored, string $precision): ?string
    {
        if ($stored === null || $precision === 'UNKNOWN') {
            return null;
        }
        return match ($precision) {
            'MONTH' => substr($stored, 0, 7),
            'YEAR' => substr($stored, 0, 4),
            default => substr($stored, 0, 10),
        };
    }
}
