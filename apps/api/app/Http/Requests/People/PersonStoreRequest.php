<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class PersonStoreRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['full_name'=>['required','string','min:2','max:191'],'unit'=>['sometimes','nullable','string','size:26']] + self::birthRules(true);
    }
}
