<?php
namespace App\Http\Requests\Academy;
final class TransitionRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['to_state' => ['required','string','max:64'], 'reason' => ['sometimes','nullable','string','max:500'], 'lock_version' => ['sometimes','nullable','integer','min:0']]; }
}
