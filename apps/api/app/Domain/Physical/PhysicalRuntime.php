<?php

declare(strict_types=1);

namespace App\Domain\Physical;

use App\Domain\People\PeopleCrypto;
use App\Domain\People\PeopleError;
use App\Domain\Territorial\TerritorialActor;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

// Collaborator bundle + transactional boundary of every Physical service (ADR 0018 D11).
//
// write(): one business transaction (deadlock retry x5)
//   1. organizational_structure_lock NATIONAL_TREE FOR SHARE (serializes with territorial moves)
//   2. actor + session validated
//   3. the service takes its locks in the fixed order units (ascending id) -> physical_locations ->
//      properties/temples -> unit_location_links, and authorizes through PhysicalGuard
//   4. business writes + mandatory audit in the same transaction
//   5. COMMIT-TIME RECHECK, locks held: actor/session and every recorded decision re-read FOR SHARE, then the
//      link invariants (one primary per unit, one active link per unit/location, operational location keeps an
//      active link, publication requirements) for every touched unit/location.
// read(): no transaction, one authorization pass, nothing written.
//
// Crypto: the P0.5 primitive and key ring (PeopleCrypto: AES-256-GCM, key_version per row, fail-closed); its
// errors are mapped to the Physical domain. There is no plaintext fallback.
final class PhysicalRuntime
{
    private ?PeopleCrypto $crypto = null;

    public function __construct(
        public Connection $db,
        public PhysicalAuthority $authority,
        public PhysicalAudit $audit,
        public PhysicalRef $refs,
        private Closure $cryptoFactory,
        private ?Closure $ownerProjector = null,
        public array $settings = [],
        private ?Closure $beforeCommit = null
    ) {
    }

    public function write(int $user, int $session, callable $work): mixed
    {
        try {
            return $this->db->transaction(function () use ($user, $session, $work) {
                $this->treeShared();
                $actor = $this->authority->actor($user, $session);
                $guard = new PhysicalGuard($this->authority, $actor);
                $result = $work($guard, $actor);
                if ($this->beforeCommit !== null) {
                    ($this->beforeCommit)();
                }
                $this->commitRecheck($user, $session, $guard);
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
            return $work(new PhysicalGuard($this->authority, $actor), $actor);
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    private function commitRecheck(int $user, int $session, PhysicalGuard $guard): void
    {
        $actor = $this->authority->actor($user, $session, true);
        foreach ($guard->decisions() as $decision) {
            $this->authority->recheck($actor, $decision);
        }
        PhysicalInvariants::assert($this->db, $guard->units(), $guard->locations(), $this->ts());
    }

    // ---- lock order helpers (D11) -----------------------------------------------------------------------

    private function treeShared(): void
    {
        if (!$this->db->table('organizational_structure_lock')->where('code', 'NATIONAL_TREE')->sharedLock()->exists()) {
            throw new PhysicalError(PhysicalReason::CONFIG_MISSING, ['reason' => 'tree_lock_missing']);
        }
    }

    /** @param list<int> $ids */
    public function lockUnits(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->db->table('organizational_units')->where('id', $id)->lockForUpdate()->first(['id', 'public_id', 'name', 'status']);
            if ($row) {
                $rows[$id] = $row;
            }
        }
        return $rows;
    }

    public function lockLocation(int $id): object
    {
        $row = $this->db->table('physical_locations')->where('id', $id)->lockForUpdate()->first();
        if (!$row) {
            throw new PhysicalError(PhysicalReason::TARGET_NOT_FOUND, ['entity' => 'physical_locations']);
        }
        return $row;
    }

    /** Locks every link of the location plus the ACTIVE links of the given units, in ascending id order. */
    public function lockLinks(int $location, array $units = []): array
    {
        $query = $this->db->table('unit_location_links')->where('location_id', $location);
        if ($units !== []) {
            $query->orWhere(fn ($q) => $q->whereIn('unit_id', array_map('intval', $units))->where('status', PhysicalCatalog::LINK_ACTIVE));
        }
        $rows = [];
        foreach ($query->orderBy('id')->lockForUpdate()->get() as $row) {
            $rows[(int) $row->id] = $row;
        }
        return $rows;
    }

    // ---- crypto (fail closed, mapped to the Physical domain) --------------------------------------------

    /** @return array{0: string, 1: int} */
    public function encrypt(string $plaintext, string $aad): array
    {
        try {
            return $this->crypto()->encrypt($plaintext, $aad);
        } catch (PeopleError $e) {
            throw new PhysicalError(PhysicalReason::CRYPTO_UNAVAILABLE, ['reason' => (string) ($e->context['reason'] ?? 'crypto')]);
        }
    }

    public function decrypt(string $blob, int $version, string $aad): string
    {
        try {
            return $this->crypto()->decrypt($blob, $version, $aad);
        } catch (PeopleError $e) {
            throw new PhysicalError(PhysicalReason::CRYPTO_UNAVAILABLE, ['reason' => (string) ($e->context['reason'] ?? 'crypto')]);
        }
    }

    private function crypto(): PeopleCrypto
    {
        return $this->crypto ??= ($this->cryptoFactory)();
    }

    /**
     * Owner Person projection delegated to People (ADR 0018 D07): {public_id, display_name} only when the actor
     * independently holds People authority over that Person; null otherwise (fail closed).
     */
    public function ownerPerson(TerritorialActor $actor, int $personId): ?array
    {
        if ($this->ownerProjector === null) {
            return null;
        }
        try {
            $projection = ($this->ownerProjector)($actor, $personId);
        } catch (\Throwable) {
            return null;
        }
        return is_array($projection) ? $projection : null;
    }

    // ---- misc ---------------------------------------------------------------------------------------------

    public function ts(): string
    {
        return $this->authority->now();
    }

    public function perPage(mixed $requested): int
    {
        $default = (int) ($this->settings['pagination']['default'] ?? 50);
        $max = (int) ($this->settings['pagination']['max'] ?? 100);
        $value = $requested === null ? $default : (int) $requested;
        if ($value < 1 || $value > $max) {
            throw new PhysicalError(PhysicalReason::INVALID_INPUT, ['field' => 'per_page']);
        }
        return $value;
    }

    private function translate(QueryException $e): PhysicalError
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $reason = match (true) {
            $code === 1062 => PhysicalReason::STORAGE_CONFLICT,
            $code === 1452 => PhysicalReason::INVALID_INPUT,
            in_array($code, [3819, 4025], true) => PhysicalReason::INVARIANT_VIOLATION,
            default => PhysicalReason::STORAGE_CONFLICT,
        };
        return new PhysicalError($reason, ['db_error_code' => $code], $e);
    }
}
