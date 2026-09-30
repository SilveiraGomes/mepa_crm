<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

final class FileLifecycleRequest extends FilesRequest
{
    public function rules(): array
    {
        return ['reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:0']];
    }
}
