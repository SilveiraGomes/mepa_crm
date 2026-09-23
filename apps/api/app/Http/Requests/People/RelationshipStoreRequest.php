<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

final class RelationshipStoreRequest extends PeopleRequest
{
    public function rules(): array
    {
        return ['related_person'=>['required','string','size:26'],'type'=>['required','string','max:64']];
    }
}
