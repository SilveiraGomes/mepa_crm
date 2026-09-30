<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;
use Closure;
use Illuminate\Support\Str;
use Throwable;

// ADR 0019 D03 upload pipeline. The database and the filesystem share no transaction, so the order is fixed and every
// window has an explicit compensation (fault-injection points in brackets):
//
//   0. authorize (the calling service, BEFORE any byte is processed: permission, scope, clearance, consumer relation)
//   1. validate size / name (no row, no object on failure)
//   2. type verdict (extension allowlist, finfo MIME, magic, double extension) + image RE-ENCODE (EXIF dropped)
//   3. encrypt (MEPAF1) to staging/<hex>.part                                   [write]  A: staging removed, 503, no row
//   4. tx: recheck authority, lock owner unit + national quota lock, quota, INSERT files QUARANTINED, audit file.uploaded
//                                                                               [row]    B: rollback + staging removed
//   5. promote staging -> v1/yyyy/mm/<hex>.bin (rename)                           [promote] C: row stays QUARANTINED
//                                                                                          (never AVAILABLE); the
//                                                                                          reconciler promotes or purges
//   6. security inspection (PDF active content / encryption, optional clamd)      [inspect] D: interrupted -> QUARANTINED,
//                                                                                          reconciled after 15 minutes
//   7. FINAL GATE tx: lock the row, re-read the stored object and verify it decrypts to the recorded checksum and size;
//      approved -> AVAILABLE (file.available, inspection level); rejected -> object destroyed, QUARANTINED -> PURGED
//      with purged_at (file.security_rejected): the only PURGED path of V1. A file is NEVER AVAILABLE before step 7,
//      and never AVAILABLE when its object is missing or fails integrity.
// Plaintext exists only in memory (and in PHP's own transient upload file); nothing plaintext is written by this code.
final class FileUploadPipeline
{
    private FileRecords $records;

    public function __construct(private FilesRuntime $rt)
    {
        $this->records = new FileRecords($rt);
    }

    /**
     * @param Closure(FilesGuard, TerritorialActor): void $authorize re-run INSIDE the insert transaction (locking recheck)
     * @return object the AVAILABLE files row
     */
    public function upload(int $user, int $session, string $path, mixed $clientName, int $ownerUnit, string $classification, Closure $authorize): object
    {
        FileClassification::rank($classification);
        $size = is_file($path) ? (int) filesize($path) : -1;
        if ($size <= 0) {
            throw new FilesError($size === 0 ? FilesReason::FILE_EMPTY : FilesReason::INVALID_INPUT, ['field' => 'file']);
        }
        if ($size > $this->rt->maxFileBytes()) {
            throw new FilesError(FilesReason::FILE_TOO_LARGE, ['field' => 'file']);
        }
        $name = FileInspector::sanitizeName($clientName);
        $ring = $this->ready($size);

        $bytes = (string) file_get_contents($path);
        $verdict = $this->rt->inspector->verdict($name, $bytes);
        $rejection = $verdict['ok'] ? null : $verdict['reason'];
        $content = $bytes;
        if ($rejection === null && str_starts_with($verdict['mime'], 'image/')) {
            $normalized = $this->rt->inspector->normalizeImage($verdict['mime'], $bytes);
            $rejection = $normalized['ok'] ? null : $normalized['reason'];
            $content = $normalized['bytes'];
        }
        unset($bytes);

        $publicId = (string) Str::ulid();
        $object = $this->rt->storage->newObject($this->rt->ts());
        $sealed = $this->seal($content, $object['staging'], $publicId, $ring);

        try {
            $fileId = $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($authorize, $ownerUnit, $classification, $publicId, $object, $sealed, $name, $verdict, $user): int {
                $this->rt->lockUnits([$ownerUnit]);
                $authorize($guard, $actor);
                $this->rt->nationalQuotaLock();
                $this->rt->assertQuota($ownerUnit, $sealed['bytes']);
                $now = $this->rt->ts();
                $id = (int) $this->rt->db->table('files')->insertGetId([
                    'public_id' => $publicId, 'owner_unit_id' => $ownerUnit, 'owner_department_id' => null, 'created_by' => $user,
                    'classification' => $classification, 'disk' => FileStorage::DISK, 'storage_key' => $object['key'],
                    'original_name' => $name, 'mime_type' => $verdict['mime'], 'size_bytes' => $sealed['bytes'], 'checksum' => $sealed['sha256'],
                    'status' => FilesCatalog::QUARANTINED, 'deleted_at' => null, 'purged_at' => null, 'key_version' => $sealed['key_version'],
                    'created_at' => $now, 'lock_version' => 0,
                ]);
                $this->rt->audit->record($actor, $ownerUnit, 'file.uploaded', 'files', $id, null, [
                    'public_id' => $publicId, 'status' => FilesCatalog::QUARANTINED, 'classification' => $classification,
                    'mime_type' => $verdict['mime'], 'size_bytes' => $sealed['bytes'], 'key_version' => $sealed['key_version'],
                ]);
                $this->rt->fault('row', ['file' => $id]);
                return $id;
            });
        } catch (Throwable $e) {
            $this->discard($object['staging']);
            throw $e;
        } finally {
            $this->rt->releaseNationalQuotaLock();
        }

        try {
            $this->rt->fault('promote', ['file' => $fileId]);
            $this->rt->storage->promote($object['staging'], $object['key']);
        } catch (Throwable $e) {
            // Window C: the row stays QUARANTINED (never downloadable/attachable); the staged ciphertext is kept for the
            // reconciler, which promotes it or purges the custody after the stale window.
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => 'promote', 'file' => $fileId], $e instanceof FilesError ? null : $e);
        }

        $this->rt->fault('inspect', ['file' => $fileId]);
        [$rejection, $level] = $this->inspect($rejection, $verdict['mime'], $content);
        unset($content);
        $actor = $this->rt->actorFor($user, $session);
        $row = $this->finalize($fileId, $rejection, $level, $actor);
        if ((string) $row->status !== FilesCatalog::AVAILABLE) {
            throw new FilesError(
                in_array($rejection ?? FileInspector::R_CUSTODY, FileInspector::CONTENT_REASONS, true) ? FilesReason::FILE_CONTENT_REJECTED : FilesReason::FILE_TYPE_NOT_ALLOWED,
                ['file' => $fileId, 'reason_code' => $rejection ?? FileInspector::R_CUSTODY],
                null,
                ['reason_code' => $rejection ?? FileInspector::R_CUSTODY, 'file_public_id' => $publicId, 'status' => (string) $row->status]
            );
        }
        return $row;
    }

    /**
     * Step 6: security inspection of an allowed type. A configured scanner that cannot answer => SCANNER_UNAVAILABLE
     * (the file stays QUARANTINED, 503, Cron retries).
     * @return array{0: ?string, 1: string} [rejection reason, inspection level]
     */
    public function inspect(?string $rejection, string $mime, string $content): array
    {
        $level = $this->rt->scanner === null ? FileInspector::INSPECTION_STRUCTURAL : FileInspector::INSPECTION_STRUCTURAL_AV;
        if ($rejection !== null) {
            return [$rejection, FileInspector::INSPECTION_STRUCTURAL];
        }
        if ($mime === 'application/pdf') {
            $rejection = $this->rt->inspector->pdfReason($content);
        }
        if ($rejection === null && $this->rt->scanner !== null && !$this->rt->scanner->clean($content)) {
            $rejection = FileInspector::R_AV;
        }
        return [$rejection, $level];
    }

    /**
     * Step 7, the final gate (also used by the reconciler with $actor = null). Only a QUARANTINED row moves.
     */
    public function finalize(int $fileId, ?string $rejection, string $level, ?TerritorialActor $actor): object
    {
        return $this->rt->system(function () use ($fileId, $rejection, $level, $actor): object {
            $row = $this->records->file($fileId, true);
            if ((string) $row->status !== FilesCatalog::QUARANTINED) {
                return $row;
            }
            $unit = (int) $row->owner_unit_id;
            if ($rejection === null && !$this->verifyStored($row)) {
                $rejection = FileInspector::R_CUSTODY;
            }
            $now = $this->rt->ts();
            if ($rejection !== null) {
                $this->destroy((string) $row->storage_key);
                $this->rt->db->table('files')->where('id', $fileId)->update(['status' => FilesCatalog::PURGED, 'purged_at' => $now, 'lock_version' => (int) $row->lock_version + 1]);
                $this->rt->audit->record($actor, $unit, 'file.security_rejected', 'files', $fileId, ['status' => FilesCatalog::QUARANTINED], [
                    'public_id' => (string) $row->public_id, 'status' => FilesCatalog::PURGED, 'reason_code' => $rejection, 'inspection' => $level,
                    'classification' => (string) $row->classification, 'size_bytes' => (int) $row->size_bytes,
                ]);
            } else {
                $this->rt->db->table('files')->where('id', $fileId)->update(['status' => FilesCatalog::AVAILABLE, 'lock_version' => (int) $row->lock_version + 1]);
                $this->rt->audit->record($actor, $unit, 'file.available', 'files', $fileId, ['status' => FilesCatalog::QUARANTINED], [
                    'public_id' => (string) $row->public_id, 'status' => FilesCatalog::AVAILABLE, 'inspection' => $level,
                    'classification' => (string) $row->classification, 'key_version' => (int) $row->key_version,
                ]);
            }
            return $this->records->file($fileId);
        });
    }

    /** The stored object exists and decrypts (MEPAF1, key_version of the row) to exactly the recorded checksum and size. */
    public function verifyStored(object $row): bool
    {
        try {
            $handle = $this->rt->storage->openRead((string) $row->storage_key);
        } catch (FilesError) {
            return false;
        }
        try {
            $result = Mepaf1::decrypt($handle, (string) $row->public_id, $this->rt->keyRing(), $row->key_version === null ? -1 : (int) $row->key_version);
            return hash_equals((string) $row->checksum, $result['sha256']) && $result['bytes'] === (int) $row->size_bytes;
        } catch (FilesError $e) {
            if ($e->reason === FilesReason::CRYPTO_UNAVAILABLE) {
                throw $e;   // a missing/unknown key is an outage, never a verdict on the content
            }
            return false;
        } finally {
            fclose($handle);
        }
    }

    /** Destroys the final object and any staging leftover of a key (idempotent). */
    public function destroy(string $key): void
    {
        $this->rt->storage->delete($key);
        $this->rt->storage->delete(FileStorage::stagingFor($key));
    }

    /** Storage root, reserve and key ring must be usable BEFORE any byte is written (503 otherwise). */
    private function ready(int $size): FilesKeyRing
    {
        try {
            $this->rt->storage->assertCapacity($size);
            return $this->rt->keyRing();
        } catch (FilesError $e) {
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => (string) ($e->context['reason'] ?? $e->reason)]);
        }
    }

    /** @return array{sha256: string, bytes: int, key_version: int} */
    private function seal(string $content, string $staging, string $publicId, FilesKeyRing $ring): array
    {
        $out = null;
        try {
            $out = $this->rt->storage->createStaging($staging);
            $this->rt->fault('write', ['staging' => true]);
            $in = fopen('php://temp/maxmemory:' . (32 * 1048576), 'w+b');
            fwrite($in, $content);
            rewind($in);
            $sealed = Mepaf1::encrypt($in, $out, $publicId, $ring);
            fclose($in);
            fclose($out);
            $out = null;
            return $sealed;
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            $this->discard($staging);
            throw new FilesError(FilesReason::STORAGE_UNAVAILABLE, ['reason' => $e instanceof FilesError ? (string) ($e->context['reason'] ?? $e->reason) : 'write']);
        }
    }

    private function discard(string $staging): void
    {
        try {
            $this->rt->storage->delete($staging);
        } catch (Throwable) {
            // an undeletable orphan is ciphertext only; the reconciler removes stale staging objects
        }
    }
}
