<?php

declare(strict_types=1);

namespace App\Http\Territorial;

use App\Domain\Territorial\TerritorialAudit;
use App\Domain\Territorial\TerritorialAuthority;
use App\Domain\Territorial\TerritorialService;
use Illuminate\Database\Connection;

final class TerritorialServiceFactory
{
    public function __construct(private Connection $db) {}

    public function make(): TerritorialService
    {
        return new TerritorialService($this->db,new TerritorialAuthority($this->db,
            (array)config('auth_contract.active_user_statuses',[]),(array)config('auth_contract.active_grant_statuses',[])),new TerritorialAudit($this->db));
    }
}
