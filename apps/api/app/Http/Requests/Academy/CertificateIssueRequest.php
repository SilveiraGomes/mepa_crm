<?php
namespace App\Http\Requests\Academy;
final class CertificateIssueRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['file_id' => ['required','integer','min:1'], 'reason' => ['sometimes','nullable','string','max:500']]; }
}
