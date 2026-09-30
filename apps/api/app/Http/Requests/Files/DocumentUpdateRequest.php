<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

final class DocumentUpdateRequest extends FilesRequest
{
    public function rules(): array
    {
        return ['type_code' => ['sometimes', 'string', 'max:64'], 'reference' => ['sometimes', 'string', 'max:64'], 'title' => ['sometimes', 'string', 'max:191'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000'], 'lock_version' => ['required', 'integer', 'min:0']];
    }
}
