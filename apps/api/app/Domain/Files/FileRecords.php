<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;

// Lookups and projections shared by the Files/Documents services. Unknown and malformed public ids are
// TARGET_NOT_FOUND (F-06). Projections expose public ids only: never the PK, disk, storage_key, checksum or key_version.
final class FileRecords
{
    public function __construct(private FilesRuntime $rt)
    {
    }

    public function fileId(mixed $publicId): int
    {
        return $this->idOf('files', $publicId);
    }

    public function documentId(mixed $publicId): int
    {
        return $this->idOf('legal_documents', $publicId);
    }

    public function unitId(mixed $publicId): int
    {
        return $this->idOf('organizational_units', $publicId);
    }

    public function file(int $id, bool $lock = false): object
    {
        $query = $this->rt->db->table('files')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row) {
            throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['entity' => 'files']);
        }
        return $row;
    }

    public function document(int $id, bool $lock = false): object
    {
        $query = $this->rt->db->table('legal_documents')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$row) {
            throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['entity' => 'legal_documents']);
        }
        return $row;
    }

    public function documentType(mixed $code): object
    {
        $row = is_string($code) ? $this->rt->db->table('legal_document_types')->where('code', $code)->where('is_active', 1)->first() : null;
        if (!$row) {
            throw new FilesError(FilesReason::INVALID_INPUT, ['field' => 'type_code']);
        }
        return $row;
    }

    /**
     * Metadata visibility of a file to an actor holding $permission (D01, D04, D05, D07): owner department NULL, scope
     * over the owner unit, clearance >= classification, status AVAILABLE (QUARANTINED/TOMBSTONE only with FILES_MANAGE
     * over the unit; PURGED never), and consumer authority. Every failure is the same concealed TARGET_NOT_FOUND.
     * @return array{0: ?object, 1: list<string>} [owning document, consumer references]
     */
    public function assertVisible(TerritorialActor $actor, object $file, string $permission, bool $lock = false, bool $availableOnly = false): array
    {
        $unit = (int) $file->owner_unit_id;
        $conceal = fn (string $why) => new FilesError(FilesReason::TARGET_NOT_FOUND, ['reason' => $why]);
        if ($file->owner_department_id !== null) {
            throw $conceal('department_owned');
        }
        if (!isset($this->rt->authority->covered($actor, $permission, $lock)[$unit])) {
            throw $conceal('scope');
        }
        if (!FileClassification::allows($this->rt->authority->clearance($actor, $unit, $lock), $file->classification)) {
            throw $conceal('clearance');
        }
        $manage = isset($this->rt->authority->covered($actor, FilesCatalog::FILES_MANAGE, $lock)[$unit]);
        $status = (string) $file->status;
        $available = $status === FilesCatalog::AVAILABLE && $file->deleted_at === null && $file->purged_at === null;
        $managedView = !$availableOnly && $manage && in_array($status, [FilesCatalog::QUARANTINED, FilesCatalog::TOMBSTONE], true) && $file->purged_at === null;
        if (!$available && !$managedView) {
            throw $conceal('status');
        }
        $refs = $this->rt->consumers->fileReferences((int) $file->id, $lock);
        $document = $this->rt->consumers->authorize($this->rt->authority, $actor, $file, $refs, $lock);
        return [$document, $refs];
    }

    /** SQL predicate for file lists: per-unit clearance groups (unknown classifications never match). */
    public function clearancePredicate(string $f, TerritorialActor $actor, array $units): string
    {
        if ($units === []) {
            return '1 = 0';
        }
        $parts = [];
        foreach ($this->rt->authority->clearanceGroups($actor, $units) as $rank => $list) {
            $allowed = implode(',', array_map(fn (string $c): string => "'" . $c . "'", FileClassification::allowedFor((int) $rank)));
            $parts[] = "({$f}.owner_unit_id IN (" . implode(',', array_map('intval', $list)) . ") AND {$f}.classification IN ({$allowed}))";
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    public function assertVersion(object $row, mixed $expected, string $entity): void
    {
        if (!is_numeric($expected) || (int) $expected !== (int) $row->lock_version) {
            throw new FilesError(FilesReason::STALE_WRITE, ['entity' => $entity]);
        }
    }

    public static function requireReason(mixed $reason): string
    {
        $value = is_string($reason) ? trim($reason) : '';
        if (mb_strlen($value) < 3) {
            throw new FilesError(FilesReason::REASON_REQUIRED);
        }
        return mb_substr($value, 0, 2000);
    }

    public static function optionalReason(mixed $reason): ?string
    {
        $value = is_string($reason) ? trim($reason) : '';
        return $value === '' ? null : mb_substr($value, 0, 2000);
    }

    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    // ---- projections ----------------------------------------------------------------------------------------

    public function unit(int $id): array
    {
        $row = $this->rt->db->table('organizational_units')->where('id', $id)->first(['public_id', 'name']);
        return $row ? ['public_id' => (string) $row->public_id, 'name' => (string) $row->name] : ['public_id' => null, 'name' => null];
    }

    /** Metadata projection of a file. NEVER disk, storage_key, checksum, key_version or a numeric key. */
    public function projectFile(object $row, array $extra = []): array
    {
        return [
            'public_id' => (string) $row->public_id,
            'original_name' => (string) $row->original_name,
            'mime_type' => (string) $row->mime_type,
            'size_bytes' => (int) $row->size_bytes,
            'classification' => (string) $row->classification,
            'classification_label' => FileClassification::LABELS[(string) $row->classification] ?? null,
            'status' => (string) $row->status,
            'status_label' => FilesCatalog::FILE_STATUSES[(string) $row->status] ?? null,
            'owner_unit' => $this->unit((int) $row->owner_unit_id),
            'inspection' => FileInspector::INSPECTION_STRUCTURAL,
            'created_at' => (string) $row->created_at,
            'deleted_at' => $row->deleted_at === null ? null : (string) $row->deleted_at,
            'lock_version' => (int) $row->lock_version,
        ] + $extra;
    }

    private function idOf(string $table, mixed $publicId): int
    {
        if (!is_string($publicId) || preg_match(FilesCatalog::PUBLIC_ID_PATTERN, $publicId) !== 1) {
            throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['entity' => $table]);
        }
        $id = $this->rt->db->table($table)->where('public_id', $publicId)->value('id');
        if ($id === null) {
            throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['entity' => $table]);
        }
        return (int) $id;
    }
}
