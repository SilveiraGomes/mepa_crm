<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceError;

/**
 * D-04A.15 production gate: PAYROLL ENGINE READY != PAYROLL PRODUCTION ENABLED.
 *
 * `payroll.production_enabled` is a server configuration flag (never a column, never a permission), FALSE by default.
 * While false, configuration (employments, compensation, rules, readiness) works normally and APPROVE / POST / PAY of a
 * payroll run are refused with PAYROLL_PRODUCTION_DISABLED (fail-closed). F2A has no run operation at all; F2B calls
 * assertEnabled() before approve / post / pay.
 */
final class PayrollProduction
{
    public function __construct(private array $settings)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('payroll', []));
    }

    public function enabled(): bool
    {
        return ($this->settings['production_enabled'] ?? false) === true;
    }

    public function assertEnabled(string $operation): void
    {
        if (!$this->enabled()) {
            throw new FinanceError('PAYROLL_PRODUCTION_DISABLED', [], ['operation' => $operation]);
        }
    }

    /** @return array{enabled: bool, status: string, code: ?string, operations: array<string, bool>} */
    public function status(): array
    {
        $enabled = $this->enabled();
        return ['enabled' => $enabled, 'status' => $enabled ? 'ENABLED' : 'DISABLED', 'code' => $enabled ? null : 'PAYROLL_PRODUCTION_DISABLED',
            // F2A: the run pipeline (calculate / approve / post / pay) is not implemented; nothing can be operated.
            'operations' => ['calculate' => false, 'approve' => false, 'post' => false, 'pay' => false], 'engine' => 'F2A_FOUNDATION'];
    }
}
