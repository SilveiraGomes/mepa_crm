<?php

declare(strict_types=1);

namespace App\Http\Requests\Files;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// Every Documents/Files request declares its complete field list: any other top-level field (an internal id such as
// file_id / owner_unit_id / source_document_id, disk, storage_key, status, checksum, ...) is rejected with 422. Public
// ids are plain strings here; the service resolves them AFTER the permission, so unknown and malformed ids share the
// same concealed response. A declared MIME type is never read.
class FilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $allowed = array_unique(array_map(fn (string $k): string => explode('.', $k, 2)[0], array_keys($this->rules())));
        $unknown = array_diff(array_keys($this->all()), $allowed);
        $validator->after(function (Validator $v) use ($unknown): void {
            foreach ($unknown as $field) {
                $v->errors()->add($field, 'This field is not allowed.');
            }
        });
    }
}
