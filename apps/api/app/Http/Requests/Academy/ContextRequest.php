<?php
namespace App\Http\Requests\Academy;
final class ContextRequest extends AcademyRequest { public function rules(): array { return $this->contextRules(); } }
