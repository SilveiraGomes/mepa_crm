<?php
namespace App\Http\Requests\Academy;
final class ResourceProgressRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['watched_seconds' => ['required','integer','min:0','max:86400'], 'to_state' => ['sometimes','nullable','string','max:64']]; }
}
