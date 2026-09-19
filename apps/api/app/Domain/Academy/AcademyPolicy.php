<?php

declare(strict_types=1);

namespace App\Domain\Academy;

// Server-owned state vocabulary and transition catalog. Constructed only from trusted server
// configuration (config/academy.php); no institutional defaults while D-09/D-11 are open, so a
// missing entry is POLICY_NOT_CONFIGURED, never a guessed value. State names are data here and
// are referred to elsewhere only by kind + role (e.g. 'enrollments' / 'completed').
final class AcademyPolicy
{
    public const APPROVED = 'APPROVED';
    public const PENDING = 'PENDING';
    public const UNKNOWN = 'UNKNOWN';

    public function __construct(private ?string $version, private array $states, private array $transitions)
    {
    }

    public static function fromConfig(array $config): self
    {
        return new self($config['policy_version'] ?? null, $config['states'] ?? [], $config['transitions'] ?? []);
    }

    public function version(): string
    {
        if ($this->version === null || trim($this->version) === '') {
            throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, ['missing' => 'policy_version']);
        }
        return $this->version;
    }

    public function initial(string $kind): string
    {
        $initial = $this->states[$kind]['initial'] ?? null;
        if (!is_string($initial) || $initial === '') {
            throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, ['missing' => $kind . '.initial']);
        }
        return $initial;
    }

    // Every state name configured for a role; an unconfigured role is never "empty means allow".
    public function set(string $kind, string $role): array
    {
        $set = $this->states[$kind]['sets'][$role] ?? null;
        if (!is_array($set) || $set === []) {
            throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, ['missing' => $kind . '.' . $role]);
        }
        return $set;
    }

    public function inSet(string $kind, string $role, string $state): bool
    {
        return in_array($state, $this->set($kind, $role), true);
    }

    // Optional roles (e.g. which transitions count as an approval): absent means "none", never "all".
    public function inOptionalSet(string $kind, string $role, string $state): bool
    {
        $set = $this->states[$kind]['sets'][$role] ?? [];
        return is_array($set) && in_array($state, $set, true);
    }

    // The state a role moves an entity to (first configured name of the role).
    public function target(string $kind, string $role): string
    {
        return $this->set($kind, $role)[0];
    }

    public function transition(string $kind, string $from, string $to): string
    {
        foreach ($this->transitions['approved'][$kind] ?? [] as [$f, $t]) {
            if ($f === $from && $t === $to) {
                return self::APPROVED;
            }
        }
        foreach ($this->transitions['pending'][$kind] ?? [] as [$f, $t]) {
            if ($f === $from && $t === $to) {
                return self::PENDING;
            }
        }
        return self::UNKNOWN;
    }
}
