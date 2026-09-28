<?php

declare(strict_types=1);

namespace Tests\DatabaseV2\Support;

use App\Domain\People\PeopleCatalog;
use App\Domain\Territorial\TerritorialCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;

abstract class TerritorialHttpCase extends HttpWaveFiveCase
{
    protected function setUp(): void
    {
        parent::setUp(); $this->withoutMiddleware(ThrottleRequests::class); PeopleCatalog::install(DB::connection()); TerritorialCatalog::install(DB::connection());
        $rules=json_decode(file_get_contents(dirname(__DIR__,5).'/docs/database/unit_parent_rules.json'),true,512,JSON_THROW_ON_ERROR);
        foreach(array_unique(array_merge(...$rules['allowed_parent_child_pairs'])) as $code){if(!DB::table('organizational_unit_types')->where('code',$code)->exists())$this->row('organizational_unit_types',['code'=>$code,'name'=>TerritorialCatalog::TYPE_LABELS[$code],'is_active'=>1]);}
        foreach($rules['allowed_parent_child_pairs'] as[$parent,$child]){$p=(int)DB::table('organizational_unit_types')->where('code',$parent)->value('id');$c=(int)DB::table('organizational_unit_types')->where('code',$child)->value('id');if(!DB::table('unit_parent_rules')->where('parent_type_id',$p)->where('child_type_id',$c)->exists())$this->row('unit_parent_rules',['parent_type_id'=>$p,'child_type_id'=>$c]);}
    }
    protected function unit(string $type,?int $parent=null,string $status='DRAFT',?string $name=null):array{$tid=(int)DB::table('organizational_unit_types')->where('code',$type)->value('id');$id=$this->row('organizational_units',['unit_type_id'=>$tid,'parent_id'=>$parent,'public_id'=>(string)Str::ulid(),'code'=>'P06-'.Str::upper(Str::random(10)),'name'=>$name??$type.' '.Str::random(5),'status'=>$status,'lock_version'=>0]);return['id'=>$id,'public_id'=>(string)DB::table('organizational_units')->where('id',$id)->value('public_id')];}
    protected function staff(array $permissions,int $unit,bool $descendants=true):array{$person=$this->row('people',['full_name'=>'Territorial staff '.Str::random(5)]);$user=$this->row('users',['person_id'=>$person,'status'=>'SYNTHETIC_READY','mfa_required'=>0]);$token='p06-'.bin2hex(random_bytes(20));$session=$this->row('auth_sessions',['user_id'=>$user,'token_hash'=>hash('sha256',$token,true),'expires_at'=>now('UTC')->addHours(4)->format('Y-m-d H:i:s.u'),'revoked_at'=>null]);$role=$this->row('roles',['is_active'=>1]);$scope=$this->row('scopes',['unit_id'=>$unit,'include_descendants'=>$descendants?1:0,'scope_kind'=>'UNIT','department_instance_id'=>null]);foreach($permissions as$code){$permission=(int)DB::table('permissions')->where('code',$code)->value('id');$this->row('role_permissions',['role_id'=>$role,'permission_id'=>$permission]);}$this->row('user_role_scopes',['user_id'=>$user,'role_id'=>$role,'scope_id'=>$scope,'granted_by'=>$user,'status'=>'SYNTHETIC_READY','starts_at'=>now('UTC')->subHour()->format('Y-m-d H:i:s.u'),'ends_at'=>null]);return compact('user','session','token','unit');}
    protected function api(array $actor,string $method,string $uri,array $body=[]):TestResponse{return $this->withHeaders(['Authorization'=>'Bearer '.$actor['token'],'Accept'=>'application/json'])->json($method,'/api/v1/'.ltrim($uri,'/'),$body);}
    protected function allPermissions():array{return TerritorialCatalog::PERMISSIONS;}
}
