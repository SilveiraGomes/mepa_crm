<?php

// P0.10 RH / Payroll server configuration (ADR 0021 D22-D28 + D-04A.15). Vocabulary is the controlled catalog
// App\Domain\Payroll\PayrollCatalog; statutory values are NEVER configuration (they are approved rules with a source).
return [
    // D-04A.15: PAYROLL PRODUCTION ENABLED is false by default and only an operator turns it on after the documented
    // activation checklist. Unset / empty / unrecognised => false; PHP boolean literals ("true", "1", "on", "yes") => true.
    'production_enabled' => filter_var(env('PAYROLL_PRODUCTION_ENABLED', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
    'pagination' => ['default' => 50, 'max' => 100],
];
