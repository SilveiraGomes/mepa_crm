<?php

// P0.5-I People / Families server configuration. Institutional vocabulary is NOT here: it is the
// controlled catalog of ADR-0017 (App\Domain\People\PeopleCatalog). Actor/grant activity uses the
// same auth_contract statuses as the authentication middleware.
return [
    // Key ring JSON outside the DB, the code tree, Git and the public directory (ADR-0017 D-08).
    // Unset, unreadable, misplaced or malformed => every encrypted operation fails closed.
    'keyring_path' => env('PEOPLE_KEYRING_PATH'),
    'pagination' => ['default' => 50, 'max' => 100],
    'export_max_rows' => 1000,
    'majority_age' => 18,
    'timezone' => 'Africa/Luanda',
];
