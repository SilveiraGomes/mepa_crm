<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

final class FileClassificationRequest extends FilesRequest
{
    public function rules(): array
    {
        return ['classification' => ['required', 'string', 'max:64'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:0']];
    }
}
