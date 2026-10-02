<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2A (ADR 0021 D23-D28). Draft a new version of a statutory rule; every value comes from the official source loaded by a person. Any other top-level field (a numeric *_id, a raw unit / person id, a status, an amount
// where none is taken) is rejected with 422 by the closed-field-list base request.
final class RuleDraftRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:64'], 'component' => ['required', 'string', 'max:64'], 'method' => ['required', 'string', 'max:16'], 'rate' => ['present', 'nullable', 'string', 'max:16'], 'starts_on' => ['required', 'string', 'max:10'], 'ends_on' => ['sometimes', 'nullable', 'string', 'max:10'], 'base_components' => ['required', 'array', 'max:20'], 'base_components.*' => ['string', 'max:64'], 'brackets' => ['sometimes', 'array', 'max:50'], 'brackets.*' => ['array:lower_bound,upper_bound,rate,fixed_amount,excess_over'], 'brackets.*.lower_bound' => ['required', 'string', 'max:32'], 'brackets.*.upper_bound' => ['present', 'nullable', 'string', 'max:32'], 'brackets.*.rate' => ['required', 'string', 'max:16'], 'brackets.*.fixed_amount' => ['sometimes', 'string', 'max:32'], 'brackets.*.excess_over' => ['sometimes', 'string', 'max:32'], 'source_document' => ['sometimes', 'nullable', 'string', 'max:26']];
    }
}
