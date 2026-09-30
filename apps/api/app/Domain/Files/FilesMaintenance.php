<?php

declare(strict_types=1);

namespace App\Domain\Files;

use Throwable;

// Cron-side operations of ADR 0019 (no resident worker; idempotent; every decision audited as SYSTEM):
//
// reconcile(): QUARANTINED rows older than the stale window (15 min, process interrupted) and orphan staging objects.
//   - final object present            -> decrypt + verify checksum, re-run the structural/AV inspection -> AVAILABLE or
//                                        PURGED (object destroyed); scanner configured but down -> left QUARANTINED
//   - only the staged object present  -> promoted, then as above
//   - no object at all                -> QUARANTINED -> PURGED with reason CUSTODY_LOST (content never available)
//   - staging object without a QUARANTINED row, older than the window -> deleted (ciphertext only; nothing exposed)
// verifySample(): integrity sampling of AVAILABLE objects; a missing/corrupted object is audited as an incident
//   (file.content_unavailable / file.integrity_failure). Its status is NOT changed (no AVAILABLE -> PURGED path).
// rewrap(): operational key rotation, separate from the content: the DEK is re-wrapped under the ACTIVE key into a new
//   object (same chunks), the new object's plaintext checksum is verified, storage_key/key_version switch under lock,
//   the old object is removed. Not a new document version.
final class FilesMaintenance
{
    private FileRecords $records;
    private FileUploadPipeline $pipeline;

    public function __construct(private FilesRuntime $rt)
    {
        $this->records = new FileRecords($rt);
        $this->pipeline = new FileUploadPipeline($rt);
    }

    /** @return array<string, int> */
    public function reconcile(?int $staleMinutes = null): array
    {
        $minutes = $staleMinutes ?? (int) ($this->rt->settings['quarantine_stale_minutes'] ?? 15);
        $cutoff = (string) $this->rt->db->selectOne('SELECT UTC_TIMESTAMP(6) - INTERVAL ? MINUTE AS c', [$minutes])->c;
        $report = ['examined' => 0, 'available' => 0, 'purged' => 0, 'left_quarantined' => 0, 'promoted' => 0, 'orphans_removed' => 0];
        $ids = $this->rt->db->table('files')->where('status', FilesCatalog::QUARANTINED)->where('created_at', '<', $cutoff)->orderBy('id')->limit(500)->pluck('id')->all();
        foreach ($ids as $id) {
            $report['examined']++;
            try {
                $outcome = $this->reconcileOne((int) $id);
            } catch (FilesError $e) {
                $outcome = $e->reason === FilesReason::SCANNER_UNAVAILABLE || $e->reason === FilesReason::CRYPTO_UNAVAILABLE || $e->reason === FilesReason::STORAGE_UNAVAILABLE ? 'left_quarantined' : throw $e;
            }
            $report[$outcome === 'promoted_available' ? 'available' : ($outcome === 'promoted_purged' ? 'purged' : $outcome)]++;
            if (str_starts_with($outcome, 'promoted_')) {
                $report['promoted']++;
            }
        }
        $threshold = time() - ($minutes * 60);
        foreach ($this->rt->storage->stagingObjects() as $object) {
            if ($object['modified'] >= $threshold) {
                continue;
            }
            $hex = substr(basename($object['staging']), 0, 32);
            $pending = $this->rt->db->table('files')->where('status', FilesCatalog::QUARANTINED)->where('storage_key', 'like', '%/' . $hex . '.bin')->exists();
            if (!$pending) {
                $this->rt->storage->delete($object['staging']);
                $report['orphans_removed']++;
            }
        }
        return $report;
    }

    private function reconcileOne(int $id): string
    {
        $row = $this->records->file($id);
        if ((string) $row->status !== FilesCatalog::QUARANTINED) {
            return 'left_quarantined';
        }
        $key = (string) $row->storage_key;
        $promoted = false;
        if (!$this->rt->storage->exists($key)) {
            $staging = FileStorage::stagingFor($key);
            if (is_file($this->rt->storage->path($staging))) {
                $this->rt->storage->promote($staging, $key);
                $promoted = true;
            } else {
                $this->pipeline->finalize($id, FileInspector::R_CUSTODY_LOST, FileInspector::INSPECTION_STRUCTURAL, null);
                return 'purged';
            }
        }
        $plain = '';
        try {
            $handle = $this->rt->storage->openRead($key);
            try {
                $result = Mepaf1::decrypt($handle, (string) $row->public_id, $this->rt->keyRing(), $row->key_version === null ? -1 : (int) $row->key_version, function (string $chunk) use (&$plain): void {
                    $plain .= $chunk;
                });
            } finally {
                fclose($handle);
            }
            $intact = hash_equals((string) $row->checksum, $result['sha256']) && $result['bytes'] === (int) $row->size_bytes;
        } catch (FilesError $e) {
            if ($e->reason === FilesReason::CRYPTO_UNAVAILABLE) {
                throw $e;
            }
            $intact = false;
        }
        if (!$intact) {
            $final = $this->pipeline->finalize($id, FileInspector::R_CUSTODY, FileInspector::INSPECTION_STRUCTURAL, null);
        } else {
            $verdict = $this->rt->inspector->verdict((string) $row->original_name, $plain);
            $rejection = $verdict['ok'] ? null : $verdict['reason'];
            if ($rejection === null && str_starts_with($verdict['mime'], 'image/')) {
                $check = $this->rt->inspector->normalizeImage($verdict['mime'], $plain);
                $rejection = $check['ok'] ? null : $check['reason'];
            }
            [$rejection, $level] = $this->pipeline->inspect($rejection, $verdict['mime'], $plain);
            $final = $this->pipeline->finalize($id, $rejection, $level, null);
        }
        $status = (string) $final->status === FilesCatalog::AVAILABLE ? 'available' : 'purged';
        return $promoted ? 'promoted_' . $status : $status;
    }

    /** @return array<string, int> */
    public function verifySample(int $sample = 20): array
    {
        $report = ['checked' => 0, 'intact' => 0, 'incidents' => 0];
        $rows = $this->rt->db->table('files')->where('status', FilesCatalog::AVAILABLE)->inRandomOrder()->limit(max(1, min(1000, $sample)))->get()->all();
        foreach ($rows as $row) {
            $report['checked']++;
            $missing = !$this->rt->storage->exists((string) $row->storage_key);
            if (!$missing && $this->pipeline->verifyStored($row)) {
                $report['intact']++;
                continue;
            }
            $report['incidents']++;
            $this->rt->system(fn () => $this->rt->audit->record(null, (int) $row->owner_unit_id, $missing ? 'file.content_unavailable' : 'file.integrity_failure', 'files', (int) $row->id, null, [
                'public_id' => (string) $row->public_id, 'classification' => (string) $row->classification, 'key_version' => $row->key_version === null ? null : (int) $row->key_version,
                'reason_code' => $missing ? 'OBJECT_MISSING' : 'INTEGRITY', 'stage' => 'cron_sample',
            ]));
        }
        return $report;
    }

    /** Re-wraps every object whose key_version is not the active one (batch, audited). @return array<string, int> */
    public function rewrap(int $limit = 100): array
    {
        $ring = $this->rt->keyRing();
        $active = $ring->activeVersion();
        $report = ['rewrapped' => 0, 'failed' => 0];
        $ids = $this->rt->db->table('files')->whereIn('status', [FilesCatalog::AVAILABLE, FilesCatalog::TOMBSTONE])->where('key_version', '!=', $active)->orderBy('id')->limit($limit)->pluck('id')->all();
        foreach ($ids as $id) {
            try {
                $this->rewrapOne((int) $id, $ring) ? $report['rewrapped']++ : $report['failed']++;
            } catch (Throwable) {
                $report['failed']++;
            }
        }
        return $report;
    }

    public function rewrapOne(int $id, FilesKeyRing $ring): bool
    {
        $row = $this->records->file($id);
        $object = $this->rt->storage->newObject($this->rt->ts());
        $in = $this->rt->storage->openRead((string) $row->storage_key);
        $out = $this->rt->storage->createStaging($object['staging']);
        try {
            $version = Mepaf1::rewrap($in, $out, (string) $row->public_id, $ring);
        } finally {
            fclose($in);
            fclose($out);
        }
        $check = fopen($this->rt->storage->path($object['staging']), 'rb');
        try {
            $result = Mepaf1::decrypt($check, (string) $row->public_id, $ring, $version);
        } finally {
            fclose($check);
        }
        if (!hash_equals((string) $row->checksum, $result['sha256']) || $result['bytes'] !== (int) $row->size_bytes) {
            $this->rt->storage->delete($object['staging']);
            return false;
        }
        $this->rt->storage->promote($object['staging'], $object['key']);
        $old = null;
        $switched = $this->rt->system(function () use ($id, $row, $object, $version, &$old): bool {
            $locked = $this->records->file($id, true);
            if ((string) $locked->storage_key !== (string) $row->storage_key || (int) $locked->lock_version !== (int) $row->lock_version) {
                return false;
            }
            $old = (string) $locked->storage_key;
            $this->rt->db->table('files')->where('id', $id)->update(['storage_key' => $object['key'], 'key_version' => $version, 'lock_version' => (int) $locked->lock_version + 1]);
            $this->rt->audit->record(null, (int) $locked->owner_unit_id, 'file.key_rewrapped', 'files', $id, ['key_version' => (int) $locked->key_version], ['public_id' => (string) $locked->public_id, 'key_version' => $version]);
            return true;
        });
        $this->rt->storage->delete($switched ? (string) $old : $object['key']);
        return $switched;
    }
}
