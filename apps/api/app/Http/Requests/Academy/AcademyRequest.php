<?php

declare(strict_types=1);

namespace App\Http\Requests\Academy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class AcademyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function contextRules(): array
    {
        return [
            'academic_unit_id' => ['sometimes', 'integer', 'min:1'],
            'unit_id' => ['sometimes', 'integer', 'min:1'],
            'class_id' => ['sometimes', 'integer', 'min:1'],
            'override_reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }

    public function claimed(): ?array
    {
        $claims = array_intersect_key($this->validated(), array_flip(['academic_unit_id', 'unit_id', 'class_id']));
        return $claims === [] ? null : $claims;
    }

    public function withValidator(Validator $validator): void
    {
        $allowed = array_map(static fn (string $key): string => explode('.', $key, 2)[0], array_keys($this->rules()));
        $unknown = array_values(array_diff(array_keys($this->all()), array_unique($allowed)));
        $validator->after(function (Validator $validator) use ($unknown): void {
            foreach ($unknown as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
        });
    }
}
