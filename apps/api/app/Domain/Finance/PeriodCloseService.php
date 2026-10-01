<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Territorial\TerritorialActor;

/**
 * Accounting period closes (ADR 0021 D12, D17, D30 C3; F1C application layer over FinancePeriods).
 *
 *   close unit      FINANCE_PERIOD_CLOSE on the unit. Closing unit A never closes unit B (one row per (period, unit)).
 *                   Precondition (D12): no DRAFT / SUBMITTED entry of the unit in the month. closed_by / closed_at.
 *   reopen unit     FINANCE_PERIOD_REOPEN on the unit, mandatory reason, reopener != closer (service + CHECK), only
 *                   while the month is OPEN nationally. The close row keeps its history (closed_at/by stay; REOPENED).
 *   national close  FINANCE_PERIOD_CLOSE held through a scope that covers the NATIONAL ROOT (the single ACTIVE
 *                   GENERAL_DIRECTION without parent): a grant on any lower level -- however high -- is not enough.
 *                   Requires every unit with POSTED lines in the month closed; IRREVERSIBLE (no reopen exists).
 * Lock order: the month FOR UPDATE (postings hold it FOR SHARE), then the close row FOR UPDATE; decisions on locking
 * reads. A posting either commits before the close (and is counted) or is refused PERIOD_CLOSED (C3 / PC1).
 */
final class PeriodCloseService extends FinanceOperation
{
    public function closeUnit(int $user, int $session, string $code, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($code, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_PERIOD_CLOSE);
            $unit = (int) $this->byPublicId('organizational_units', $in['unit'] ?? null)->id;
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_PERIOD_CLOSE], $unit);
            $guard->unit(FinanceCatalog::PERMISSION_PERIOD_CLOSE, $unit);
            (new FinancePeriods($this->rt->db))->closeUnit($actor->user, $this->code($code), $unit);
            return ['replayed' => false];
        });
    }

    public function reopenUnit(int $user, int $session, string $code, array $in): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($code, $in): array {
            $guard->requires(FinanceCatalog::PERMISSION_PERIOD_REOPEN);
            $unit = (int) $this->byPublicId('organizational_units', $in['unit'] ?? null)->id;
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_PERIOD_REOPEN], $unit);
            $guard->unit(FinanceCatalog::PERMISSION_PERIOD_REOPEN, $unit);
            (new FinancePeriods($this->rt->db))->reopenUnit($actor->user, $this->code($code), $unit, $this->reason($in['reason'] ?? null));
            return ['replayed' => false];
        });
    }

    public function closeNational(int $user, int $session, string $code): array
    {
        return $this->rt->write($user, $session, function (FinanceGuard $guard, TerritorialActor $actor) use ($code): array {
            $guard->requires(FinanceCatalog::PERMISSION_PERIOD_CLOSE);
            $root = self::nationalRoot($this->rt->db);
            // D12/D17: only a grant covering the national root decides a national close (never a lower level).
            $this->preauthorize($actor, [FinanceCatalog::PERMISSION_PERIOD_CLOSE], $root);
            $guard->unit(FinanceCatalog::PERMISSION_PERIOD_CLOSE, $root);
            (new FinancePeriods($this->rt->db))->closeNational($actor->user, $this->code($code), $root);
            return ['replayed' => false];
        });
    }

    /** The single ACTIVE GENERAL_DIRECTION unit without parent; anything else is a configuration error (fail closed). */
    public static function nationalRoot(\Illuminate\Database\Connection $db): int
    {
        $roots = $db->table('organizational_units as u')->join('organizational_unit_types as t', 't.id', '=', 'u.unit_type_id')->whereNull('u.parent_id')
            ->where('t.code', FinanceCatalog::NATIONAL_ROOT_TYPE)->where('u.status', 'ACTIVE')->limit(2)->pluck('u.id')->all();
        if (count($roots) !== 1) {
            throw new FinanceError('CONFIG_MISSING', [], ['reason' => 'national_root', 'roots' => count($roots)]);
        }
        return (int) $roots[0];
    }

    private function code(string $code): string
    {
        if (preg_match('/^\d{4}-\d{2}$/D', $code) !== 1) {
            throw new FinanceError('INVALID_INPUT', [], ['field' => 'period']);
        }
        return $code;
    }
}
