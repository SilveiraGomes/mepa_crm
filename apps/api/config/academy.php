<?php

// P0.3.5-A2 central Academy configuration (server-owned; never derived from a request).
//
// D-11 (exact states/transitions) and D-09 (policy values) are OPEN. Nothing below is
// institutionally approved yet, so the approved catalog is intentionally EMPTY and every
// state-dependent operation fails closed with POLICY_NOT_CONFIGURED / STATE_POLICY_PENDING
// until the domain owners approve entries. Proposed (unapproved) states are documented in
// docs/database/physical/wave5_application_contracts.json and are never read from here.
//
// Shape (per kind, e.g. 'enrollments'):
//   'states'      => [kind => ['initial' => string, 'sets' => [role => [state, ...]]]]
//   'transitions' => ['approved' => [kind => [[from, to, effect], ...]], 'pending' => [kind => [[from, to], ...]]]
//   effect = NONE | COMPLETION: every APPROVED transition declares what it does. NONE is an explicit
//   declaration, never a default; an undeclared or contradictory effect (e.g. a transition into the
//   'completed' set that does not declare COMPLETION) is POLICY_NOT_CONFIGURED. This is structure, not a value.
return [
    'policy_version' => null,
    'states' => [],
    'transitions' => ['approved' => [], 'pending' => []],
];
