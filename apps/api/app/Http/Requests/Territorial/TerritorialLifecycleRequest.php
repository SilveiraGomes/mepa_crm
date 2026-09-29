<?php
declare(strict_types=1);
namespace App\Http\Requests\Territorial;
final class TerritorialLifecycleRequest extends TerritorialRequest { public function rules():array{return ['status'=>['required','string','in:ACTIVE,CLOSED'],'reason'=>['required','string','min:3','max:2000'],'closed_on'=>['sometimes','nullable','date_format:Y-m-d'],'lock_version'=>['required','integer','min:0']];} }
