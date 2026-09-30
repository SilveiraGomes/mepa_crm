<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

final class TransferSendRequest extends FinanceRequest
{
    public function rules(): array
    {
        return ['lock_version' => ['sometimes', 'nullable', 'integer', 'min:0'], 'sent_on' => ['sometimes', 'nullable', 'date_format:Y-m-d']];
    }
}
