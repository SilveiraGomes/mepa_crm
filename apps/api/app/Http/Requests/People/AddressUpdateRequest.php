<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class AddressUpdateRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['country_code'=>['sometimes','string','size:3','alpha'],'province'=>['sometimes','nullable','string','max:64'],'municipality'=>['sometimes','nullable','string','max:64'],'line1'=>['sometimes','string','min:3','max:255'],'locality'=>['sometimes','nullable','string','max:191'],'lock_version'=>['required','integer','min:0']];
    }
}
