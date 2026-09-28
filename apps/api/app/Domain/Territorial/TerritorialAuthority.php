<?php

declare(strict_types=1);

namespace App\Domain\Territorial;

use Illuminate\Database\Connection;

final class TerritorialAuthority
{
    public function __construct(private Connection $db, private array $activeUsers, private array $activeGrants)
    {
    }

    public function actor(int $user, int $session, bool $lock = false): TerritorialActor
    {
        $uq = $this->db->table('users')->where('id', $user);
        $sq = $this->db->table('auth_sessions')->where('id', $session);
        if ($lock) { $uq->sharedLock(); $sq->sharedLock(); }
        $u = $uq->first(); $s = $sq->first();
        $now = $this->now();
        if (!$u || $u->archived_at !== null || !in_array((string) $u->status, $this->activeUsers, true)
            || !$s || (int) $s->user_id !== $user || $s->revoked_at !== null || (string) $s->expires_at <= $now) {
            throw new TerritorialError(TerritorialReason::NOT_AUTHORIZED);
        }
        return new TerritorialActor($user, $session);
    }

    /** @return array<int,int> unit id => include descendants */
    public function scopeRoots(TerritorialActor $actor, string $permission, bool $lock = false): array
    {
        if ($this->activeGrants === []) return [];
        $q = $this->db->table('user_role_scopes as urs')
            ->join('roles as r', 'r.id', '=', 'urs.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')->join('scopes as s', 's.id', '=', 'urs.scope_id')
            ->where('urs.user_id', $actor->user)->where('r.is_active', 1)->where('p.code', $permission)
            ->where('p.data_type', TerritorialCatalog::DATA_TYPE)->whereColumn('p.action', 'p.code')
            ->where('s.scope_kind', 'UNIT')->whereNull('s.department_instance_id')->whereNotNull('s.unit_id');
        if ($lock) $q->sharedLock();
        $now = $this->now(); $roots = [];
        foreach ($q->get(['urs.status','urs.starts_at','urs.ends_at','s.unit_id','s.include_descendants']) as $g) {
            if (in_array((string) $g->status, $this->activeGrants, true) && (string) $g->starts_at <= $now
                && ($g->ends_at === null || (string) $g->ends_at > $now)) {
                $roots[(int) $g->unit_id] = max($roots[(int) $g->unit_id] ?? 0, (int) $g->include_descendants);
            }
        }
        return $roots;
    }

    /** @return array<int,true> */
    public function coveredUnits(TerritorialActor $actor, string $permission, bool $lock = false): array
    {
        $roots = $this->scopeRoots($actor, $permission, $lock);
        if ($roots === []) return [];
        $seeds=[]; $bindings=[];
        foreach ($roots as $id=>$expand) { $seeds[]='SELECT ? AS id, ? AS expand, 0 AS depth'; $bindings[]=$id; $bindings[]=$expand; }
        $sql='WITH RECURSIVE covered AS ('.implode(' UNION ALL ',$seeds)
            .' UNION ALL SELECT ou.id,covered.expand,covered.depth+1 FROM organizational_units ou JOIN covered ON ou.parent_id=covered.id WHERE covered.expand=1 AND covered.depth<64) SELECT DISTINCT id FROM covered';
        $out=[]; foreach ($this->db->select($sql,$bindings) as $row) $out[(int)$row->id]=true;
        return $out;
    }

    public function authorize(TerritorialActor $actor, string $permission, int $unit, bool $lock = false): void
    {
        if ($this->scopeRoots($actor, $permission, $lock) === []) throw new TerritorialError(TerritorialReason::NOT_AUTHORIZED);
        if (!isset($this->coveredUnits($actor, $permission, $lock)[$unit])) throw new TerritorialError(TerritorialReason::OUT_OF_SCOPE);
    }

    public function effectivePermissions(TerritorialActor $actor): array
    {
        return array_values(array_filter(TerritorialCatalog::PERMISSIONS, fn(string $p): bool => $this->scopeRoots($actor,$p)!==[]));
    }

    private function now(): string { return (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n; }
}
