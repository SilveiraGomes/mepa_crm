<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

final class LinkPrimaryRequest extends PhysicalRequest
{
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:0'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }
}
