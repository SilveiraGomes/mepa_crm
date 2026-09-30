<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Files\FilesAuthority;
use App\Domain\Files\FilesCatalog;
use App\Domain\Files\FilesConsumers;
use App\Domain\Files\FilesError;
use App\Domain\Territorial\TerritorialActor;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Collaborator bundle + transactional boundary of every Finance application service (ADR 0021 D17, D19, D30).
 *
 * write(): one business transaction (bounded deadlock retry x3, every attempt re-validates everything)
 *   1. organizational_structure_lock NATIONAL_TREE FOR SHARE (authority coverage depends on the tree)
 *   2. actor + session validated
 *   3. the service checks permissions BEFORE resolving targets (F-06), then takes its locks in the Finance order
 *      period -> unit close -> subledger document (transfer / contribution) -> accounts -> journal header / idempotency
 *      and only then decides authority on the owner unit it read under lock
 *   4. business writes + mandatory audit in the same transaction (a failed audit rolls everything back)
 *   5. COMMIT-TIME RECHECK, locks held: actor/session and every recorded decision re-read FOR SHARE, the Files
 *      authority of every referenced supporting document re-evaluated FOR SHARE.
 * read(): no transaction, one authorization pass.
 */
final class FinanceRuntime
{
    public function __construct(
        public Connection $db,
        public FinanceAuthority $authority,
        public FilesAuthority $files,
        public FilesConsumers $consumers,
        public array $settings = [],
        private ?Closure $beforeCommit = null,
        private ?Closure $peopleFactory = null
    ) {
    }

    private ?\App\Domain\People\PeopleRuntime $people = null;

    /**
     * An identified contributor that is a Person must be visible to the actor through PeopleAuthority (People is consumed,
     * never written); otherwise the Person is concealed (404). Fails closed when People is not wired.
     */
    public function canSeePerson(TerritorialActor $actor, int $personId): bool
    {
        if ($this->peopleFactory === null) {
            return false;
        }
        $this->people ??= ($this->peopleFactory)();
        return $this->people->authority->canView(new \App\Domain\People\PeopleActor($actor->user, $actor->session, null), $personId);
    }

    public function write(int $user, int $session, callable $work): mixed
    {
        try {
            return $this->db->transaction(function () use ($user, $session, $work) {
                if (!$this->db->table('organizational_structure_lock')->where('code', 'NATIONAL_TREE')->sharedLock()->exists()) {
                    throw new FinanceError('CONFIG_MISSING', [], ['reason' => 'tree_lock_missing']);
                }
                $actor = $this->authority->actor($user, $session);
                $guard = new FinanceGuard($this->authority, $actor);
                $result = $work($guard, $actor);
                if ($this->beforeCommit !== null) {
                    ($this->beforeCommit)();
                }
                $locked = $this->authority->actor($user, $session, true);
                foreach ($guard->decisions() as $decision) {
                    $this->authority->recheck($locked, $decision);
                }
                foreach ($guard->documents() as $document) {
                    $this->documentAuthority($locked, $document, true);
                }
                return $result;
            }, 3);
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    public function read(int $user, int $session, callable $work): mixed
    {
        try {
            $actor = $this->authority->actor($user, $session);
            return $work(new FinanceGuard($this->authority, $actor), $actor);
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    // ---- supporting documents (D14: public_id only, Finance AND Files authority, owner = the financial object's unit) --

    /**
     * Resolves a supporting-document public_id AFTER the Finance decision. Unknown, malformed, owned by another unit,
     * above the actor's clearance, archived or unversioned documents all raise the same TARGET_NOT_FOUND (concealed 404).
     * A numeric id is never accepted (the HTTP layer refuses every *_id field).
     */
    public function supportingDocument(FinanceGuard $guard, TerritorialActor $actor, mixed $publicId, int $ownerUnit): ?object
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }
        $document = is_string($publicId) && preg_match(FilesCatalog::PUBLIC_ID_PATTERN, $publicId) === 1
            ? $this->db->table('legal_documents')->where('public_id', $publicId)->sharedLock()->first() : null;
        if (!$document || (int) $document->owner_unit_id !== $ownerUnit) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'legal_documents']);
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
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'legal_documents', 'stage' => $lock ? 'locked' : 'read']);
        }
        if ($document->status !== FilesCatalog::DOCUMENT_ACTIVE) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'legal_documents', 'reason' => 'not_active']);
        }
    }

    /** Projection rule: the document public_id only when the actor passes the Files authority. */
    public function documentProjection(TerritorialActor $actor, ?int $documentId): ?array
    {
        if ($documentId === null) {
            return null;
        }
        $document = $this->db->table('legal_documents')->where('id', $documentId)->first();
        if (!$document) {
            return ['public_id' => null];
        }
        try {
            $this->documentAuthority($actor, $document);
            return ['public_id' => (string) $document->public_id];
        } catch (FinanceError) {
            return ['public_id' => null];
        }
    }

    // ---- clock / paging --------------------------------------------------------------------------------------------

    public function ts(): string
    {
        return (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
    }

    /** Civil date in Africa/Luanda of now. */
    public function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(FinanceCatalog::TIMEZONE)))->format('Y-m-d');
    }

    /** Civil date in Africa/Luanda of a UTC instant. */
    public static function luandaDate(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(FinanceCatalog::TIMEZONE))->format('Y-m-d');
    }

    /**
     * The UTC instant recorded for a business date: now when the date is today (Luanda), otherwise 12:00 Luanda of that
     * date. Refuses malformed dates and dates in the future.
     */
    public function instantFor(?string $date): array
    {
        $today = $this->today();
        if ($date === null || $date === '' || $date === $today) {
            return [$today, $this->ts()];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'date']);
        }
        if ($date > $today) {
            throw new FinanceError('ENTRY_DATE_IN_FUTURE');
        }
        $utc = (new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone(FinanceCatalog::TIMEZONE)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        return [$date, $utc];
    }

    public function perPage(mixed $requested): int
    {
        $default = (int) ($this->settings['pagination']['default'] ?? 50);
        $max = (int) ($this->settings['pagination']['max'] ?? 100);
        $value = $requested === null ? $default : (int) $requested;
        if ($value < 1 || $value > $max) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'per_page']);
        }
        return $value;
    }

    private function translate(QueryException $e): FinanceError
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $reason = match (true) {
            $code === 1062 && str_contains($e->getMessage(), 'uq_transfer_postings') => 'ALREADY_PROCESSED',
            $code === 1062 && str_contains($e->getMessage(), 'uq_journal_entries_reversal_of_id') => 'ALREADY_REVERSED',
            $code === 1062 => 'STORAGE_CONFLICT',
            $code === 1452 => 'INVALID_INPUT',
            in_array($code, [3819, 4025], true) => 'INVARIANT_VIOLATION',
            in_array($code, [1205, 1213], true) => 'BUSY',
            default => 'STORAGE_CONFLICT',
        };
        return new FinanceError($reason, [], ['db_error_code' => $code], $e);
    }
}
