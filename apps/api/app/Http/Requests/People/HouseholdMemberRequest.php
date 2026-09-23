<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class HouseholdMemberRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['person'=>['required','string','size:26'],'role'=>['required','string','max:64']];
    }
}
