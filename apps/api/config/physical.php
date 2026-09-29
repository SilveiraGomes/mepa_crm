<?php

// P0.7 Physical Locations server configuration (ADR 0018). Vocabulary is NOT here: it is the controlled catalog
// App\Domain\Physical\PhysicalCatalog. The address line reuses the P0.5 key ring (ADR 0017 D-08): a JSON file
// outside the DB, the code tree, Git and the public directory. Unset, unreadable, misplaced or malformed => every
// encrypted operation fails closed.
return [
    'keyring_path' => env('PHYSICAL_KEYRING_PATH', env('PEOPLE_KEYRING_PATH')),
    'pagination' => ['default' => 50, 'max' => 100],
];
