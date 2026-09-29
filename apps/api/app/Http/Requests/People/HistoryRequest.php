<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class HistoryRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['include_history'=>['sometimes','boolean']];
    }
}
