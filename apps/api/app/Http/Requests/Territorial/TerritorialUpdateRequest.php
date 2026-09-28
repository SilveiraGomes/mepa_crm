<?php
declare(strict_types=1);
namespace App\Http\Requests\Territorial;
final class TerritorialUpdateRequest extends TerritorialRequest { public function rules():array{return ['code'=>['required','string','max:64'],'name'=>['required','string','max:191'],'opened_on'=>['sometimes','nullable','date_format:Y-m-d'],'reason'=>['sometimes','nullable','string','max:2000'],'lock_version'=>['required','integer','min:0']];} }
