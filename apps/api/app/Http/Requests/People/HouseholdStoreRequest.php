<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class HouseholdStoreRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['name'=>['sometimes','nullable','string','max:191'],'reference_person'=>['required','string','size:26'],'role'=>['sometimes','string','max:64']];
    }
}
