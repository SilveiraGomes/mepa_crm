<?php

return [
    // Fixed-lifetime opaque bearer sessions. Renewal requires a new login; there is no
    // refresh-token structure in the approved schema.
    'session_ttl_minutes' => (int) env('AUTH_SESSION_TTL_MINUTES', 480),
    'active_user_statuses' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTH_ACTIVE_USER_STATUSES', 'ACTIVE'))))),
    'active_grant_statuses' => array_values(array_filter(array_map('trim', explode(',', (string) env('AUTH_ACTIVE_GRANT_STATUSES', 'ACTIVE'))))),
];
