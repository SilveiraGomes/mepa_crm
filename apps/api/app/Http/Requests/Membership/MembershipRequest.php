<?php

declare(strict_types=1);

namespace App\Http\Requests\Membership;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

// Every Membership request declares its complete field list: any other top-level field (a numeric
// source_document_id, a raw person_id/unit_id, member_number, status, approved_by...) is rejected with 422. Public ids
// referenced in the body are plain strings here; the service resolves them AFTER the permission check so unknown,
// malformed and out-of-scope ids share the same concealed response (F-06).
class MembershipRequest extends FormRequest
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
