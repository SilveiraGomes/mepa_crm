<?php

declare(strict_types=1);

namespace App\Http\Academy;

use App\Domain\Academy\AcademyError;
use App\Domain\Academy\AcademyPolicy;
use App\Domain\Academy\AcademyReason;
use App\Domain\Academy\AcademyRuntime;
use App\Domain\Events\EventPolicy;
use App\Domain\WaveFour\DomainPolicy;
use Illuminate\Database\Connection;
use Throwable;

final class AcademyServiceFactory
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Server-owned Events access, Children and Academy policy configuration (test vocabulary only under
     * APP_ENV=e2e with ACADEMY_E2E_TEST_POLICY). Shared with People, which consumes the Events and Academy
     * semantics of its context sources instead of naming their states.
     * @return array{access: array, child: array, academy: array}
     */
    public static function policyConfig(): array
    {
        $testOnly = app()->environment('e2e') && (bool) config('academy_e2e.enabled', false);
        return [
            'access' => (array) config($testOnly ? 'academy_e2e.access_policy' : 'academy_http.access_policy', []),
            'child' => (array) config($testOnly ? 'academy_e2e.child_policy' : 'academy_http.child_policy', []),
            'academy' => (array) config($testOnly ? 'academy_e2e.academy' : 'academy', []),
        ];
    }

    public function make(string $service): object
    {
        try {
            ['access' => $access, 'child' => $child, 'academy' => $academy] = self::policyConfig();
            $eventPolicy = new EventPolicy(
                (string) ($access['version'] ?? ''),
                (array) ($access['states'] ?? []),
                false,
                (array) ($access['credential_type_ids'] ?? [])
            );
            $childPolicy = new DomainPolicy(
                (string) ($child['version'] ?? ''),
                (array) ($child['vocabulary'] ?? []),
                (array) ($child['identifiers'] ?? []),
                (string) ($child['delivery_kind'] ?? ''),
                (string) ($child['pickup_kind'] ?? ''),
                (string) ($child['guardian_kind'] ?? ''),
                (string) ($child['participation_purpose'] ?? '')
            );
            $policy = new AcademyPolicy(
                isset($academy['policy_version']) ? (string) $academy['policy_version'] : null,
                (array) ($academy['states'] ?? []),
                (array) ($academy['transitions'] ?? [])
            );

            return new $service(AcademyRuntime::make($this->db, $policy, $eventPolicy, $childPolicy));
        } catch (AcademyError $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AcademyError(AcademyReason::POLICY_NOT_CONFIGURED, [], $e);
        }
    }
}
