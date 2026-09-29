<?php

declare(strict_types=1);

namespace App\Domain\People;

use DateTimeImmutable;

// Birth precision (ADR-0017 D-10): EXACT (real full date), MONTH (year + month, no day), YEAR (year
// only) and UNKNOWN (no component). Nothing is ever completed with an artificial day or month.
final class BirthDate
{
    public const EXACT = 'EXACT';
    public const MONTH = 'MONTH';
    public const YEAR = 'YEAR';
    public const UNKNOWN = 'UNKNOWN';
    public const PRECISIONS = [self::EXACT, self::MONTH, self::YEAR, self::UNKNOWN];

    public const MINOR = 'MINOR';
    public const ADULT = 'ADULT';
    public const UNCERTAIN = 'UNCERTAIN';

    /**
     * Validates the request fields for one precision and returns the four storage columns.
     * Fields that do not belong to the precision must be absent or null: they are never ignored silently.
     */
    public static function columns(array $in, DateTimeImmutable $today): array
    {
        $precision = $in['birth_precision'] ?? null;
        $date = $in['birth_date'] ?? null;
        $year = $in['birth_year'] ?? null;
        $month = $in['birth_month'] ?? null;
        $fail = fn (string $field) => new PeopleError(PeopleReason::INVALID_INPUT, ['field' => $field]);
        $currentYear = (int) $today->format('Y');
        $currentMonth = (int) $today->format('n');
        switch ($precision) {
            case self::EXACT:
                if (!is_string($date) || $year !== null || $month !== null) {
                    throw $fail('birth_date');
                }
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date > $today->format('Y-m-d') || (int) $parsed->format('Y') < 1900) {
                    throw $fail('birth_date');
                }
                return ['birth_precision' => self::EXACT, 'birth_date' => $date, 'birth_year' => null, 'birth_month' => null];
            case self::MONTH:
                if ($date !== null || !self::isInt($year) || !self::isInt($month)) {
                    throw $fail('birth_month');
                }
                $year = (int) $year;
                $month = (int) $month;
                if ($year < 1900 || $month < 1 || $month > 12 || $year > $currentYear || ($year === $currentYear && $month > $currentMonth)) {
                    throw $fail('birth_month');
                }
                return ['birth_precision' => self::MONTH, 'birth_date' => null, 'birth_year' => $year, 'birth_month' => $month];
            case self::YEAR:
                if ($date !== null || $month !== null || !self::isInt($year) || (int) $year < 1900 || (int) $year > $currentYear) {
                    throw $fail('birth_year');
                }
                return ['birth_precision' => self::YEAR, 'birth_date' => null, 'birth_year' => (int) $year, 'birth_month' => null];
            case self::UNKNOWN:
                if ($date !== null || $year !== null || $month !== null) {
                    throw $fail('birth_precision');
                }
                return ['birth_precision' => self::UNKNOWN, 'birth_date' => null, 'birth_year' => null, 'birth_month' => null];
            default:
                throw $fail('birth_precision');
        }
    }

    /**
     * MINOR / ADULT when the stored precision proves it, UNCERTAIN when the 18th birthday may or may not
     * have passed (treated as protected), null when nothing is known.
     */
    public static function ageBand(object $person, DateTimeImmutable $today, int $majority): ?string
    {
        $ymd = $today->format('Y-m-d');
        switch ($person->birth_precision) {
            case self::EXACT:
                $birth = new DateTimeImmutable((string) $person->birth_date);
                return $birth->modify('+' . $majority . ' years')->format('Y-m-d') <= $ymd ? self::ADULT : self::MINOR;
            case self::MONTH:
                // The 18th birthday falls somewhere in month (birth_year + 18, birth_month).
                $firstDay = sprintf('%04d-%02d-01', (int) $person->birth_year + $majority, (int) $person->birth_month);
                $monthAfter = (new DateTimeImmutable($firstDay))->modify('first day of next month')->format('Y-m-d');
                return $ymd < $firstDay ? self::MINOR : ($ymd >= $monthAfter ? self::ADULT : self::UNCERTAIN);
            case self::YEAR:
                $threshold = (int) $person->birth_year + $majority;
                $year = (int) $today->format('Y');
                return $year < $threshold ? self::MINOR : ($year > $threshold ? self::ADULT : self::UNCERTAIN);
            default:
                return null;
        }
    }

    public static function projection(object $person): array
    {
        return match ($person->birth_precision) {
            self::EXACT => ['precision' => self::EXACT, 'date' => (string) $person->birth_date],
            self::MONTH => ['precision' => self::MONTH, 'year' => (int) $person->birth_year, 'month' => (int) $person->birth_month],
            self::YEAR => ['precision' => self::YEAR, 'year' => (int) $person->birth_year],
            default => ['precision' => self::UNKNOWN],
        };
    }

    private static function isInt(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d{1,4}$/D', $value) === 1);
    }
}
