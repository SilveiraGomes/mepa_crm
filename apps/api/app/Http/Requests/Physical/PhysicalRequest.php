<?php

declare(strict_types=1);

namespace App\Http\Requests\Physical;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// Every Physical request declares its complete field list: any other top-level field (source_document_id, status,
// public_visibility, a raw unit_id, ...) is rejected with 422. Public ids referenced in the body are plain strings
// here; the service resolves them so unknown and malformed ids share the same concealed response.
class PhysicalRequest extends FormRequest
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
