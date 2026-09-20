<?php
namespace App\Http\Requests\Academy;
final class TranscriptIssueRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['file_id' => ['required','integer','min:1']]; }
}
