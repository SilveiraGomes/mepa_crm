<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;
use Illuminate\Support\Str;

// Documents and versions (ADR 0019 D08): legal_documents -> document_versions -> files.
//  - a version is IMMUTABLE (file_id, version, issued_on, supersedes_id never change) and its object is never
//    overwritten: a replacement is a NEW file + a NEW version, in one transaction under FOR UPDATE on the document with
//    its lock_version: version = max + 1 (monotonic), supersedes_id = the previous current version;
//  - current version = the highest version (derived, no pointer);
//  - every version's classification is >= the one it supersedes; the document metadata needs clearance >= the highest
//    version classification; version files inherit the document owner unit;
//  - status ACTIVE / ARCHIVED (archive/restore: DOCUMENTS_MANAGE + reason + audit; ARCHIVED documents only for MANAGE);
//  - a version always uses a file that no consumer references yet (a fresh upload, or the explicit, re-authorized reuse
//    of an AVAILABLE, unreferenced file of the same unit by public_id — never the same file in two versions);
//  - owner change moves the document and every version file in one transaction (DOCUMENTS_MANAGE on both units).
final class DocumentService
{
    private FileRecords $records;
    private FileUploadPipeline $pipeline;

    public function __construct(private FilesRuntime $rt)
    {
        $this->records = new FileRecords($rt);
        $this->pipeline = new FileUploadPipeline($rt);
    }

    public function list(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($q): array {
            $guard->requires(FilesCatalog::DOCUMENTS_VIEW);
            $archived = ($q['status'] ?? FilesCatalog::DOCUMENT_ACTIVE) === FilesCatalog::DOCUMENT_ARCHIVED;
            if (!in_array($q['status'] ?? FilesCatalog::DOCUMENT_ACTIVE, [FilesCatalog::DOCUMENT_ACTIVE, FilesCatalog::DOCUMENT_ARCHIVED], true)) {
                throw new FilesError(FilesReason::INVALID_INPUT, ['field' => 'status']);
            }
            $per = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            $query = $this->rt->db->table('legal_documents as d')->join('legal_document_types as t', 't.id', '=', 'd.document_type_id')
                ->whereRaw($this->rt->consumers->documentVisible('d', $this->rt->authority, $actor, $archived));
            if (($q['unit_public_id'] ?? '') !== '') {
                $unit = $this->rt->db->table('organizational_units')->where('public_id', (string) $q['unit_public_id'])->value('id');
                $query->where('d.owner_unit_id', $unit === null ? 0 : (int) $unit);
            }
            if (($q['type_code'] ?? '') !== '') {
                $query->where('t.code', (string) $q['type_code']);
            }
            if (($q['search'] ?? '') !== '') {
                $term = '%' . FileRecords::escapeLike((string) $q['search']) . '%';
                $query->where(fn ($w) => $w->where('d.title', 'like', $term)->orWhere('d.reference', 'like', $term));
            }
            $total = (clone $query)->count('d.id');
            $rows = $query->orderByDesc('d.created_at')->orderByDesc('d.id')->forPage($page, $per)->get(['d.*', 't.code as type_code', 't.name as type_name'])->all();
            return ['items' => array_map(fn (object $row): array => $this->summary($row), $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function detail(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(FilesCatalog::DOCUMENTS_VIEW);
            $document = $this->records->document($this->records->documentId($publicId));
            $classification = $this->rt->consumers->documentAuthority($this->rt->authority, $actor, $document);
            if (FileClassification::rank($classification) >= FileClassification::ORDER[FileClassification::CONFIDENTIAL]) {
                $current = $this->versions((int) $document->id)[0] ?? null;
                if ($current !== null) {
                    $this->rt->audit->record($actor, (int) $document->owner_unit_id, 'file.metadata_read', 'files', (int) $current->file_id, null, [
                        'public_id' => (string) $current->file_public_id, 'classification' => (string) $current->classification, 'document_public_id' => (string) $document->public_id,
                    ]);
                }
            }
            return $this->project($actor, $document, $classification);
        });
    }

    /** Creates a document with its first version (DOCUMENTS_MANAGE; new content also needs FILES_UPLOAD). */
    public function create(int $user, int $session, array $in, ?string $path, mixed $clientName): array
    {
        [$unit, $type, $classification] = $this->rt->read($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($in, $path): array {
            $guard->requires(FilesCatalog::DOCUMENTS_MANAGE, ...($path !== null ? [FilesCatalog::FILES_UPLOAD] : [FilesCatalog::FILES_VIEW]));
            $classification = FileClassification::requested($in['classification'] ?? null);
            $unit = $this->records->unitId($in['owner_unit_public_id'] ?? null);
            $this->rt->authority->requireUnit($actor, FilesCatalog::DOCUMENTS_MANAGE, $unit);
            $this->rt->authority->requireUnit($actor, $path !== null ? FilesCatalog::FILES_UPLOAD : FilesCatalog::FILES_VIEW, $unit);
            $type = $this->records->documentType($in['type_code'] ?? null);
            $this->metadata($in, (string) $type->code, true);
            if ($path !== null && !FileClassification::allows($this->rt->authority->clearance($actor, $unit), $classification)) {
                throw new FilesError(FilesReason::CLEARANCE_REQUIRED, ['reason' => 'document_classification']);
            }
            return [$unit, $type, $classification];
        });
        $fileId = $this->content($user, $session, $in, $path, $clientName, $unit, $classification);
        $id = $this->attaching($path, $fileId, fn () => $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($in, $unit, $type, $fileId): int {
            $this->rt->lockUnits([$unit]);
            $guard->unit(FilesCatalog::DOCUMENTS_MANAGE, $unit);
            $file = $this->attachable($guard, $actor, $fileId, $unit, null, !isset($in['file_public_id']));
            [$reference, $title] = $this->metadata($in, (string) $type->code, true);
            $now = $this->rt->ts();
            $publicId = (string) Str::ulid();
            $id = (int) $this->rt->db->table('legal_documents')->insertGetId([
                'public_id' => $publicId, 'document_type_id' => (int) $type->id, 'owner_unit_id' => $unit, 'reference' => $reference,
                'title' => $title, 'status' => FilesCatalog::DOCUMENT_ACTIVE, 'created_at' => $now, 'lock_version' => 0,
            ]);
            $correlation = $this->rt->audit->record($actor, $unit, 'document.created', 'legal_documents', $id, null, [
                'public_id' => $publicId, 'type_code' => (string) $type->code, 'status' => FilesCatalog::DOCUMENT_ACTIVE, 'classification' => (string) $file->classification,
            ]);
            $this->insertVersion($actor, $id, $publicId, $unit, $file, 1, null, $in['issued_on'] ?? null, $correlation, isset($in['file_public_id']));
            return $id;
        }));
        return $this->detailById($user, $session, $id);
    }

    /** New version = new file + new document_versions row (DOCUMENTS_VERSION_MANAGE; new content also needs FILES_UPLOAD). */
    public function addVersion(int $user, int $session, string $documentPublicId, array $in, ?string $path, mixed $clientName): array
    {
        [$documentId, $unit, $classification] = $this->rt->read($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($documentPublicId, $in, $path): array {
            $guard->requires(FilesCatalog::DOCUMENTS_VERSION_MANAGE, ...($path !== null ? [FilesCatalog::FILES_UPLOAD] : [FilesCatalog::FILES_VIEW]));
            $document = $this->records->document($this->records->documentId($documentPublicId));
            $current = $this->rt->consumers->documentAuthority($this->rt->authority, $actor, $document);
            $unit = (int) $document->owner_unit_id;
            $this->rt->authority->requireUnit($actor, FilesCatalog::DOCUMENTS_VERSION_MANAGE, $unit);
            $this->rt->authority->requireUnit($actor, $path !== null ? FilesCatalog::FILES_UPLOAD : FilesCatalog::FILES_VIEW, $unit);
            if ($document->status !== FilesCatalog::DOCUMENT_ACTIVE) {
                throw new FilesError(FilesReason::DOCUMENT_ARCHIVED);
            }
            $this->records->assertVersion($document, $in['lock_version'] ?? null, 'legal_documents');
            $previous = $this->versions((int) $document->id)[0]->classification;
            $classification = array_key_exists('classification', $in) && $in['classification'] !== null ? FileClassification::requested($in['classification']) : (string) $previous;
            if (FileClassification::rank($classification) < FileClassification::rank($previous)) {
                throw new FilesError(FilesReason::CLASSIFICATION_BELOW_FLOOR, ['reason' => 'version_order']);
            }
            if ($path !== null && !FileClassification::allows($this->rt->authority->clearance($actor, $unit), $classification)) {
                throw new FilesError(FilesReason::CLEARANCE_REQUIRED, ['reason' => 'version_classification']);
            }
            unset($current);
            return [(int) $document->id, $unit, $classification];
        });
        $fileId = $this->content($user, $session, $in, $path, $clientName, $unit, $classification);
        $this->attaching($path, $fileId, fn () => $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($documentId, $unit, $in, $fileId): void {
            $this->rt->lockUnits([$unit]);
            $document = $this->records->document($documentId, true);
            if ((int) $document->owner_unit_id !== $unit) {
                throw new FilesError(FilesReason::STALE_WRITE, ['entity' => 'legal_documents']);
            }
            $this->rt->consumers->documentAuthority($this->rt->authority, $actor, $document, true);
            $guard->unit(FilesCatalog::DOCUMENTS_VERSION_MANAGE, $unit);
            if ($document->status !== FilesCatalog::DOCUMENT_ACTIVE) {
                throw new FilesError(FilesReason::DOCUMENT_ARCHIVED);
            }
            $this->records->assertVersion($document, $in['lock_version'] ?? null, 'legal_documents');
            $current = $this->rt->db->table('document_versions as dv')->join('files as f', 'f.id', '=', 'dv.file_id')->where('dv.document_id', $documentId)
                ->orderByDesc('dv.version')->lockForUpdate()->first(['dv.id', 'dv.public_id', 'dv.version', 'f.classification']);
            $file = $this->attachable($guard, $actor, $fileId, $unit, $current === null ? null : (string) $current->classification, !isset($in['file_public_id']));
            $next = $current === null ? 1 : (int) $current->version + 1;
            $this->rt->db->table('legal_documents')->where('id', $documentId)->update(['lock_version' => (int) $document->lock_version + 1]);
            $this->insertVersion($actor, $documentId, (string) $document->public_id, $unit, $file, $next, $current, $in['issued_on'] ?? null, null, isset($in['file_public_id']));
        }));
        return $this->detailById($user, $session, $documentId);
    }

    public function update(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($publicId, $in): int {
            $guard->requires(FilesCatalog::DOCUMENTS_MANAGE);
            $document = $this->manageable($guard, $actor, $publicId);
            $this->records->assertVersion($document, $in['lock_version'] ?? null, 'legal_documents');
            $type = array_key_exists('type_code', $in) ? $this->records->documentType($in['type_code']) : $this->rt->db->table('legal_document_types')->where('id', $document->document_type_id)->first();
            [$reference, $title] = $this->metadata($in + ['reference' => $document->reference, 'title' => $document->title], (string) $type->code, false);
            $changed = array_keys(array_filter(['type_code' => (int) $type->id !== (int) $document->document_type_id, 'reference' => $reference !== $document->reference, 'title' => $title !== $document->title]));
            if ($changed === []) {
                throw new FilesError(FilesReason::TRANSITION_NOT_ALLOWED, ['reason' => 'no_change']);
            }
            $this->rt->db->table('legal_documents')->where('id', $document->id)->update(['document_type_id' => (int) $type->id, 'reference' => $reference, 'title' => $title, 'lock_version' => (int) $document->lock_version + 1]);
            $this->rt->audit->record($actor, (int) $document->owner_unit_id, 'document.updated', 'legal_documents', (int) $document->id, null, ['public_id' => (string) $document->public_id, 'changed' => $changed, 'type_code' => (string) $type->code], FileRecords::optionalReason($in['reason'] ?? null));
            return (int) $document->id;
        });
        return $this->detailById($user, $session, $id);
    }

    public function archive(int $user, int $session, string $publicId, array $in, bool $archive): array
    {
        $id = $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($publicId, $in, $archive): int {
            $guard->requires(FilesCatalog::DOCUMENTS_MANAGE);
            $document = $this->manageable($guard, $actor, $publicId);
            $reason = FileRecords::requireReason($in['reason'] ?? null);
            $this->records->assertVersion($document, $in['lock_version'] ?? null, 'legal_documents');
            [$from, $to] = $archive ? [FilesCatalog::DOCUMENT_ACTIVE, FilesCatalog::DOCUMENT_ARCHIVED] : [FilesCatalog::DOCUMENT_ARCHIVED, FilesCatalog::DOCUMENT_ACTIVE];
            if ($document->status !== $from) {
                throw new FilesError(FilesReason::TRANSITION_NOT_ALLOWED, ['from' => (string) $document->status]);
            }
            $this->rt->db->table('legal_documents')->where('id', $document->id)->update(['status' => $to, 'lock_version' => (int) $document->lock_version + 1]);
            $this->rt->audit->record($actor, (int) $document->owner_unit_id, $archive ? 'document.archived' : 'document.restored', 'legal_documents', (int) $document->id, ['status' => $from], ['public_id' => (string) $document->public_id, 'status' => $to], $reason);
            return (int) $document->id;
        });
        return $this->detailById($user, $session, $id);
    }

    /** D10: moves the document AND every version file (DOCUMENTS_MANAGE on origin and destination, reason, not in use). */
    public function transferOwner(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($publicId, $in): int {
            $guard->requires(FilesCatalog::DOCUMENTS_MANAGE);
            $id = $this->records->documentId($publicId);
            $origin = (int) $this->records->document($id)->owner_unit_id;
            $to = $this->records->unitId($in['to_unit_public_id'] ?? null);
            $this->rt->lockUnits([$origin, $to]);
            $document = $this->records->document($id, true);
            $classification = $this->rt->consumers->documentAuthority($this->rt->authority, $actor, $document, true);
            $guard->unit(FilesCatalog::DOCUMENTS_MANAGE, $origin);
            $guard->cleared($origin, $classification);
            $this->rt->authority->requireUnit($actor, FilesCatalog::DOCUMENTS_MANAGE, $to, true);
            $guard->unit(FilesCatalog::DOCUMENTS_MANAGE, $to);
            $guard->cleared($to, $classification, FilesReason::CLEARANCE_REQUIRED);
            $reason = FileRecords::requireReason($in['reason'] ?? null);
            $this->records->assertVersion($document, $in['lock_version'] ?? null, 'legal_documents');
            if ((int) $document->owner_unit_id !== $origin || $to === $origin) {
                throw new FilesError($to === $origin ? FilesReason::INVALID_INPUT : FilesReason::STALE_WRITE, ['field' => 'to_unit_public_id']);
            }
            if ($this->rt->consumers->documentReferences($id, true) !== []) {
                throw new FilesError(FilesReason::FILE_IN_USE, ['reason' => 'document_referenced']);
            }
            $files = $this->rt->db->table('document_versions as dv')->join('files as f', 'f.id', '=', 'dv.file_id')->where('dv.document_id', $id)->orderBy('f.id')->lockForUpdate()->get(['f.*'])->all();
            $bytes = 0;
            foreach ($files as $file) {
                if (array_diff($this->rt->consumers->fileReferences((int) $file->id, true), [FilesConsumers::DOCUMENT_VERSIONS]) !== []) {
                    throw new FilesError(FilesReason::FILE_IN_USE, ['reason' => 'version_file_referenced']);
                }
                $bytes += (int) $file->size_bytes;
            }
            $this->rt->assertQuota($to, $bytes, false);
            $this->rt->db->table('legal_documents')->where('id', $id)->update(['owner_unit_id' => $to, 'lock_version' => (int) $document->lock_version + 1]);
            $fromPublic = $this->records->unit($origin)['public_id'];
            $toPublic = $this->records->unit($to)['public_id'];
            $correlation = null;
            foreach ([$origin, $to] as $auditUnit) {
                $correlation = $this->rt->audit->record($actor, $auditUnit, 'document.updated', 'legal_documents', $id, ['owner_unit' => $fromPublic], ['public_id' => (string) $document->public_id, 'owner_unit' => $toPublic, 'changed' => ['owner_unit']], $reason, $correlation);
            }
            foreach ($files as $file) {
                $this->rt->db->table('files')->where('id', $file->id)->update(['owner_unit_id' => $to, 'lock_version' => (int) $file->lock_version + 1]);
                foreach ([$origin, $to] as $auditUnit) {
                    $this->rt->audit->record($actor, $auditUnit, 'file.ownership_changed', 'files', (int) $file->id, ['owner_unit' => $fromPublic], ['public_id' => (string) $file->public_id, 'owner_unit' => $toPublic, 'document_public_id' => (string) $document->public_id], $reason, $correlation);
                }
            }
            return $id;
        });
        return $this->detailById($user, $session, $id);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /**
     * The database and the filesystem share no transaction: when the version transaction fails AFTER a fresh upload
     * became AVAILABLE, that file stays an unattached AVAILABLE file of the same unit (never a broken version) and its
     * public_id is returned so the client can retry by explicit reuse.
     */
    private function attaching(?string $path, int $fileId, callable $work): mixed
    {
        try {
            return $work();
        } catch (FilesError $e) {
            if ($path === null) {
                throw $e;
            }
            $public = (string) $this->rt->db->table('files')->where('id', $fileId)->value('public_id');
            throw new FilesError($e->reason, $e->context, $e, $e->details + ['file_public_id' => $public]);
        }
    }

    /** Content of a new version: a fresh upload through the pipeline, or the id of an explicitly reused file. */
    private function content(int $user, int $session, array $in, ?string $path, mixed $clientName, int $unit, string $classification): int
    {
        if ($path !== null) {
            $row = $this->pipeline->upload($user, $session, $path, $clientName, $unit, $classification, function (FilesGuard $guard) use ($unit, $classification): void {
                $guard->unit(FilesCatalog::FILES_UPLOAD, $unit);
                $guard->cleared($unit, $classification, FilesReason::CLEARANCE_REQUIRED);
            });
            return (int) $row->id;
        }
        if (!isset($in['file_public_id'])) {
            throw new FilesError(FilesReason::INVALID_INPUT, ['field' => 'file']);
        }
        return $this->records->fileId($in['file_public_id']);
    }

    /**
     * The file a version will point at, re-authorized under lock: visible to the actor (FILES_VIEW scope, clearance,
     * AVAILABLE), same owner unit as the document, and referenced by NO consumer (never one file in two versions).
     */
    private function attachable(FilesGuard $guard, TerritorialActor $actor, int $fileId, int $unit, ?string $minimum, bool $fresh): object
    {
        $file = $this->records->file($fileId, true);
        // A fresh upload is the actor's own FILES_UPLOAD custody; a reused file needs FILES_VIEW over it (re-authorized).
        $this->records->assertVisible($actor, $file, $fresh ? FilesCatalog::FILES_UPLOAD : FilesCatalog::FILES_VIEW, true, true);
        if ((int) $file->owner_unit_id !== $unit) {
            throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['reason' => 'reuse_other_unit']);
        }
        $guard->cleared($unit, (string) $file->classification);
        if ($this->rt->consumers->fileReferences($fileId, true) !== []) {
            throw new FilesError(FilesReason::FILE_IN_USE, ['reason' => 'already_attached']);
        }
        if ($minimum !== null && FileClassification::rank($file->classification) < FileClassification::rank($minimum)) {
            throw new FilesError(FilesReason::CLASSIFICATION_BELOW_FLOOR, ['reason' => 'version_order']);
        }
        return $file;
    }

    private function insertVersion(TerritorialActor $actor, int $documentId, string $documentPublicId, int $unit, object $file, int $version, ?object $previous, mixed $issuedOn, ?string $correlation, bool $reused): void
    {
        $issued = $this->issuedOn($issuedOn);
        $publicId = (string) Str::ulid();
        $id = (int) $this->rt->db->table('document_versions')->insertGetId([
            'public_id' => $publicId, 'document_id' => $documentId, 'version' => $version, 'file_id' => (int) $file->id,
            'issued_on' => $issued, 'supersedes_id' => $previous === null ? null : (int) $previous->id, 'created_at' => $this->rt->ts(), 'lock_version' => 0,
        ]);
        $correlation = $this->rt->audit->record($actor, $unit, 'document.version_created', 'document_versions', $id, null, [
            'public_id' => $publicId, 'document_public_id' => $documentPublicId, 'version' => $version, 'file_public_id' => (string) $file->public_id,
            'supersedes_public_id' => $previous === null ? null : (string) $previous->public_id, 'classification' => (string) $file->classification, 'reused' => $reused,
        ], null, $correlation);
        if ($reused && FileClassification::rank($file->classification) >= FileClassification::ORDER[FileClassification::CONFIDENTIAL]) {
            $this->rt->audit->record($actor, $unit, 'file.attached', 'files', (int) $file->id, null, [
                'public_id' => (string) $file->public_id, 'consumer' => FilesConsumers::DOCUMENT_VERSIONS, 'consumer_public_id' => $publicId, 'classification' => (string) $file->classification,
            ], null, $correlation);
        }
    }

    private function manageable(FilesGuard $guard, TerritorialActor $actor, string $publicId): object
    {
        $document = $this->records->document($this->records->documentId($publicId), true);
        $classification = $this->rt->consumers->documentAuthority($this->rt->authority, $actor, $document, true);
        $unit = (int) $document->owner_unit_id;
        $this->rt->authority->requireUnit($actor, FilesCatalog::DOCUMENTS_MANAGE, $unit, true);
        $guard->unit(FilesCatalog::DOCUMENTS_MANAGE, $unit);
        $guard->cleared($unit, $classification);
        return $document;
    }

    /** @return array{0: string, 1: string} [reference, title]; OTHER requires a descriptive title */
    private function metadata(array $in, string $type, bool $create): array
    {
        $reference = is_string($in['reference'] ?? null) ? trim($in['reference']) : '';
        $title = is_string($in['title'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $in['title']) ?? '') : '';
        if ($reference === '' || mb_strlen($reference) > 64) {
            throw new FilesError(FilesReason::INVALID_INPUT, ['field' => 'reference']);
        }
        if ($title === '' || mb_strlen($title) > 191 || ($type === FilesCatalog::DOCUMENT_TYPE_REQUIRES_TITLE && mb_strlen($title) < 5)) {
            throw new FilesError(FilesReason::INVALID_INPUT, ['field' => 'title']);
        }
        unset($create);
        return [$reference, $title];
    }

    private function issuedOn(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1 || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new FilesError(FilesReason::INVALID_INPUT, ['field' => 'issued_on']);
        }
        return $value;
    }

    /** @return list<object> versions newest first, with file metadata */
    private function versions(int $documentId): array
    {
        return $this->rt->db->table('document_versions as dv')->join('files as f', 'f.id', '=', 'dv.file_id')->leftJoin('document_versions as sv', 'sv.id', '=', 'dv.supersedes_id')
            ->where('dv.document_id', $documentId)->orderByDesc('dv.version')
            ->get(['dv.id', 'dv.public_id', 'dv.version', 'dv.issued_on', 'dv.created_at', 'dv.file_id', 'sv.public_id as supersedes_public_id', 'f.public_id as file_public_id', 'f.original_name', 'f.mime_type', 'f.size_bytes', 'f.classification', 'f.status', 'f.created_by'])->all();
    }

    private function summary(object $row): array
    {
        $current = $this->rt->db->table('document_versions')->where('document_id', $row->id)->max('version');
        return [
            'public_id' => (string) $row->public_id,
            'type' => ['code' => (string) $row->type_code, 'label' => (string) $row->type_name],
            'reference' => (string) $row->reference,
            'title' => (string) $row->title,
            'status' => (string) $row->status,
            'status_label' => FilesCatalog::DOCUMENT_STATUSES[(string) $row->status] ?? null,
            'owner_unit' => $this->records->unit((int) $row->owner_unit_id),
            'classification' => $this->rt->consumers->documentClassification((int) $row->id),
            'current_version' => $current === null ? null : (int) $current,
            'created_at' => (string) $row->created_at,
        ];
    }

    private function project(TerritorialActor $actor, object $document, string $classification): array
    {
        $type = $this->rt->db->table('legal_document_types')->where('id', $document->document_type_id)->first(['code', 'name']);
        $unit = (int) $document->owner_unit_id;
        $versions = $this->versions((int) $document->id);
        $download = $this->rt->authority->holdsOn($actor, FilesCatalog::FILES_DOWNLOAD, $unit);
        $manage = $this->rt->authority->holdsOn($actor, FilesCatalog::DOCUMENTS_MANAGE, $unit);
        return [
            'public_id' => (string) $document->public_id,
            'type' => ['code' => (string) $type->code, 'label' => (string) $type->name],
            'reference' => (string) $document->reference,
            'title' => (string) $document->title,
            'status' => (string) $document->status,
            'status_label' => FilesCatalog::DOCUMENT_STATUSES[(string) $document->status] ?? null,
            'owner_unit' => $this->records->unit($unit),
            'classification' => $classification,
            'classification_label' => FileClassification::LABELS[$classification],
            'current_version' => $versions === [] ? null : (int) $versions[0]->version,
            'created_at' => (string) $document->created_at,
            'lock_version' => (int) $document->lock_version,
            'versions' => array_map(fn (object $v, int $i): array => [
                'public_id' => (string) $v->public_id,
                'version' => (int) $v->version,
                'is_current' => $i === 0,
                'issued_on' => $v->issued_on === null ? null : (string) $v->issued_on,
                'supersedes_public_id' => $v->supersedes_public_id === null ? null : (string) $v->supersedes_public_id,
                'created_at' => (string) $v->created_at,
                'file' => [
                    'public_id' => (string) $v->file_public_id, 'original_name' => (string) $v->original_name, 'mime_type' => (string) $v->mime_type,
                    'size_bytes' => (int) $v->size_bytes, 'classification' => (string) $v->classification,
                    'classification_label' => FileClassification::LABELS[(string) $v->classification] ?? null, 'status' => (string) $v->status,
                ],
                'download_reason_required' => (string) $v->classification === FileClassification::HIGHLY_SENSITIVE,
            ], $versions, array_keys($versions)),
            'actions' => [
                'download' => $download,
                'new_version' => $document->status === FilesCatalog::DOCUMENT_ACTIVE && $this->rt->authority->holdsOn($actor, FilesCatalog::DOCUMENTS_VERSION_MANAGE, $unit),
                'edit' => $manage,
                'archive' => $manage && $document->status === FilesCatalog::DOCUMENT_ACTIVE,
                'restore' => $manage && $document->status === FilesCatalog::DOCUMENT_ARCHIVED,
                'transfer' => $manage,
            ],
        ];
    }

    private function detailById(int $user, int $session, int $id): array
    {
        return $this->detail($user, $session, (string) $this->rt->db->table('legal_documents')->where('id', $id)->value('public_id'));
    }
}
