<?php

declare (strict_types=1);
namespace App\Domain\Events;

// Server-owned configuration only. No institutional defaults while D-05/D-11 are open.
final class EventPolicy
{
    public function __construct(public string $version, private array $states, public bool $invitationRequired, public array $credentialTypeIds)
    {
        foreach (['users', 'auth_grants', 'devices', 'events', 'sessions', 'registrations', 'lists', 'credentials', 'templates', 'composition', 'ministerial', 'departments', 'memberships'] as $kind) {
            if (empty($states[$kind]) || !is_array($states[$kind])) {
                throw new EventError('POLICY_NOT_CONFIGURED');
            }
        }
        if ($version === '' || !$credentialTypeIds) {
            throw new EventError('POLICY_NOT_CONFIGURED');
        }
    }
    public function permits(string $kind, string $state): bool
    {
        return in_array($state, $this->states[$kind] ?? [], true);
    }
}
