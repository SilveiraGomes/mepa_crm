<?php
namespace App\Http\Requests\Academy;
final class GradeStoreRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['score' => ['required','numeric','min:0'], 'reason' => ['sometimes','nullable','string','max:500']]; }
}
