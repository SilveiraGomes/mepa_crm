<?php
namespace App\Http\Requests\Academy;
final class AttendanceBulkRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['entries' => ['required','array','min:1','max:200'], 'entries.*.enrollment' => ['required','string','regex:/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/'], 'entries.*.attendance_status' => ['required','string','max:64']]; }
}
