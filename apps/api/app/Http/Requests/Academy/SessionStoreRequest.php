<?php
namespace App\Http\Requests\Academy;
final class SessionStoreRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['starts_at' => ['required','date'], 'ends_at' => ['required','date','after:starts_at'], 'lesson_id' => ['sometimes','nullable','integer','min:1'], 'event_session_id' => ['sometimes','nullable','integer','min:1']]; }
}
