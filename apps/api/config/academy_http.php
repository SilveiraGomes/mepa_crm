<?php

// HTTP composition only. Institutional state/policy values remain empty until approved.
return [
    'pagination' => ['default' => 50, 'max' => 100],
    'access_policy' => [
        'version' => env('ACADEMY_ACCESS_POLICY_VERSION'),
        'states' => [],
        'credential_type_ids' => [],
    ],
    'child_policy' => [
        'version' => env('ACADEMY_CHILD_POLICY_VERSION'),
        'vocabulary' => [],
        'identifiers' => [],
        'delivery_kind' => null,
        'pickup_kind' => null,
        'guardian_kind' => null,
        'participation_purpose' => null,
    ],
];
