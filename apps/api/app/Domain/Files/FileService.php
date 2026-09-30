<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;

// Files (ADR 0019): metadata list/detail, upload, tombstone/restore, classification change and owner change.
//  - every target is a public_id; the permission is checked before the target is resolved (F-06);
//  - a file above the actor's clearance, out of scope, owned by a department, referenced by a consumer without an
//    adapter, QUARANTINED/TOMBSTONE (without FILES_MANAGE) or PURGED is concealed (404) and absent from lists/counts;
//  - retention (D07): AVAILABLE <-> TOMBSTONE only, with FILES_MANAGE, reason and audit; a file in use by a consumer
//    cannot be tombstoned or change owner (409 FILE_IN_USE); there is NO operation to PURGED here and no hard delete;
//  - dedup (D09): the checksum only yields the same-unit warning DUPLICATE_CONTENT_IN_UNIT, and only when the actor
//    could already see the existing file; nothing is merged and nothing is revealed across units or clearance.
final class FileService
{
    private FileRecords $records;
    private FileUploadPipeline $pipeline;

    public function __construct(private FilesRuntime $rt)
    {
        $this->records = new FileRecords($rt);
        $this->pipeline = new FileUploadPipeline($rt);
    }

    public function context(int $user, int $session): array
    {
        return $this->rt->read($user, $session, function (FilesGuard $guard, TerritorialActor $actor): array {
            $byUnit = [];
            foreach (array_keys(FilesCatalog::PERMISSIONS) as $permission) {
                foreach ($this->rt->authority->covered($actor, $permission) as $unit => $_) {
                    $byUnit[$unit][] = $permission;
                }
            }
            $units = [];
            if ($byUnit !== []) {
                $rows = $this->rt->db->table('organizational_units')->whereIn('id', array_keys($byUnit))->where('status', '!=', 'CLOSED')->orderBy('name')->orderBy('id')->limit(200)->get(['id', 'public_id', 'name']);
                foreach ($rows as $row) {
                    $clearance = FileClassification::fromRank($this->rt->authority->clearance($actor, (int) $row->id));
                    $units[] = ['public_id' => (string) $row->public_id, 'name' => (string) $row->name, 'permissions' => $byUnit[(int) $row->id], 'clearance' => $clearance];
                }
            }
            return [
                'permissions' => $this->rt->authority->effectivePermissions($actor),
                'units' => $units,
                'classifications' => array_map(fn (string $code, int $rank): array => ['code' => $code, 'label' => FileClassification::LABELS[$code], 'rank' => $rank], array_keys(FileClassification::ORDER), FileClassification::ORDER),
                'default_classification' => FileClassification::DEFAULT_UPLOAD,
                'document_types' => array_map(fn (string $code, string $label): array => ['code' => $code, 'label' => $label, 'requires_title' => $code === FilesCatalog::DOCUMENT_TYPE_REQUIRES_TITLE], array_keys(FilesCatalog::DOCUMENT_TYPES), FilesCatalog::DOCUMENT_TYPES),
                'file_statuses' => array_map(fn (string $code, string $label): array => ['code' => $code, 'label' => $label], array_keys(FilesCatalog::FILE_STATUSES), FilesCatalog::FILE_STATUSES),
                'limits' => ['max_file_bytes' => $this->rt->maxFileBytes(), 'extensions' => array_keys(FileInspector::ALLOWED), 'mime_types' => array_values(array_unique(FileInspector::ALLOWED))],
                'inspection' => FileInspector::INSPECTION_STRUCTURAL,
                'pagination' => ['default' => (int) ($this->rt->settings['pagination']['default'] ?? 50), 'max' => (int) ($this->rt->settings['pagination']['max'] ?? 100)],
            ];
        });
    }

    public function list(int $user, int $session, array $q): array
    {
        return $this->rt->read($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($q): array {
            $units = $this->rt->authority->covered($actor, FilesCatalog::FILES_VIEW);
            if ($units === []) {
                throw new FilesError(FilesReason::NOT_AUTHORIZED, ['permission' => FilesCatalog::FILES_VIEW]);
            }
            $status = (string) ($q['status'] ?? FilesCatalog::AVAILABLE);
            if (!in_array($status, [FilesCatalog::AVAILABLE, FilesCatalog::TOMBSTONE, FilesCatalog::QUARANTINED], true)) {
                throw new FilesError(FilesReason::INVALID_INPUT, ['field' => 'status']);
            }
            if ($status !== FilesCatalog::AVAILABLE) {
                $units = array_intersect_key($units, $this->rt->authority->covered($actor, FilesCatalog::FILES_MANAGE));
            }
            if (($q['unit_public_id'] ?? '') !== '') {
                $unit = $this->rt->db->table('organizational_units')->where('public_id', (string) $q['unit_public_id'])->value('id');
                $units = $unit !== null && isset($units[(int) $unit]) ? [(int) $unit => true] : [];
            }
            $per = $this->rt->perPage($q['per_page'] ?? null);
            $page = max(1, (int) ($q['page'] ?? 1));
            $query = $this->rt->db->table('files as f')->where('f.status', $status)->whereNull('f.owner_department_id')->whereNull('f.purged_at')
                ->whereRaw($this->records->clearancePredicate('f', $actor, $units))
                ->whereRaw($this->rt->consumers->fileListPredicate('f', $this->rt->authority, $actor));
            if (($q['classification'] ?? '') !== '') {
                $query->where('f.classification', FileClassification::requested((string) $q['classification']));
            }
            if (($q['search'] ?? '') !== '') {
                $query->where('f.original_name', 'like', '%' . FileRecords::escapeLike((string) $q['search']) . '%');
            }
            $total = (clone $query)->count('f.id');
            $rows = $query->orderByDesc('f.created_at')->orderByDesc('f.id')->forPage($page, $per)->get(['f.*'])->all();
            return ['items' => array_map(fn (object $row): array => $this->records->projectFile($row), $rows), 'page' => $page, 'per_page' => $per, 'total' => $total];
        });
    }

    public function detail(int $user, int $session, string $publicId): array
    {
        return $this->rt->read($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($publicId): array {
            $guard->requires(FilesCatalog::FILES_VIEW);
            $row = $this->records->file($this->records->fileId($publicId));
            [$document, $refs] = $this->records->assertVisible($actor, $row, FilesCatalog::FILES_VIEW);
            if (FileClassification::rank($row->classification) >= FileClassification::ORDER[FileClassification::CONFIDENTIAL]) {
                $this->rt->audit->record($actor, (int) $row->owner_unit_id, 'file.metadata_read', 'files', (int) $row->id, null, [
                    'public_id' => (string) $row->public_id, 'classification' => (string) $row->classification, 'status' => (string) $row->status,
                ]);
            }
            return $this->project($actor, $row, $document, $refs);
        });
    }

    /** Stand-alone upload (D10: the client names the owner unit by public_id; the backend validates the coverage). */
    public function upload(int $user, int $session, array $in, string $path, mixed $clientName): array
    {
        [$unit, $classification] = $this->rt->read($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($in): array {
            $guard->requires(FilesCatalog::FILES_UPLOAD);
            $classification = FileClassification::requested($in['classification'] ?? null);
            $unit = $this->records->unitId($in['owner_unit_public_id'] ?? null);
            $this->rt->authority->requireUnit($actor, FilesCatalog::FILES_UPLOAD, $unit);
            if (!FileClassification::allows($this->rt->authority->clearance($actor, $unit), $classification)) {
                throw new FilesError(FilesReason::CLEARANCE_REQUIRED, ['reason' => 'upload_classification']);
            }
            return [$unit, $classification];
        });
        $row = $this->pipeline->upload($user, $session, $path, $clientName, $unit, $classification, function (FilesGuard $guard) use ($unit, $classification): void {
            $guard->unit(FilesCatalog::FILES_UPLOAD, $unit);
            $guard->cleared($unit, $classification, FilesReason::CLEARANCE_REQUIRED);
        });
        $actor = $this->rt->actorFor($user, $session);
        return $this->records->projectFile($row, ['warnings' => $this->duplicateWarnings($actor, $row)]);
    }

    public function tombstone(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($publicId, $in): int {
            $guard->requires(FilesCatalog::FILES_MANAGE);
            $row = $this->manageable($guard, $actor, $publicId);
            $reason = FileRecords::requireReason($in['reason'] ?? null);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'files');
            if ((string) $row->status !== FilesCatalog::AVAILABLE) {
                throw new FilesError(FilesReason::TRANSITION_NOT_ALLOWED, ['from' => (string) $row->status]);
            }
            if ($this->rt->consumers->fileReferences((int) $row->id, true) !== []) {
                throw new FilesError(FilesReason::FILE_IN_USE);
            }
            $now = $this->rt->ts();
            $this->rt->db->table('files')->where('id', $row->id)->update(['status' => FilesCatalog::TOMBSTONE, 'deleted_at' => $now, 'lock_version' => (int) $row->lock_version + 1]);
            $this->rt->audit->record($actor, (int) $row->owner_unit_id, 'file.tombstoned', 'files', (int) $row->id, ['status' => FilesCatalog::AVAILABLE], ['public_id' => (string) $row->public_id, 'status' => FilesCatalog::TOMBSTONE], $reason);
            return (int) $row->id;
        });
        return $this->detailById($user, $session, $id);
    }

    public function restore(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($publicId, $in): int {
            $guard->requires(FilesCatalog::FILES_MANAGE);
            $row = $this->manageable($guard, $actor, $publicId);
            $reason = FileRecords::requireReason($in['reason'] ?? null);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'files');
            if ((string) $row->status !== FilesCatalog::TOMBSTONE) {
                throw new FilesError(FilesReason::TRANSITION_NOT_ALLOWED, ['from' => (string) $row->status]);
            }
            // Never AVAILABLE pointing at a missing or corrupted object.
            if (!$this->pipeline->verifyStored($row)) {
                throw new FilesError(FilesReason::CONTENT_UNAVAILABLE, ['reason' => 'restore_integrity']);
            }
            $this->rt->db->table('files')->where('id', $row->id)->update(['status' => FilesCatalog::AVAILABLE, 'deleted_at' => null, 'lock_version' => (int) $row->lock_version + 1]);
            $this->rt->audit->record($actor, (int) $row->owner_unit_id, 'file.restored', 'files', (int) $row->id, ['status' => FilesCatalog::TOMBSTONE], ['public_id' => (string) $row->public_id, 'status' => FilesCatalog::AVAILABLE], $reason);
            return (int) $row->id;
        });
        return $this->detailById($user, $session, $id);
    }

    /**
     * D11 reclassification: raising needs FILES_MANAGE + clearance >= new class; lowering needs clearance >= current
     * class + reason and never goes below a consumer floor nor below the previous version of the same document (each
     * version keeps classification >= the one it supersedes).
     */
    public function reclassify(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($publicId, $in): int {
            $guard->requires(FilesCatalog::FILES_MANAGE);
            if (!FileClassification::isKnown($in['classification'] ?? null)) {
                throw new FilesError(FilesReason::CLASSIFICATION_INVALID, ['field' => 'classification']);
            }
            $row = $this->manageable($guard, $actor, $publicId);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'files');
            if (!in_array((string) $row->status, [FilesCatalog::AVAILABLE, FilesCatalog::TOMBSTONE], true)) {
                throw new FilesError(FilesReason::TRANSITION_NOT_ALLOWED, ['from' => (string) $row->status]);
            }
            $unit = (int) $row->owner_unit_id;
            $current = (string) $row->classification;
            $target = (string) $in['classification'];
            if ($target === $current) {
                throw new FilesError(FilesReason::TRANSITION_NOT_ALLOWED, ['reason' => 'same_classification']);
            }
            $lowering = FileClassification::rank($target) < FileClassification::rank($current);
            $reason = $lowering ? FileRecords::requireReason($in['reason'] ?? null) : FileRecords::optionalReason($in['reason'] ?? null);
            $guard->cleared($unit, $lowering ? $current : $target, FilesReason::CLEARANCE_REQUIRED);
            $refs = $this->rt->consumers->fileReferences((int) $row->id, true);
            $floor = $this->rt->consumers->floor((int) $row->id, $refs);
            if ($floor !== null && FileClassification::rank($target) < FileClassification::rank($floor)) {
                throw new FilesError(FilesReason::CLASSIFICATION_BELOW_FLOOR, ['floor' => $floor]);
            }
            $this->assertVersionOrder((int) $row->id, $target);
            $this->rt->db->table('files')->where('id', $row->id)->update(['classification' => $target, 'lock_version' => (int) $row->lock_version + 1]);
            $this->rt->audit->record($actor, $unit, 'file.classification_changed', 'files', (int) $row->id, ['classification' => $current], ['public_id' => (string) $row->public_id, 'classification' => $target, 'direction' => $lowering ? 'DOWN' : 'UP'], $reason);
            return (int) $row->id;
        });
        return $this->detailById($user, $session, $id);
    }

    /** D10 owner change: FILES_MANAGE over the origin AND the destination, reason, not in use, destination quota. */
    public function transferOwner(int $user, int $session, string $publicId, array $in): array
    {
        $id = $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($publicId, $in): int {
            $guard->requires(FilesCatalog::FILES_MANAGE);
            $id = $this->records->fileId($publicId);
            $origin = (int) $this->records->file($id)->owner_unit_id;
            $to = $this->records->unitId($in['to_unit_public_id'] ?? null);
            $this->rt->lockUnits([$origin, $to]);
            $row = $this->records->file($id, true);
            $this->records->assertVisible($actor, $row, FilesCatalog::FILES_MANAGE, true);
            $guard->unit(FilesCatalog::FILES_MANAGE, (int) $row->owner_unit_id);
            $guard->cleared((int) $row->owner_unit_id, (string) $row->classification);
            $this->rt->authority->requireUnit($actor, FilesCatalog::FILES_MANAGE, $to, true);
            $guard->unit(FilesCatalog::FILES_MANAGE, $to);
            $guard->cleared($to, (string) $row->classification, FilesReason::CLEARANCE_REQUIRED);
            $reason = FileRecords::requireReason($in['reason'] ?? null);
            $this->records->assertVersion($row, $in['lock_version'] ?? null, 'files');
            if ((int) $row->owner_unit_id !== $origin || $to === $origin) {
                throw new FilesError($to === $origin ? FilesReason::INVALID_INPUT : FilesReason::STALE_WRITE, ['field' => 'to_unit_public_id']);
            }
            if (!in_array((string) $row->status, [FilesCatalog::AVAILABLE, FilesCatalog::TOMBSTONE], true)) {
                throw new FilesError(FilesReason::TRANSITION_NOT_ALLOWED, ['from' => (string) $row->status]);
            }
            if ($this->rt->consumers->fileReferences($id, true) !== []) {
                throw new FilesError(FilesReason::FILE_IN_USE);
            }
            $this->rt->assertQuota($to, (int) $row->size_bytes, false);
            $this->rt->db->table('files')->where('id', $id)->update(['owner_unit_id' => $to, 'lock_version' => (int) $row->lock_version + 1]);
            $before = ['owner_unit' => $this->records->unit($origin)['public_id']];
            $after = ['public_id' => (string) $row->public_id, 'owner_unit' => $this->records->unit($to)['public_id'], 'classification' => (string) $row->classification];
            $correlation = $this->rt->audit->record($actor, $origin, 'file.ownership_changed', 'files', $id, $before, $after, $reason);
            $this->rt->audit->record($actor, $to, 'file.ownership_changed', 'files', $id, $before, $after, $reason, $correlation);
            return $id;
        });
        return $this->detailById($user, $session, $id);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** Locked target of a FILES_MANAGE operation: resolved after the permission, visible (manage view), scope + clearance recorded. */
    private function manageable(FilesGuard $guard, TerritorialActor $actor, string $publicId): object
    {
        $row = $this->records->file($this->records->fileId($publicId), true);
        $this->records->assertVisible($actor, $row, FilesCatalog::FILES_MANAGE, true);
        $guard->unit(FilesCatalog::FILES_MANAGE, (int) $row->owner_unit_id);
        $guard->cleared((int) $row->owner_unit_id, (string) $row->classification);
        return $row;
    }

    private function assertVersionOrder(int $fileId, string $target): void
    {
        $version = $this->rt->db->table('document_versions')->where('file_id', $fileId)->lockForUpdate()->first();
        if (!$version) {
            return;
        }
        $rank = FileClassification::rank($target);
        $previous = $version->supersedes_id === null ? null : $this->rt->db->table('document_versions as dv')->join('files as f', 'f.id', '=', 'dv.file_id')->where('dv.id', $version->supersedes_id)->value('f.classification');
        $next = $this->rt->db->table('document_versions as dv')->join('files as f', 'f.id', '=', 'dv.file_id')->where('dv.supersedes_id', $version->id)->value('f.classification');
        if (($previous !== null && $rank < FileClassification::rank($previous)) || ($next !== null && $rank > FileClassification::rank($next))) {
            throw new FilesError(FilesReason::CLASSIFICATION_BELOW_FLOOR, ['reason' => 'version_order']);
        }
    }

    private function detailById(int $user, int $session, int $id): array
    {
        return $this->detail($user, $session, (string) $this->rt->db->table('files')->where('id', $id)->value('public_id'));
    }

    /** @return list<string> */
    private function duplicateWarnings(TerritorialActor $actor, object $row): array
    {
        $candidates = $this->rt->db->table('files')->where('owner_unit_id', $row->owner_unit_id)->where('checksum', $row->checksum)->where('id', '!=', $row->id)
            ->whereIn('status', [FilesCatalog::AVAILABLE, FilesCatalog::TOMBSTONE])->whereNull('purged_at')->limit(20)->get()->all();
        foreach ($candidates as $candidate) {
            try {
                $this->records->assertVisible($actor, $candidate, FilesCatalog::FILES_VIEW);
                return ['DUPLICATE_CONTENT_IN_UNIT'];
            } catch (FilesError) {
                continue;
            }
        }
        return [];
    }

    private function project(TerritorialActor $actor, object $row, ?object $document, array $refs): array
    {
        $unit = (int) $row->owner_unit_id;
        $status = (string) $row->status;
        $manage = $this->rt->authority->holdsOn($actor, FilesCatalog::FILES_MANAGE, $unit);
        $inUse = $refs !== [];
        $version = $document === null ? null : $this->rt->db->table('document_versions')->where('file_id', $row->id)->first(['public_id', 'version']);
        return $this->records->projectFile($row, [
            'in_use' => $inUse,
            'document' => $document === null ? null : ['public_id' => (string) $document->public_id, 'title' => (string) $document->title, 'version' => $version ? (int) $version->version : null, 'version_public_id' => $version ? (string) $version->public_id : null],
            'actions' => [
                'download' => $status === FilesCatalog::AVAILABLE && $this->rt->authority->holdsOn($actor, FilesCatalog::FILES_DOWNLOAD, $unit),
                'download_reason_required' => (string) $row->classification === FileClassification::HIGHLY_SENSITIVE,
                'tombstone' => $manage && $status === FilesCatalog::AVAILABLE && !$inUse,
                'restore' => $manage && $status === FilesCatalog::TOMBSTONE,
                'reclassify' => $manage && in_array($status, [FilesCatalog::AVAILABLE, FilesCatalog::TOMBSTONE], true),
                'transfer' => $manage && !$inUse && in_array($status, [FilesCatalog::AVAILABLE, FilesCatalog::TOMBSTONE], true),
            ],
        ]);
    }
}
