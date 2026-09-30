<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

final class FileOwnerRequest extends FilesRequest
{
    public function rules(): array
    {
        return ['to_unit_public_id' => ['required', 'string', 'max:64'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:0']];
    }
}
