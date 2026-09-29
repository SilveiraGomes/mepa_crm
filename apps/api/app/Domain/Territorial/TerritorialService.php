<?php

declare(strict_types=1);

namespace App\Domain\Territorial;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;

final class TerritorialService
{
    public function __construct(private Connection $db, private TerritorialAuthority $authority, private TerritorialAudit $audit)
    {
    }

    public function context(int $user, int $session): array
    {
        $actor=$this->authority->actor($user,$session);
        $units=[];
        foreach ($this->authority->coveredUnits($actor,TerritorialCatalog::VIEW) as $id=>$_) {
            $row=$this->unitRow($id); if ($row) $units[]=$this->project($row);
            if (count($units)>=200) break;
        }
        return ['permissions'=>$this->authority->effectivePermissions($actor),'working_units'=>$units,
            'types'=>array_map(fn($code,$label)=>['code'=>$code,'label'=>$label],array_keys(TerritorialCatalog::TYPE_LABELS),TerritorialCatalog::TYPE_LABELS),
            'statuses'=>TerritorialCatalog::STATUSES];
    }

    public function list(int $user,int $session,array $filters): array
    {
        $actor=$this->authority->actor($user,$session); $covered=array_keys($this->authority->coveredUnits($actor,TerritorialCatalog::VIEW));
        $page=max(1,(int)($filters['page']??1)); $per=min(100,max(1,(int)($filters['per_page']??25)));
        if ($covered===[]) return ['items'=>[],'page'=>$page,'per_page'=>$per,'total'=>0];
        $q=$this->base()->whereIn('ou.id',$covered);
        if (($filters['search']??'')!=='') $q->where(fn($w)=>$w->where('ou.name','like','%'.$filters['search'].'%')->orWhere('ou.code','like','%'.$filters['search'].'%'));
        if (($filters['type']??'')!=='') $q->where('ut.code',$filters['type']);
        if (($filters['status']??'')!=='') $q->where('ou.status',$filters['status']);
        $total=(clone $q)->count('ou.id');
        $rows=$q->orderBy('ou.name')->orderBy('ou.id')->forPage($page,$per)->get();
        return ['items'=>array_map(fn($r)=>$this->project($r),$rows->all()),'page'=>$page,'per_page'=>$per,'total'=>$total];
    }

    public function roots(int $user,int $session,array $filters=[]): array
    {
        $actor=$this->authority->actor($user,$session); $covered=array_keys($this->authority->coveredUnits($actor,TerritorialCatalog::VIEW));
        if ($covered===[]) return ['items'=>[],'page'=>1,'per_page'=>25,'total'=>0];
        $page=max(1,(int)($filters['page']??1)); $per=min(50,max(1,(int)($filters['per_page']??25)));
        $q=$this->base()->whereIn('ou.id',$covered)->where(fn($w)=>$w->whereNull('ou.parent_id')->orWhereNotIn('ou.parent_id',$covered));
        $total=(clone $q)->count('ou.id'); $rows=$q->orderBy('ou.name')->forPage($page,$per)->get();
        return ['items'=>array_map(fn($r)=>$this->project($r),$rows->all()),'page'=>$page,'per_page'=>$per,'total'=>$total];
    }

    public function detail(int $user,int $session,string $public): array
    {
        [$actor,$row]=$this->visible($user,$session,$public,TerritorialCatalog::VIEW);
        return $this->project($row)+['path'=>$this->pathFor($actor,(int)$row->id)];
    }

    public function children(int $user,int $session,string $public,array $filters=[]): array
    {
        [$actor,$parent]=$this->visible($user,$session,$public,TerritorialCatalog::VIEW); $covered=array_keys($this->authority->coveredUnits($actor,TerritorialCatalog::VIEW));
        $page=max(1,(int)($filters['page']??1)); $per=min(50,max(1,(int)($filters['per_page']??25)));
        $q=$this->base()->where('ou.parent_id',(int)$parent->id)->whereIn('ou.id',$covered);
        $total=(clone $q)->count('ou.id'); $rows=$q->orderBy('ou.name')->forPage($page,$per)->get();
        return ['items'=>array_map(fn($r)=>$this->project($r),$rows->all()),'page'=>$page,'per_page'=>$per,'total'=>$total];
    }

    public function path(int $user,int $session,string $public): array
    {
        [$actor,$row]=$this->visible($user,$session,$public,TerritorialCatalog::VIEW);
        return $this->pathFor($actor,(int)$row->id);
    }

    public function create(int $user,int $session,array $input): array
    {
        return $this->db->transaction(function() use($user,$session,$input): array {
            $this->treeLock(); $actor=$this->authority->actor($user,$session,true);
            $parent=$this->byPublic((string)$input['parent_public_id'],true);
            if(!$parent) throw new TerritorialError(TerritorialReason::TARGET_NOT_FOUND);
            $this->authority->authorize($actor,TerritorialCatalog::MANAGE,(int)$parent->id,true);
            $type=$this->typeByCode((string)$input['type_code']);
            $this->assertParentPair((int)$parent->unit_type_id,(int)$type->id);
            $this->assertCenterPath((int)$parent->id,(string)$type->code,null);
            $now=$this->now();
            $id=(int)$this->db->table('organizational_units')->insertGetId([
                'public_id'=>(string)Str::ulid(),'parent_id'=>(int)$parent->id,'unit_type_id'=>(int)$type->id,
                'municipality_id'=>$parent->municipality_id,'code'=>(string)$input['code'],'name'=>(string)$input['name'],
                'status'=>'DRAFT','opened_on'=>$input['opened_on']??null,'closed_on'=>null,'created_at'=>$now,'lock_version'=>0,
            ]);
            $this->db->table('unit_parent_periods')->insert(['unit_id'=>$id,'parent_unit_id'=>(int)$parent->id,'status'=>'ACTIVE','starts_at'=>$now,'ends_at'=>null,'reason'=>$input['reason']??null,'source_document_id'=>null,'created_at'=>$now,'lock_version'=>0]);
            $this->authority->authorize($actor,TerritorialCatalog::MANAGE,(int)$parent->id,true);
            $this->audit->record($actor,(int)$parent->id,'TERRITORIAL_CREATE',$id,null,['parent'=>(int)$parent->id,'type'=>(string)$type->code,'status'=>'DRAFT'],$input['reason']??null);
            return $this->project($this->unitRow($id));
        },3);
    }

    public function update(int $user,int $session,string $public,array $input): array
    {
        return $this->db->transaction(function() use($user,$session,$public,$input): array {
            $actor=$this->authority->actor($user,$session,true); $row=$this->byPublic($public,true);
            if(!$row) throw new TerritorialError(TerritorialReason::TARGET_NOT_FOUND);
            $this->authority->authorize($actor,TerritorialCatalog::MANAGE,(int)$row->id,true);
            if((int)$row->lock_version!==(int)$input['lock_version']) throw new TerritorialError(TerritorialReason::STALE_WRITE);
            $before=['code'=>(string)$row->code,'status'=>(string)$row->status,'version'=>(int)$row->lock_version];
            $changed=$this->db->table('organizational_units')->where('id',$row->id)->where('lock_version',$row->lock_version)->update([
                'code'=>(string)$input['code'],'name'=>(string)$input['name'],'opened_on'=>$input['opened_on']??null,'lock_version'=>$row->lock_version+1,
            ]);
            if($changed!==1) throw new TerritorialError(TerritorialReason::STALE_WRITE);
            $this->authority->authorize($actor,TerritorialCatalog::MANAGE,(int)$row->id,true);
            $this->audit->record($actor,(int)$row->id,'TERRITORIAL_UPDATE',(int)$row->id,$before,['code'=>(string)$input['code'],'status'=>(string)$row->status,'version'=>$row->lock_version+1],$input['reason']??null);
            return $this->project($this->unitRow((int)$row->id));
        },3);
    }

    public function move(int $user,int $session,string $public,array $input): array
    {
        return $this->db->transaction(function() use($user,$session,$public,$input): array {
            $this->treeLock(); $actor=$this->authority->actor($user,$session,true);
            $unit=$this->byPublic($public,true); $parent=$this->byPublic((string)$input['new_parent_public_id'],true);
            if(!$unit||!$parent) throw new TerritorialError(TerritorialReason::TARGET_NOT_FOUND);
            if($unit->parent_id===null) throw new TerritorialError(TerritorialReason::ROOT_MOVE_FORBIDDEN);
            $this->authority->authorize($actor,TerritorialCatalog::MOVE,(int)$unit->id,true);
            $this->authority->authorize($actor,TerritorialCatalog::MOVE,(int)$parent->id,true);
            if((int)$unit->lock_version!==(int)$input['lock_version']) throw new TerritorialError(TerritorialReason::STALE_WRITE);
            $this->assertNoCycle((int)$unit->id,(int)$parent->id);
            $this->assertParentPair((int)$parent->unit_type_id,(int)$unit->unit_type_id);
            $this->assertCenterPath((int)$parent->id,(string)$unit->type_code,(int)$unit->id);
            $this->assertMunicipalMinimumAfterMove($unit,$parent);
            // Commit-time recheck while NATIONAL_TREE and both endpoints remain locked.
            $this->assertParentPair((int)$parent->unit_type_id,(int)$unit->unit_type_id);
            $this->assertNoCycle((int)$unit->id,(int)$parent->id);
            $this->authority->authorize($actor,TerritorialCatalog::MOVE,(int)$unit->id,true);
            $this->authority->authorize($actor,TerritorialCatalog::MOVE,(int)$parent->id,true);
            $now=$this->now();
            $this->db->table('unit_parent_periods')->where('unit_id',$unit->id)->whereNull('ends_at')->lockForUpdate()->update(['ends_at'=>$now,'status'=>'ENDED','lock_version'=>$this->db->raw('lock_version + 1')]);
            $this->db->table('unit_parent_periods')->insert(['unit_id'=>$unit->id,'parent_unit_id'=>$parent->id,'status'=>'ACTIVE','starts_at'=>$now,'ends_at'=>null,'reason'=>(string)$input['reason'],'source_document_id'=>null,'created_at'=>$now,'lock_version'=>0]);
            $changed=$this->db->table('organizational_units')->where('id',$unit->id)->where('lock_version',$unit->lock_version)->update(['parent_id'=>$parent->id,'municipality_id'=>$parent->municipality_id,'lock_version'=>$unit->lock_version+1]);
            if($changed!==1) throw new TerritorialError(TerritorialReason::STALE_WRITE);
            $descendants=$this->db->select('WITH RECURSIVE descendants AS (SELECT id,0 depth FROM organizational_units WHERE parent_id=? UNION ALL SELECT ou.id,d.depth+1 FROM organizational_units ou JOIN descendants d ON ou.parent_id=d.id WHERE d.depth<64) SELECT id FROM descendants',[$unit->id]);
            $descendantIds=array_map(fn($row)=>(int)$row->id,$descendants);
            foreach(array_chunk($descendantIds,500) as $chunk)$this->db->table('organizational_units')->whereIn('id',$chunk)->update(['municipality_id'=>$parent->municipality_id,'lock_version'=>$this->db->raw('lock_version + 1')]);
            $this->audit->record($actor,(int)$unit->id,'TERRITORIAL_MOVE',(int)$unit->id,['parent'=>(int)$unit->parent_id,'municipality'=>(int)($unit->municipality_id??0),'version'=>(int)$unit->lock_version],['old_parent'=>(int)$unit->parent_id,'new_parent'=>(int)$parent->id,'municipality'=>(int)($parent->municipality_id??0),'descendants_updated'=>count($descendantIds),'version'=>$unit->lock_version+1],(string)$input['reason']);
            return $this->project($this->unitRow((int)$unit->id))+['path'=>$this->pathFor($actor,(int)$unit->id)];
        },3);
    }

    public function lifecycle(int $user,int $session,string $public,array $input): array
    {
        return $this->db->transaction(function() use($user,$session,$public,$input): array {
            $this->treeLock(); $actor=$this->authority->actor($user,$session,true); $unit=$this->byPublic($public,true);
            if(!$unit) throw new TerritorialError(TerritorialReason::TARGET_NOT_FOUND);
            $this->authority->authorize($actor,TerritorialCatalog::LIFECYCLE,(int)$unit->id,true);
            if((int)$unit->lock_version!==(int)$input['lock_version']) throw new TerritorialError(TerritorialReason::STALE_WRITE);
            $to=(string)$input['status']; $from=(string)$unit->status;
            $allowed=($from==='DRAFT'&&in_array($to,['ACTIVE','CLOSED'],true))||($from==='ACTIVE'&&$to==='CLOSED');
            if(!$allowed) throw new TerritorialError(TerritorialReason::TRANSITION_NOT_ALLOWED);
            if($to==='ACTIVE'&&$unit->type_code==='MUNICIPAL_DIRECTION'&&$this->activeCenterCount((int)$unit->id)===0) throw new TerritorialError(TerritorialReason::MUNICIPAL_CENTER_REQUIRED);
            if($to==='CLOSED') {
                if($this->db->table('organizational_units')->where('parent_id',$unit->id)->where('status','ACTIVE')->exists()) throw new TerritorialError(TerritorialReason::ACTIVE_DEPENDENCIES);
                if($unit->type_code==='CENTER') $this->assertMunicipalMinimumAfterCenterRemoval((int)$unit->id);
            }
            $closed=$to==='CLOSED'?($input['closed_on']??date('Y-m-d')):null;
            $changed=$this->db->table('organizational_units')->where('id',$unit->id)->where('lock_version',$unit->lock_version)->update(['status'=>$to,'closed_on'=>$closed,'lock_version'=>$unit->lock_version+1]);
            if($changed!==1) throw new TerritorialError(TerritorialReason::STALE_WRITE);
            $this->authority->authorize($actor,TerritorialCatalog::LIFECYCLE,(int)$unit->id,true);
            $this->audit->record($actor,(int)$unit->id,'TERRITORIAL_LIFECYCLE',(int)$unit->id,['status'=>$from,'version'=>(int)$unit->lock_version],['status'=>$to,'version'=>$unit->lock_version+1],(string)$input['reason']);
            return $this->project($this->unitRow((int)$unit->id));
        },3);
    }

    private function visible(int $user,int $session,string $public,string $permission): array
    {
        $actor=$this->authority->actor($user,$session); $row=$this->byPublic($public);
        if(!$row) throw new TerritorialError(TerritorialReason::TARGET_NOT_FOUND);
        $this->authority->authorize($actor,$permission,(int)$row->id); return [$actor,$row];
    }
    private function base() { return $this->db->table('organizational_units as ou')->join('organizational_unit_types as ut','ut.id','=','ou.unit_type_id')->leftJoin('organizational_units as p','p.id','=','ou.parent_id')->select(['ou.id','ou.public_id','ou.parent_id','ou.unit_type_id','ou.municipality_id','ou.code','ou.name','ou.status','ou.opened_on','ou.closed_on','ou.lock_version','ut.code as type_code','ut.name as type_name','p.public_id as parent_public_id']); }
    private function unitRow(int $id): ?object { return $this->base()->where('ou.id',$id)->first(); }
    private function byPublic(string $public,bool $lock=false): ?object { $q=$this->base()->where('ou.public_id',$public); if($lock)$q->lockForUpdate(); return $q->first(); }
    private function typeByCode(string $code): object { $row=$this->db->table('organizational_unit_types')->where('code',$code)->where('is_active',1)->first(); if(!$row||!isset(TerritorialCatalog::TYPE_LABELS[$code]))throw new TerritorialError(TerritorialReason::INVALID_INPUT,['field'=>'type_code']); return $row; }
    private function project(object $r): array { return ['public_id'=>(string)$r->public_id,'parent_public_id'=>$r->parent_public_id===null?null:(string)$r->parent_public_id,'code'=>(string)$r->code,'name'=>(string)$r->name,'type'=>(string)$r->type_code,'type_label'=>TerritorialCatalog::TYPE_LABELS[(string)$r->type_code]??(string)$r->type_name,'status'=>(string)$r->status,'opened_on'=>$r->opened_on,'closed_on'=>$r->closed_on,'lock_version'=>(int)$r->lock_version]; }
    private function now(): string { return (string)$this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n; }
    private function treeLock(): void { $row=$this->db->table('organizational_structure_lock')->where('code','NATIONAL_TREE')->lockForUpdate()->first(); if(!$row)throw new TerritorialError(TerritorialReason::INVARIANT_VIOLATION,['reason'=>'tree_lock_missing']); }
    private function assertParentPair(int $parentType,int $childType): void { if(!$this->db->table('unit_parent_rules')->where('parent_type_id',$parentType)->where('child_type_id',$childType)->exists())throw new TerritorialError(TerritorialReason::INVALID_PARENT_TYPE); }
    private function assertNoCycle(int $unit,int $parent): void { if($unit===$parent)throw new TerritorialError(TerritorialReason::CYCLE_DETECTED); $rows=$this->db->select('WITH RECURSIVE descendants AS (SELECT id,0 depth FROM organizational_units WHERE parent_id=? UNION ALL SELECT ou.id,d.depth+1 FROM organizational_units ou JOIN descendants d ON ou.parent_id=d.id WHERE d.depth<64) SELECT id FROM descendants WHERE id=? LIMIT 1',[$unit,$parent]); if($rows!==[])throw new TerritorialError(TerritorialReason::CYCLE_DETECTED); }
    private function assertCenterPath(int $parent,string $childType,?int $moving): void { $p=$this->unitRow($parent); if(!$p)return; if($childType==='CENTER'&&$p->type_code==='MUNICIPAL_DIRECTION'){ $q=$this->base()->where('ou.parent_id',$parent)->where('ut.code','GENERAL_CENTER')->where('ou.status','!=','CLOSED'); if($moving)$q->where('ou.id','!=',$moving); if($q->exists())throw new TerritorialError(TerritorialReason::GENERAL_CENTER_EXISTS); } if($childType==='GENERAL_CENTER'){ $q=$this->base()->where('ou.parent_id',$parent)->where('ut.code','GENERAL_CENTER')->where('ou.status','!=','CLOSED'); if($moving)$q->where('ou.id','!=',$moving); if($q->exists())throw new TerritorialError(TerritorialReason::GENERAL_CENTER_EXISTS); $direct=$this->base()->where('ou.parent_id',$parent)->where('ut.code','CENTER')->where('ou.status','!=','CLOSED'); if($direct->exists())throw new TerritorialError(TerritorialReason::INVALID_PARENT_TYPE,['reason'=>'direct_centers_exist']); } }
    private function pathFor(TerritorialActor $actor,int $unit): array { $covered=$this->authority->coveredUnits($actor,TerritorialCatalog::VIEW); $rows=$this->db->select('WITH RECURSIVE path AS (SELECT id,parent_id,0 depth FROM organizational_units WHERE id=? UNION ALL SELECT p.id,p.parent_id,path.depth+1 FROM organizational_units p JOIN path ON path.parent_id=p.id WHERE path.depth<64) SELECT id,depth FROM path ORDER BY depth DESC',[$unit]); $out=[]; foreach($rows as $r){ if(isset($covered[(int)$r->id])){$row=$this->unitRow((int)$r->id); if($row)$out[]=$this->project($row);} } return $out; }
    private function activeCenterCount(int $municipal,?int $exclude=null): int { $sql="SELECT COUNT(*) c FROM organizational_units c JOIN organizational_unit_types ct ON ct.id=c.unit_type_id LEFT JOIN organizational_units gc ON gc.id=c.parent_id LEFT JOIN organizational_unit_types gt ON gt.id=gc.unit_type_id WHERE ct.code='CENTER' AND c.status='ACTIVE' AND ((c.parent_id=?) OR (gt.code='GENERAL_CENTER' AND gc.parent_id=? AND gc.status!='CLOSED'))"; $bindings=[$municipal,$municipal]; if($exclude!==null){$sql.=' AND c.id != ?';$bindings[]=$exclude;} return (int)$this->db->selectOne($sql,$bindings)->c; }
    private function municipalAncestor(int $unit): ?int { $row=$this->db->selectOne("WITH RECURSIVE a AS (SELECT ou.id,ou.parent_id,ut.code,0 depth FROM organizational_units ou JOIN organizational_unit_types ut ON ut.id=ou.unit_type_id WHERE ou.id=? UNION ALL SELECT p.id,p.parent_id,pt.code,a.depth+1 FROM organizational_units p JOIN organizational_unit_types pt ON pt.id=p.unit_type_id JOIN a ON a.parent_id=p.id WHERE a.depth<64) SELECT id FROM a WHERE code='MUNICIPAL_DIRECTION' LIMIT 1",[$unit]); return $row?(int)$row->id:null; }
    private function assertMunicipalMinimumAfterCenterRemoval(int $center): void { $m=$this->municipalAncestor($center); if($m!==null&&$this->unitRow($m)?->status==='ACTIVE'&&$this->activeCenterCount($m,$center)===0)throw new TerritorialError(TerritorialReason::MUNICIPAL_CENTER_REQUIRED); }
    private function assertMunicipalMinimumAfterMove(object $unit,object $newParent): void { if(!in_array((string)$unit->type_code,['CENTER','GENERAL_CENTER'],true)||(int)$unit->parent_id===(int)$newParent->id)return; $m=$this->municipalAncestor((int)$unit->id); if($m!==null&&$this->unitRow($m)?->status==='ACTIVE'){ if($unit->type_code==='CENTER'&&$unit->status==='ACTIVE'&&$this->activeCenterCount($m,(int)$unit->id)===0)throw new TerritorialError(TerritorialReason::MUNICIPAL_CENTER_REQUIRED); if($unit->type_code==='GENERAL_CENTER'){ $inside=(int)$this->base()->where('ou.parent_id',$unit->id)->where('ut.code','CENTER')->where('ou.status','ACTIVE')->count('ou.id'); if($this->activeCenterCount($m)-$inside===0)throw new TerritorialError(TerritorialReason::MUNICIPAL_CENTER_REQUIRED); } } }
}
