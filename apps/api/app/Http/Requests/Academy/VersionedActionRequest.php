<?php
namespace App\Http\Requests\Academy;
final class VersionedActionRequest extends AcademyRequest
{
    public function rules(): array { return $this->contextRules() + ['reason' => ['sometimes','nullable','string','max:500'], 'lock_version' => ['sometimes','nullable','integer','min:0']]; }
}
