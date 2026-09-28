<?php
declare(strict_types=1);
namespace App\Http\Requests\Territorial;
final class TerritorialListRequest extends TerritorialRequest { public function rules():array{return ['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100'],'search'=>['sometimes','string','max:100'],'type'=>['sometimes','string','max:64'],'status'=>['sometimes','string','in:DRAFT,ACTIVE,CLOSED']];} }
