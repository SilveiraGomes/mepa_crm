<?php
namespace App\Http\Requests\Academy;
final class LessonProgressRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['ratio' => ['required','numeric','between:0,1'], 'to_state' => ['sometimes','nullable','string','max:64']]; }
}
