<?php
declare(strict_types=1);
namespace App\Http\Requests\Territorial;
final class TerritorialCreateRequest extends TerritorialRequest { public function rules():array{return ['parent_public_id'=>['required','string','size:26'],'type_code'=>['required','string','max:64'],'code'=>['required','string','max:64'],'name'=>['required','string','max:191'],'opened_on'=>['sometimes','nullable','date_format:Y-m-d'],'reason'=>['sometimes','nullable','string','max:2000']];} }
