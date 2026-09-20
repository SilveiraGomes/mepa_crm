<?php
namespace App\Http\Requests\Academy;
final class AttendanceRecordRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['attendance_status' => ['required','string','max:64'], 'lock_version' => ['sometimes','nullable','integer','min:0']]; }
}
