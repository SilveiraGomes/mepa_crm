<?php

declare(strict_types=1);

namespace App\Http\Territorial;

use App\Domain\Territorial\TerritorialError;
use App\Domain\Territorial\TerritorialReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TerritorialOutput
{
    private const FORBIDDEN=['id','unit_id','parent_id','unit_type_id','municipality_id'];
    public static function item(array $data,int $status=200): JsonResponse { self::assertSafe($data); return response()->json(['data'=>$data],$status); }
    public static function page(Request $request,array $p): JsonResponse { self::assertSafe($p['items']??[]); $page=(int)($p['page']??1);$per=(int)($p['per_page']??25);$total=(int)($p['total']??0);$last=max(1,(int)ceil($total/max(1,$per)));return response()->json(['data'=>$p['items']??[],'meta'=>['current_page'=>$page,'per_page'=>$per,'total'=>$total,'last_page'=>$last],'links'=>['prev'=>$page>1?$request->fullUrlWithQuery(['page'=>$page-1]):null,'next'=>$page<$last?$request->fullUrlWithQuery(['page'=>$page+1]):null]]); }
    public static function assertSafe(array $data): void { foreach($data as $key=>$value){ if(is_string($key)&&(in_array($key,self::FORBIDDEN,true)||(str_ends_with($key,'_id')&&!in_array($key,['public_id','parent_public_id'],true))))throw new TerritorialError(TerritorialReason::INVARIANT_VIOLATION,['reason'=>'internal_field_in_response']); if(is_array($value))self::assertSafe($value); } }
}
