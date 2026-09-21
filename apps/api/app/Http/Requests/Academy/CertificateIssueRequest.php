<?php
namespace App\Http\Requests\Academy;
final class CertificateIssueRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['file' => ['required','string','regex:/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/'], 'reason' => ['sometimes','nullable','string','max:500']]; }
}
