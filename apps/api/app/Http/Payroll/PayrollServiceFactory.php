<?php

declare(strict_types=1);

namespace App\Http\Payroll;

use App\Domain\Files\FilesAuthority;
use App\Domain\Files\FilesCatalog;
use App\Domain\Files\FilesConsumers;
use App\Domain\Finance\FinanceAuthority;
use App\Domain\Finance\FinanceRuntime;
use App\Domain\Payroll\PayrollCatalog;
use App\Domain\People\PeopleRuntime;
use App\Domain\Territorial\TerritorialAuthority;
use App\Http\People\PeopleServiceFactory;
use Closure;
use Illuminate\Database\Connection;

/**
 * RH / payroll services (ADR 0021 D17, D31): the Finance runtime (transaction, commit-time recheck of every decision and
 * supporting document, Files authority, People visibility) wired to the ONE scope engine TerritorialAuthority evaluated
 * for the HR permission domain. Finance, Files and People code are consumed unchanged.
 */
final class PayrollServiceFactory
{
    public function __construct(private Connection $db)
    {
    }

    public function runtime(): FinanceRuntime
    {
        $db = $this->db;
        $users = (array) config('auth_contract.active_user_statuses', []);
        $grants = (array) config('auth_contract.active_grant_statuses', []);
        $scope = new TerritorialAuthority($db, $users, $grants, PayrollCatalog::DATA_TYPE);
        $files = new FilesAuthority($db, new TerritorialAuthority($db, $users, $grants, FilesCatalog::DATA_TYPE));
        $people = new PeopleServiceFactory($db);
        $beforeCommit = app()->bound('payroll.before_commit') ? app('payroll.before_commit') : null;
        return new FinanceRuntime(
            $db,
            new FinanceAuthority($scope),
            $files,
            new FilesConsumers($db),
            (array) config('payroll', []),
            $beforeCommit instanceof Closure ? $beforeCommit : null,
            static fn (): PeopleRuntime => $people->runtime(),
            null
        );
    }

    /** @template T @param class-string<T> $service @return T */
    public function make(string $service): object
    {
        return new $service($this->runtime());
    }
}
