<?php

declare(strict_types=1);

namespace App\Http\People;

use App\Domain\People\OpaqueRef;
use App\Domain\People\PeopleAudit;
use App\Domain\People\PeopleAuthority;
use App\Domain\People\PeopleCrypto;
use App\Domain\People\PeopleError;
use App\Domain\People\PeopleReason;
use App\Domain\People\PeopleRuntime;
use Illuminate\Database\Connection;

final class PeopleServiceFactory
{
    public function __construct(private Connection $db)
    {
    }

    // Built per call: Laravel may keep controller (and so factory) instances on the route object, and the
    // key ring / configuration must be read for every operation (a removed key ring must fail closed at once).
    public function runtime(): PeopleRuntime
    {
        $appKey = (string) config('app.key', '');
        if ($appKey === '') {
            throw new PeopleError(PeopleReason::CONFIG_MISSING, ['reason' => 'app_key']);
        }
        $settings = (array) config('people', []);
        $path = $settings['keyring_path'] ?? null;
        $forbidden = [base_path(), public_path(), dirname(base_path(), 2)];
        return new PeopleRuntime(
            $this->db,
            new PeopleAuthority($this->db, (array) config('auth_contract.active_user_statuses', []), (array) config('auth_contract.active_grant_statuses', [])),
            new PeopleAudit($this->db),
            new OpaqueRef(hash('sha256', 'mepa.people.ref|' . $appKey, true)),
            static fn (): PeopleCrypto => PeopleCrypto::fromKeyRingFile(is_string($path) ? $path : null, $forbidden),
            $settings
        );
    }

    /** @template T @param class-string<T> $service @return T */
    public function make(string $service): object
    {
        return new $service($this->runtime());
    }
}
