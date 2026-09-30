<?php

declare(strict_types=1);

namespace App\Http\Membership;

use App\Domain\Files\FilesAuthority;
use App\Domain\Files\FilesCatalog;
use App\Domain\Files\FilesConsumers;
use App\Domain\Membership\MembershipAudit;
use App\Domain\Membership\MembershipAuthority;
use App\Domain\Membership\MembershipCatalog;
use App\Domain\Membership\MembershipRuntime;
use App\Domain\People\PeopleRuntime;
use App\Domain\Territorial\TerritorialAuthority;
use App\Http\People\PeopleServiceFactory;
use Closure;
use Illuminate\Database\Connection;

final class MembershipServiceFactory
{
    public function __construct(private Connection $db)
    {
    }

    // Built per call (configuration and grants are read for every operation).
    public function runtime(): MembershipRuntime
    {
        $db = $this->db;
        $users = (array) config('auth_contract.active_user_statuses', []);
        $grants = (array) config('auth_contract.active_grant_statuses', []);
        // The one scope engine (TerritorialAuthority), evaluated for the MEMBERSHIP permission domain (D05) and, for the
        // Documents adapter of D09, for the FILES domain. Files code is consumed unchanged.
        $scope = new TerritorialAuthority($db, $users, $grants, MembershipCatalog::DATA_TYPE);
        $files = new FilesAuthority($db, new TerritorialAuthority($db, $users, $grants, FilesCatalog::DATA_TYPE));
        $people = new PeopleServiceFactory($db);
        $beforeCommit = app()->bound('membership.before_commit') ? app('membership.before_commit') : null;
        return new MembershipRuntime(
            $db,
            new MembershipAuthority($scope),
            new MembershipAudit($db),
            static fn (): PeopleRuntime => $people->runtime(),
            $files,
            new FilesConsumers($db),
            (array) config('membership', []),
            $beforeCommit instanceof Closure ? $beforeCommit : null
        );
    }

    /** @template T @param class-string<T> $service @return T */
    public function make(string $service): object
    {
        return new $service($this->runtime());
    }
}
