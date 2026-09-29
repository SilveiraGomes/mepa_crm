<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class HouseholdListRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],'search'=>['sometimes','string','min:2','max:100'],'status'=>['sometimes','string','in:ACTIVE,INACTIVE,ARCHIVED']];
    }
}
