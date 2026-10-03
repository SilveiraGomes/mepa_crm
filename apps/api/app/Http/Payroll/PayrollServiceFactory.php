<?php

declare(strict_types=1);

namespace App\Http\Payroll;

use App\Domain\Files\FilesAuthority;
use App\Domain\Files\FilesCatalog;
use App\Domain\Files\FilesConsumers;
use App\Domain\Finance\FinanceAuthority;
use App\Domain\Finance\FinanceCatalog;
use App\Domain\Finance\FinanceRuntime;
use App\Domain\Payroll\PayrollCatalog;
use App\Domain\Payroll\PayrollFinanceGate;
use App\Domain\People\PeopleRuntime;
use App\Domain\Territorial\TerritorialAuthority;
use App\Http\People\PeopleServiceFactory;
use Closure;
use Illuminate\Database\Connection;

/**
 * RH / payroll services (ADR 0021 D17, D31): the Finance runtime (transaction, commit-time recheck of every decision and
 * supporting document, Files authority, People visibility) wired to the ONE scope engine TerritorialAuthority evaluated
 * for the HR permission domain. Finance, Files and People code are consumed unchanged.
 *
 * F2B: the operations that write the ledger also need FINANCE_POST on the employing unit (D26/D31). The same scope engine
 * evaluated for the FINANCE domain (PayrollFinanceGate) records those decisions; the runtime's before-commit step
 * re-verifies them under lock, after the optional test hook `payroll.before_commit` (deterministic concurrency barrier).
 */
final class PayrollServiceFactory
{
    public function __construct(private Connection $db)
    {
    }

    public function runtime(?PayrollFinanceGate $gate = null): FinanceRuntime
    {
        $db = $this->db;
        $users = (array) config('auth_contract.active_user_statuses', []);
        $grants = (array) config('auth_contract.active_grant_statuses', []);
        $scope = new TerritorialAuthority($db, $users, $grants, PayrollCatalog::DATA_TYPE);
        $files = new FilesAuthority($db, new TerritorialAuthority($db, $users, $grants, FilesCatalog::DATA_TYPE));
        $people = new PeopleServiceFactory($db);
        $hook = app()->bound('payroll.before_commit') ? app('payroll.before_commit') : null;
        $hook = $hook instanceof Closure ? $hook : null;
        $beforeCommit = $gate === null ? $hook : static function () use ($hook, $gate): void {
            if ($hook !== null) {
                $hook();
            }
            $gate->recheck();
        };
        return new FinanceRuntime(
            $db,
            new FinanceAuthority($scope),
            $files,
            new FilesConsumers($db),
            (array) config('payroll', []),
            $beforeCommit,
            static fn (): PeopleRuntime => $people->runtime(),
            null
        );
    }

    public function financeGate(): PayrollFinanceGate
    {
        $users = (array) config('auth_contract.active_user_statuses', []);
        $grants = (array) config('auth_contract.active_grant_statuses', []);
        return new PayrollFinanceGate(new FinanceAuthority(new TerritorialAuthority($this->db, $users, $grants, FinanceCatalog::DATA_TYPE)));
    }

    /** @template T @param class-string<T> $service @return T */
    public function make(string $service): object
    {
        $gate = $this->financeGate();
        return new $service($this->runtime($gate), $gate);
    }
}
