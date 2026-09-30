<?php

declare(strict_types=1);

namespace App\Http\Files;

use App\Domain\Files\ClamdScanner;
use App\Domain\Files\FileInspector;
use App\Domain\Files\FileScanner;
use App\Domain\Files\FilesAudit;
use App\Domain\Files\FilesAuthority;
use App\Domain\Files\FilesCatalog;
use App\Domain\Files\FilesConsumers;
use App\Domain\Files\FileStorage;
use App\Domain\Files\FilesKeyRing;
use App\Domain\Files\FilesRuntime;
use App\Domain\Territorial\TerritorialAuthority;
use Closure;
use Illuminate\Database\Connection;

final class FilesServiceFactory
{
    public function __construct(private Connection $db)
    {
    }

    // Built per call: storage root, key ring and scanner configuration are re-read for every operation (a removed
    // root or ring fails closed on the next request).
    public function runtime(): FilesRuntime
    {
        // Stack traces of any exception raised while handling file content never carry argument values (original
        // names, content prefixes): ADR 0019 D03/D12 keep both out of every log.
        ini_set('zend.exception_ignore_args', '1');
        $settings = (array) config('files', []);
        $forbidden = [public_path(), base_path(), dirname(base_path(), 2)];
        $db = $this->db;
        // The one scope engine (TerritorialAuthority) evaluated for the FILES permission domain.
        $scope = new TerritorialAuthority($db, (array) config('auth_contract.active_user_statuses', []), (array) config('auth_contract.active_grant_statuses', []), FilesCatalog::DATA_TYPE);
        $ring = $settings['keyring_path'] ?? null;
        $scanner = app()->bound('files.scanner') ? app('files.scanner') : (is_string($settings['clamd'] ?? null) && $settings['clamd'] !== '' ? new ClamdScanner($settings['clamd'], (int) ($settings['clamd_timeout'] ?? 10)) : null);
        $fault = app()->bound('files.fault') ? app('files.fault') : null;
        return new FilesRuntime(
            $db,
            new FilesAuthority($db, $scope),
            new FilesAudit($db),
            new FileStorage(is_string(config('filesystems.disks.' . FileStorage::DISK . '.root')) ? config('filesystems.disks.' . FileStorage::DISK . '.root') : ($settings['storage_root'] ?? null), $forbidden, (int) ($settings['reserve_bytes'] ?? FileStorage::MIN_RESERVE_BYTES)),
            static fn (): FilesKeyRing => FilesKeyRing::fromFile(is_string($ring) ? $ring : null, $forbidden),
            new FileInspector((int) ($settings['image_max_pixels'] ?? 40000000), (int) ($settings['image_max_side'] ?? 10000)),
            $scanner instanceof FileScanner ? $scanner : null,
            new FilesConsumers($db),
            $settings,
            $fault instanceof Closure ? $fault : null
        );
    }

    /** @template T @param class-string<T> $service @return T */
    public function make(string $service): object
    {
        return new $service($this->runtime());
    }
}
