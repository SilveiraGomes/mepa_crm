<?php
namespace App\Http\Requests\Academy;
final class CurriculumCourseRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['course' => ['required','string','regex:/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/'], 'sequence' => ['required','integer','min:1'], 'required' => ['required','boolean']]; }
}
