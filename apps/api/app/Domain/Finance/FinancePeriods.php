<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * ADR 0021 D12: calendar year, month = posting period. Two close levels:
 *  - unit close (accounting_period_unit_closes): each unit closes its own month independently; reopen only while the
 *    national month is OPEN, with a reason, by a user different from the closer (also a physical CHECK);
 *  - national close (accounting_periods.status): requires every unit with POSTED lines in the month closed; IRREVERSIBLE
 *    in V1 (this class deliberately has no national reopen).
 * Every operation here locks the national period row FOR UPDATE; postings hold it FOR SHARE (LedgerPostingService), so a
 * close and a posting of the same month are serialised: a posting either commits before the close (and is counted) or is
 * refused PERIOD_CLOSED. Authorization (TerritorialAuthority FINANCE: FINANCE_PERIOD_CLOSE / FINANCE_PERIOD_REOPEN) is the
 * application layer's job (F1B) and must run before these domain calls.
 */
final class FinancePeriods
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Idempotent: 1 YEAR + 12 MONTH periods for $year; inserts missing codes only. @return list<string> inserted codes */
    public function ensureYear(int $year): array
    {
        if ($year < 2000 || $year > 2999) {
            throw new FinanceError('PERIOD_YEAR_INVALID');
        }
        return $this->db->transaction(function () use ($year): array {
            $now = $this->now();
            $wanted = [(string) $year => [FinanceCatalog::PERIOD_YEAR, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)]];
            for ($month = 1; $month <= 12; $month++) {
                $start = sprintf('%04d-%02d-01', $year, $month);
                $wanted[sprintf('%04d-%02d', $year, $month)] = [FinanceCatalog::PERIOD_MONTH, $start, date('Y-m-t', strtotime($start))];
            }
            $inserted = [];
            foreach ($wanted as $code => [$kind, $start, $end]) {
                $row = $this->db->table('accounting_periods')->where('code', $code)->first();
                if ($row !== null) {
                    if ($row->period_kind !== $kind || $row->starts_on !== $start || $row->ends_on !== $end) {
                        throw new RuntimeException('FINANCE_PERIOD_CONFLICT: ' . $code . '; nothing repaired.');
                    }
                    continue;
                }
                $this->db->table('accounting_periods')->insert(['code' => $code, 'period_kind' => $kind, 'starts_on' => $start, 'ends_on' => $end,
                    'status' => FinanceCatalog::PERIOD_OPEN, 'closed_at' => null, 'closed_by' => null, 'created_at' => $now, 'lock_version' => 0]);
                $inserted[] = $code;
            }
            return $inserted;
        });
    }

    public function closeUnit(int $actor, string $code, int $unitId): void
    {
        $this->db->transaction(function () use ($actor, $code, $unitId): void {
            $period = $this->lockMonth($code);
            $close = $this->db->table('accounting_period_unit_closes')->where('period_id', $period->id)->where('unit_id', $unitId)->lockForUpdate()->first();
            if ($close !== null && $close->status === FinanceCatalog::UNIT_CLOSED) {
                throw new FinanceError('PERIOD_ALREADY_CLOSED');
            }
            // Decision reads after the period lock wait are LOCKING reads (F1B-P01): a competitor's commit is never missed.
            $pending = $this->db->table('journal_entries')->where('period_id', $period->id)->where('unit_id', $unitId)
                ->whereIn('status', [FinanceCatalog::DRAFT, FinanceCatalog::SUBMITTED])->orderBy('id')->sharedLock()->pluck('public_id')->all();
            if ($pending !== []) {
                throw new FinanceError('PERIOD_HAS_PENDING_ENTRIES', $pending);
            }
            $now = $this->now();
            if ($close === null) {
                $id = (int) $this->db->table('accounting_period_unit_closes')->insertGetId(['period_id' => $period->id, 'unit_id' => $unitId, 'status' => FinanceCatalog::UNIT_CLOSED,
                    'closed_at' => $now, 'closed_by' => $actor, 'reopened_at' => null, 'reopened_by' => null, 'reason' => null, 'created_at' => $now, 'lock_version' => 0]);
            } else {
                $id = (int) $close->id;
                $this->db->table('accounting_period_unit_closes')->where('id', $id)->update(['status' => FinanceCatalog::UNIT_CLOSED, 'closed_at' => $now, 'closed_by' => $actor, 'lock_version' => $close->lock_version + 1]);
            }
            FinanceAudit::write($this->db, $actor, 'finance.period_unit_closed', 'accounting_period_unit_closes', $id, $unitId, FinanceAudit::correlation(), ['period' => $code]);
        });
    }

    public function reopenUnit(int $actor, string $code, int $unitId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new FinanceError('REASON_REQUIRED');
        }
        $this->db->transaction(function () use ($actor, $code, $unitId, $reason): void {
            $period = $this->lockMonth($code);
            $close = $this->db->table('accounting_period_unit_closes')->where('period_id', $period->id)->where('unit_id', $unitId)->lockForUpdate()->first();
            if ($close === null || $close->status !== FinanceCatalog::UNIT_CLOSED) {
                throw new FinanceError('PERIOD_NOT_CLOSED');
            }
            if ((int) $close->closed_by === $actor) {
                throw new FinanceError('SEGREGATION_REQUIRED');
            }
            $this->db->table('accounting_period_unit_closes')->where('id', $close->id)->update(['status' => FinanceCatalog::UNIT_REOPENED, 'reopened_at' => $this->now(),
                'reopened_by' => $actor, 'reason' => $reason, 'lock_version' => $close->lock_version + 1]);
            FinanceAudit::write($this->db, $actor, 'finance.period_unit_reopened', 'accounting_period_unit_closes', (int) $close->id, $unitId, FinanceAudit::correlation(), ['period' => $code], $reason);
        });
    }

    /** National close of a month: irreversible in V1. The refusal lists the units still open (public ids). */
    public function closeNational(int $actor, string $code, int $rootUnitId): void
    {
        $this->db->transaction(function () use ($actor, $code, $rootUnitId): void {
            $period = $this->lockMonth($code);
            $pending = $this->db->table('journal_entries')->where('period_id', $period->id)->whereIn('status', [FinanceCatalog::DRAFT, FinanceCatalog::SUBMITTED])->orderBy('id')->sharedLock()->pluck('public_id')->all();
            if ($pending !== []) {
                throw new FinanceError('PERIOD_HAS_PENDING_ENTRIES', $pending);
            }
            $open = $this->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->join('organizational_units as u', 'u.id', '=', 'l.unit_id')
                ->where('e.period_id', $period->id)->where('e.status', FinanceCatalog::POSTED)
                ->whereNotExists(fn ($q) => $q->from('accounting_period_unit_closes as c')->whereColumn('c.unit_id', 'l.unit_id')->where('c.period_id', $period->id)->where('c.status', FinanceCatalog::UNIT_CLOSED))
                ->distinct()->orderBy('u.public_id')->sharedLock()->pluck('u.public_id')->all();
            if ($open !== []) {
                throw new FinanceError('UNITS_NOT_CLOSED', $open);
            }
            $this->db->table('accounting_periods')->where('id', $period->id)->update(['status' => FinanceCatalog::PERIOD_CLOSED, 'closed_at' => $this->now(), 'closed_by' => $actor, 'lock_version' => $period->lock_version + 1]);
            FinanceAudit::write($this->db, $actor, 'finance.period_national_closed', 'accounting_periods', (int) $period->id, $rootUnitId, FinanceAudit::correlation(), ['period' => $code]);
        });
    }

    private function lockMonth(string $code): object
    {
        $period = $this->db->table('accounting_periods')->where('code', $code)->lockForUpdate()->first();
        if ($period === null) {
            throw new FinanceError('PERIOD_NOT_FOUND');
        }
        if ($period->period_kind !== FinanceCatalog::PERIOD_MONTH) {
            throw new FinanceError('PERIOD_NOT_POSTABLE');
        }
        if ($period->status !== FinanceCatalog::PERIOD_OPEN) {
            throw new FinanceError('PERIOD_CLOSED');
        }
        return $period;
    }

    private function now(): string
    {
        return (string) $this->db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
    }
}
