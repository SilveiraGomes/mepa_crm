<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2A (ADR 0021 D23-D28). New effective-dated compensation line; the amount is a decimal STRING (never a JSON number). Any other top-level field (a numeric *_id, a raw unit / person id, a status, an amount
// where none is taken) is rejected with 422 by the closed-field-list base request.
final class CompensationChangeRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['component' => ['required', 'string', 'max:64'], 'amount' => ['present', 'nullable', 'string', 'max:32'], 'currency' => ['sometimes', 'nullable', 'string', 'max:3'], 'starts_on' => ['required', 'string', 'max:10'], 'reason' => ['required', 'string', 'max:2000'], 'source_document' => ['sometimes', 'nullable', 'string', 'max:26']];
    }
}
