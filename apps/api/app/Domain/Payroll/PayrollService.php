<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinanceGuard;
use App\Domain\Finance\FinanceRuntime;
use App\Domain\Territorial\TerritorialActor;
use DateTimeImmutable;

/**
 * Shared base of the RH / payroll application services (ADR 0021 D23-D28, D31). The runtime is the Finance runtime
 * (one transactional boundary, commit-time recheck, Files authority) wired to TerritorialAuthority with data_type HR:
 * one scope engine, no second authority model.
 *
 * F-06 order everywhere: the permission is decided BEFORE any target is resolved (403 only when it is held nowhere);
 * then unknown, malformed and out-of-scope targets are the same concealed TARGET_NOT_FOUND. Only public ids are accepted.
 */
abstract class PayrollService
{
    public function __construct(protected FinanceRuntime $rt)
    {
    }

    /** At least one of $permissions held somewhere, else NOT_AUTHORIZED (403). */
    protected function requiresAny(TerritorialActor $actor, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if ($this->rt->authority->holdsAnywhere($actor, $permission)) {
                return;
            }
        }
        throw new FinanceError('NOT_AUTHORIZED', [], ['permission' => implode('|', $permissions)]);
    }

    protected function unitByPublicId(mixed $publicId): object
    {
        $row = is_string($publicId) && preg_match(PayrollCatalog::PUBLIC_ID_PATTERN, $publicId) === 1
            ? $this->rt->db->table('organizational_units')->where('public_id', $publicId)->first(['id', 'public_id', 'name', 'status']) : null;
        if (!$row) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'organizational_units']);
        }
        return $row;
    }

    protected function employmentByPublicId(mixed $publicId, bool $forUpdate = false): object
    {
        $q = is_string($publicId) && preg_match(PayrollCatalog::PUBLIC_ID_PATTERN, $publicId) === 1 ? $this->rt->db->table('employments')->where('public_id', $publicId) : null;
        if ($q !== null && $forUpdate) {
            $q->lockForUpdate();
        }
        $row = $q?->first();
        if (!$row) {
            throw new FinanceError('TARGET_NOT_FOUND', [], ['entity' => 'employments']);
        }
        return $row;
    }

    /**
     * D25: rules are national. The actor must hold $permission on a root GENERAL_DIRECTION unit (grants over the
     * Direcção Geral); that unit is the authority / audit unit and the owner of the rule's source document.
     */
    protected function nationalUnit(FinanceGuard $guard, TerritorialActor $actor, string $permission): int
    {
        $guard->requires($permission);
        $covered = array_keys($this->rt->authority->covered($actor, $permission));
        $unit = $covered === [] ? null : $this->rt->db->table('organizational_units as u')->join('organizational_unit_types as t', 't.id', '=', 'u.unit_type_id')
            ->whereIn('u.id', $covered)->where('t.code', 'GENERAL_DIRECTION')->whereNull('u.parent_id')->where('u.status', 'ACTIVE')->orderBy('u.id')->value('u.id');
        if ($unit === null) {
            throw new FinanceError('NOT_AUTHORIZED', [], ['permission' => $permission, 'reason' => 'national_scope_required']);
        }
        $guard->unit($permission, (int) $unit);
        return (int) $unit;
    }

    protected function component(mixed $code, bool $forUpdate = false): object
    {
        $q = is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $code) === 1 ? $this->rt->db->table('compensation_component_types')->where('code', $code) : null;
        if ($q !== null && $forUpdate) {
            $q->lockForUpdate();
        }
        $row = $q?->first();
        if (!$row) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'component']);
        }
        return $row;
    }

    protected function date(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1 || DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') !== $value) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => $field]);
        }
        return $value;
    }

    /** @return array{0: string, 1: string, 2: string} [code, from, to] of a service month YYYY-MM */
    protected function month(mixed $value): array
    {
        if (!is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value) !== 1) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'period']);
        }
        $from = $value . '-01';
        return [$value, $from, (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d')];
    }

    protected static function dayBefore(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    }

    protected function text(mixed $value, string $field, int $max = 2000, bool $required = true): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                throw new FinanceError('REASON_REQUIRED', [], ['field' => $field]);
            }
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > $max) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => $field]);
        }
        return trim($value);
    }

    /** Minimal Person projection for HR (identification only; the People profile is never duplicated). */
    protected function personProjection(int $personId): array
    {
        $p = $this->rt->db->table('people')->where('id', $personId)->first(['public_id', 'full_name']);
        return ['public_id' => (string) $p->public_id, 'name' => (string) $p->full_name];
    }

    protected function unitProjection(int $unitId): array
    {
        $u = $this->rt->db->table('organizational_units')->where('id', $unitId)->first(['public_id', 'name']);
        return ['public_id' => (string) $u->public_id, 'name' => (string) $u->name];
    }

    /** Provenance projection of an internal user: the person's name only (never a users.id). */
    protected function actorName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }
        $name = $this->rt->db->table('users as u')->leftJoin('people as p', 'p.id', '=', 'u.person_id')->where('u.id', $userId)->value('p.full_name');
        return $name === null ? 'Utilizador interno' : (string) $name;
    }
}
