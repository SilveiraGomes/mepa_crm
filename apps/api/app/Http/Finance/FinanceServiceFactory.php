<?php

declare(strict_types=1);

namespace App\Http\Finance;

use App\Domain\Files\FilesAuthority;
use App\Domain\Files\FilesCatalog;
use App\Domain\Files\FilesConsumers;
use App\Domain\Files\FilesKeyRing;
use App\Domain\Finance\FinanceAuthority;
use App\Domain\Finance\FinanceCatalog;
use App\Domain\Finance\FinanceRuntime;
use App\Domain\People\PeopleRuntime;
use App\Domain\Territorial\TerritorialAuthority;
use App\Http\People\PeopleServiceFactory;
use Closure;
use Illuminate\Database\Connection;

final class FinanceServiceFactory
{
    public function __construct(private Connection $db)
    {
    }

    // Built per call (configuration and grants are read for every operation).
    public function runtime(): FinanceRuntime
    {
        $db = $this->db;
        $users = (array) config('auth_contract.active_user_statuses', []);
        $grants = (array) config('auth_contract.active_grant_statuses', []);
        // The one scope engine (TerritorialAuthority), evaluated for the FINANCE permission domain (D17) and, for the
        // supporting-document adapter of D14, for the FILES domain. Files and People code are consumed unchanged.
        $scope = new TerritorialAuthority($db, $users, $grants, FinanceCatalog::DATA_TYPE);
        $files = new FilesAuthority($db, new TerritorialAuthority($db, $users, $grants, FilesCatalog::DATA_TYPE));
        $people = new PeopleServiceFactory($db);
        $beforeCommit = app()->bound('finance.before_commit') ? app('finance.before_commit') : null;
        return new FinanceRuntime(
            $db,
            new FinanceAuthority($scope),
            $files,
            new FilesConsumers($db),
            (array) config('finance', []),
            $beforeCommit instanceof Closure ? $beforeCommit : null,
            static fn (): PeopleRuntime => $people->runtime(),
            static fn (): FilesKeyRing => FilesKeyRing::fromFile(is_string(config('files.keyring_path')) ? config('files.keyring_path') : null, [public_path(), base_path(), dirname(base_path(), 2)])
        );
    }

    /** @template T @param class-string<T> $service @return T */
    public function make(string $service): object
    {
        return new $service($this->runtime());
    }
}
