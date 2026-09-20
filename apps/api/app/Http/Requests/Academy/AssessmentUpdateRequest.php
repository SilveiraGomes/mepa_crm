<?php
namespace App\Http\Requests\Academy;
final class AssessmentUpdateRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['lock_version' => ['required','integer','min:0'], 'name' => ['sometimes','string','max:191'], 'max_score' => ['sometimes','numeric','min:0'], 'pass_score' => ['sometimes','nullable','numeric','min:0'], 'max_attempts' => ['sometimes','integer','min:1'], 'weight' => ['sometimes','numeric','min:0']]; }
}
