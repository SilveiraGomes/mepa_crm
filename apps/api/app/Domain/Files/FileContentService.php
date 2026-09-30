<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Domain\Territorial\TerritorialActor;

// ADR 0019 D04/D05/D06 download. Cumulative authority, all under a shared lock on the file row:
//   1. FILES_DOWNLOAD held (checked BEFORE the public id is resolved: F-06)
//   2. Territorial scope over files.owner_unit_id
//   3. clearance >= classification (unknown classification never passes)
//   4. consumer authority (Documents adapter; any consumer without an adapter => concealed, fail closed)
//   5. status AVAILABLE, deleted_at and purged_at NULL
//   (+ HIGHLY_SENSITIVE: a mandatory reason, audited)
// Then the WHOLE stored object is authenticated (MEPAF1 chunk MACs + TAG_FINAL + plaintext checksum/size) before the first
// byte is sent, so a missing/unknown key, a missing object or tampered ciphertext is a 503 with an audited incident and
// NO plaintext; only then file.downloaded is written and the content is streamed (decrypted chunk by chunk, constant
// memory, nothing plaintext written to disk).
final class FileContentService
{
    private FileRecords $records;

    public function __construct(private FilesRuntime $rt)
    {
        $this->records = new FileRecords($rt);
    }

    /** @return array{file: object, stream: callable(): void} */
    public function file(int $user, int $session, string $publicId, ?string $reason): array
    {
        $actor = $this->rt->authority->actor($user, $session);
        $this->rt->authority->requireAnywhere($actor, FilesCatalog::FILES_DOWNLOAD);
        $id = $this->records->fileId($publicId);
        return $this->serve($user, $session, $id, $reason, null);
    }

    /** Content of a document version (DOCUMENTS_VIEW + FILES_DOWNLOAD, document authority, same file rules). */
    public function version(int $user, int $session, string $documentPublicId, string $versionPublicId, ?string $reason): array
    {
        $actor = $this->rt->authority->actor($user, $session);
        $this->rt->authority->requireAnywhere($actor, FilesCatalog::DOCUMENTS_VIEW, FilesCatalog::FILES_DOWNLOAD);
        $documentId = $this->records->documentId($documentPublicId);
        $fileId = preg_match(FilesCatalog::PUBLIC_ID_PATTERN, $versionPublicId) === 1
            ? $this->rt->db->table('document_versions')->where('public_id', $versionPublicId)->where('document_id', $documentId)->value('file_id') : null;
        if ($fileId === null) {
            throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['entity' => 'document_versions']);
        }
        return $this->serve($user, $session, (int) $fileId, $reason, $documentId);
    }

    private function serve(int $user, int $session, int $fileId, ?string $reason, ?int $documentId): array
    {
        try {
            $file = $this->rt->write($user, $session, function (FilesGuard $guard, TerritorialActor $actor) use ($fileId, $reason, $documentId): object {
                $row = $this->rt->db->table('files')->where('id', $fileId)->sharedLock()->first();
                if (!$row) {
                    throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['entity' => 'files']);
                }
                [$document] = $this->records->assertVisible($actor, $row, FilesCatalog::FILES_DOWNLOAD, true, true);
                if ($documentId !== null && ($document === null || (int) $document->id !== $documentId)) {
                    throw new FilesError(FilesReason::TARGET_NOT_FOUND, ['reason' => 'version_document_mismatch']);
                }
                $unit = (int) $row->owner_unit_id;
                $guard->unit(FilesCatalog::FILES_DOWNLOAD, $unit);
                $guard->cleared($unit, (string) $row->classification);
                $why = null;
                if ((string) $row->classification === FileClassification::HIGHLY_SENSITIVE) {
                    $why = FileRecords::requireReason($reason);
                }
                $this->authenticate($row);
                $this->rt->audit->record($actor, $unit, 'file.downloaded', 'files', (int) $row->id, null, [
                    'public_id' => (string) $row->public_id, 'classification' => (string) $row->classification, 'mime_type' => (string) $row->mime_type,
                    'size_bytes' => (int) $row->size_bytes, 'key_version' => (int) $row->key_version,
                    'document_public_id' => $document === null ? null : (string) $document->public_id,
                ], $why);
                return $row;
            });
        } catch (FilesError $e) {
            if (in_array($e->reason, [FilesReason::INTEGRITY_FAILURE, FilesReason::CONTENT_UNAVAILABLE, FilesReason::CRYPTO_UNAVAILABLE], true) && isset($e->context['incident_file'])) {
                $this->incident((int) $e->context['incident_file'], $e, $this->rt->actorFor($user, $session));
                throw new FilesError(FilesReason::CONTENT_UNAVAILABLE, ['reason' => (string) ($e->context['reason'] ?? $e->reason)]);
            }
            throw $e;
        }
        $rt = $this->rt;
        $stream = static function () use ($rt, $file): void {
            $handle = $rt->storage->openRead((string) $file->storage_key);
            try {
                Mepaf1::decrypt($handle, (string) $file->public_id, $rt->keyRing(), (int) $file->key_version, static function (string $plain): void {
                    echo $plain;
                    flush();
                });
            } finally {
                fclose($handle);
            }
        };
        return ['file' => $file, 'stream' => $stream];
    }

    /** Full authentication pass of the stored object (no output). Failures carry the incident file id. */
    private function authenticate(object $row): void
    {
        $fail = fn (string $reason, string $kind) => new FilesError($reason, ['incident_file' => (int) $row->id, 'reason' => $kind]);
        try {
            $ring = $this->rt->keyRing();
        } catch (FilesError $e) {
            throw $fail(FilesReason::CRYPTO_UNAVAILABLE, (string) ($e->context['reason'] ?? 'keyring'));
        }
        if ($row->key_version === null || !$ring->has((int) $row->key_version)) {
            throw $fail(FilesReason::CRYPTO_UNAVAILABLE, 'unknown_key_version');
        }
        try {
            $handle = $this->rt->storage->openRead((string) $row->storage_key);
        } catch (FilesError $e) {
            throw $fail(FilesReason::CONTENT_UNAVAILABLE, (string) ($e->context['reason'] ?? 'object_missing'));
        }
        try {
            $result = Mepaf1::decrypt($handle, (string) $row->public_id, $ring, (int) $row->key_version);
        } catch (FilesError $e) {
            throw $fail($e->reason === FilesReason::CRYPTO_UNAVAILABLE ? FilesReason::CRYPTO_UNAVAILABLE : FilesReason::INTEGRITY_FAILURE, (string) ($e->context['reason'] ?? 'integrity'));
        } finally {
            fclose($handle);
        }
        if (!hash_equals((string) $row->checksum, $result['sha256']) || $result['bytes'] !== (int) $row->size_bytes) {
            throw $fail(FilesReason::INTEGRITY_FAILURE, 'checksum');
        }
    }

    /** D12 incident, in its own transaction (the authorizing transaction was rolled back). */
    private function incident(int $fileId, FilesError $e, TerritorialActor $actor): void
    {
        $row = $this->rt->db->table('files')->where('id', $fileId)->first(['id', 'public_id', 'owner_unit_id', 'classification', 'key_version']);
        if (!$row) {
            return;
        }
        $action = $e->reason === FilesReason::INTEGRITY_FAILURE ? 'file.integrity_failure' : 'file.content_unavailable';
        $this->rt->system(fn () => $this->rt->audit->record($actor, (int) $row->owner_unit_id, $action, 'files', (int) $row->id, null, [
            'public_id' => (string) $row->public_id, 'classification' => (string) $row->classification, 'key_version' => $row->key_version === null ? null : (int) $row->key_version,
            'reason_code' => strtoupper((string) ($e->context['reason'] ?? $e->reason)), 'stage' => 'download',
        ]));
    }
}
