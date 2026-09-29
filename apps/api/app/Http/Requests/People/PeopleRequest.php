<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// Strict People request: unknown fields are rejected (a client can never smuggle unit_id,
// context_kind, status, member_number or internal ids into a write).
class PeopleRequest extends FormRequest
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
        $allowed = array_unique(array_map(static fn (string $key): string => explode('.', $key, 2)[0], array_keys($this->rules())));
        $unknown = array_values(array_diff(array_keys($this->all()), $allowed));
        $validator->after(function (Validator $validator) use ($unknown): void {
            foreach ($unknown as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
        });
    }

    protected static function birthRules(bool $required): array
    {
        return [
            'birth_precision' => [$required ? 'required' : 'sometimes', 'string', 'in:EXACT,MONTH,YEAR,UNKNOWN'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'birth_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2999'],
            'birth_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
