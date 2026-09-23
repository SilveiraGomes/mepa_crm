<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class ContactUpdateRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['value'=>['sometimes','string','min:3','max:191'],'is_primary'=>['sometimes','boolean'],'lock_version'=>['required','integer','min:0']];
    }
}
