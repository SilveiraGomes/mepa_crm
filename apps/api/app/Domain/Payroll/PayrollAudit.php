<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceAudit;
use App\Domain\Finance\FinanceError;
use Illuminate\Database\Connection;

/**
 * HR / payroll audit rows (ADR 0021 D19/D20, D28): the same audit_logs writer as Finance, inside the business transaction
 * (a failed audit rolls everything back). Metadata carries public ids, component codes, dates and changed-field
 * indicators only: a person's monetary value, rate or salary NEVER enters before/after metadata.
 */
final class PayrollAudit
{
    private const MONEY_KEYS = ['amount', 'salary', 'base_salary', 'gross', 'net', 'rate', 'value', 'gross_amount', 'net_amount', 'deductions_amount', 'employer_charges_amount',
        'base_amount', 'fixed_amount', 'lower_bound', 'upper_bound', 'excess_over'];

    public static function write(Connection $db, int $actor, string $action, string $entityType, int $entityId, int $unitId, array $after = [], ?string $reason = null, ?int $session = null): void
    {
        self::assertNoMoney($after);
        FinanceAudit::write($db, $actor, $action, $entityType, $entityId, $unitId, FinanceAudit::correlation(), $after, $reason, $session);
    }

    private static function assertNoMoney(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && in_array($key, self::MONEY_KEYS, true)) {
                throw new FinanceError('INVARIANT_VIOLATION', [], ['reason' => 'payroll_audit_money_key', 'key' => $key]);
            }
            if (is_array($value)) {
                self::assertNoMoney($value);
            }
        }
    }
}
