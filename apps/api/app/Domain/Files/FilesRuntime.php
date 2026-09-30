<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

// Collaborator bundle + transactional boundary of every Documents/Files service (ADR 0019).
//
// write(): one business transaction (deadlock retry x5)
//   1. organizational_structure_lock NATIONAL_TREE FOR SHARE (serializes with territorial moves: scope depends on it)
//   2. actor + session validated
//   3. the service locks its rows (units ascending id -> legal_documents -> files) and authorizes through FilesGuard
//   4. business writes + mandatory audit in the same transaction
//   5. COMMIT-TIME RECHECK, locks held: actor/session and every recorded scope and clearance decision re-read FOR SHARE
// read(): no transaction, one authorization pass; reads never write except the audited sensitive-read rows.
//
// The database and the filesystem share no transaction: the upload pipeline (FileUploadPipeline) orders its steps and
// compensates explicitly. `fault` is a test-only hook (bound in the container by the fault-injection tests) that is a
// no-op in production.
final class FilesRuntime
{
    private ?FilesKeyRing $ring = null;

    public function __construct(
        public Connection $db,
        public FilesAuthority $authority,
        public FilesAudit $audit,
        public FileStorage $storage,
        private Closure $keyRingFactory,
        public FileInspector $inspector,
        public ?FileScanner $scanner,
        public FilesConsumers $consumers,
        public array $settings = [],
        private ?Closure $fault = null
    ) {
    }

    public function write(int $user, int $session, callable $work): mixed
    {
        try {
            return $this->db->transaction(function () use ($user, $session, $work) {
                $this->treeShared();
                $actor = $this->authority->actor($user, $session);
                $guard = new FilesGuard($this->authority, $actor);
                $result = $work($guard, $actor);
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
            return $work(new FilesGuard($this->authority, $actor), $actor);
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    /** System transaction (Cron/reconciler): no actor, same audit rules. */
    public function system(callable $work): mixed
    {
        try {
            return $this->db->transaction(fn () => $work(), 5);
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    private function commitRecheck(int $user, int $session, FilesGuard $guard): void
    {
        $actor = $this->authority->actor($user, $session, true);
        foreach ($guard->scopes() as [$permission, $unit]) {
            if (!isset($this->authority->covered($actor, $permission, true)[$unit])) {
                throw new FilesError(FilesReason::NOT_AUTHORIZED, ['stage' => 'final', 'permission' => $permission]);
            }
        }
        foreach ($guard->clearances() as [$unit, $classification]) {
            if (!FileClassification::allows($this->authority->clearance($actor, $unit, true), $classification)) {
                throw new FilesError(FilesReason::NOT_AUTHORIZED, ['stage' => 'final', 'reason' => 'clearance']);
            }
        }
    }

    private function treeShared(): void
    {
        if (!$this->db->table('organizational_structure_lock')->where('code', 'NATIONAL_TREE')->sharedLock()->exists()) {
            throw new FilesError(FilesReason::CONFIG_MISSING, ['reason' => 'tree_lock_missing']);
        }
    }

    /** Key ring, read for every runtime (a removed or broken ring fails closed on the next request). */
    public function keyRing(): FilesKeyRing
    {
        return $this->ring ??= ($this->keyRingFactory)();
    }

    /** Test-only fault injection point; no-op unless a hook is bound. */
    public function fault(string $point, array $context = []): void
    {
        if ($this->fault !== null) {
            ($this->fault)($point, $context);
        }
    }

    /** @param list<int> $ids @return array<int, object> units locked FOR UPDATE in ascending id order */
    public function lockUnits(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->db->table('organizational_units')->where('id', $id)->lockForUpdate()->first(['id', 'public_id', 'name', 'status']);
            if (!$row) {
                throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['entity' => 'organizational_units']);
            }
            $rows[$id] = $row;
        }
        return $rows;
    }

    /**
     * D02 quotas, evaluated while the owner unit row is locked FOR UPDATE (serializes uploads of the same unit) and
     * the national named lock is held (serializes the national total without touching territorial rows):
     *   unit  : size_bytes of QUARANTINED + AVAILABLE + TOMBSTONE of the owner unit + incoming <= unit quota (2 GiB)
     *   total : the same over every unit <= FILES_TOTAL_QUOTA_BYTES (mandatory in production)
     *   disk  : free space - incoming >= reserve (>= 1 GiB)
     */
    public function assertQuota(int $unit, int $incoming, bool $newContent = true): void
    {
        $unitQuota = (int) ($this->settings['unit_quota_bytes'] ?? 2147483648);
        // LOCKING reads (FOR SHARE): the latest committed rows, never this transaction's older snapshot (lesson P0.7).
        $used = (int) ($this->db->selectOne("SELECT COALESCE(SUM(size_bytes), 0) AS s FROM files WHERE owner_unit_id = ? AND status IN ('QUARANTINED', 'AVAILABLE', 'TOMBSTONE') FOR SHARE", [$unit])->s ?? 0);
        if ($used + $incoming > $unitQuota) {
            throw new FilesError(FilesReason::QUOTA_EXCEEDED, ['scope' => 'unit']);
        }
        if (!$newContent) {
            return;   // an owner change moves existing bytes: the national total and the disk are unchanged
        }
        $total = $this->settings['total_quota_bytes'] ?? null;
        if ($total === null || $total === '') {
            if ((bool) ($this->settings['total_quota_required'] ?? false)) {
                throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'total_quota_not_configured']);
            }
        } else {
            $all = (int) ($this->db->selectOne("SELECT COALESCE(SUM(size_bytes), 0) AS s FROM files WHERE status IN ('QUARANTINED', 'AVAILABLE', 'TOMBSTONE') FOR SHARE")->s ?? 0);
            if ($all + $incoming > (int) $total) {
                throw new FilesError(FilesReason::QUOTA_EXCEEDED, ['scope' => 'national']);
            }
        }
        $this->storage->assertCapacity($incoming);
    }

    public function nationalQuotaLock(): void
    {
        $got = $this->db->selectOne("SELECT GET_LOCK('mepa.files.national_quota', 15) AS g");
        if ((int) ($got->g ?? 0) !== 1) {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'quota_lock_timeout']);
        }
    }

    public function releaseNationalQuotaLock(): void
    {
        try {
            // GET_LOCK is re-entrant per session (a deadlock retry may have taken it twice): release every level.
            for ($i = 0; $i < 10 && (int) ($this->db->selectOne("SELECT RELEASE_LOCK('mepa.files.national_quota') AS r")->r ?? 0) === 1; $i++) {
            }
        } catch (\Throwable) {
            // the lock dies with the session anyway
        }
    }

    public function ts(): string
    {
        return $this->authority->now();
    }

    public function maxFileBytes(): int
    {
        return min(20971520, max(1, (int) ($this->settings['max_file_bytes'] ?? 10485760)));
    }

    public function perPage(mixed $requested): int
    {
        $default = (int) ($this->settings['pagination']['default'] ?? 50);
        $max = (int) ($this->settings['pagination']['max'] ?? 100);
        $value = $requested === null ? $default : (int) $requested;
        if ($value < 1 || $value > $max) {
            throw new FilesError(FilesReason::INVALID_INPUT, ['field' => 'per_page']);
        }
        return $value;
    }

    public function actorFor(int $user, int $session): TerritorialActor
    {
        return $this->authority->actor($user, $session);
    }

    private function translate(QueryException $e): FilesError
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $reason = match (true) {
            $code === 1062 => FilesReason::STORAGE_CONFLICT,
            $code === 1452 => FilesReason::INVALID_INPUT,
            in_array($code, [3819, 4025], true) => FilesReason::INVARIANT_VIOLATION,
            default => FilesReason::STORAGE_CONFLICT,
        };
        return new FilesError($reason, ['db_error_code' => $code], $e);
    }
}
