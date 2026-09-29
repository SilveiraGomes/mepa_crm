<?php

declare (strict_types=1);
namespace App\Domain\Events;

// Events-owned answer to "does this registration still link its Person to the event's owner unit?",
// consumed by the People resolver (ADR-0017 D01, P05R-F01). People never names a registration or
// event state: a registration authorizes only while this policy permits its own state and its
// event's state under the event's policy version, the same checks credential issue and check-in
// apply (a cancelled registration is REGISTRATION_CANCELLED there). No configured policy
// (D-05/D-11 open => POLICY_NOT_CONFIGURED) means no registration authorizes: fail closed.
final class EventParticipationContext
{
    public function __construct(private ?EventPolicy $policy)
    {
    }
    /** Built from the same server-owned access policy the Events services use. */
    public static function fromConfig(array $access): self
    {
        try {
            return new self(new EventPolicy((string) ($access['version'] ?? ''), (array) ($access['states'] ?? []), false, (array) ($access['credential_type_ids'] ?? [])));
        } catch (EventError) {
            return new self(null);
        }
    }
    public function isAuthorizingPersonContext(string $registrationStatus, string $eventStatus, string $eventPolicyVersion): bool
    {
        return $this->policy !== null && $eventPolicyVersion === $this->policy->version
            && $this->policy->permits('registrations', $registrationStatus) && $this->policy->permits('events', $eventStatus);
    }
    /**
     * SQL equivalent of isAuthorizingPersonContext() over a registration alias and its event alias.
     * @return array{0: string, 1: array}
     */
    public function predicate(string $registration, string $event): array
    {
        $registrations = $this->policy?->permitted('registrations') ?? [];
        $events = $this->policy?->permitted('events') ?? [];
        if ($registrations === [] || $events === []) {
            return ['1 = 0', []];
        }
        $in = fn (array $values): string => implode(',', array_fill(0, count($values), '?'));
        return ["{$event}.eligibility_policy_version = ? AND {$registration}.status IN ({$in($registrations)}) AND {$event}.status IN ({$in($events)})", [$this->policy->version, ...$registrations, ...$events]];
    }
}
