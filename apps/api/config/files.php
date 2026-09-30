<?php

// P0.8 Documents/Files server configuration (ADR 0019). Vocabulary is NOT here: it is the controlled catalog
// App\Domain\Files\FilesCatalog / FileClassification. Storage and keys live OUTSIDE the document root, the application
// tree, Git and the logs; unset, missing, misplaced or malformed => every file operation fails closed (503).
return [
    // Private object root of the `files_private` disk (D02). Never public_path(), never storage:link.
    'storage_root' => env('FILES_STORAGE_ROOT'),
    // Files KEK ring (D06), distinct from the People ring: {"active_version":n,"keys":{"n":{"kek":"<base64 32 bytes>"}}}.
    'keyring_path' => env('FILES_KEYRING_PATH'),
    // Limits (D02): default 10 MiB per file, hard ceiling 20 MiB enforced in code; images 40 MP / 10 000 px per side.
    'max_file_bytes' => (int) env('FILES_MAX_FILE_BYTES', 10485760),
    'image_max_pixels' => 40000000,
    'image_max_side' => 10000,
    // Quotas (D02): per owner unit (QUARANTINED + AVAILABLE + TOMBSTONE), national (mandatory in production),
    // free-disk reserve (never below 1 GiB).
    'unit_quota_bytes' => (int) env('FILES_UNIT_QUOTA_BYTES', 2147483648),
    'total_quota_bytes' => env('FILES_TOTAL_QUOTA_BYTES'),
    'total_quota_required' => env('APP_ENV') === 'production',
    'reserve_bytes' => (int) env('FILES_RESERVE_BYTES', 1073741824),
    // Reconciliation of QUARANTINED rows left by an interrupted process (D03 step 6).
    'quarantine_stale_minutes' => 15,
    // Optional clamd (tcp://host:port or unix:///path). Configured but unavailable => fail closed (503, QUARANTINED).
    'clamd' => env('FILES_CLAMD'),
    'clamd_timeout' => (int) env('FILES_CLAMD_TIMEOUT', 10),
    'pagination' => ['default' => 50, 'max' => 100],
];
