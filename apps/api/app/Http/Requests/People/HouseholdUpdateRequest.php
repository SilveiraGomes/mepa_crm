<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class HouseholdUpdateRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['name'=>['present','nullable','string','max:191'],'lock_version'=>['required','integer','min:0']];
    }
}
