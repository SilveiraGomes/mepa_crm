<?php

declare(strict_types=1);

namespace Tests\DatabaseV2;

use App\Domain\Finance\FinanceCatalog;
use App\Domain\Finance\FinanceError;
use App\Domain\Finance\FinancePeriods;
use App\Domain\Finance\LedgerPostingService;
use App\Domain\Finance\LedgerQueries;
use App\Domain\Finance\Money;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\DatabaseV2\Support\PooledWaveFiveCase;

/**
 * P0.10-F1A Finance Core foundation (ADR 0021 + D-04A) against an isolated MySQL 8.4 Wave 5 pool (never SQLite, never the
 * development database). S01-S07 schema/catalog, J01-J16 accounting contracts, C1/C3 real-process concurrency. Every test
 * builds its own units, users and accounts, so any test can run alone (the mutation probes use --filter). Month 2026-01 is
 * reserved to the national-close test; every other test posts in 2026-02..2026-09.
 */
final class FinanceCoreFoundationTest extends PooledWaveFiveCase
{
    private const P010_TABLES = ['currencies', 'funds', 'chart_of_accounts', 'financial_categories', 'accounting_periods', 'accounts', 'bank_account_details',
        'cash_registers', 'financial_parties', 'journal_entries', 'journal_lines', 'financial_documents', 'accounting_period_unit_closes', 'internal_transfers',
        'transfer_postings', 'receivables', 'payables', 'settlements', 'settlement_allocations', 'budgets', 'budget_lines'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $db = self::$capsule->getConnection();
        FinanceCatalog::install($db);
        (new FinancePeriods($db))->ensureYear(2026);
    }

    // ---- S: schema, catalog, constraints ----------------------------------------------------------------------------

    public function test_s01_fresh_migration_materializes_exactly_the_f1a_tables_and_rollback_is_safe(): void
    {
        $manifest = $this->manifest();
        $this->assertSame(self::P010_TABLES, $manifest['materialized_tables_f1a']);
        foreach (self::P010_TABLES as $table) {
            $this->assertTrue($this->db()->getSchemaBuilder()->hasTable($table), $table);
        }
        foreach (['contributions', 'bank_statements', 'bank_statement_lines', 'reconciliations', 'reconciliation_matches', 'obligation_rules', 'obligations',
            'contribution_allocations', ...$manifest['plan']['payroll_tables_reserved_f2']] as $absent) {
            $this->assertFalse($this->db()->getSchemaBuilder()->hasTable($absent), $absent . ' must not exist in F1A');
        }
        $this->assertSame(35, $manifest['planned_table_count']);
        $this->assertSame(35, 28 - count($manifest['plan']['catalog_tables_not_created_v1']) + count($manifest['plan']['new_finance_tables']) + count($manifest['plan']['payroll_tables_reserved_f2']));
        $this->assertSame(35, count(self::P010_TABLES) + count($manifest['deferred_finance_tables']['F1B']) + count($manifest['deferred_finance_tables']['F1C']) + count($manifest['plan']['payroll_tables_reserved_f2']));
        $ran = $this->db()->table('migrations')->where('migration', 'like', '2026_09_30_1000%_p010_%')->orderBy('migration')->pluck('migration')->all();
        $this->assertSame(array_map(fn ($f) => substr($f, 0, -4), $manifest['migrations']), $ran);

        // Preconditions are explicit and change nothing.
        $migration = require self::$root . '/apps/api/database/migrations/2026_09_30_100012_p010_create_financial_documents.php';
        $this->refused('P010_PRECONDITION_FAILED', fn () => $migration->up());
        // Rollback of an empty table drops only that table and up() re-creates it identically.
        $before = $this->db()->selectOne('SHOW CREATE TABLE `financial_documents`')->{'Create Table'};
        $migration->down();
        $this->assertFalse($this->db()->getSchemaBuilder()->hasTable('financial_documents'));
        $migration->up();
        $this->assertSame($before, $this->db()->selectOne('SHOW CREATE TABLE `financial_documents`')->{'Create Table'});
        // Rollback never deletes financial data.
        $w = $this->finWorld();
        (new FinancePeriods($this->db()))->closeUnit($w['u1'], '2026-02', $w['c']);
        $closes = require self::$root . '/apps/api/database/migrations/2026_09_30_100013_p010_create_accounting_period_unit_closes.php';
        $this->refused('P010_ROLLBACK_REFUSED', fn () => $closes->down());
        $this->assertTrue($this->db()->getSchemaBuilder()->hasTable('accounting_period_unit_closes'));
    }

    public function test_s02_catalog_is_complete_idempotent_role_free_and_refuses_conflicting_meaning(): void
    {
        $this->assertSame([], FinanceCatalog::install($this->db()), 'second install inserts nothing');
        $counts = $this->manifest()['controlled_data_counts'];
        $this->assertSame($counts['currencies'], $this->db()->table('currencies')->count());
        $this->assertSame(['AOA'], $this->db()->table('currencies')->pluck('code')->all());
        $this->assertSame(2, (int) $this->db()->table('currencies')->where('code', 'AOA')->value('minor_units'));
        $this->assertSame($counts['funds'], $this->db()->table('funds')->count());
        $this->assertSame($counts['chart_of_accounts'], $this->db()->table('chart_of_accounts')->whereNotNull('system_role')->count());
        $this->assertSame($counts['financial_categories'], $this->db()->table('financial_categories')->count());
        $this->assertSame($counts['permissions'], $this->db()->table('permissions')->where('data_type', 'FINANCE')->whereColumn('action', 'code')->where('maximum_classification', 'CONFIDENTIAL')->count());
        $this->assertSame($counts['legal_document_types'], $this->db()->table('legal_document_types')->whereIn('code', array_keys(FinanceCatalog::DOCUMENT_TYPES))->count());
        $this->assertSame($counts['workflows'], $this->db()->table('workflows')->whereIn('code', array_keys(FinanceCatalog::WORKFLOWS))->where('version', 1)->where('status', 'ACTIVE')->count());
        $this->assertSame(array_sum($counts), FinanceCatalog::controlledRowCount());
        $this->assertSame(0, $this->db()->table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('p.data_type', 'FINANCE')->count(), 'no role granted');
        // Nothing statutory is seeded: no account, no rule, no rate; periods only through ensureYear (idempotent).
        $this->assertSame([], (new FinancePeriods($this->db()))->ensureYear(2026));
        $this->assertSame(13, $this->db()->table('accounting_periods')->where('code', 'like', '2026%')->count());
        $this->assertSame(0, $this->db()->table('financial_categories')->where('code', 'like', '%ARREND%')->count(), 'Arrendamento is not installed');

        // An existing code with another meaning aborts the WHOLE install and repairs nothing.
        $id = (int) $this->db()->table('permissions')->where('code', 'FINANCE_POST')->value('id');
        $this->db()->table('permissions')->where('id', $id)->update(['data_type' => 'MEMBERSHIP']);
        $missing = (int) $this->db()->table('legal_document_types')->where('code', 'PAYSLIP')->value('id');
        $this->db()->table('legal_document_types')->where('id', $missing)->update(['code' => 'PAYSLIP_TMP']);
        try {
            $this->refused('FINANCE_CATALOG_CONFLICT', fn () => FinanceCatalog::install($this->db()));
            $this->assertFalse($this->db()->table('legal_document_types')->where('code', 'PAYSLIP')->exists(), 'nothing installed on conflict');
            $this->assertSame('MEMBERSHIP', $this->db()->table('permissions')->where('id', $id)->value('data_type'), 'nothing repaired');
        } finally {
            $this->db()->table('permissions')->where('id', $id)->update(['data_type' => 'FINANCE']);
            $this->db()->table('legal_document_types')->where('id', $missing)->update(['code' => 'PAYSLIP']);
        }
        // D-04A.12: remapping an INVESTMENT rubric (FIXED_ASSETS <-> INVESTMENT_EXPENSE) is configuration, not a conflict.
        $roles = FinanceCatalog::roleIds($this->db());
        $this->db()->table('financial_categories')->where('code', 'INV_AST_UTENSILS')->update(['ledger_account_id' => $roles['INVESTMENT_EXPENSE']]);
        $this->assertSame([], FinanceCatalog::install($this->db()));
        $this->db()->table('financial_categories')->where('code', 'INV_AST_UTENSILS')->update(['ledger_account_id' => $roles['FIXED_ASSETS']]);
    }

    public function test_s03_public_ids_are_ulid_char26_ascii_bin_unique_and_generated(): void
    {
        foreach (['accounts', 'journal_entries', 'internal_transfers', 'receivables', 'payables', 'settlements', 'budgets'] as $table) {
            $col = $this->db()->selectOne("SELECT COLUMN_TYPE t, IS_NULLABLE n, CHARACTER_SET_NAME cs, COLLATION_NAME co FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'public_id'", [$table]);
            $this->assertSame(['char(26)', 'NO', 'ascii', 'ascii_bin'], [$col->t, $col->n, $col->cs, $col->co], $table);
            $this->assertSame(1, (int) $this->db()->selectOne("SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0", [$table, 'uq_' . $table . '_public_id'])->c, $table);
        }
        foreach (['currencies', 'funds', 'chart_of_accounts', 'financial_categories', 'accounting_periods', 'journal_lines', 'transfer_postings', 'accounting_period_unit_closes', 'settlement_allocations', 'budget_lines'] as $internal) {
            $this->assertFalse($this->db()->getSchemaBuilder()->hasColumn($internal, 'public_id'), $internal . ' has no external identity (D20)');
        }
        $w = $this->finWorld();
        $entry = $this->revenue($w, $w['c'], $w['cashC'], '100.00', '2026-02-10');
        $this->assertMatchesRegularExpression(FinanceCatalog::PUBLIC_ID_PATTERN, $entry['public_id']);
        $this->assertSame('JE-' . $entry['public_id'], $this->db()->table('journal_entries')->where('id', $entry['id'])->value('reference'));
        $dup = fn () => $this->db()->table('accounts')->insert(['public_id' => $this->db()->table('accounts')->where('id', $w['cashC'])->value('public_id'), 'unit_id' => $w['c'],
            'ledger_account_id' => $w['roles']['CASH'], 'currency_id' => $w['aoa'], 'code' => 'DUP', 'name' => 'x', 'account_kind' => 'CASH', 'status' => 'OPEN', 'opened_on' => '2026-01-01', 'created_at' => $this->ts(), 'lock_version' => 0]);
        $this->sqlError(1062, 'uq_accounts_public_id', $dup);
    }

    public function test_s04_foreign_keys_match_the_manifest_restrict_bigint_and_zero_cascade(): void
    {
        $tables = "'" . implode("','", self::P010_TABLES) . "'";
        $rules = $this->db()->select("SELECT CONSTRAINT_NAME n, UPDATE_RULE u, DELETE_RULE d FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ($tables)");
        $this->assertGreaterThan(60, count($rules));
        foreach ($rules as $rule) {
            $this->assertSame(['RESTRICT', 'RESTRICT'], [$rule->u, $rule->d], $rule->n);
        }
        foreach ($this->db()->select("SELECT k.TABLE_NAME t, k.COLUMN_NAME c, col.COLUMN_TYPE ty FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.COLUMNS col ON col.TABLE_SCHEMA = k.TABLE_SCHEMA AND col.TABLE_NAME = k.TABLE_NAME AND col.COLUMN_NAME = k.COLUMN_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ($tables) AND k.REFERENCED_TABLE_NAME IS NOT NULL") as $fk) {
            $this->assertSame('bigint unsigned', $fk->ty, $fk->t . '.' . $fk->c . ' (D-01)');
        }
        $composite = $this->db()->select("SELECT CONSTRAINT_NAME n, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) cols, REFERENCED_TABLE_NAME rt, GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION) rc FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN ('fk_journal_lines_entry_unit','fk_journal_lines_unit_financial_account','fk_internal_transfers_origin_unit_account','fk_internal_transfers_destination_unit_account') GROUP BY CONSTRAINT_NAME, REFERENCED_TABLE_NAME ORDER BY CONSTRAINT_NAME");
        $this->assertSame([
            'fk_internal_transfers_destination_unit_account' => 'destination_unit_id,destination_account_id->accounts(unit_id,id)',
            'fk_internal_transfers_origin_unit_account' => 'origin_unit_id,origin_account_id->accounts(unit_id,id)',
            'fk_journal_lines_entry_unit' => 'entry_id,unit_id->journal_entries(id,unit_id)',
            'fk_journal_lines_unit_financial_account' => 'unit_id,financial_account_id->accounts(unit_id,id)',
        ], array_combine(array_column($composite, 'n'), array_map(fn ($r) => $r->cols . '->' . $r->rt . '(' . $r->rc . ')', $composite)));
        // Deferred FK: receivables.obligation_id keeps its column + index, no FK (obligations does not exist in V1).
        $this->assertSame(0, (int) $this->db()->selectOne("SELECT COUNT(*) c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'receivables' AND COLUMN_NAME = 'obligation_id' AND REFERENCED_TABLE_NAME IS NOT NULL")->c);
        $this->assertTrue($this->db()->getSchemaBuilder()->hasColumn('receivables', 'obligation_id'));
    }

    public function test_s05_check_constraints_match_the_manifest_and_money_is_decimal_19_4(): void
    {
        $expected = $this->manifest()['checks'];
        foreach (self::P010_TABLES as $table) {
            $actual = array_map(fn ($r) => $r->n, $this->db()->select("SELECT CONSTRAINT_NAME n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK' ORDER BY CONSTRAINT_NAME", [$table]));
            $want = $expected[$table];
            sort($want);
            $this->assertSame($want, $actual, $table);
        }
        $tables = "'" . implode("','", self::P010_TABLES) . "'";
        $this->assertSame([], $this->db()->select("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($tables) AND DATA_TYPE IN ('float','double','real')"), 'no FLOAT/DOUBLE');
        $money = $this->db()->select("SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME) c, COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($tables) AND DATA_TYPE = 'decimal'");
        $this->assertCount(9, $money, 'debit, credit, 5 subledger amounts, 2 budget amounts (F1A tables)');
        foreach ($money as $col) {
            $this->assertSame('decimal(19,4)', $col->t, $col->c);
        }
        // A few physical refusals (errno 3819 = CHECK violated).
        $roles = FinanceCatalog::roleIds($this->db());
        $this->sqlError(3819, 'ck_chart_of_accounts_account_kind', fn () => $this->db()->table('chart_of_accounts')->insert(['code' => 'X_' . Str::random(6), 'name' => 'x', 'account_kind' => 'REVENUE', 'normal_side' => 'CREDIT', 'postable' => 1, 'status' => 'ACTIVE', 'created_at' => $this->ts(), 'lock_version' => 0]));
        $this->sqlError(3819, 'ck_chart_of_accounts_kind_side', fn () => $this->db()->table('chart_of_accounts')->insert(['code' => 'X_' . Str::random(6), 'name' => 'x', 'account_kind' => 'INCOME', 'normal_side' => 'DEBIT', 'postable' => 1, 'status' => 'ACTIVE', 'created_at' => $this->ts(), 'lock_version' => 0]));
        $this->sqlError(3819, 'ck_accounting_periods_calendar', fn () => $this->db()->table('accounting_periods')->insert(['code' => '2031-02', 'period_kind' => 'MONTH', 'starts_on' => '2031-02-01', 'ends_on' => '2031-02-27', 'status' => 'OPEN', 'created_at' => $this->ts(), 'lock_version' => 0]));
        $this->sqlError(3819, 'ck_financial_categories_postable_nature', fn () => $this->db()->table('financial_categories')->insert(['code' => 'X_' . Str::random(6), 'name' => 'x', 'ledger_account_id' => $roles['OPERATING_INCOME'], 'economic_nature' => null, 'classification_status' => 'APPROVED', 'status' => 'ACTIVE', 'created_at' => $this->ts(), 'lock_version' => 0]));
        $w = $this->finWorld();
        $this->sqlError(3819, 'ck_accounts_account_kind', fn () => $this->db()->table('accounts')->insert(['public_id' => (string) Str::ulid(), 'unit_id' => $w['c'], 'ledger_account_id' => $roles['CASH'], 'currency_id' => $w['aoa'], 'code' => 'LOAN', 'name' => 'x', 'account_kind' => 'LOAN', 'status' => 'OPEN', 'opened_on' => '2026-01-01', 'created_at' => $this->ts(), 'lock_version' => 0]));
        $this->sqlError(3819, 'ck_financial_parties_xor', fn () => $this->db()->table('financial_parties')->insert(['party_kind' => 'EXTERNAL', 'person_id' => $this->row('people'), 'external_name' => 'x', 'status' => 'ACTIVE', 'created_at' => $this->ts(), 'lock_version' => 0]));
    }

    public function test_s06_at_most_one_approved_budget_version_and_approver_differs_from_submitter(): void
    {
        $w = $this->finWorld();
        $year = (int) $this->db()->table('accounting_periods')->where('code', '2026')->value('id');
        $fund = (int) $this->db()->table('funds')->where('code', 'GENERAL')->value('id');
        $budget = fn (int $version, string $status, ?int $submitted = null, ?int $approved = null) => $this->db()->table('budgets')->insertGetId(['public_id' => (string) Str::ulid(),
            'unit_id' => $w['c'], 'period_id' => $year, 'fund_id' => $fund, 'currency_id' => $w['aoa'], 'version' => $version, 'status' => $status, 'submitted_by' => $submitted,
            'approved_at' => $approved ? $this->ts() : null, 'approved_by' => $approved, 'created_at' => $this->ts(), 'lock_version' => 0]);
        $v1 = $budget(1, 'APPROVED', $w['u1'], $w['u2']);
        $this->sqlError(1062, 'uq_budgets_unit_id_period_id_fund_id_approved', fn () => $budget(2, 'APPROVED', $w['u1'], $w['u2']));
        $v2 = $budget(2, 'DRAFT');
        // Revision: v1 SUPERSEDED and v2 APPROVED in one transaction (the D13 transition) is accepted.
        $this->db()->transaction(function () use ($v1, $v2, $w): void {
            $this->db()->table('budgets')->where('id', $v1)->update(['status' => 'SUPERSEDED']);
            $this->db()->table('budgets')->where('id', $v2)->update(['status' => 'APPROVED', 'submitted_by' => $w['u1'], 'approved_by' => $w['u2'], 'approved_at' => $this->ts()]);
        });
        $this->assertSame(1, $this->db()->table('budgets')->where('unit_id', $w['c'])->where('status', 'APPROVED')->count());
        $this->sqlError(1062, 'uq_budgets_unit_id_period_id_fund_id_approved', fn () => $this->db()->table('budgets')->where('id', $v1)->update(['status' => 'APPROVED']));
        $this->sqlError(3819, 'ck_budgets_segregation', fn () => $budget(3, 'DRAFT', $w['u1'], $w['u1']));
        $this->sqlError(3819, 'ck_budgets_approved', fn () => $budget(4, 'SUPERSEDED'));
        $this->sqlError(3819, 'ck_budgets_status', fn () => $budget(5, 'IN_EXECUTION'));
        $this->sqlError(3819, 'ck_budget_lines_requested_amount', fn () => $this->db()->table('budget_lines')->insert(['budget_id' => $v2, 'category_id' => $this->cat('ADM_WATER'), 'requested_amount' => '10.005', 'approved_amount' => '0', 'created_at' => $this->ts(), 'lock_version' => 0]));
        $gen = $this->db()->selectOne("SELECT GENERATION_EXPRESSION g, EXTRA x FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budgets' AND COLUMN_NAME = 'approved_guard'");
        $this->assertStringContainsString('STORED GENERATED', $gen->x);
    }

    public function test_s07_unit_close_is_unique_per_period_and_unit_and_reopen_is_segregated(): void
    {
        $w = $this->finWorld();
        $periods = new FinancePeriods($this->db());
        $periods->closeUnit($w['u1'], '2026-03', $w['c']);
        $period = (int) $this->db()->table('accounting_periods')->where('code', '2026-03')->value('id');
        $raw = fn (array $v = []) => $this->db()->table('accounting_period_unit_closes')->insert($v + ['period_id' => $period, 'unit_id' => $w['c'], 'status' => 'CLOSED', 'closed_at' => $this->ts(), 'closed_by' => $w['u1'], 'created_at' => $this->ts(), 'lock_version' => 0]);
        $this->sqlError(1062, 'uq_accounting_period_unit_closes_period_id_unit_id', $raw);
        $this->sqlError(3819, 'ck_accounting_period_unit_closes_segregation', fn () => $raw(['unit_id' => $w['m'], 'status' => 'REOPENED', 'reopened_at' => $this->ts(), 'reopened_by' => $w['u1'], 'reason' => 'x']));
        $this->sqlError(3819, 'ck_accounting_period_unit_closes_reopened', fn () => $raw(['unit_id' => $w['m'], 'status' => 'REOPENED', 'reopened_at' => $this->ts(), 'reopened_by' => $w['u2'], 'reason' => ' ']));
        $this->refused('PERIOD_ALREADY_CLOSED', fn () => $periods->closeUnit($w['u2'], '2026-03', $w['c']));
        $this->refused('SEGREGATION_REQUIRED', fn () => $periods->reopenUnit($w['u1'], '2026-03', $w['c'], 'correcção'));
        $this->refused('REASON_REQUIRED', fn () => $periods->reopenUnit($w['u2'], '2026-03', $w['c'], '  '));
        $periods->reopenUnit($w['u2'], '2026-03', $w['c'], 'correcção de classificação');
        $this->assertSame('REOPENED', $this->db()->table('accounting_period_unit_closes')->where('period_id', $period)->where('unit_id', $w['c'])->value('status'));
        $periods->closeUnit($w['u2'], '2026-03', $w['c']);
        $this->assertSame(1, $this->db()->table('accounting_period_unit_closes')->where('period_id', $period)->where('unit_id', $w['c'])->count());
        $this->assertSame(['finance.period_unit_closed', 'finance.period_unit_reopened', 'finance.period_unit_closed'], $this->db()->table('audit_logs')->where('source', 'P010_FINANCE')->where('unit_id', $w['c'])->where('entity_type', 'accounting_period_unit_closes')->orderBy('id')->pluck('action')->all());
        // A YEAR period is not a posting/closing period.
        $this->refused('PERIOD_NOT_POSTABLE', fn () => $periods->closeUnit($w['u1'], '2026', $w['c']));
    }

    // ---- J: accounting contracts ------------------------------------------------------------------------------------

    public function test_j01_balanced_posting_is_published_once_with_audit_and_provenance(): void
    {
        $w = $this->finWorld();
        $svc = $this->ledger();
        $key = $this->key();
        $draft = $svc->createDraft($w['u1'], $key, $this->revenueInput($w['c'], $w['cashC'], '100.00', '2026-02-10'));
        $this->assertSame($draft, array_replace($svc->createDraft($w['u1'], $key, $this->revenueInput($w['c'], $w['cashC'], '100.00', '2026-02-10')), ['replayed' => false]), 'idempotent replay returns the same entry');
        $this->refused('IDEMPOTENCY_CONFLICT', fn () => $svc->createDraft($w['u1'], $key, $this->revenueInput($w['c'], $w['cashC'], '101.00', '2026-02-10')));
        $svc->submit($w['u1'], $draft['public_id'], 0);
        $svc->post($w['u2'], $draft['public_id'], 1);
        $e = $this->db()->table('journal_entries')->where('id', $draft['id'])->first();
        $this->assertSame(['POSTED', 'REVENUE', $w['c'], $w['u1'], $w['u1'], $w['u2']], [$e->status, $e->entry_kind, (int) $e->unit_id, (int) $e->created_by, (int) $e->submitted_by, (int) $e->posted_by]);
        $this->assertNotNull($e->posted_at);
        $this->assertSame(1, $this->db()->table('journal_entries')->where('idempotency_request_id', $e->idempotency_request_id)->count());
        $this->assertSame([10000, 10000], $this->sums($draft['id']));
        $this->assertSame(['finance.entry_created', 'finance.entry_submitted', 'finance.entry_posted'], $this->db()->table('audit_logs')->where('source', 'P010_FINANCE')->where('entity_type', 'journal_entries')->where('entity_id', $draft['id'])->orderBy('id')->pluck('action')->all());
        $this->assertSame(0, $this->db()->table('audit_logs')->where('source', 'P010_FINANCE')->where('entity_type', 'journal_entries')->where('entity_id', $draft['id'])->where('unit_id', '!=', $w['c'])->count(), 'audit unit = owner unit');
        $this->refused('ALREADY_POSTED', fn () => $svc->post($w['u2'], $draft['public_id'], 2));
    }

    public function test_j02_unbalanced_or_degenerate_entries_never_reach_posted(): void
    {
        $w = $this->finWorld();
        $svc = $this->ledger();
        $input = $this->revenueInput($w['c'], $w['cashC'], '100.00', '2026-02-11');
        $input['lines'][1]['credit'] = '99.99';
        $draft = $svc->createDraft($w['u1'], $this->key(), $input);
        $this->refused('UNBALANCED', fn () => $svc->post($w['u1'], $draft['public_id'], 0));
        $this->assertSame('DRAFT', $this->db()->table('journal_entries')->where('id', $draft['id'])->value('status'));
        $this->assertNull($this->db()->table('journal_entries')->where('id', $draft['id'])->value('posted_at'));

        $one = $svc->createDraft($w['u1'], $this->key(), ['lines' => [$input['lines'][0]]] + $input);
        $this->refused('TOO_FEW_LINES', fn () => $svc->post($w['u1'], $one['public_id'], 0));
        $empty = $svc->createDraft($w['u1'], $this->key(), ['lines' => []] + $input);
        $this->refused('TOO_FEW_LINES', fn () => $svc->post($w['u1'], $empty['public_id'], 0));
        $this->refused('AMOUNT_NOT_POSITIVE', fn () => $svc->createDraft($w['u1'], $this->key(), ['lines' => [['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'debit' => '0'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_TITHES', 'credit' => '0.00']]] + $input));

        // A subledger entry that does not balance is rolled back: no header, no line, no idempotency residue.
        $entries = $this->db()->table('journal_entries')->count();
        $lines = $this->db()->table('journal_lines')->count();
        $claims = $this->db()->table('idempotency_requests')->count();
        $this->refused('UNBALANCED', fn () => $this->db()->transaction(fn () => $svc->postSubledgerEntry($w['u1'], $this->key(), $this->sendInput($w, '50.00', '2026-02-11', '49.00'))));
        $this->assertSame([$entries, $lines, $claims], [$this->db()->table('journal_entries')->count(), $this->db()->table('journal_lines')->count(), $this->db()->table('idempotency_requests')->count()]);
        // The line CHECK makes a zero or two-sided line physically impossible.
        $this->sqlError(3819, 'ck_journal_lines_one_side', fn () => $this->rawLine($draft['id'], $w['c'], $w['roles']['OPENING_NET_ASSETS'], '0', '0'));
        $this->sqlError(3819, 'ck_journal_lines_one_side', fn () => $this->rawLine($draft['id'], $w['c'], $w['roles']['OPENING_NET_ASSETS'], '5', '5'));
    }

    public function test_j03_posted_header_and_lines_are_immutable_through_every_service_path(): void
    {
        $w = $this->finWorld();
        $svc = $this->ledger();
        $entry = $this->revenue($w, $w['c'], $w['cashC'], '80.00', '2026-02-12');
        $snapshot = fn () => [(array) $this->db()->table('journal_entries')->where('id', $entry['id'])->first(), $this->db()->table('journal_lines')->where('entry_id', $entry['id'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
        $before = $snapshot();
        $lock = (int) $before[0]['lock_version'];
        $this->refused('ENTRY_NOT_EDITABLE', fn () => $svc->replaceDraftLines($w['u1'], $entry['public_id'], $lock, $this->revenueInput($w['c'], $w['cashC'], '1.00', '2026-02-12')['lines']));
        $this->refused('ALREADY_POSTED', fn () => $svc->submit($w['u1'], $entry['public_id'], $lock));
        $this->refused('ALREADY_POSTED', fn () => $svc->returnToDraft($w['u1'], $entry['public_id'], $lock));
        $this->refused('ALREADY_POSTED', fn () => $svc->discard($w['u1'], $entry['public_id'], $lock));
        $this->refused('ALREADY_POSTED', fn () => $svc->post($w['u1'], $entry['public_id'], $lock));
        $this->assertSame($before, $snapshot(), 'header and lines byte-identical after every refused path');
        // Draft lines ARE editable (the only line mutation), and a discarded draft never counts nor posts.
        $draft = $svc->createDraft($w['u1'], $this->key(), $this->revenueInput($w['c'], $w['cashC'], '5.00', '2026-02-12'));
        $svc->replaceDraftLines($w['u1'], $draft['public_id'], 0, $this->revenueInput($w['c'], $w['cashC'], '6.00', '2026-02-12')['lines']);
        $this->assertSame([600, 600], $this->sums($draft['id']));
        $this->refused('STALE_LOCK_VERSION', fn () => $svc->discard($w['u1'], $draft['public_id'], 0));
        $svc->discard($w['u1'], $draft['public_id'], 1);
        $this->refused('ENTRY_DISCARDED', fn () => $svc->post($w['u1'], $draft['public_id'], 2));
        $this->assertSame(8000, $this->queries()->financialAccountBalance($w['cashC']));
    }

    public function test_j04_reversal_is_the_exact_inverse_once_with_reason_in_an_open_period(): void
    {
        $w = $this->finWorld();
        $svc = $this->ledger();
        $periods = new FinancePeriods($this->db());
        $original = $this->revenue($w, $w['c'], $w['cashC'], '70.00', '2026-02-13');
        $periods->closeUnit($w['u1'], '2026-02', $w['c']);
        $this->refused('REASON_REQUIRED', fn () => $svc->reverse($w['u1'], $this->key(), $original['public_id'], ' ', '2026-03-02'));
        $this->refused('PERIOD_CLOSED', fn () => $svc->reverse($w['u1'], $this->key(), $original['public_id'], 'erro de caixa', '2026-02-20'));
        $key = $this->key();
        $reversal = $svc->reverse($w['u1'], $key, $original['public_id'], 'erro de caixa', '2026-03-02');
        $this->assertSame($reversal['public_id'], $svc->reverse($w['u1'], $key, $original['public_id'], 'erro de caixa', '2026-03-02')['public_id'], 'idempotent replay');
        $r = $this->db()->table('journal_entries')->where('id', $reversal['id'])->first();
        $this->assertSame(['POSTED', 'REVERSAL', (int) $original['id'], 'erro de caixa'], [$r->status, $r->entry_kind, (int) $r->reversal_of_id, $r->reason]);
        $this->assertSame('POSTED', $this->db()->table('journal_entries')->where('id', $original['id'])->value('status'), 'the original stays in its closed month');
        $this->assertSame(0, $this->queries()->financialAccountBalance($w['cashC']));
        $this->assertSame(7000, $this->queries()->financialAccountBalance($w['cashC'], '2026-02-28'));
        $this->assertSame(['income' => 7000, 'expense' => 0, 'investment_consumed' => 0, 'result' => 7000], $this->queries()->economicResult($w['c'], '2026-02-01', '2026-02-28'));
        $this->assertSame(-7000, $this->queries()->economicResult($w['c'], '2026-03-01', '2026-03-31')['result'], 'the correction appears in the reversal month');
        $this->refused('ALREADY_REVERSED', fn () => $svc->reverse($w['u1'], $this->key(), $original['public_id'], 'de novo', '2026-03-03'));
        $this->refused('CANNOT_REVERSE_REVERSAL', fn () => $svc->reverse($w['u1'], $this->key(), $reversal['public_id'], 'x', '2026-03-03'));
        $draft = $svc->createDraft($w['u1'], $this->key(), $this->revenueInput($w['c'], $w['cashC'], '1.00', '2026-03-03'));
        $this->refused('ENTRY_NOT_POSTED', fn () => $svc->reverse($w['u1'], $this->key(), $draft['public_id'], 'x', '2026-03-03'));
        $send = $this->send($w, '10.00', '2026-03-03');
        $this->refused('SUBLEDGER_OWNED', fn () => $svc->reverse($w['u1'], $this->key(), $send['entry']['public_id'], 'x', '2026-03-04'));
        // Physical: one reversal per entry (UNIQUE) and REVERSAL <=> reversal_of_id <=> reason (CHECK).
        $this->sqlError(1062, 'uq_journal_entries_reversal_of_id', fn () => $this->rawEntry($w['c'], 'REVERSAL', ['reversal_of_id' => $original['id'], 'reason' => 'x']));
        $this->sqlError(3819, 'ck_journal_entries_reversal', fn () => $this->rawEntry($w['c'], 'REVERSAL', ['reason' => 'x']));
        $this->sqlError(3819, 'ck_journal_entries_reversal', fn () => $this->rawEntry($w['c'], 'REVENUE', ['reversal_of_id' => $send['entry']['id']]));
        $this->sqlError(3819, 'ck_journal_entries_reason', fn () => $this->rawEntry($w['c'], 'ADJUSTMENT'));
    }

    public function test_j05_accrual_revenue_is_recognised_before_the_cash_arrives(): void
    {
        $w = $this->finWorld();
        $party = $this->party();
        $receivable = $this->db()->table('receivables')->insertGetId(['public_id' => (string) Str::ulid(), 'party_id' => $party, 'unit_id' => $w['c'], 'obligation_id' => null,
            'currency_id' => $w['aoa'], 'amount' => '300.00', 'due_on' => '2026-05-31', 'status' => 'PENDING', 'created_at' => $this->ts(), 'lock_version' => 0]);
        $this->sqlError(3819, 'ck_receivables_recognized', fn () => $this->db()->table('receivables')->where('id', $receivable)->update(['status' => 'RECOGNIZED']));
        $this->sqlError(3819, 'ck_receivables_obligation_v1', fn () => $this->db()->table('receivables')->where('id', $receivable)->update(['obligation_id' => 1]));
        $recognition = $this->db()->transaction(function () use ($w, $receivable) {
            $e = $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), ['unit_id' => $w['c'], 'entry_kind' => 'RECEIVABLE_RECOGNITION', 'entry_date' => '2026-04-15', 'description' => 'Doação prometida', 'lines' => [
                ['account' => 'RECEIVABLES', 'debit' => '300.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_DONATIONS', 'credit' => '300.00']]]);
            $this->db()->table('receivables')->where('id', $receivable)->update(['status' => 'RECOGNIZED', 'recognition_entry_id' => $e['id']]);
            return $e;
        });
        $april = $this->queries()->economicResult($w['c'], '2026-04-01', '2026-04-30');
        $this->assertSame(30000, $april['income'], 'revenue recognised in April');
        $this->assertSame(0, $this->queries()->financialAccountBalance($w['cashC']), 'with no cash yet');
        $this->assertSame(30000, $this->queries()->roleTotals($w['c'], '2026-04-01', '2026-04-30')['RECEIVABLES']);

        $this->db()->transaction(function () use ($w, $receivable) {
            $e = $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), ['unit_id' => $w['c'], 'entry_kind' => 'SETTLEMENT', 'entry_date' => '2026-05-20', 'description' => 'Recebimento', 'lines' => [
                ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'debit' => '300.00'], ['account' => 'RECEIVABLES', 'credit' => '300.00']]]);
            $s = $this->db()->table('settlements')->insertGetId(['public_id' => (string) Str::ulid(), 'account_id' => $w['cashC'], 'currency_id' => $w['aoa'], 'amount' => '300.00', 'settled_at' => $this->ts(), 'entry_id' => $e['id'], 'direction' => 'RECEIPT', 'status' => 'POSTED', 'created_at' => $this->ts(), 'lock_version' => 0]);
            $this->db()->table('settlement_allocations')->insert(['settlement_id' => $s, 'receivable_id' => $receivable, 'payable_id' => null, 'amount' => '300.00', 'created_at' => $this->ts(), 'lock_version' => 0]);
            $this->db()->table('receivables')->where('id', $receivable)->update(['status' => 'SETTLED']);
        });
        $this->assertSame(0, $this->queries()->economicResult($w['c'], '2026-05-01', '2026-05-31')['result'], 'collection is not revenue again');
        $this->assertSame(30000, $this->queries()->financialAccountBalance($w['cashC']));
        $this->assertSame(0, $this->queries()->roleTotals($w['c'], '2026-04-01', '2026-05-31')['RECEIVABLES']);
        $this->assertSame((int) $recognition['id'], (int) $this->db()->table('receivables')->where('id', $receivable)->value('recognition_entry_id'));
        $this->sqlError(3819, 'ck_settlement_allocations_xor', fn () => $this->db()->table('settlement_allocations')->insert(['settlement_id' => $this->db()->table('settlements')->max('id'), 'receivable_id' => null, 'payable_id' => null, 'amount' => '1.00', 'created_at' => $this->ts(), 'lock_version' => 0]));
        // A recognition may not pretend to be cash: CASH on a RECEIVABLE_RECOGNITION is a shape error.
        $this->refused('ENTRY_SHAPE_INVALID', fn () => $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), ['unit_id' => $w['c'], 'entry_kind' => 'RECEIVABLE_RECOGNITION', 'entry_date' => '2026-04-15', 'description' => 'x', 'lines' => [
            ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'debit' => '1.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_DONATIONS', 'credit' => '1.00']]])));
    }

    public function test_j06_accrual_expense_is_recognised_before_it_is_paid(): void
    {
        $w = $this->finWorld();
        $payable = $this->payable($w, '120.00');
        $this->db()->transaction(function () use ($w, $payable) {
            $e = $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), ['unit_id' => $w['c'], 'entry_kind' => 'PAYABLE_RECOGNITION', 'entry_date' => '2026-04-10', 'description' => 'Factura de energia', 'lines' => [
                ['account' => 'OPERATING_EXPENSE', 'category' => 'ADM_ELECTRICITY', 'debit' => '120.00'], ['account' => 'PAYABLES', 'credit' => '120.00']]]);
            $this->db()->table('payables')->where('id', $payable)->update(['status' => 'RECOGNIZED', 'recognition_entry_id' => $e['id']]);
        });
        $this->assertSame(['income' => 0, 'expense' => 12000, 'investment_consumed' => 0, 'result' => -12000], $this->queries()->economicResult($w['c'], '2026-04-01', '2026-04-30'));
        $this->assertSame(0, $this->queries()->financialAccountBalance($w['cashC']), 'no cash moved yet');
        $this->assertSame(-12000, $this->queries()->roleTotals($w['c'], '2026-04-01', '2026-04-30')['PAYABLES']);
        $this->revenue($w, $w['c'], $w['cashC'], '200.00', '2026-05-01');
        $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), ['unit_id' => $w['c'], 'entry_kind' => 'SETTLEMENT', 'entry_date' => '2026-05-05', 'description' => 'Pagamento', 'lines' => [
            ['account' => 'PAYABLES', 'debit' => '120.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '120.00']]]));
        $this->assertSame(0, $this->queries()->economicResult($w['c'], '2026-05-01', '2026-05-31')['expense'], 'the payment is not a second expense');
        $this->assertSame(8000, $this->queries()->financialAccountBalance($w['cashC']));
        // An expense rubric on a result line is mandatory, and must match its control account.
        $this->refused('CATEGORY_REQUIRED', fn () => $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), ['unit_id' => $w['c'], 'entry_kind' => 'PAYABLE_RECOGNITION', 'entry_date' => '2026-04-10', 'description' => 'x', 'lines' => [
            ['account' => 'OPERATING_EXPENSE', 'debit' => '1.00'], ['account' => 'PAYABLES', 'credit' => '1.00']]])));
        $this->refused('CATEGORY_LEDGER_MISMATCH', fn () => $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), ['unit_id' => $w['c'], 'entry_kind' => 'PAYABLE_RECOGNITION', 'entry_date' => '2026-04-10', 'description' => 'x', 'lines' => [
            ['account' => 'OPERATING_EXPENSE', 'category' => 'REV_TITHES', 'debit' => '1.00'], ['account' => 'PAYABLES', 'credit' => '1.00']]])));
    }

    public function test_j07_cash_and_bank_balances_are_derived_from_posted_lines_only(): void
    {
        $columns = $this->db()->select("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' ORDER BY ORDINAL_POSITION");
        $this->assertSame(['id', 'public_id', 'unit_id', 'ledger_account_id', 'currency_id', 'code', 'name', 'account_kind', 'status', 'opened_on', 'closed_on', 'created_at', 'lock_version'], array_map(fn ($r) => $r->c, $columns), 'no stored balance');
        $this->assertSame([], $this->db()->select("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME LIKE '%balance%' AND TABLE_NAME IN ('accounts','journal_entries','journal_lines','cash_registers','bank_account_details','internal_transfers')"));
        $w = $this->finWorld();
        $bank = $this->account($w['c'], 'BANK');
        $this->revenue($w, $w['c'], $w['cashC'], '500.00', '2026-02-14');
        $this->manual($w, 'ACCOUNT_TRANSFER', '2026-02-15', [['account' => 'BANK', 'financial_account_id' => $bank, 'debit' => '200.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '200.00']]);
        $svc = $this->ledger();
        $draft = $svc->createDraft($w['u1'], $this->key(), $this->revenueInput($w['c'], $w['cashC'], '999.00', '2026-02-16'));
        $submitted = $svc->createDraft($w['u1'], $this->key(), $this->revenueInput($w['c'], $w['cashC'], '888.00', '2026-02-16'));
        $svc->submit($w['u1'], $submitted['public_id'], 0);
        $this->assertSame(30000, $this->queries()->financialAccountBalance($w['cashC']));
        $this->assertSame(20000, $this->queries()->financialAccountBalance($bank));
        $this->assertSame(50000, $this->queries()->financialAccountBalance($w['cashC'], '2026-02-14'));
        $sql = $this->db()->selectOne("SELECT SUM(l.debit) - SUM(l.credit) b FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id WHERE e.status = 'POSTED' AND l.financial_account_id = ?", [$w['cashC']]);
        $this->assertSame(30000, Money::fromDecimal($sql->b));
        $this->assertSame(0, $this->queries()->economicResult($w['c'], '2026-02-15', '2026-02-15')['result'], 'moving money between own accounts is not a result');
        // Wrong control account for a financial account, or a financial account on a non-treasury line, is refused.
        $this->refused('FINANCIAL_ACCOUNT_LEDGER_MISMATCH', fn () => $this->manual($w, 'ACCOUNT_TRANSFER', '2026-02-15', [['account' => 'CASH', 'financial_account_id' => $bank, 'debit' => '1.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '1.00']]));
        $this->refused('FINANCIAL_ACCOUNT_REQUIRED', fn () => $this->manual($w, 'ACCOUNT_TRANSFER', '2026-02-15', [['account' => 'BANK', 'debit' => '1.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '1.00']]));
        $this->db()->table('accounts')->where('id', $bank)->update(['status' => 'CLOSED', 'closed_on' => '2026-02-20']);
        $this->refused('FINANCIAL_ACCOUNT_CLOSED', fn () => $this->manual($w, 'ACCOUNT_TRANSFER', '2026-02-21', [['account' => 'BANK', 'financial_account_id' => $bank, 'debit' => '1.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '1.00']]));
        unset($draft);
    }

    public function test_j08_capitalizable_investment_has_zero_immediate_result_and_the_mapping_is_configurable(): void
    {
        $w = $this->finWorld();
        $this->revenue($w, $w['c'], $w['cashC'], '1000.00', '2026-02-17');
        $this->assertSame('FIXED_ASSETS', $this->roleOf('INV_AST_IT'));
        $this->manual($w, 'EXPENSE', '2026-03-05', [['account' => 'FIXED_ASSETS', 'category' => 'INV_AST_IT', 'debit' => '400.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '400.00']]);
        $payable = $this->payable($w, '250.00');
        $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), ['unit_id' => $w['c'], 'entry_kind' => 'PAYABLE_RECOGNITION', 'entry_date' => '2026-03-06', 'description' => 'Mobiliário a crédito', 'lines' => [
            ['account' => 'FIXED_ASSETS', 'category' => 'INV_AST_FURNITURE', 'debit' => '250.00'], ['account' => 'PAYABLES', 'credit' => '250.00']]]));
        $march = $this->queries()->economicResult($w['c'], '2026-03-01', '2026-03-31');
        $this->assertSame(['income' => 0, 'expense' => 0, 'investment_consumed' => 0, 'result' => 0], $march, 'Dr FIXED_ASSETS / Cr CASH|PAYABLES: immediate DRE = 0');
        $this->assertSame(65000, $this->queries()->roleTotals($w['c'], '2026-03-01', '2026-03-31')['FIXED_ASSETS']);
        $this->assertSame(60000, $this->queries()->financialAccountBalance($w['cashC']));
        // A capitalizable rubric may not be expensed through its wrong control account, nor may FIXED_ASSETS carry a non-investment rubric.
        $this->refused('CATEGORY_LEDGER_MISMATCH', fn () => $this->manual($w, 'EXPENSE', '2026-03-05', [['account' => 'INVESTMENT_EXPENSE', 'category' => 'INV_AST_IT', 'debit' => '1.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '1.00']]));
        $this->refused('CATEGORY_LEDGER_MISMATCH', fn () => $this->manual($w, 'EXPENSE', '2026-03-05', [['account' => 'FIXED_ASSETS', 'category' => 'ADM_WATER', 'debit' => '1.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '1.00']]));
        // Configurable: the accountant remaps a rubric to consumed investment; new postings follow, posted lines do not change.
        $roles = $w['roles'];
        $this->db()->table('financial_categories')->where('code', 'INV_AST_UNIFORMS')->update(['ledger_account_id' => $roles['INVESTMENT_EXPENSE']]);
        try {
            $this->manual($w, 'EXPENSE', '2026-03-07', [['account' => 'INVESTMENT_EXPENSE', 'category' => 'INV_AST_UNIFORMS', 'debit' => '30.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '30.00']]);
            $this->assertSame(-3000, $this->queries()->economicResult($w['c'], '2026-03-07', '2026-03-07')['result']);
        } finally {
            $this->db()->table('financial_categories')->where('code', 'INV_AST_UNIFORMS')->update(['ledger_account_id' => $roles['FIXED_ASSETS']]);
        }
        $this->assertSame(65000, $this->queries()->roleTotals($w['c'], '2026-03-01', '2026-03-31')['FIXED_ASSETS'], 'posted lines unchanged by a remap');
        $this->assertSame(0, $this->db()->table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')->where('journal_entries.status', 'POSTED')->where('ledger_account_id', $roles['FIXED_ASSETS'])->whereNotIn('category_id', $this->db()->table('financial_categories')->where('economic_nature', 'INVESTMENT')->pluck('id'))->count());
    }

    public function test_j09_consumed_investment_stays_in_the_result_as_investment(): void
    {
        $w = $this->finWorld();
        $this->revenue($w, $w['c'], $w['cashC'], '100.00', '2026-02-18');
        foreach (['INV_COM_DIGITAL', 'INV_DEV_TRAINING'] as $rubric) {
            $this->assertSame('INVESTMENT_EXPENSE', $this->roleOf($rubric));
        }
        $this->manual($w, 'EXPENSE', '2026-03-08', [['account' => 'INVESTMENT_EXPENSE', 'category' => 'INV_COM_DIGITAL', 'debit' => '25.00'], ['account' => 'INVESTMENT_EXPENSE', 'category' => 'INV_DEV_TRAINING', 'debit' => '15.00'],
            ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '40.00']]);
        $this->assertSame(['income' => 0, 'expense' => 4000, 'investment_consumed' => 4000, 'result' => -4000], $this->queries()->economicResult($w['c'], '2026-03-01', '2026-03-31'));
        $this->assertSame(6000, $this->queries()->financialAccountBalance($w['cashC']));
        // Not "every INVESTMENT is an expense": each §16.8 rubric is capitalizable, §16.7/§16.9 consumed (D-04A.12).
        foreach ($this->db()->table('financial_categories as c')->join('chart_of_accounts as a', 'a.id', '=', 'c.ledger_account_id')->where('c.economic_nature', 'INVESTMENT')->get(['c.code', 'a.system_role']) as $row) {
            $this->assertSame(str_starts_with($row->code, 'INV_AST_') ? 'FIXED_ASSETS' : 'INVESTMENT_EXPENSE', $row->system_role, $row->code);
        }
    }

    public function test_j10_send_touches_only_the_origin_and_never_the_result(): void
    {
        $w = $this->finWorld();
        $this->revenue($w, $w['c'], $w['cashC'], '100.00', '2026-02-19');
        $send = $this->send($w, '60.00', '2026-03-10');
        $lines = $this->db()->table('journal_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.ledger_account_id')->where('l.entry_id', $send['entry']['id'])->orderBy('l.line_number')->get(['l.unit_id', 'l.counterparty_unit_id', 'a.system_role', 'a.account_kind', 'l.debit', 'l.credit']);
        $this->assertSame([[$w['c'], $w['x'], 'INTERUNIT_CLEARING_OUT', 'INTERUNIT_CONTROL', 6000, 0], [$w['c'], null, 'CASH', 'ASSET', 0, 6000]],
            $lines->map(fn ($l) => [(int) $l->unit_id, $l->counterparty_unit_id === null ? null : (int) $l->counterparty_unit_id, $l->system_role, $l->account_kind, Money::fromDecimal($l->debit), Money::fromDecimal($l->credit)])->all());
        $this->assertSame(0, $this->queries()->economicResult($w['c'], '2026-03-01', '2026-03-31')['result']);
        $this->assertSame(['sent' => 6000, 'received' => 0, 'net' => -6000], $this->queries()->interunitPosition($w['c'], '2026-03-31'));
        $this->assertSame(0, $this->db()->table('journal_lines')->where('unit_id', $w['x'])->count(), 'the destination is untouched by SEND');
        $this->assertSame('SENT', $this->db()->table('internal_transfers')->where('id', $send['transfer'])->value('status'));
        $bad = fn (array $lines) => fn () => $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), ['unit_id' => $w['c'], 'entry_kind' => 'TRANSFER_SEND', 'entry_date' => '2026-03-10', 'description' => 'x', 'lines' => $lines]));
        $this->refused('TRANSFER_TOUCHES_RESULT', $bad([['account' => 'OPERATING_EXPENSE', 'category' => 'ADM_OTHER', 'debit' => '5.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '5.00']]));
        $this->refused('COUNTERPARTY_REQUIRED', $bad([['account' => 'INTERUNIT_CLEARING_OUT', 'category' => 'TRF_SUPPORT', 'debit' => '5.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '5.00']]));
        $this->refused('TRANSFER_PURPOSE_REQUIRED', $bad([['account' => 'INTERUNIT_CLEARING_OUT', 'counterparty_unit_id' => $w['x'], 'debit' => '5.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '5.00']]));
        $this->refused('ENTRY_SHAPE_INVALID', $bad([['account' => 'INTERUNIT_CLEARING_IN', 'counterparty_unit_id' => $w['x'], 'category' => 'TRF_SUPPORT', 'debit' => '5.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '5.00']]));
        // INTERUNIT_CONTROL cannot be reached by any non-transfer form (no clearing through ADJUSTMENT/EXPENSE).
        $this->refused('INTERUNIT_ONLY_BY_TRANSFER', fn () => $this->manual($w, 'ADJUSTMENT', '2026-03-10', [['account' => 'INTERUNIT_CLEARING_OUT', 'counterparty_unit_id' => $w['x'], 'category' => 'TRF_OTHER', 'debit' => '5.00'], ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'credit' => '5.00']], 'ajuste'));
        // I1 physical vocabulary: the clearing accounts are INTERUNIT_CONTROL, never INCOME/EXPENSE/EQUITY.
        $this->assertSame(['INTERUNIT_CONTROL', 'INTERUNIT_CONTROL'], $this->db()->table('chart_of_accounts')->whereIn('system_role', ['INTERUNIT_CLEARING_OUT', 'INTERUNIT_CLEARING_IN'])->orderBy('system_role')->pluck('account_kind')->all());
        $this->assertSame(6, $this->db()->table('financial_categories as c')->join('chart_of_accounts as a', 'a.id', '=', 'c.ledger_account_id')->where('c.economic_nature', 'INTERNAL_TRANSFER')->where('a.account_kind', 'INTERUNIT_CONTROL')->count());
    }

    public function test_j11_receive_touches_only_the_destination_and_never_the_result(): void
    {
        $w = $this->finWorld();
        $this->revenue($w, $w['c'], $w['cashC'], '100.00', '2026-02-19');
        $send = $this->send($w, '60.00', '2026-03-11');
        $receive = $this->receive($w, $send, '2026-03-12');
        $this->assertSame([$w['x']], $this->db()->table('journal_lines')->where('entry_id', $receive['id'])->distinct()->pluck('unit_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, $this->queries()->economicResult($w['x'], '2026-03-01', '2026-03-31')['result'], 'a receipt is not revenue');
        $this->assertSame(['sent' => 0, 'received' => 6000, 'net' => 6000], $this->queries()->interunitPosition($w['x'], '2026-03-31'));
        $this->assertSame(6000, $this->queries()->financialAccountBalance($w['cashX']));
        $t = $this->db()->table('internal_transfers')->where('id', $send['transfer'])->first();
        $this->assertSame(['RECEIVED', $w['cashX']], [$t->status, (int) $t->destination_account_id]);
        $this->assertSame(['RECEIVE', 'SEND'], $this->db()->table('transfer_postings')->where('transfer_id', $send['transfer'])->orderBy('posting_stage')->pluck('posting_stage')->all());
        $this->sqlError(1062, 'uq_transfer_postings_transfer_id_posting_stage', fn () => $this->db()->table('transfer_postings')->insert(['transfer_id' => $send['transfer'], 'posting_stage' => 'RECEIVE', 'entry_id' => $this->rawEntry($w['x'], 'TRANSFER_RECEIVE'), 'created_at' => $this->ts(), 'lock_version' => 0]));
        $this->sqlError(3819, 'ck_transfer_postings_posting_stage', fn () => $this->db()->table('transfer_postings')->insert(['transfer_id' => $send['transfer'], 'posting_stage' => 'REVERSE_RECEIVE', 'entry_id' => $this->rawEntry($w['x'], 'TRANSFER_RECEIVE'), 'created_at' => $this->ts(), 'lock_version' => 0]));
        $this->sqlError(1062, 'uq_transfer_postings_entry_id', fn () => $this->db()->table('transfer_postings')->insert(['transfer_id' => $this->rawTransfer($w, []), 'posting_stage' => 'SEND', 'entry_id' => $send['entry']['id'], 'created_at' => $this->ts(), 'lock_version' => 0]));
        $this->refused('TRANSFER_TOUCHES_RESULT', fn () => $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u2'], $this->key(), ['unit_id' => $w['x'], 'entry_kind' => 'TRANSFER_RECEIVE', 'entry_date' => '2026-03-12', 'description' => 'x', 'lines' => [
            ['account' => 'CASH', 'financial_account_id' => $w['cashX'], 'debit' => '5.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '5.00']]])));
        // The destination can never post with the origin's cash (owner coherence, service + composite FK).
        $this->refused('UNIT_MISMATCH', fn () => $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u2'], $this->key(), ['unit_id' => $w['x'], 'entry_kind' => 'TRANSFER_RECEIVE', 'entry_date' => '2026-03-12', 'description' => 'x', 'lines' => [
            ['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'debit' => '5.00'], ['account' => 'INTERUNIT_CLEARING_IN', 'counterparty_unit_id' => $w['c'], 'category' => 'TRF_SUPPORT', 'credit' => '5.00']]])));
    }

    public function test_j12_in_transit_is_a_consistent_state_and_the_normative_example_never_duplicates_revenue(): void
    {
        $w = $this->finWorld();
        // D-04A.3 (F-D): Congregação C receives 100 000 externally, sends 60 000 to Centro X, X sends 40 000 to Município M.
        $this->revenue($w, $w['c'], $w['cashC'], '100000.00', '2026-02-20');
        $t1 = $this->send($w, '60000.00', '2026-03-01');
        $this->receive($w, $t1, '2026-03-02');
        $t2 = $this->send($w, '40000.00', '2026-03-03', 'x', 'm', 'TRF_REMITTANCE');
        // t2 is SENT and not yet received: a consistent in-transit state.
        $t = $this->db()->table('internal_transfers')->where('id', $t2['transfer'])->first();
        $this->assertSame(['SENT', null, null], [$t->status, $t->destination_account_id, $t->received_at]);
        $this->assertSame(['SEND'], $this->db()->table('transfer_postings')->where('transfer_id', $t2['transfer'])->pluck('posting_stage')->all());
        $this->assertSame(['amount' => 4000000, 'transfers' => [$t->public_id]], $this->queries()->inTransit('2026-03-31', [$w['x']]));
        $this->sqlError(3819, 'ck_internal_transfers_received', fn () => $this->db()->table('internal_transfers')->where('id', $t2['transfer'])->update(['status' => 'RECEIVED']));
        $this->sqlError(3819, 'ck_internal_transfers_received', fn () => $this->db()->table('internal_transfers')->where('id', $t2['transfer'])->update(['destination_account_id' => $w['cashM']]));
        $units = [$w['c'], $w['x'], $w['m']];
        $revenue = array_sum(array_map(fn ($u) => $this->queries()->economicResult($u, '2026-01-01', '2026-12-31')['income'], $units));
        $this->assertSame(10000000, $revenue, 'perimeter revenue 100 000 (never 160 000 / 200 000)');
        $cash = array_sum(array_map(fn ($a) => $this->queries()->financialAccountBalance($a), [$w['cashC'], $w['cashX'], $w['cashM']]));
        $this->assertSame(10000000 - 4000000, $cash);
        $this->assertSame(10000000, $cash + $this->queries()->inTransit('2026-03-31', $units)['amount'], 'cash + in transit = the external revenue');
        $net = array_sum(array_map(fn ($u) => $this->queries()->interunitPosition($u, '2026-03-31')['net'], $units));
        $this->assertSame(-4000000, $net, 'D-04A.4: the perimeter interunit position = -(in transit)');
        // Receipt later: in transit disappears, still no revenue.
        $this->receive($w, $t2, '2026-04-02', 'm');
        $this->assertSame(0, $this->queries()->inTransit('2026-04-30', $units)['amount']);
        $this->assertSame(0, array_sum(array_map(fn ($u) => $this->queries()->interunitPosition($u, '2026-04-30')['net'], $units)));
        // Own reports (D-04A.3): C = external revenue 100 000, remitted 60 000; X = received 60 000, remitted 40 000; M = received 40 000.
        $own = fn (int $u) => [$this->queries()->economicResult($u, '2026-01-01', '2026-12-31')['income'], $this->queries()->interunitPosition($u, '2026-04-30')['received'], $this->queries()->interunitPosition($u, '2026-04-30')['sent']];
        $this->assertSame([[10000000, 0, 6000000], [0, 6000000, 4000000], [0, 4000000, 0]], array_map($own, $units));
        $this->assertSame([4000000, 2000000, 4000000], [$this->queries()->financialAccountBalance($w['cashC']), $this->queries()->financialAccountBalance($w['cashX']), $this->queries()->financialAccountBalance($w['cashM'])]);
        $this->assertSame([0, 0, 0], array_map(fn ($u) => $this->queries()->economicResult($u, '2026-03-01', '2026-04-30')['result'], $units), 'no transfer stage created a result');
    }

    public function test_j13_every_operational_row_has_an_explicit_indexed_owner_unit_never_a_department(): void
    {
        foreach (['accounts' => 'unit_id', 'journal_entries' => 'unit_id', 'journal_lines' => 'unit_id', 'accounting_period_unit_closes' => 'unit_id', 'internal_transfers' => 'origin_unit_id',
            'receivables' => 'unit_id', 'payables' => 'unit_id', 'budgets' => 'unit_id'] as $table => $column) {
            $col = $this->db()->selectOne('SELECT IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
            $this->assertSame('NO', $col->n, $table . '.' . $column);
            $this->assertGreaterThan(0, (int) $this->db()->selectOne('SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1', [$table, $column])->c, $table . ' owner indexed');
        }
        $tables = "'" . implode("','", self::P010_TABLES) . "'";
        $this->assertSame([], $this->db()->select("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($tables) AND COLUMN_NAME LIKE '%department%' AND TABLE_NAME <> 'financial_parties'"), 'a department never owns finance data (D18)');
        $w = $this->finWorld();
        $entry = $this->revenue($w, $w['c'], $w['cashC'], '10.00', '2026-02-21');
        // Physical owner coherence: a line of C's entry cannot carry another unit, nor C's line another unit's cash.
        $this->sqlError(1452, 'fk_journal_lines_entry_unit', fn () => $this->rawLine($entry['id'], $w['x'], $w['roles']['OPENING_NET_ASSETS'], '1', '0', 90));
        $this->sqlError(1452, 'fk_journal_lines_unit_financial_account', fn () => $this->rawLine($entry['id'], $w['c'], $w['roles']['CASH'], '1', '0', 91, $w['cashX']));
        $this->refused('UNIT_MISMATCH', fn () => $this->manual($w, 'REVENUE', '2026-02-21', [['account' => 'CASH', 'financial_account_id' => $w['cashX'], 'debit' => '1.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '1.00']]));
        $this->sqlError(1452, 'fk_internal_transfers_origin_unit_account', fn () => $this->rawTransfer($w, ['origin_account_id' => $w['cashX']]));
        $this->sqlError(3819, 'ck_internal_transfers_units', fn () => $this->rawTransfer($w, ['destination_unit_id' => $w['c']]));
        $this->db()->table('organizational_units')->where('id', $w['m'])->update(['status' => 'CLOSED']);
        $this->refused('UNIT_NOT_ACTIVE', fn () => $this->manual($w, 'REVENUE', '2026-02-21', [['account' => 'CASH', 'financial_account_id' => $w['cashM'], 'debit' => '1.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '1.00']], null, $w['m']));
    }

    public function test_j14_closed_periods_refuse_postings_units_close_independently_and_the_national_close_is_final(): void
    {
        $w = $this->finWorld();
        $periods = new FinancePeriods($this->db());
        $svc = $this->ledger();
        $pending = $svc->createDraft($w['u1'], $this->key(), $this->revenueInput($w['c'], $w['cashC'], '5.00', '2026-06-03'));
        $this->refused('PERIOD_HAS_PENDING_ENTRIES', fn () => $periods->closeUnit($w['u1'], '2026-06', $w['c']));
        $svc->discard($w['u1'], $pending['public_id'], 0);
        $submitted = $svc->createDraft($w['u1'], $this->key(), $this->revenueInput($w['c'], $w['cashC'], '7.00', '2026-06-04'));
        $svc->submit($w['u1'], $submitted['public_id'], 0);
        $svc->discard($w['u1'], $submitted['public_id'], 1);
        $this->revenue($w, $w['c'], $w['cashC'], '10.00', '2026-06-05');
        $periods->closeUnit($w['u1'], '2026-06', $w['c']);
        $this->refused('PERIOD_CLOSED', fn () => $this->revenue($w, $w['c'], $w['cashC'], '1.00', '2026-06-06'));
        $this->refused('PERIOD_CLOSED', fn () => $svc->createDraft($w['u1'], $this->key(), $this->revenueInput($w['c'], $w['cashC'], '1.00', '2026-06-06')));
        $this->refused('PERIOD_CLOSED', fn () => $this->db()->transaction(fn () => $svc->postSubledgerEntry($w['u1'], $this->key(), $this->sendInput($w, '1.00', '2026-06-06'))));
        // Independence: X still posts in June; C still posts in July.
        $this->revenue($w, $w['x'], $w['cashX'], '3.00', '2026-06-06');
        $this->revenue($w, $w['c'], $w['cashC'], '2.00', '2026-07-01');
        // Reopen (other user) -> posting allowed again -> re-close.
        $periods->reopenUnit($w['u2'], '2026-06', $w['c'], 'lançamento em falta');
        $this->revenue($w, $w['c'], $w['cashC'], '4.00', '2026-06-07');
        $periods->closeUnit($w['u1'], '2026-06', $w['c']);
        // Not in the future, not outside a MONTH period, not in a missing period.
        $tomorrow = (new DateTimeImmutable('now', new DateTimeZone('Africa/Luanda')))->modify('+1 day')->format('Y-m-d');
        if (str_starts_with($tomorrow, '2026-')) {
            $this->refused('ENTRY_DATE_IN_FUTURE', fn () => $this->revenue($w, $w['x'], $w['cashX'], '1.00', $tomorrow));
        }
        $this->refused('PERIOD_NOT_FOUND', fn () => $this->revenue($w, $w['x'], $w['cashX'], '1.00', '2019-05-05'));

        // National close of 2026-01 (reserved month): lists the units still open, then is final.
        $this->revenue($w, $w['m'], $w['cashM'], '9.00', '2026-01-15');
        $this->refused('UNITS_NOT_CLOSED', fn () => $periods->closeNational($w['u1'], '2026-01', $w['c']));
        try {
            $periods->closeNational($w['u1'], '2026-01', $w['c']);
        } catch (FinanceError $e) {
            $this->assertContains($this->db()->table('organizational_units')->where('id', $w['m'])->value('public_id'), $e->items);
        }
        foreach ($this->db()->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')->join('accounting_periods as p', 'p.id', '=', 'e.period_id')->where('p.code', '2026-01')->where('e.status', 'POSTED')->distinct()->pluck('l.unit_id') as $unit) {
            if (!$this->db()->table('accounting_period_unit_closes')->where('period_id', $this->db()->table('accounting_periods')->where('code', '2026-01')->value('id'))->where('unit_id', $unit)->where('status', 'CLOSED')->exists()) {
                $periods->closeUnit($w['u1'], '2026-01', (int) $unit);
            }
        }
        $periods->closeNational($w['u1'], '2026-01', $w['c']);
        $this->assertSame('CLOSED', $this->db()->table('accounting_periods')->where('code', '2026-01')->value('status'));
        $this->refused('PERIOD_CLOSED', fn () => $this->revenue($w, $w['x'], $w['cashX'], '1.00', '2026-01-20'));
        $this->refused('PERIOD_CLOSED', fn () => $periods->reopenUnit($w['u2'], '2026-01', $w['m'], 'tarde demais'));
        $this->refused('PERIOD_CLOSED', fn () => $periods->closeNational($w['u1'], '2026-01', $w['c']));
        $this->assertFalse(method_exists(FinancePeriods::class, 'reopenNational'), 'no national reopen path in V1');
        $this->sqlError(3819, 'ck_accounting_periods_closed', fn () => $this->db()->table('accounting_periods')->where('code', '2026-01')->update(['closed_at' => null]));
        $this->assertSame('finance.period_national_closed', $this->db()->table('audit_logs')->where('source', 'P010_FINANCE')->where('entity_type', 'accounting_periods')->orderByDesc('id')->value('action'));
    }

    public function test_j15_money_is_exact_two_decimals_never_rounded_never_float(): void
    {
        $this->assertSame(123456, Money::cents('1234.56'));
        $this->assertSame(123450, Money::cents('1234.5'));
        $this->assertSame(500, Money::cents(5));
        $this->assertSame(Money::MAX_CENTS, Money::cents('999999999999.99'));
        foreach (['1.001' => 'AMOUNT_SCALE', '0.005' => 'AMOUNT_SCALE', '10.0000' => 'AMOUNT_SCALE', '-1.00' => 'AMOUNT_NOT_POSITIVE', '0' => 'AMOUNT_NOT_POSITIVE', '1e3' => 'AMOUNT_INVALID',
            '1,50' => 'AMOUNT_INVALID', ' 1.00' => 'AMOUNT_INVALID', '1000000000000.00' => 'AMOUNT_LIMIT'] as $input => $reason) {
            $this->refused($reason, fn () => Money::cents((string) $input), (string) $input);
        }
        try {
            Money::cents(1.5); // @phpstan-ignore-line: a float is a type error under strict_types, never silently accepted
            $this->fail('a float must be refused');
        } catch (\TypeError) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('1234.50', Money::format(123450));
        $this->assertSame(-1, Money::fromDecimal('-0.0100'));
        $this->refused('AMOUNT_SCALE', fn () => Money::fromDecimal('1.0050'));
        $w = $this->finWorld();
        $this->refused('AMOUNT_SCALE', fn () => $this->manual($w, 'REVENUE', '2026-02-22', [['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'debit' => '10.001'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '10.001']]));
        $entry = $this->revenue($w, $w['c'], $w['cashC'], '0.10', '2026-02-22');
        $this->sqlError(3819, 'ck_journal_lines_scale', fn () => $this->rawLine($entry['id'], $w['c'], $w['roles']['OPENING_NET_ASSETS'], '1.0050', '0', 92));
        $this->sqlError(3819, 'ck_journal_lines_limit', fn () => $this->rawLine($entry['id'], $w['c'], $w['roles']['OPENING_NET_ASSETS'], '1000000000000.00', '0', 93));
        // 0.10 + 0.20 == 0.30 exactly (the float trap).
        $this->manual($w, 'REVENUE', '2026-02-22', [['account' => 'CASH', 'financial_account_id' => $w['cashC'], 'debit' => '0.20'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '0.20']]);
        $this->assertSame(30, $this->queries()->financialAccountBalance($w['cashC']));
    }

    public function test_j16_only_aoa_is_installed_and_any_other_currency_is_refused(): void
    {
        $this->assertSame(['AOA'], $this->db()->table('currencies')->pluck('code')->all());
        $w = $this->finWorld();
        $usd = (int) $this->db()->table('currencies')->insertGetId(['code' => 'SYN_USD', 'name' => 'synthetic', 'minor_units' => 2, 'is_active' => 1, 'created_at' => $this->ts(), 'lock_version' => 0]);
        try {
            $draft = $this->ledger()->createDraft($w['u1'], $this->key(), ['currency' => 'SYN_USD'] + $this->revenueInput($w['c'], $w['cashC'], '1.00', '2026-02-23'));
            $this->refused('CURRENCY_NOT_SUPPORTED', fn () => $this->ledger()->post($w['u1'], $draft['public_id'], 0));
            $foreign = $this->account($w['c'], 'CASH', $usd);
            $this->refused('CURRENCY_NOT_SUPPORTED', fn () => $this->manual($w, 'REVENUE', '2026-02-23', [['account' => 'CASH', 'financial_account_id' => $foreign, 'debit' => '1.00'], ['account' => 'OPERATING_INCOME', 'category' => 'REV_OTHER', 'credit' => '1.00']]));
            $this->refused('CURRENCY_NOT_SUPPORTED', fn () => $this->ledger()->createDraft($w['u1'], $this->key(), ['currency' => 'EUR'] + $this->revenueInput($w['c'], $w['cashC'], '1.00', '2026-02-23')));
            $this->ledger()->discard($w['u1'], $draft['public_id'], 0);
        } finally {
            $this->assertSame(0, $this->db()->table('journal_entries')->where('currency_id', $usd)->where('status', 'POSTED')->count());
        }
    }

    // ---- C: real-process concurrency --------------------------------------------------------------------------------

    public function test_c1_two_processes_posting_the_same_entry_publish_it_once_and_a_replayed_subledger_entry_is_one_entry(): void
    {
        $w = $this->finWorld();
        $draft = $this->ledger()->createDraft($w['u1'], $this->key(), $this->revenueInput($w['c'], $w['cashC'], '50.00', '2026-08-03'));
        $this->ledger()->submit($w['u1'], $draft['public_id'], 0);
        $results = $this->race([['op' => 'post', 'user' => $w['u2'], 'entry' => $draft['public_id'], 'lock_version' => 1, 'hold_ms' => 350],
            ['op' => 'post', 'user' => $w['u2'], 'entry' => $draft['public_id'], 'lock_version' => 1, 'hold_ms' => 350]]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['ALREADY_POSTED', 'OK'], $statuses);
        $this->assertSame(5000, $this->queries()->financialAccountBalance($w['cashC']), 'posted exactly once');
        $this->assertSame(1, $this->db()->table('audit_logs')->where('source', 'P010_FINANCE')->where('entity_id', $draft['id'])->where('action', 'finance.entry_posted')->count());

        $key = $this->key();
        $input = $this->sendInput($w, '20.00', '2026-08-04');
        $results = $this->race([['op' => 'subledger', 'user' => $w['u1'], 'key' => $key, 'input' => $input, 'hold_ms' => 350], ['op' => 'subledger', 'user' => $w['u1'], 'key' => $key, 'input' => $input, 'hold_ms' => 350]]);
        $this->assertSame(['OK', 'OK'], array_column($results, 'status'));
        $this->assertSame($results[0]['result']['public_id'], $results[1]['result']['public_id'], 'same idempotency key = same entry');
        $this->assertSame(1, $this->db()->table('journal_entries')->where('public_id', $results[0]['result']['public_id'])->count());
        $this->assertSame(3000, $this->queries()->financialAccountBalance($w['cashC']));
        $this->assertNotSame($results[0]['pid'], $results[1]['pid']);
        $this->evidence('C1', ['post' => $statuses, 'idempotent' => $results]);
    }

    /**
     * C3-A close first. Deterministic: the CLOSE connection runs closeUnit and HOLDS its transaction (national period row
     * locked FOR UPDATE); only after the POST connection is OBSERVED in LOCK WAIT on accounting_periods does the close
     * commit. The decision therefore follows who legitimately acquired the lock first, not timing.
     */
    public function test_c3a_close_first_a_waiting_posting_is_refused_and_leaves_nothing(): void
    {
        $w = $this->finWorld();
        $period = (int) $this->db()->table('accounting_periods')->where('code', '2026-09')->value('id');
        $key = $this->key();
        [$close, $post, $wait] = $this->lockOrdered(['op' => 'close_unit', 'user' => $w['u2'], 'period' => '2026-09', 'unit' => $w['c']],
            ['op' => 'subledger', 'user' => $w['u1'], 'key' => $key, 'input' => $this->sendInput($w, '1.00', '2026-09-01')]);
        $this->assertSame('OK', $close['status']);
        $this->assertSame('PERIOD_CLOSED', $post['status'], 'the posting that waited for the close is denied');
        $this->assertSame(['accounting_periods', true], [$wait['object'], $wait['blocked_by_first']], 'the posting waited on the national period row held by the close');
        $this->assertSame(0, $this->db()->table('journal_entries')->where('unit_id', $w['c'])->count(), 'no header, not even partially');
        $this->assertSame(0, $this->db()->table('journal_lines')->where('unit_id', $w['c'])->count(), 'no line');
        $this->assertSame(0, $this->db()->table('idempotency_requests')->where('actor_id', $w['u1'])->where('client_key', $key)->count(), 'no idempotency residue');
        $this->assertSame('CLOSED', $this->db()->table('accounting_period_unit_closes')->where('period_id', $period)->where('unit_id', $w['c'])->value('status'));
        $this->refused('PERIOD_CLOSED', fn () => $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), $this->sendInput($w, '1.00', '2026-09-02'))));
        $this->evidence('C3A', ['close' => $close, 'post' => $post, 'observed_lock_wait' => $wait]);
    }

    /**
     * C3-B posting first. Deterministic: the POST connection posts and HOLDS its transaction (period row FOR SHARE); the
     * CLOSE connection is observed in LOCK WAIT on accounting_periods, then the posting commits and the close proceeds.
     */
    public function test_c3b_posting_first_the_close_waits_for_its_commit_and_then_closes(): void
    {
        $w = $this->finWorld();
        $period = (int) $this->db()->table('accounting_periods')->where('code', '2026-09')->value('id');
        [$post, $close, $wait] = $this->lockOrdered(['op' => 'subledger', 'user' => $w['u1'], 'key' => $this->key(), 'input' => $this->sendInput($w, '2.00', '2026-09-01')],
            ['op' => 'close_unit', 'user' => $w['u2'], 'period' => '2026-09', 'unit' => $w['c']]);
        $this->assertSame('OK', $post['status']);
        $this->assertSame('OK', $close['status'], 'the close waited for the posting commit and then succeeded');
        $this->assertSame(['accounting_periods', true], [$wait['object'], $wait['blocked_by_first']], 'the close waited on the national period row held by the posting');
        $entry = $this->db()->table('journal_entries')->where('public_id', $post['result']['public_id'])->first();
        $closed = $this->db()->table('accounting_period_unit_closes')->where('period_id', $period)->where('unit_id', $w['c'])->first();
        $this->assertSame(['POSTED', 'CLOSED'], [$entry->status, $closed->status]);
        $this->assertLessThan($closed->closed_at, $entry->posted_at, 'the posting committed before the close');
        $this->assertSame([200, 200], $this->sums((int) $entry->id), 'ledger balanced');
        $this->assertSame(1, $this->db()->table('journal_entries')->where('unit_id', $w['c'])->where('period_id', $period)->count());
        $this->refused('PERIOD_CLOSED', fn () => $this->db()->transaction(fn () => $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), $this->sendInput($w, '1.00', '2026-09-02'))));
        $this->evidence('C3B', ['post' => $post, 'close' => $close, 'observed_lock_wait' => $wait]);
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    private function finWorld(): array
    {
        $roles = FinanceCatalog::roleIds($this->db());
        $aoa = (int) $this->db()->table('currencies')->where('code', 'AOA')->value('id');
        $w = ['roles' => $roles, 'aoa' => $aoa, 'u1' => $this->row('users'), 'u2' => $this->row('users')];
        foreach (['c', 'x', 'm'] as $u) {
            $w[$u] = $this->row('organizational_units', ['status' => 'ACTIVE']);
        }
        $w['cashC'] = $this->account($w['c'], 'CASH');
        $w['cashX'] = $this->account($w['x'], 'CASH');
        $w['cashM'] = $this->account($w['m'], 'CASH');
        return $w;
    }

    private function account(int $unit, string $kind, ?int $currency = null): int
    {
        return (int) $this->db()->table('accounts')->insertGetId(['public_id' => (string) Str::ulid(), 'unit_id' => $unit, 'ledger_account_id' => FinanceCatalog::roleIds($this->db())[$kind],
            'currency_id' => $currency ?? (int) $this->db()->table('currencies')->where('code', 'AOA')->value('id'), 'code' => $kind . '-' . Str::random(8), 'name' => $kind,
            'account_kind' => $kind, 'status' => 'OPEN', 'opened_on' => '2026-01-01', 'closed_on' => null, 'created_at' => $this->ts(), 'lock_version' => 0]);
    }

    private function revenueInput(int $unit, int $cash, string $amount, string $date, string $category = 'REV_TITHES'): array
    {
        return ['unit_id' => $unit, 'entry_kind' => 'REVENUE', 'entry_date' => $date, 'description' => 'Receita ' . $category, 'lines' => [
            ['account' => 'CASH', 'financial_account_id' => $cash, 'debit' => $amount], ['account' => 'OPERATING_INCOME', 'category' => $category, 'credit' => $amount]]];
    }

    /** Draft + post by a second user (maker != checker is not required, only exercised). */
    private function revenue(array $w, int $unit, int $cash, string $amount, string $date): array
    {
        $draft = $this->ledger()->createDraft($w['u1'], $this->key(), $this->revenueInput($unit, $cash, $amount, $date));
        $this->ledger()->post($w['u2'], $draft['public_id'], 0);
        return $draft;
    }

    private function manual(array $w, string $kind, string $date, array $lines, ?string $reason = null, ?int $unit = null): array
    {
        $draft = $this->ledger()->createDraft($w['u1'], $this->key(), ['unit_id' => $unit ?? $w['c'], 'entry_kind' => $kind, 'entry_date' => $date, 'description' => $kind, 'reason' => $reason, 'lines' => $lines]);
        $this->ledger()->post($w['u1'], $draft['public_id'], 0);
        return $draft;
    }

    private function sendInput(array $w, string $amount, string $date, ?string $credit = null, string $from = 'c', string $to = 'x', string $purpose = 'TRF_SUPPORT'): array
    {
        return ['unit_id' => $w[$from], 'entry_kind' => 'TRANSFER_SEND', 'entry_date' => $date, 'description' => 'Envio', 'lines' => [
            ['account' => 'INTERUNIT_CLEARING_OUT', 'counterparty_unit_id' => $w[$to], 'category' => $purpose, 'debit' => $amount],
            ['account' => 'CASH', 'financial_account_id' => $w['cash' . strtoupper($from)], 'credit' => $credit ?? $amount]]];
    }

    /** The F1A transfer STRUCTURE (F1B owns the workflow/service): SENT transfer + SEND entry + transfer_postings, one transaction. */
    private function send(array $w, string $amount, string $date, string $from = 'c', string $to = 'x', string $purpose = 'TRF_SUPPORT'): array
    {
        return $this->db()->transaction(function () use ($w, $amount, $date, $from, $to, $purpose): array {
            $transfer = $this->rawTransfer($w, ['origin_unit_id' => $w[$from], 'destination_unit_id' => $w[$to], 'origin_account_id' => $w['cash' . strtoupper($from)], 'amount' => $amount, 'category_id' => $this->cat($purpose)]);
            $entry = $this->ledger()->postSubledgerEntry($w['u1'], $this->key(), $this->sendInput($w, $amount, $date, null, $from, $to, $purpose));
            $this->db()->table('internal_transfers')->where('id', $transfer)->update(['status' => 'SENT', 'sent_at' => $date . ' 10:00:00']);
            $this->db()->table('transfer_postings')->insert(['transfer_id' => $transfer, 'posting_stage' => 'SEND', 'entry_id' => $entry['id'], 'created_at' => $this->ts(), 'lock_version' => 0]);
            return ['transfer' => $transfer, 'entry' => $entry, 'amount' => $amount, 'from' => $from, 'to' => $to, 'purpose' => $purpose];
        });
    }

    private function receive(array $w, array $send, string $date, ?string $to = null): array
    {
        $to ??= $send['to'];
        return $this->db()->transaction(function () use ($w, $send, $date, $to): array {
            $entry = $this->ledger()->postSubledgerEntry($w['u2'], $this->key(), ['unit_id' => $w[$to], 'entry_kind' => 'TRANSFER_RECEIVE', 'entry_date' => $date, 'description' => 'Recepção', 'lines' => [
                ['account' => 'CASH', 'financial_account_id' => $w['cash' . strtoupper($to)], 'debit' => $send['amount']],
                ['account' => 'INTERUNIT_CLEARING_IN', 'counterparty_unit_id' => $w[$send['from']], 'category' => $send['purpose'], 'credit' => $send['amount']]]]);
            $this->db()->table('internal_transfers')->where('id', $send['transfer'])->update(['status' => 'RECEIVED', 'received_at' => $date . ' 11:00:00', 'reconciled_at' => $date . ' 11:00:00', 'destination_account_id' => $w['cash' . strtoupper($to)]]);
            $this->db()->table('transfer_postings')->insert(['transfer_id' => $send['transfer'], 'posting_stage' => 'RECEIVE', 'entry_id' => $entry['id'], 'created_at' => $this->ts(), 'lock_version' => 0]);
            return $entry;
        });
    }

    private function rawTransfer(array $w, array $values): int
    {
        $workflow = (int) $this->db()->table('workflows')->where('code', 'FINANCE_INTERNAL_TRANSFER')->value('id');
        $instance = $this->row('workflow_instances', ['workflow_id' => $workflow, 'unit_id' => $values['origin_unit_id'] ?? $w['c'], 'requested_by' => $w['u1']]);
        return (int) $this->db()->table('internal_transfers')->insertGetId($values + ['public_id' => (string) Str::ulid(), 'origin_unit_id' => $w['c'], 'destination_unit_id' => $w['x'],
            'origin_account_id' => $w['cashC'], 'destination_account_id' => null, 'currency_id' => $w['aoa'], 'fund_id' => (int) $this->db()->table('funds')->where('code', 'GENERAL')->value('id'),
            'category_id' => null, 'period_id' => (int) $this->db()->table('accounting_periods')->where('code', '2026-03')->value('id'), 'amount' => '1.00', 'status' => 'DRAFT',
            'workflow_instance_id' => $instance, 'created_at' => $this->ts(), 'lock_version' => 0]);
    }

    private function payable(array $w, string $amount): int
    {
        $workflow = (int) $this->db()->table('workflows')->where('code', 'FINANCE_PAYABLE')->value('id');
        $instance = $this->row('workflow_instances', ['workflow_id' => $workflow, 'unit_id' => $w['c'], 'requested_by' => $w['u1']]);
        $type = (int) $this->db()->table('legal_document_types')->where('code', 'INVOICE')->value('id');
        $document = $this->row('legal_documents', ['document_type_id' => $type, 'owner_unit_id' => $w['c'], 'status' => 'ACTIVE']);
        return (int) $this->db()->table('payables')->insertGetId(['public_id' => (string) Str::ulid(), 'party_id' => $this->party(), 'unit_id' => $w['c'], 'currency_id' => $w['aoa'],
            'amount' => $amount, 'due_on' => '2026-05-31', 'recognition_entry_id' => null, 'document_id' => $document, 'workflow_instance_id' => $instance, 'status' => 'PENDING', 'created_at' => $this->ts(), 'lock_version' => 0]);
    }

    private function party(): int
    {
        return (int) $this->db()->table('financial_parties')->insertGetId(['party_kind' => 'EXTERNAL', 'external_name' => 'Fornecedor sintético', 'status' => 'ACTIVE', 'created_at' => $this->ts(), 'lock_version' => 0]);
    }

    private function rawEntry(int $unit, string $kind, array $values = []): int
    {
        $actor = $this->row('users');
        return (int) $this->db()->table('journal_entries')->insertGetId($values + ['public_id' => (string) Str::ulid(), 'unit_id' => $unit, 'period_id' => (int) $this->db()->table('accounting_periods')->where('code', '2026-03')->value('id'),
            'currency_id' => (int) $this->db()->table('currencies')->where('code', 'AOA')->value('id'), 'entry_kind' => $kind, 'entry_date' => '2026-03-01', 'reference' => 'RAW-' . Str::ulid(),
            'description' => 'raw', 'status' => 'DRAFT', 'created_by' => $actor, 'idempotency_request_id' => $this->row('idempotency_requests', ['actor_id' => $actor]), 'created_at' => $this->ts(), 'lock_version' => 0]);
    }

    private function rawLine(int $entry, int $unit, int $ledger, string $debit, string $credit, int $number = 99, ?int $financial = null): void
    {
        $this->db()->table('journal_lines')->insert(['entry_id' => $entry, 'line_number' => $number, 'unit_id' => $unit, 'ledger_account_id' => $ledger, 'financial_account_id' => $financial,
            'fund_id' => (int) $this->db()->table('funds')->where('code', 'GENERAL')->value('id'), 'debit' => $debit, 'credit' => $credit, 'created_at' => $this->ts(), 'lock_version' => 0]);
    }

    private function sums(int $entry): array
    {
        $r = $this->db()->selectOne('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines WHERE entry_id = ?', [$entry]);
        return [Money::fromDecimal($r->d), Money::fromDecimal($r->c)];
    }

    private function roleOf(string $category): string
    {
        return (string) $this->db()->table('financial_categories as c')->join('chart_of_accounts as a', 'a.id', '=', 'c.ledger_account_id')->where('c.code', $category)->value('a.system_role');
    }

    private function cat(string $code): int
    {
        return (int) $this->db()->table('financial_categories')->where('code', $code)->value('id');
    }

    private function ledger(): LedgerPostingService
    {
        return new LedgerPostingService($this->db());
    }

    private function queries(): LedgerQueries
    {
        return new LedgerQueries($this->db());
    }

    private function key(): string
    {
        return 'k-' . Str::ulid();
    }

    private function ts(): string
    {
        return $this->now()->format('Y-m-d H:i:s.u');
    }

    private function manifest(): array
    {
        return json_decode((string) file_get_contents(self::$root . '/docs/database/physical/p010_finance_delta_manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function refused(string $reason, callable $fn, string $message = ''): void
    {
        try {
            $fn();
        } catch (FinanceError $e) {
            $this->assertSame($reason, $e->reason, $message . ' ' . $e->getMessage());
            return;
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($reason, $e->getMessage(), $message);
            return;
        }
        $this->fail('Expected refusal ' . $reason . ' ' . $message);
    }

    private function sqlError(int $errno, string $constraint, callable $fn): void
    {
        try {
            $fn();
        } catch (QueryException $e) {
            $this->assertSame($errno, (int) $e->errorInfo[1], $e->getMessage());
            $this->assertStringContainsString($constraint, $e->getMessage());
            return;
        }
        $this->fail('Expected SQL error ' . $errno . ' on ' . $constraint);
    }

    /** Each job in its own PHP process / MySQL connection, released together by a READY/go barrier. */
    private function race(array $jobs): array
    {
        $php = getenv('MEPA_PHP_BIN') ?: PHP_BINARY;
        $env = array_merge(getenv(), ['WAVE5_ALLOW_SYNTHETIC' => '1', 'XDEBUG_MODE' => 'off']);
        $procs = [];
        foreach ($jobs as $job) {
            $proc = proc_open([$php, self::$root . '/scripts/p010-finance-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$root, $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        foreach ($procs as [, $pipes]) {
            $line = str_replace("\r", '', (string) fgets($pipes[1]));
            if ($line !== "READY\n") {
                $this->fail('worker not ready: ' . $line . stream_get_contents($pipes[2]));
            }
        }
        foreach ($procs as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }
        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($proc), $err);
            $results[] = json_decode(trim((string) $out), true, 512, JSON_THROW_ON_ERROR);
        }
        foreach ($results as $result) {
            $this->assertNotSame('UNEXPECTED', $result['status'], json_encode($result));
        }
        return $results;
    }

    /**
     * Deterministic two-connection ordering. FIRST runs its operation and holds its transaction open (prints HELD: its locks
     * are acquired). SECOND is then released and must be OBSERVED in LOCK WAIT (information_schema.INNODB_TRX of its own
     * MySQL connection id; the awaited table from performance_schema.data_lock_waits). Only then FIRST commits.
     * @return array{0: array, 1: array, 2: array{object: ?string, mode: ?string, state: string}}
     */
    private function lockOrdered(array $first, array $second): array
    {
        $a = $this->spawn($first + ['hold_until_commit' => true, 'announce' => true]);
        $b = $this->spawn($second + ['announce' => true]);
        fwrite($a[1][0], "go\n");
        fflush($a[1][0]);
        $firstConnection = (int) substr(trim(str_replace("\r", '', (string) fgets($a[1][1]))), 8);
        $held = str_replace("\r", '', (string) fgets($a[1][1]));
        $this->assertSame("HELD\n", $held, 'FIRST must hold its locks: ' . $held);
        fwrite($b[1][0], "go\n");
        fclose($b[1][0]);
        $running = str_replace("\r", '', (string) fgets($b[1][1]));
        $this->assertMatchesRegularExpression('/^RUNNING \d+\n$/', $running);
        $connection = (int) substr(trim($running), 8);
        $wait = null;
        for ($i = 0; $i < 750 && $wait === null; $i++) {
            // performance_schema.data_lock_waits is the authoritative InnoDB wait graph (INNODB_TRX may still say RUNNING
            // while a const primary-key lookup waits during optimisation). The blocker must be FIRST's connection.
            $lock = $this->db()->selectOne('SELECT l.OBJECT_NAME AS o, l.LOCK_MODE AS m, bt.PROCESSLIST_ID AS blocker FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.threads bt ON bt.THREAD_ID = w.BLOCKING_THREAD_ID WHERE t.PROCESSLIST_ID = ?', [$connection]);
            if ($lock !== null) {
                $wait = ['object' => $lock->o, 'mode' => $lock->m, 'state' => 'LOCK WAIT', 'blocked_by_first' => (int) $lock->blocker === $firstConnection];
            } else {
                usleep(20000); // observation poll only; the outcome never depends on it
            }
        }
        $this->assertNotNull($wait, "SECOND was never observed waiting on FIRST's lock: " . json_encode([
            'trx' => $this->db()->select('SELECT trx_mysql_thread_id, trx_state, trx_query FROM information_schema.INNODB_TRX'),
            'process' => $this->db()->select('SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$connection]),
        ]));
        fwrite($a[1][0], "commit\n");
        fclose($a[1][0]);
        return [$this->finish($a), $this->finish($b), $wait];
    }

    private function spawn(array $job): array
    {
        $php = getenv('MEPA_PHP_BIN') ?: PHP_BINARY;
        $env = array_merge(getenv(), ['WAVE5_ALLOW_SYNTHETIC' => '1', 'XDEBUG_MODE' => 'off']);
        $proc = proc_open([$php, self::$root . '/scripts/p010-finance-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$root, $env);
        $this->assertIsResource($proc);
        $line = str_replace("\r", '', (string) fgets($pipes[1]));
        if ($line !== "READY\n") {
            $this->fail('worker not ready: ' . $line . stream_get_contents($pipes[2]));
        }
        return [$proc, $pipes];
    }

    private function finish(array $worker): array
    {
        [$proc, $pipes] = $worker;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $err);
        $result = json_decode(trim((string) $out), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotSame('UNEXPECTED', $result['status'], json_encode($result));
        return $result;
    }

    private function evidence(string $name, array $data): void
    {
        $dir = getenv('P010_EVIDENCE_DIR');
        if (is_string($dir) && $dir !== '' && is_dir($dir)) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . 'concurrency-' . $name . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        }
    }
}
