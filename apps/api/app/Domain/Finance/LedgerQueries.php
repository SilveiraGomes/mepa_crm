<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Database\Connection;

/**
 * Canonical reads over POSTED journal lines only (ADR 0021 D06, D15, D-04A). No stored balance exists anywhere: every
 * figure here is a SUM of immutable lines. These are the foundation reads the F1B/F1D read models (DRE, DOAF,
 * consolidation, reports) must agree with; they are not those reports.
 */
final class LedgerQueries
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** D06: balance of a financial account (CASH/BANK) = SUM(debit) - SUM(credit) of POSTED lines up to $asOf. */
    public function financialAccountBalance(int $financialAccountId, ?string $asOf = null): int
    {
        $query = $this->posted()->where('l.financial_account_id', $financialAccountId);
        if ($asOf !== null) {
            $query->where('e.entry_date', '<=', $asOf);
        }
        return Money::fromDecimal((string) ($query->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) AS net')->value('net') ?? '0'));
    }

    /**
     * Economic result of one unit (own view) for [$from, $to]: income = credits - debits on INCOME accounts, expense =
     * debits - credits on EXPENSE accounts (investment consumed included and also reported apart). Balance-sheet,
     * FIXED_ASSETS and INTERUNIT_CONTROL lines never contribute.
     *
     * @return array{income: int, expense: int, investment_consumed: int, result: int}
     */
    public function economicResult(int $unitId, string $from, string $to): array
    {
        $rows = $this->posted()->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.unit_id', $unitId)
            ->whereBetween('e.entry_date', [$from, $to])->whereIn('a.account_kind', [FinanceCatalog::INCOME, FinanceCatalog::EXPENSE])
            ->groupBy('a.account_kind', 'a.system_role')->selectRaw('a.account_kind AS class, a.system_role AS role, SUM(l.debit) AS d, SUM(l.credit) AS c')->get();
        $income = 0;
        $expense = 0;
        $investment = 0;
        foreach ($rows as $row) {
            $net = Money::fromDecimal((string) $row->d) - Money::fromDecimal((string) $row->c);
            if ($row->class === FinanceCatalog::INCOME) {
                $income -= $net;
            } else {
                $expense += $net;
                if ($row->role === 'INVESTMENT_EXPENSE') {
                    $investment += $net;
                }
            }
        }
        return ['income' => $income, 'expense' => $expense, 'investment_consumed' => $investment, 'result' => $income - $expense];
    }

    /** Net debit (debit - credit) per system_role for one unit and interval (POSTED only). @return array<string, int> */
    public function roleTotals(int $unitId, string $from, string $to): array
    {
        $out = [];
        foreach ($this->posted()->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.unit_id', $unitId)->whereBetween('e.entry_date', [$from, $to])
            ->groupBy('a.system_role')->selectRaw('a.system_role AS role, SUM(l.debit) AS d, SUM(l.credit) AS c')->get() as $row) {
            $out[(string) $row->role] = Money::fromDecimal((string) $row->d) - Money::fromDecimal((string) $row->c);
        }
        return $out;
    }

    /**
     * D-04A.4 interunit position of a unit up to $asOf: funds sent (net of REVERSE_SEND) and received, by the control
     * accounts. @return array{sent: int, received: int, net: int} (net = received - sent)
     */
    public function interunitPosition(int $unitId, string $asOf): array
    {
        $rows = $this->posted()->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.unit_id', $unitId)->where('e.entry_date', '<=', $asOf)
            ->whereIn('a.system_role', ['INTERUNIT_CLEARING_OUT', 'INTERUNIT_CLEARING_IN'])->groupBy('a.system_role')
            ->selectRaw('a.system_role AS role, SUM(l.debit) AS d, SUM(l.credit) AS c')->get()->keyBy('role');
        $sent = isset($rows['INTERUNIT_CLEARING_OUT']) ? Money::fromDecimal((string) $rows['INTERUNIT_CLEARING_OUT']->d) - Money::fromDecimal((string) $rows['INTERUNIT_CLEARING_OUT']->c) : 0;
        $received = isset($rows['INTERUNIT_CLEARING_IN']) ? Money::fromDecimal((string) $rows['INTERUNIT_CLEARING_IN']->c) - Money::fromDecimal((string) $rows['INTERUNIT_CLEARING_IN']->d) : 0;
        return ['sent' => $sent, 'received' => $received, 'net' => $received - $sent];
    }

    /**
     * D-04A.7 funds in transit at $asOf: transfers whose SEND is POSTED on or before $asOf and that have no RECEIVE or
     * REVERSE_SEND posted on or before $asOf. Optionally restricted to transfers sent by $originUnits.
     *
     * @param list<int>|null $originUnits
     * @return array{amount: int, transfers: list<string>}
     */
    public function inTransit(string $asOf, ?array $originUnits = null): array
    {
        $query = $this->db->table('internal_transfers as t')
            ->join('transfer_postings as s', fn ($j) => $j->on('s.transfer_id', '=', 't.id')->where('s.posting_stage', '=', 'SEND'))
            ->join('journal_entries as se', 'se.id', '=', 's.entry_id')->where('se.status', FinanceCatalog::POSTED)->where('se.entry_date', '<=', $asOf)
            ->whereNotExists(fn ($q) => $q->from('transfer_postings as r')->join('journal_entries as re', 're.id', '=', 'r.entry_id')->whereColumn('r.transfer_id', 't.id')
                ->whereIn('r.posting_stage', ['RECEIVE', 'REVERSE_SEND'])->where('re.status', FinanceCatalog::POSTED)->where('re.entry_date', '<=', $asOf));
        if ($originUnits !== null) {
            $query->whereIn('t.origin_unit_id', $originUnits);
        }
        $amount = 0;
        $ids = [];
        foreach ($query->orderBy('t.id')->get(['t.public_id', 't.amount']) as $row) {
            $amount += Money::fromDecimal((string) $row->amount);
            $ids[] = (string) $row->public_id;
        }
        return ['amount' => $amount, 'transfers' => $ids];
    }

    private function posted(): \Illuminate\Database\Query\Builder
    {
        return $this->db->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->where('e.status', FinanceCatalog::POSTED);
    }
}
