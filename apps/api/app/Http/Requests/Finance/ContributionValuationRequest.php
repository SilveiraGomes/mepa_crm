<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class ContributionValuationRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['valuation_amount' => ['required', 'string', 'max:20'], 'document' => ['required', 'string', 'size:26']];
    }
}
