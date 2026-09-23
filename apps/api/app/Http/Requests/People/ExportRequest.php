<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class ExportRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['class'=>['required','string','in:STANDARD,SENSITIVE,CLASS_C'],'reason'=>['sometimes','nullable','string','max:500'],'search'=>['sometimes','nullable','string','min:2','max:100'],'status'=>['sometimes','nullable','string','in:ACTIVE,INACTIVE,DECEASED']];
    }
}
