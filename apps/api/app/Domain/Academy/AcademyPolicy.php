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

    // Business effect a transition carries. NONE is an explicit declaration ("this transition produces
    // no effect that a specialized operation guards"), never a default: an approved transition that does
    // not declare its effect cannot be executed by the generic engine (POLICY_NOT_CONFIGURED).
    public const EFFECT_NONE = 'NONE';
    public const EFFECT_COMPLETION = 'COMPLETION';
    public const EFFECTS = [self::EFFECT_NONE, self::EFFECT_COMPLETION];

    // Cross-check between an effect and the role set the policy itself lists for it: a transition into
    // a state of that set MUST declare the effect, and a transition declaring the effect MUST land in
    // that set. Both come from the server configuration; no state name is known to the code.
    private const EFFECT_TARGETS = [self::EFFECT_COMPLETION => ['enrollments', 'completed']];

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

    /** Non-throwing form of version(): false while the policy has no version (D-11 open). */
    public function isVersioned(): bool
    {
        return $this->version !== null && trim($this->version) !== '';
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

    /** Read-only UI projection. Missing D-11 configuration is represented by an empty list. */
    public function configuredSet(string $kind, string $role): array
    {
        $values = $this->states[$kind]['sets'][$role] ?? [];
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    /** Only approved transitions are projected. Pending proposals never become UI choices. */
    public function approvedTransitions(string $kind): array
    {
        $safe = [];
        foreach ($this->transitions['approved'][$kind] ?? [] as $entry) {
            if (is_array($entry) && is_string($entry[0] ?? null) && is_string($entry[1] ?? null)) {
                $safe[] = ['from' => $entry[0], 'to' => $entry[1]];
            }
        }
        return $safe;
    }

    // The effect an APPROVED transition declares. Third element of the approved entry: [from, to, effect].
    // Anything but exactly one recognised declaration, or a declaration that contradicts the role sets
    // (see EFFECT_TARGETS), is ambiguous and fails closed.
    public function effect(string $kind, string $from, string $to): string
    {
        $declared = [];
        foreach ($this->transitions['approved'][$kind] ?? [] as $entry) {
            if (($entry[0] ?? null) === $from && ($entry[1] ?? null) === $to) {
                $declared[] = $entry[2] ?? null;
            }
        }
        $declared = array_values(array_unique($declared, SORT_REGULAR));
        if (count($declared) !== 1 || !is_string($declared[0]) || !in_array($declared[0], self::EFFECTS, true)) {
            throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, ['missing' => 'transitions.' . $kind . '.effect']);
        }
        foreach (self::EFFECT_TARGETS as $effect => [$effectKind, $role]) {
            $lands = $kind === $effectKind && $this->inOptionalSet($effectKind, $role, $to);
            if ($lands !== ($declared[0] === $effect)) {
                throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, ['ambiguous' => 'transitions.' . $kind . '.effect', 'effect' => $effect]);
            }
        }
        return $declared[0];
    }
}
