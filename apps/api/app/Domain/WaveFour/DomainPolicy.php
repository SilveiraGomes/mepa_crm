<?php

declare (strict_types=1);
namespace App\Domain\WaveFour;

// Constructed only by trusted server configuration. No institutional defaults.
final class DomainPolicy
{
    public function __construct(public string $version, private array $vocabulary, private array $identifiers, public string $deliveryKind, public string $pickupKind, public string $guardianKind, public string $participationPurpose)
    {
        foreach (['child_profiles', 'guardian_authorizations', 'person_consents', 'child_emergency_contacts', 'child_custody_visits', 'custody_closed', 'authorization_revoked', 'consent_revoked', 'outreach_campaigns', 'outreach_contacts', 'followup_outcomes', 'decision_types', 'date_precisions', 'discipleship_tracks', 'discipleship_enrollments', 'discipleship_progress', 'consent_purposes', 'authorization_kinds', 'age_band_rules', 'department_transition_recommendations'] as $kind) {
            if (empty($vocabulary[$kind]) || !is_array($vocabulary[$kind])) {
                throw new DomainError('POLICY_NOT_CONFIGURED');
            }
        }
        if ($version === '' || !$identifiers) {
            throw new DomainError('POLICY_NOT_CONFIGURED');
        }
        foreach ([$deliveryKind, $pickupKind, $guardianKind] as $value) {
            $this->require('authorization_kinds', $value);
        }
        $this->require('consent_purposes', $participationPurpose);
    }
    public function require(string $kind, string $value): void
    {
        if (!in_array($value, $this->vocabulary[$kind] ?? [], true)) {
            throw new DomainError('POLICY_VALUE_NOT_CONFIGURED');
        }
    }
    public function state(string $kind): string
    {
        return $this->vocabulary[$kind][0];
    }
    public function identify(string $method, bool $confirmed): void
    {
        if (!$confirmed || !in_array($method, $this->identifiers, true)) {
            throw new DomainError('IDENTITY_NOT_VERIFIED');
        }
    }
}
