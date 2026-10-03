<?php

// P0.10 Finance server configuration (ADR 0021). Vocabulary is NOT here: it is the controlled catalog
// App\Domain\Finance\FinanceCatalog. Finance has no secret of its own.
return [
    'pagination' => ['default' => 50, 'max' => 100],
];
