<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

final class DocumentVersionRequest extends FilesRequest
{
    public function rules(): array
    {
        return ['file' => ['required_without:file_public_id', 'prohibits:file_public_id', 'file'], 'file_public_id' => ['required_without:file', 'string', 'max:64'], 'classification' => ['sometimes', 'nullable', 'string', 'max:64'], 'issued_on' => ['sometimes', 'nullable', 'string', 'max:10'], 'lock_version' => ['required', 'integer', 'min:0']];
    }
}
