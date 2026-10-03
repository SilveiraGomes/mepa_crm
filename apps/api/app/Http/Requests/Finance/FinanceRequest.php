<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// Every Finance request declares its complete field list: any other top-level field (a numeric *_id, a raw unit id,
// status, amount on a stage that does not take one...) is rejected with 422. Public ids referenced in the body are plain
// strings here; the service resolves them AFTER the permission check so unknown, malformed and out-of-scope ids share
// the same concealed response (F-06). Money is a decimal STRING (never a JSON number: no float ever reaches the domain).
class FinanceRequest extends FormRequest
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
