<?php
namespace App\Http\Requests\Academy;
final class GradeRevisionRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['score' => ['required','numeric','min:0'], 'expected_version' => ['required','integer','min:1'], 'reason' => ['required','string','min:3','max:500']]; }
}
