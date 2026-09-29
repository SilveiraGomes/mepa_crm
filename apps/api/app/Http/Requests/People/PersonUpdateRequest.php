<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class PersonUpdateRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['full_name'=>['sometimes','string','min:2','max:191'],'lock_version'=>['required','integer','min:0']] + self::birthRules(false);
    }
}
