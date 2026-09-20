<?php
namespace App\Http\Requests\Academy;
final class InstructorAssignRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['person' => ['required','string','regex:/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/'], 'starts_at' => ['sometimes','nullable','date'], 'ends_at' => ['sometimes','nullable','date','after:starts_at'], 'reason' => ['sometimes','nullable','string','max:500'], 'source_document_id' => ['sometimes','nullable','integer','min:1']]; }
}
