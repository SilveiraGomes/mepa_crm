<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class EndRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['reason'=>['sometimes','nullable','string','max:500']];
    }
}
