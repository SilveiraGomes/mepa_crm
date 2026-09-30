<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

final class FileListRequest extends FilesRequest
{
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1'], 'search' => ['sometimes', 'nullable', 'string', 'max:100'], 'status' => ['sometimes', 'nullable', 'string', 'in:AVAILABLE,TOMBSTONE,QUARANTINED'], 'classification' => ['sometimes', 'nullable', 'string', 'max:64'], 'unit_public_id' => ['sometimes', 'nullable', 'string', 'max:64']];
    }
}
