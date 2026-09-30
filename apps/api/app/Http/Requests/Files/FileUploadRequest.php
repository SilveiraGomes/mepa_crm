<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

final class FileUploadRequest extends FilesRequest
{
    public function rules(): array
    {
        return ['file' => ['required', 'file'], 'owner_unit_public_id' => ['required', 'string', 'max:64'], 'classification' => ['sometimes', 'nullable', 'string', 'max:64']];
    }
}
