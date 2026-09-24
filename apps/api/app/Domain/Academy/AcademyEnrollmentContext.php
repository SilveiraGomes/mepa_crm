<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Academy-owned answer to "does this enrollment still link its Person to the class's unit?",
// consumed by the People resolver (ADR-0017 D01, P05R-F01). People never names an enrollment
// state: an enrollment authorizes only while the Academy policy lists its state in the
// 'enrollments' / 'operational' role, the same set attendance, attempts and progress require.
// D-11 stays open: an unconfigured policy (no version or no operational set) authorizes nothing.
final class AcademyEnrollmentContext
{
    public function __construct(private AcademyPolicy $policy)
    {
    }

    public static function fromConfig(array $config): self
    {
        return new self(AcademyPolicy::fromConfig($config));
    }

    public function isAuthorizingPersonContext(string $enrollmentStatus): bool
    {
        return in_array($enrollmentStatus, $this->operational(), true);
    }

    /**
     * SQL equivalent of isAuthorizingPersonContext() over an enrollment alias.
     * @return array{0: string, 1: array}
     */
    public function predicate(string $enrollment): array
    {
        $states = $this->operational();
        if ($states === []) {
            return ['1 = 0', []];
        }
        return ["{$enrollment}.status IN (" . implode(',', array_fill(0, count($states), '?')) . ')', $states];
    }

    private function operational(): array
    {
        return $this->policy->isVersioned() ? $this->policy->configuredSet('enrollments', 'operational') : [];
    }
}
