<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class SubledgerCreateRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'unit' => ['required', 'string', 'size:26'],
            'party' => ['required', 'array:kind,person,name'],
            'party.kind' => ['required', 'string', 'in:PERSON,EXTERNAL,UNIT'],
            'party.person' => ['sometimes', 'string', 'max:26'],
            'party.name' => ['sometimes', 'string', 'max:191'],
            'category' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'string', 'max:20'],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'recognized_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'document' => ['sometimes', 'nullable', 'string', 'size:26'],
            'description' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }
}
