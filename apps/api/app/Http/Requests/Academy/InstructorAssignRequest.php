<?php
namespace App\Http\Requests\Academy;
// P08-D-F01 (ADR 0019 D04): the source document is referenced ONLY by its legal_documents public_id. A numeric
// `source_document_id` is an unknown field (422); the public_id is resolved by the domain after the class authority,
// so an unknown, malformed or foreign document gets the same concealed response.
final class InstructorAssignRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['person' => ['required','string','regex:/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/'], 'starts_at' => ['sometimes','nullable','date'], 'ends_at' => ['sometimes','nullable','date','after:starts_at'], 'reason' => ['sometimes','nullable','string','max:500'], 'source_document' => ['sometimes','nullable','string','max:64']]; }
}
