<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class ContactStoreRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['type'=>['required','string','max:64'],'value'=>['required','string','min:3','max:191'],'is_primary'=>['sometimes','boolean']];
    }
}
