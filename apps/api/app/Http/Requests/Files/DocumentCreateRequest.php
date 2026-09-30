<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

final class DocumentCreateRequest extends FilesRequest
{
    public function rules(): array
    {
        return ['file' => ['required_without:file_public_id', 'prohibits:file_public_id', 'file'], 'file_public_id' => ['required_without:file', 'string', 'max:64'], 'owner_unit_public_id' => ['required', 'string', 'max:64'], 'type_code' => ['required', 'string', 'max:64'], 'reference' => ['required', 'string', 'max:64'], 'title' => ['required', 'string', 'max:191'], 'classification' => ['sometimes', 'nullable', 'string', 'max:64'], 'issued_on' => ['sometimes', 'nullable', 'string', 'max:10']];
    }
}
