<?php
namespace App\Http\Requests\Academy;
final class AcademyListRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['page' => ['sometimes','integer','min:1'], 'per_page' => ['sometimes','integer','min:1','max:100'], 'status' => ['sometimes','string','max:64']]; }
}
