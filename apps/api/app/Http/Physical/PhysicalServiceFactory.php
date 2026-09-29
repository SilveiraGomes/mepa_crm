<?php

declare(strict_types=1);

namespace App\Http\Physical;

use App\Domain\People\PeopleActor;
use App\Domain\People\PeopleCatalog;
use App\Domain\People\PeopleCrypto;
use App\Domain\Physical\PhysicalAudit;
use App\Domain\Physical\PhysicalAuthority;
use App\Domain\Physical\PhysicalCatalog;
use App\Domain\Physical\PhysicalError;
use App\Domain\Physical\PhysicalReason;
use App\Domain\Physical\PhysicalRef;
use App\Domain\Physical\PhysicalRuntime;
use App\Domain\Territorial\TerritorialActor;
use App\Domain\Territorial\TerritorialAuthority;
use App\Http\People\PeopleServiceFactory;
use Closure;
use Illuminate\Database\Connection;

final class PhysicalServiceFactory
{
    public function __construct(private Connection $db)
    {
    }

    // Built per call: the key ring and configuration are read for every operation (a removed ring fails closed).
    public function runtime(): PhysicalRuntime
    {
        $appKey = (string) config('app.key', '');
        if ($appKey === '') {
            throw new PhysicalError(PhysicalReason::CONFIG_MISSING, ['reason' => 'app_key']);
        }
        $settings = (array) config('physical', []);
        $path = $settings['keyring_path'] ?? null;
        $forbidden = [base_path(), public_path(), dirname(base_path(), 2)];
        $db = $this->db;
        // The one scope engine (TerritorialAuthority) evaluated for the PHYSICAL permission domain.
        $scope = new TerritorialAuthority($db, (array) config('auth_contract.active_user_statuses', []), (array) config('auth_contract.active_grant_statuses', []), PhysicalCatalog::DATA_TYPE);
        // D07: the owner Person is projected by People authority, never inherited from a Physical permission.
        $people = new PeopleServiceFactory($db);
        $ownerProjector = static function (TerritorialActor $actor, int $personId) use ($people, $db): ?array {
            $runtime = $people->runtime();
            if (!$runtime->authority->holds(new PeopleActor($actor->user, $actor->session, null), PeopleCatalog::PEOPLE_VIEW, $personId)) {
                return null;
            }
            $row = $db->table('people')->where('id', $personId)->first(['public_id', 'full_name']);
            return $row ? ['public_id' => (string) $row->public_id, 'display_name' => (string) $row->full_name] : null;
        };
        $beforeCommit = app()->bound('physical.before_commit') ? app('physical.before_commit') : null;
        return new PhysicalRuntime(
            $db,
            new PhysicalAuthority($db, $scope),
            new PhysicalAudit($db),
            new PhysicalRef(hash('sha256', 'mepa.physical.ref|' . $appKey, true)),
            static fn (): PeopleCrypto => PeopleCrypto::fromKeyRingFile(is_string($path) ? $path : null, $forbidden),
            $ownerProjector,
            $settings,
            $beforeCommit instanceof Closure ? $beforeCommit : null
        );
    }

    /** @template T @param class-string<T> $service @return T */
    public function make(string $service): object
    {
        return new $service($this->runtime());
    }
}
