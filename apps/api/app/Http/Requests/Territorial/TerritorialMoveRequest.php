<?php
declare(strict_types=1);
namespace App\Http\Requests\Territorial;
final class TerritorialMoveRequest extends TerritorialRequest { public function rules():array{return ['new_parent_public_id'=>['required','string','size:26'],'reason'=>['required','string','min:3','max:2000'],'lock_version'=>['required','integer','min:0']];} }
