<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class LinkEndRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:0'], 'close_location' => ['sometimes', 'boolean']];
    }
}
