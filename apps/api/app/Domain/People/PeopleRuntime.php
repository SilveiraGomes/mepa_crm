<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\WaveFour\DomainClock;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

// Collaborator bundle + the transactional boundary of every People / Families service.
//
// write(): one business transaction (deadlock retry x5)
//   1. actor + session validated (plain reads)
//   2. the service authorizes each affected Person through PeopleGuard (recorded decisions)
//   3. business writes + mandatory audit in the same transaction
//   4. FINAL check: actor, session and every recorded decision re-verified with FOR SHARE reads as the
//      last statements before commit, so a grant/context revoked while waiting on locks cannot commit.
// read(): no transaction, one authorization pass, nothing written.
final class PeopleRuntime
{
    private ?PeopleCrypto $crypto = null;

    public function __construct(
        public Connection $db,
        public PeopleAuthority $authority,
        public PeopleAudit $audit,
        public OpaqueRef $refs,
        private Closure $cryptoFactory,
        public array $settings = []
    ) {
    }

    public function crypto(): PeopleCrypto
    {
        return $this->crypto ??= ($this->cryptoFactory)();
    }

    public function write(int $user, int $session, callable $work): mixed
    {
        try {
            return $this->db->transaction(function () use ($user, $session, $work) {
                $actor = $this->authority->actor($user, $session);
                $guard = new PeopleGuard($this->authority, $actor);
                $result = $work($guard, $actor);
                $this->authority->actor($user, $session, true);
                foreach ($guard->decisions() as $decision) {
                    $this->authority->recheck($actor, $decision);
                }
                return $result;
            }, 5);
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    public function read(int $user, int $session, callable $work): mixed
    {
        try {
            $actor = $this->authority->actor($user, $session);
            return $work(new PeopleGuard($this->authority, $actor), $actor);
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    public function now(): DateTimeImmutable
    {
        return DomainClock::now($this->db);
    }

    public function ts(): string
    {
        return $this->now()->format('Y-m-d H:i:s.u');
    }

    /** Calendar date of the institution (Africa/Luanda) used for age and birth validation. */
    public function today(): DateTimeImmutable
    {
        return $this->now()->setTimezone(new DateTimeZone((string) ($this->settings['timezone'] ?? 'Africa/Luanda')));
    }

    public function majority(): int
    {
        return (int) ($this->settings['majority_age'] ?? 18);
    }

    public function perPage(mixed $requested): int
    {
        $default = (int) ($this->settings['pagination']['default'] ?? 50);
        $max = (int) ($this->settings['pagination']['max'] ?? 100);
        $value = $requested === null ? $default : (int) $requested;
        if ($value < 1 || $value > $max) {
            throw new PeopleError(PeopleReason::INVALID_INPUT, ['field' => 'per_page']);
        }
        return $value;
    }

    private function translate(QueryException $e): PeopleError
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $reason = match (true) {
            $code === 1062 => PeopleReason::STORAGE_CONFLICT,
            $code === 1452 => PeopleReason::REFERENCE_NOT_FOUND,
            in_array($code, [3819, 4025], true) => PeopleReason::INVARIANT_VIOLATION,
            default => PeopleReason::STORAGE_CONFLICT,
        };
        return new PeopleError($reason, ['db_error_code' => $code], $e);
    }
}
