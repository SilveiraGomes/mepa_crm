<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class SelectorRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['purpose'=>['required','string','in:household,relationship'],'search'=>['required','string','min:2','max:100']];
    }
}
