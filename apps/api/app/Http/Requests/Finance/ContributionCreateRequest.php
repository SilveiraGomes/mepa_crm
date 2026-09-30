<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class ContributionCreateRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['kind' => ['required', 'string', 'in:MONETARY,IN_KIND'], 'identification' => ['required', 'string', 'in:IDENTIFIED,ANONYMOUS,AGGREGATED'], 'account' => ['sometimes', 'string', 'size:26'], 'unit' => ['sometimes', 'string', 'size:26'], 'category' => ['required', 'string', 'max:64'], 'amount' => ['sometimes', 'string', 'max:20'], 'description' => ['sometimes', 'string', 'max:2000'], 'received_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'party' => ['sometimes', 'array:kind,person,name'], 'party.kind' => ['required_with:party', 'string', 'in:PERSON,EXTERNAL,UNIT'], 'party.person' => ['sometimes', 'string', 'max:26'], 'party.name' => ['sometimes', 'string', 'max:191'], 'document' => ['sometimes', 'nullable', 'string', 'size:26']];
    }
}
