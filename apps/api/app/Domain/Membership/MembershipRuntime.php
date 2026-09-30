<?php

declare(strict_types=1);

namespace App\Domain\Membership;

use App\Domain\Files\FilesAuthority;
use App\Domain\Files\FilesCatalog;
use App\Domain\Files\FilesConsumers;
use App\Domain\Files\FilesError;
use App\Domain\People\PeopleActor;
use App\Domain\People\PeopleRuntime;
use App\Domain\People\PersonRecords;
use App\Domain\Territorial\TerritorialActor;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

// Collaborator bundle + transactional boundary of every Membership service (ADR 0020 D05, D12).
//
// write(): one business transaction (deadlock retry x5)
//   1. organizational_structure_lock NATIONAL_TREE FOR SHARE (serializes with territorial moves)
//   2. actor + session validated
//   3. the service takes its locks in the GLOBAL order Person -> membership(s) (ascending id) -> transfer ->
//      periods -> national counter, and only then decides authority (on the Congregation it read under lock)
//   4. business writes + mandatory audit in the same transaction
//   5. COMMIT-TIME RECHECK, locks held: actor/session and every recorded decision re-read FOR SHARE, the Files
//      authority of every referenced source document re-evaluated FOR SHARE, then MembershipInvariants on every
//      touched membership (one open period, status copy, contiguous history, number immutability, transfer coherence).
// read(): no transaction, one authorization pass, nothing written (except audited sensitive lookups).
//
// People and Files are consumed, never written: PeopleAuthority decides whether the actor sees a Person (D01.2) and
// PersonRecords gives the minor-safe projection; FilesConsumers::documentAuthority is the Documents adapter (D09).
final class MembershipRuntime
{
    private ?PeopleRuntime $people = null;

    public function __construct(
        public Connection $db,
        public MembershipAuthority $authority,
        public MembershipAudit $audit,
        private Closure $peopleFactory,
        public FilesAuthority $files,
        public FilesConsumers $consumers,
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
                $guard = new MembershipGuard($this->authority, $actor);
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
            return $work(new MembershipGuard($this->authority, $actor), $actor);
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    private function commitRecheck(int $user, int $session, MembershipGuard $guard): void
    {
        $actor = $this->authority->actor($user, $session, true);
        foreach ($guard->decisions() as $decision) {
            $this->authority->recheck($actor, $decision);
        }
        foreach ($guard->documents() as $document) {
            $this->documentAuthority($actor, $document, true);
        }
        MembershipInvariants::assert($this->db, $guard);
    }

    private function treeShared(): void
    {
        if (!$this->db->table('organizational_structure_lock')->where('code', 'NATIONAL_TREE')->sharedLock()->exists()) {
            throw new MembershipError(MembershipReason::CONFIG_MISSING, ['reason' => 'tree_lock_missing']);
        }
    }

    // ---- People (consumed, never written) ------------------------------------------------------------------------

    public function people(): PeopleRuntime
    {
        return $this->people ??= ($this->peopleFactory)();
    }

    /** D01.2: the actor must see the Person through PeopleAuthority (any tier), otherwise the Person is concealed. */
    public function canSeePerson(TerritorialActor $actor, int $personId): bool
    {
        return $this->people()->authority->canView(new PeopleActor($actor->user, $actor->session, null), $personId);
    }

    /** Minor-safe People projection (public_id, display name, status, age band): the only Person data Membership shows. */
    public function personProjection(int $personId): array
    {
        $records = new PersonRecords($this->people());
        $row = $records->row($personId);
        $minimal = $records->minimal($row, $records->childProfiles([$personId]) !== []);
        return [
            'public_id' => $minimal['public_id'],
            'display_name' => $minimal['display_name'],
            'status' => $minimal['status'],
            'deceased' => $minimal['status'] === 'DECEASED',
            'protected_minor' => $minimal['protected_minor'],
            'age_band' => $minimal['age_band'],
        ];
    }

    // ---- Files (Documents adapter, D09) ----------------------------------------------------------------------------

    /**
     * Resolves a source_document public_id AFTER the Membership decision. Unknown, malformed, other unit, above the
     * clearance, archived or unversioned documents all raise the same TARGET_NOT_FOUND (concealed 404).
     */
    public function sourceDocument(MembershipGuard $guard, TerritorialActor $actor, mixed $publicId): ?object
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }
        $document = is_string($publicId) && preg_match(FilesCatalog::PUBLIC_ID_PATTERN, $publicId) === 1
            ? $this->db->table('legal_documents')->where('public_id', $publicId)->sharedLock()->first() : null;
        if (!$document) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'legal_documents']);
        }
        $this->documentAuthority($actor, $document, true);
        $guard->document($document);
        return $document;
    }

    public function documentAuthority(TerritorialActor $actor, object $document, bool $lock = false): void
    {
        try {
            $this->consumers->documentAuthority($this->files, $actor, $document, $lock);
        } catch (FilesError) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'legal_documents', 'stage' => $lock ? 'locked' : 'read']);
        }
        if ($document->status !== FilesCatalog::DOCUMENT_ACTIVE) {
            throw new MembershipError(MembershipReason::TARGET_NOT_FOUND, ['entity' => 'legal_documents', 'reason' => 'not_active']);
        }
    }

    /** Projection rule of D09: the document public_id only when the actor passes the Files authority. */
    public function documentProjection(TerritorialActor $actor, ?int $documentId): array
    {
        if ($documentId === null) {
            return ['has_document' => false, 'source_document' => null];
        }
        $document = $this->db->table('legal_documents')->where('id', $documentId)->first();
        if (!$document) {
            return ['has_document' => true, 'source_document' => null];
        }
        try {
            $this->documentAuthority($actor, $document);
            return ['has_document' => true, 'source_document' => ['public_id' => (string) $document->public_id]];
        } catch (MembershipError) {
            return ['has_document' => true, 'source_document' => null];
        }
    }

    // ---- clock -------------------------------------------------------------------------------------------------

    public function ts(): string
    {
        return (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
    }

    /**
     * Instant of a period change for one membership: the server clock, strictly after the open period's start so the
     * closed period keeps ends_at > starts_at even when two commits share a clock tick. The closed period ends and the
     * new one starts at this same instant (contiguous history, D03).
     */
    public function after(string $openStartsAt): string
    {
        $now = $this->ts();
        if ($now > $openStartsAt) {
            return $now;
        }
        return (new DateTimeImmutable($openStartsAt, new DateTimeZone('UTC')))->modify('+1 microsecond')->format('Y-m-d H:i:s.u');
    }

    /** Civil date in Africa/Luanda (D02) of a UTC instant. */
    public static function luanda(string $utc): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(MembershipCatalog::NUMBER_TIMEZONE));
    }

    public function today(): DateTimeImmutable
    {
        return self::luanda($this->ts())->setTime(0, 0);
    }

    public function perPage(mixed $requested): int
    {
        $default = (int) ($this->settings['pagination']['default'] ?? 50);
        $max = (int) ($this->settings['pagination']['max'] ?? 100);
        $value = $requested === null ? $default : (int) $requested;
        if ($value < 1 || $value > $max) {
            throw new MembershipError(MembershipReason::INVALID_INPUT, ['field' => 'per_page']);
        }
        return $value;
    }

    private function translate(QueryException $e): MembershipError
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $reason = match (true) {
            $code === 1062 && str_contains($e->getMessage(), 'uq_memberships_person_id') => MembershipReason::MEMBERSHIP_EXISTS,
            $code === 1062 && str_contains($e->getMessage(), 'uq_transfers_membership_open') => MembershipReason::TRANSFER_IN_PROGRESS,
            $code === 1062 && str_contains($e->getMessage(), 'uq_membership_periods_membership_open') => MembershipReason::INVARIANT_VIOLATION,
            $code === 1062 => MembershipReason::STORAGE_CONFLICT,
            $code === 1452 => MembershipReason::INVALID_INPUT,
            in_array($code, [3819, 4025], true) => MembershipReason::INVARIANT_VIOLATION,
            default => MembershipReason::STORAGE_CONFLICT,
        };
        return new MembershipError($reason, ['db_error_code' => $code], $e);
    }
}
