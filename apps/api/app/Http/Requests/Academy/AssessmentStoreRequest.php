<?php
namespace App\Http\Requests\Academy;
final class AssessmentStoreRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['lesson_id' => ['sometimes','nullable','integer','min:1'], 'name' => ['required','string','max:191'], 'max_score' => ['required','numeric','min:0'], 'pass_score' => ['sometimes','nullable','numeric','min:0'], 'max_attempts' => ['required','integer','min:1'], 'weight' => ['required','numeric','min:0']]; }
}
