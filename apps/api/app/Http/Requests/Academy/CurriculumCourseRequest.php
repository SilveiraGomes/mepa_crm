<?php
namespace App\Http\Requests\Academy;
final class CurriculumCourseRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['course_id' => ['required','integer','min:1'], 'sequence' => ['required','integer','min:1'], 'required' => ['required','boolean']]; }
}
