<?php

// P0.9 Membership server configuration (ADR 0020). Vocabulary is NOT here: it is the controlled catalog
// App\Domain\Membership\MembershipCatalog. Membership has no secret of its own.
return [
    'pagination' => ['default' => 50, 'max' => 100],
];
