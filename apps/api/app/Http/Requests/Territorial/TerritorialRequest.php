<?php

declare(strict_types=1);

namespace App\Http\Requests\Territorial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TerritorialRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()!==null; }
    public function rules(): array { return []; }
    public function withValidator(Validator $validator): void { $allowed=array_unique(array_map(fn(string $k):string=>explode('.',$k,2)[0],array_keys($this->rules())));$unknown=array_diff(array_keys($this->all()),$allowed);$validator->after(function(Validator $v)use($unknown):void{foreach($unknown as $field)$v->errors()->add($field,'This field is not allowed.');}); }
}
