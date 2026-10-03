<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Http\Requests\Finance\FinanceRequest;

// P0.10-F2A (ADR 0021 D23-D28). Create a MEPA employment: an existing Person (public_id) + employing unit (public_id). Any other top-level field (a numeric *_id, a raw unit / person id, a status, an amount
// where none is taken) is rejected with 422 by the closed-field-list base request.
final class EmploymentStoreRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['person' => ['required', 'string', 'max:26'], 'unit' => ['required', 'string', 'max:26'], 'relationship_kind' => ['required', 'string', 'max:16'], 'job_title' => ['sometimes', 'nullable', 'string', 'max:160'], 'starts_on' => ['required', 'string', 'max:10'], 'contract_document' => ['sometimes', 'nullable', 'string', 'max:26']];
    }
}
