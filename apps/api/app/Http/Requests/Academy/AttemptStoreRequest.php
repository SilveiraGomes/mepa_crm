<?php
namespace App\Http\Requests\Academy;
final class AttemptStoreRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['enrollment' => ['required','string','regex:/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/'], 'answers_metadata' => ['sometimes','nullable','array']]; }
}
