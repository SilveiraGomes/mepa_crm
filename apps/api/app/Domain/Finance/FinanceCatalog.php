<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Database\Connection;
use RuntimeException;

// Controlled vocabulary of ADR 0021 (D02, D06, D07, D08, D09, D10, D12, D13, D14, D31) and its addendum D-04A. The only
// source of these codes. install() is idempotent, inserts missing rows only and never updates or deletes. An existing
// code whose MEANING differs (permission data_type, account class/side/role, rubric nature/parent, currency scale) aborts
// the whole install with FINANCE_CATALOG_CONFLICT: data is reported, never repaired. The ledger mapping of a rubric is
// configurable (D-04A.12: an accountant may remap an INVESTMENT rubric between FIXED_ASSETS and INVESTMENT_EXPENSE), so it
// is not treated as a conflict. Run by migration 2026_09_30_100022 and by the test harness after a TRUNCATE reset.
// Nothing statutory is seeded: no period, no account, no rate, no role.
final class FinanceCatalog
{
    public const DATA_TYPE = 'FINANCE';
    public const AUDIT_SOURCE = 'P010_FINANCE';
    /** ADR 0009 ULID: the only external identifier of finance objects (D20). */
    public const PUBLIC_ID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';

    // ---- D02 money --------------------------------------------------------------------------------------------------
    public const CURRENCY = 'AOA';
    public const CURRENCY_NAME = 'Kwanza';
    public const MINOR_UNITS = 2;
    public const TIMEZONE = 'Africa/Luanda';

    // ---- D09 funds ---------------------------------------------------------------------------------------------------
    public const FUND_GENERAL = 'GENERAL';

    // ---- D31 permissions (installed only; no role is created or changed) ----------------------------------------------
    public const PERMISSIONS = [
        'FINANCE_VIEW', 'FINANCE_MANAGE', 'FINANCE_POST', 'FINANCE_REVERSE', 'FINANCE_ACCOUNT_MANAGE', 'FINANCE_TRANSFER',
        'FINANCE_RECONCILE', 'FINANCE_BUDGET_MANAGE', 'FINANCE_BUDGET_APPROVE', 'FINANCE_PERIOD_CLOSE', 'FINANCE_PERIOD_REOPEN',
        'FINANCE_REPORT', 'FINANCE_CONSOLIDATED_VIEW', 'FINANCE_CONTRIBUTOR_VIEW', 'FINANCE_PAYROLL_SUMMARY_VIEW', 'FINANCE_CATALOG_MANAGE',
    ];
    public const PERMISSION_CLASSIFICATION = 'CONFIDENTIAL';

    // ---- D08 + D-04A structural chart: system_role => [class, normal side, name] ------------------------------------
    public const ASSET = 'ASSET';
    public const LIABILITY = 'LIABILITY';
    public const EQUITY = 'EQUITY';
    public const INCOME = 'INCOME';
    public const EXPENSE = 'EXPENSE';
    public const INTERUNIT_CONTROL = 'INTERUNIT_CONTROL';
    public const ACCOUNT_CLASSES = [self::ASSET, self::LIABILITY, self::EQUITY, self::INCOME, self::EXPENSE, self::INTERUNIT_CONTROL];

    public const CHART = [
        'CASH' => [self::ASSET, 'DEBIT', 'Caixa (controlo)'],
        'BANK' => [self::ASSET, 'DEBIT', 'Bancos (controlo)'],
        'RECEIVABLES' => [self::ASSET, 'DEBIT', 'Valores a receber'],
        'IN_KIND_ASSETS' => [self::ASSET, 'DEBIT', 'Bens recebidos em espécie'],
        'FIXED_ASSETS' => [self::ASSET, 'DEBIT', 'Activos adquiridos (investimento capitalizado)'],
        'PAYABLES' => [self::LIABILITY, 'CREDIT', 'Valores a pagar'],
        'PAYROLL_NET_PAYABLE' => [self::LIABILITY, 'CREDIT', 'Salários líquidos a pagar'],
        'PAYROLL_WITHHOLDINGS' => [self::LIABILITY, 'CREDIT', 'Retenções salariais a entregar'],
        'PAYROLL_EMPLOYER_CHARGES' => [self::LIABILITY, 'CREDIT', 'Encargos da entidade a pagar'],
        'LOANS_PAYABLE' => [self::LIABILITY, 'CREDIT', 'Empréstimos a pagar'],
        'OPENING_NET_ASSETS' => [self::EQUITY, 'CREDIT', 'Património líquido inicial'],
        'INTERUNIT_CLEARING_OUT' => [self::INTERUNIT_CONTROL, 'DEBIT', 'Controlo interunidades — fundos enviados'],
        'INTERUNIT_CLEARING_IN' => [self::INTERUNIT_CONTROL, 'CREDIT', 'Controlo interunidades — fundos recebidos'],
        'OPERATING_INCOME' => [self::INCOME, 'CREDIT', 'Receitas operacionais'],
        'NON_OPERATING_INCOME' => [self::INCOME, 'CREDIT', 'Receitas não operacionais'],
        'OPERATING_EXPENSE' => [self::EXPENSE, 'DEBIT', 'Gastos operacionais'],
        'INVESTMENT_EXPENSE' => [self::EXPENSE, 'DEBIT', 'Investimento consumido'],
        'NON_OPERATING_EXPENSE' => [self::EXPENSE, 'DEBIT', 'Gastos não operacionais'],
    ];
    public const TREASURY_ROLES = ['CASH', 'BANK'];
    /** D06: financial account kind => the control account (system_role) it must post to. */
    public const ACCOUNT_KIND_ROLE = ['CASH' => 'CASH', 'BANK' => 'BANK'];

    // ---- D09 economic natures ------------------------------------------------------------------------------------------
    public const NATURES = [
        'OPERATING_REVENUE', 'COST_OF_SALES', 'ADMINISTRATIVE_EXPENSE', 'FINANCIAL_EXPENSE', 'PERSONNEL_EXPENSE', 'MATERIALS_EXPENSE',
        'INVESTMENT', 'NON_OPERATING_INCOME', 'NON_OPERATING_EXPENSE', 'INTERNAL_TRANSFER', 'BALANCE_SHEET',
    ];
    /** D-04A.12: the only control accounts an INVESTMENT rubric may map to (capitalized or consumed). */
    public const INVESTMENT_ROLES = ['FIXED_ASSETS', 'INVESTMENT_EXPENSE'];

    /**
     * D09 + D-04A.6 + D-04A.12 rubrics: group code => [name, [code => [name, nature, control role]]].
     * Names are the §16 texts; codes are stable (names may be validated by the accountant, non-blocking).
     * "Arrendamento" is deliberately NOT installed (P0-FIN pending classification).
     */
    public const CATEGORIES = [
        'GRP_16_1' => ['Receitas/entradas operacionais', [
            'REV_TITHES' => ['Dízimos', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_SELECTIVE_TITHES' => ['Dízimos Selectivos', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_OFFERINGS' => ['Ofertas', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_RAISED_OFFERINGS' => ['Ofertas Alçadas', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_SPECIAL_OFFERINGS' => ['Ofertas Especiais', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_BUDGET_QUOTAS' => ['Quotas Orçamentais', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_DEPARTMENT_QUOTAS' => ['Quotas de Departamento', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_CONTRIBUTIONS' => ['Contribuições', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_SELECTIVE_CONTRIBUTIONS' => ['Contribuições Selectivas', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_DONATIONS' => ['Doações', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_INVESTMENT_RETURN' => ['Retorno sobre Investimentos', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_OTHER' => ['Outras Entradas', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
            'REV_IN_KIND' => ['Contribuições em espécie valorizadas', 'OPERATING_REVENUE', 'OPERATING_INCOME'],
        ]],
        'GRP_16_2' => ['Custos com produtos e serviços', [
            'COS_SUPPLIERS' => ['Fornecedores', 'COST_OF_SALES', 'OPERATING_EXPENSE'],
            'COS_TRANSPORT' => ['Transportes', 'COST_OF_SALES', 'OPERATING_EXPENSE'],
            'COS_OUTSOURCED_LABOUR' => ['Mão-de-obra terceirizada', 'COST_OF_SALES', 'OPERATING_EXPENSE'],
        ]],
        'GRP_16_3' => ['Despesas administrativas', [
            'ADM_MOBILE' => ['Telemóveis', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_POSTAGE' => ['Correios', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_TAXI' => ['Táxi/Mototáxi', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_INTERNET' => ['Internet', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_TV' => ['TV Satélite/Cabo', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_ELECTRICITY' => ['Energia Eléctrica', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_FUEL' => ['Combustíveis', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_MEALS' => ['Almoço/Café/Lanches', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_WATER' => ['Água', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_CLEANING' => ['Limpeza', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_TRAINING' => ['Formação', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
            'ADM_OTHER' => ['Outras Despesas Administrativas', 'ADMINISTRATIVE_EXPENSE', 'OPERATING_EXPENSE'],
        ]],
        'GRP_16_4' => ['Despesas financeiras', [
            'FIN_BANK_FEES' => ['Tarifas Bancárias', 'FINANCIAL_EXPENSE', 'OPERATING_EXPENSE'],
            'FIN_OTHER' => ['Outras Despesas Financeiras', 'FINANCIAL_EXPENSE', 'OPERATING_EXPENSE'],
        ]],
        'GRP_16_5' => ['Despesas com pessoal', [
            'PER_SALARY' => ['Salário', 'PERSONNEL_EXPENSE', 'OPERATING_EXPENSE'],
            'PER_THIRTEENTH' => ['13.º Salário', 'PERSONNEL_EXPENSE', 'OPERATING_EXPENSE'],
            'PER_HOLIDAY_SUBSIDY' => ['Subsídio de Férias', 'PERSONNEL_EXPENSE', 'OPERATING_EXPENSE'],
            'PER_SOCIAL_ASSISTANCE' => ['Assistência Social', 'PERSONNEL_EXPENSE', 'OPERATING_EXPENSE'],
            'PER_INSS' => ['INSS', 'PERSONNEL_EXPENSE', 'OPERATING_EXPENSE'],
            'PER_HEALTH_PLAN' => ['Plano de Saúde', 'PERSONNEL_EXPENSE', 'OPERATING_EXPENSE'],
            'PER_TRANSPORT_MEAL' => ['Vale Transporte/Refeição', 'PERSONNEL_EXPENSE', 'OPERATING_EXPENSE'],
            'PER_OTHER' => ['Outras Despesas com Pessoal', 'PERSONNEL_EXPENSE', 'OPERATING_EXPENSE'],
        ]],
        'GRP_16_6' => ['Materiais e equipamentos', [
            'MAT_MATERIALS' => ['Materiais', 'MATERIALS_EXPENSE', 'OPERATING_EXPENSE'],
            'MAT_EQUIPMENT' => ['Equipamentos', 'MATERIALS_EXPENSE', 'OPERATING_EXPENSE'],
            'MAT_OTHER' => ['Outras despesas com Materiais e Equipamentos', 'MATERIALS_EXPENSE', 'OPERATING_EXPENSE'],
        ]],
        'GRP_16_7' => ['Investimento em comunicação/marketing', [
            'INV_COM_LEAFLETS' => ['Panfletos', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
            'INV_COM_POSTERS' => ['Cartazes/Folhetos', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
            'INV_COM_DIGITAL' => ['Site/Blogs/Redes Sociais', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
            'INV_COM_RADIO_TV' => ['Rádio/TV', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
            'INV_COM_EVENTS' => ['Eventos', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
            'INV_COM_OTHER' => ['Outros', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
        ]],
        'GRP_16_8' => ['Investimento em bens materiais', [
            'INV_AST_IT' => ['Equipamentos Informáticos', 'INVESTMENT', 'FIXED_ASSETS'],
            'INV_AST_INFRASTRUCTURE' => ['Reformas de Infraestruturas', 'INVESTMENT', 'FIXED_ASSETS'],
            'INV_AST_FURNITURE' => ['Mobiliário', 'INVESTMENT', 'FIXED_ASSETS'],
            'INV_AST_ELECTRICAL' => ['Equipamentos Eléctricos', 'INVESTMENT', 'FIXED_ASSETS'],
            'INV_AST_UNIFORMS' => ['Uniformes', 'INVESTMENT', 'FIXED_ASSETS'],
            'INV_AST_UTENSILS' => ['Utensílios', 'INVESTMENT', 'FIXED_ASSETS'],
            'INV_AST_OTHER' => ['Outros', 'INVESTMENT', 'FIXED_ASSETS'],
        ]],
        'GRP_16_9' => ['Investimento em desenvolvimento', [
            'INV_DEV_CONSULTING' => ['Consultoria', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
            'INV_DEV_TRAINING' => ['Treinamento', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
            'INV_DEV_BUSINESS' => ['Negócios', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
            'INV_DEV_REFRESH' => ['Refrescamento', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
            'INV_DEV_OTHER' => ['Outros investimentos de desenvolvimento', 'INVESTMENT', 'INVESTMENT_EXPENSE'],
        ]],
        'GRP_16_10' => ['Não operacionais', [
            'NOP_IN_USED_EQUIPMENT' => ['Venda de equipamentos usados', 'NON_OPERATING_INCOME', 'NON_OPERATING_INCOME'],
            'NOP_IN_OTHER' => ['Outras entradas não operacionais autorizadas', 'NON_OPERATING_INCOME', 'NON_OPERATING_INCOME'],
            'NOP_OUT_LATE_INTEREST' => ['Juros de mora', 'NON_OPERATING_EXPENSE', 'NON_OPERATING_EXPENSE'],
            'NOP_OUT_OTHER' => ['Outras saídas não operacionais', 'NON_OPERATING_EXPENSE', 'NON_OPERATING_EXPENSE'],
            'BS_LOAN_PRINCIPAL' => ['Pagamento de empréstimos (principal)', 'BALANCE_SHEET', 'LOANS_PAYABLE'],
            'BS_PAST_DEBTS' => ['Dívidas passadas', 'BALANCE_SHEET', 'PAYABLES'],
        ]],
        'GRP_TRF' => ['Transferências internas (finalidade)', [
            'TRF_REMITTANCE' => ['Remessa regular', 'INTERNAL_TRANSFER', 'INTERUNIT_CLEARING_OUT'],
            'TRF_BUDGET_QUOTA' => ['Quota orçamental entre unidades', 'INTERNAL_TRANSFER', 'INTERUNIT_CLEARING_OUT'],
            'TRF_SPECIAL_CONTRIBUTION' => ['Contribuição especial', 'INTERNAL_TRANSFER', 'INTERUNIT_CLEARING_OUT'],
            'TRF_SUPPORT' => ['Apoio', 'INTERNAL_TRANSFER', 'INTERUNIT_CLEARING_OUT'],
            'TRF_PROJECT' => ['Transferência de projecto', 'INTERNAL_TRANSFER', 'INTERUNIT_CLEARING_OUT'],
            'TRF_OTHER' => ['Outra', 'INTERNAL_TRANSFER', 'INTERUNIT_CLEARING_OUT'],
        ]],
    ];
    /** D-04A.6: transfer purposes; regular ones are "remessas regulares" in the DOAF. */
    public const TRANSFER_PURPOSES = ['TRF_REMITTANCE', 'TRF_BUDGET_QUOTA', 'TRF_SPECIAL_CONTRIBUTION', 'TRF_SUPPORT', 'TRF_PROJECT', 'TRF_OTHER'];
    public const REGULAR_TRANSFER_PURPOSES = ['TRF_REMITTANCE', 'TRF_BUDGET_QUOTA'];

    // ---- D14 legal document types (names institutional; codes stable) ------------------------------------------------
    public const DOCUMENT_TYPES = [
        'RECEIPT' => 'Recibo',
        'INVOICE' => 'Factura',
        'BANK_PROOF' => 'Comprovativo bancário',
        'EXPENSE_VOUCHER' => 'Documento de despesa',
        'TRANSFER_PROOF' => 'Comprovativo de transferência',
        'BANK_STATEMENT' => 'Extracto bancário',
        'VALUATION_REPORT' => 'Avaliação de bem em espécie',
        'PAYSLIP' => 'Recibo de vencimento',
        'PAYROLL_SHEET' => 'Folha salarial',
        'PAYROLL_RULE_SOURCE' => 'Fonte normativa de regra salarial',
    ];

    // ---- D10 / D32 workflows (the catalog FK workflow_instance_id is NOT NULL) ----------------------------------------
    public const WORKFLOWS = ['FINANCE_INTERNAL_TRANSFER' => 'Transferência interna entre unidades', 'FINANCE_PAYABLE' => 'Valor a pagar'];
    public const WORKFLOW_VERSION = 1;

    // ---- D07 journal entries -----------------------------------------------------------------------------------------
    public const DRAFT = 'DRAFT';
    public const SUBMITTED = 'SUBMITTED';
    public const POSTED = 'POSTED';
    public const DISCARDED = 'DISCARDED';
    public const ENTRY_STATUSES = [self::DRAFT, self::SUBMITTED, self::POSTED, self::DISCARDED];

    /** Forms a user may prepare as DRAFT (D07). */
    public const MANUAL_KINDS = ['REVENUE', 'EXPENSE', 'ACCOUNT_TRANSFER', 'OPENING_BALANCE', 'ADJUSTMENT'];
    /** Born POSTED inside their own subledger transaction; reversible only by their own flow (D07, D11). */
    public const SUBLEDGER_KINDS = [
        'CONTRIBUTION', 'PAYABLE_RECOGNITION', 'RECEIVABLE_RECOGNITION', 'SETTLEMENT', 'TRANSFER_SEND', 'TRANSFER_RECEIVE',
        'TRANSFER_REVERSE_SEND', 'PAYROLL_ACCRUAL', 'PAYROLL_PAYMENT', 'PAYROLL_REVERSAL', 'LIABILITY_PAYMENT',
    ];
    public const REVERSAL = 'REVERSAL';
    public const ENTRY_KINDS = [
        'REVENUE', 'EXPENSE', 'ACCOUNT_TRANSFER', 'OPENING_BALANCE', 'ADJUSTMENT', 'REVERSAL', 'CONTRIBUTION', 'PAYABLE_RECOGNITION',
        'RECEIVABLE_RECOGNITION', 'SETTLEMENT', 'TRANSFER_SEND', 'TRANSFER_RECEIVE', 'TRANSFER_REVERSE_SEND', 'PAYROLL_ACCRUAL',
        'PAYROLL_PAYMENT', 'PAYROLL_REVERSAL', 'LIABILITY_PAYMENT',
    ];
    /** Kinds that must point at the entry they reverse (reversal_of_id, UNIQUE). */
    public const REVERSING_KINDS = ['REVERSAL', 'TRANSFER_REVERSE_SEND', 'PAYROLL_REVERSAL'];
    public const REASON_REQUIRED_KINDS = ['REVERSAL', 'TRANSFER_REVERSE_SEND', 'PAYROLL_REVERSAL', 'ADJUSTMENT'];
    /** I1: transfers never touch INCOME/EXPENSE. */
    public const TRANSFER_KINDS = ['TRANSFER_SEND', 'TRANSFER_RECEIVE', 'TRANSFER_REVERSE_SEND', 'ACCOUNT_TRANSFER'];

    /**
     * Posting shape per entry kind: [allowed on DEBIT lines, allowed on CREDIT lines, exact line count or null].
     * A tag is a system_role (e.g. CASH) or an account class (INCOME, EXPENSE). The economic effect of a line comes from
     * its account class, never from the entry kind: an EXPENSE form paying a capitalizable rubric debits FIXED_ASSETS.
     * ADJUSTMENT accepts every postable account except INTERUNIT_CONTROL (interunit custody moves only by transfers).
     */
    public const SHAPES = [
        'REVENUE' => [['CASH', 'BANK'], ['INCOME'], null],
        'EXPENSE' => [['EXPENSE', 'FIXED_ASSETS'], ['CASH', 'BANK'], null],
        'ACCOUNT_TRANSFER' => [['CASH', 'BANK'], ['CASH', 'BANK'], null],
        'OPENING_BALANCE' => [['CASH', 'BANK', 'RECEIVABLES', 'IN_KIND_ASSETS', 'FIXED_ASSETS', 'OPENING_NET_ASSETS'], ['PAYABLES', 'LOANS_PAYABLE', 'PAYROLL_NET_PAYABLE', 'PAYROLL_WITHHOLDINGS', 'PAYROLL_EMPLOYER_CHARGES', 'OPENING_NET_ASSETS'], null],
        'ADJUSTMENT' => [['ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE'], ['ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE'], null],
        'CONTRIBUTION' => [['CASH', 'BANK', 'IN_KIND_ASSETS', 'EXPENSE', 'FIXED_ASSETS'], ['INCOME'], null],
        'RECEIVABLE_RECOGNITION' => [['RECEIVABLES'], ['INCOME'], null],
        'PAYABLE_RECOGNITION' => [['EXPENSE', 'FIXED_ASSETS'], ['PAYABLES'], null],
        'SETTLEMENT' => [['CASH', 'BANK', 'PAYABLES'], ['CASH', 'BANK', 'RECEIVABLES'], null],
        'TRANSFER_SEND' => [['INTERUNIT_CLEARING_OUT'], ['CASH', 'BANK'], 2],
        'TRANSFER_RECEIVE' => [['CASH', 'BANK'], ['INTERUNIT_CLEARING_IN'], 2],
        'TRANSFER_REVERSE_SEND' => [['CASH', 'BANK'], ['INTERUNIT_CLEARING_OUT'], 2],
        'PAYROLL_ACCRUAL' => [['EXPENSE'], ['PAYROLL_NET_PAYABLE', 'PAYROLL_WITHHOLDINGS', 'PAYROLL_EMPLOYER_CHARGES'], null],
        'PAYROLL_PAYMENT' => [['PAYROLL_NET_PAYABLE'], ['CASH', 'BANK'], null],
        'PAYROLL_REVERSAL' => [['PAYROLL_NET_PAYABLE', 'PAYROLL_WITHHOLDINGS', 'PAYROLL_EMPLOYER_CHARGES'], ['EXPENSE'], null],
        'LIABILITY_PAYMENT' => [['PAYROLL_WITHHOLDINGS', 'PAYROLL_EMPLOYER_CHARGES', 'LOANS_PAYABLE'], ['CASH', 'BANK'], null],
    ];

    // ---- D12 periods / closes ------------------------------------------------------------------------------------------
    public const PERIOD_MONTH = 'MONTH';
    public const PERIOD_YEAR = 'YEAR';
    public const PERIOD_OPEN = 'OPEN';
    public const PERIOD_CLOSED = 'CLOSED';
    public const UNIT_CLOSED = 'CLOSED';
    public const UNIT_REOPENED = 'REOPENED';

    // ---- D10 transfers, D13 budgets, subledgers (CHECK vocabularies; F1B owns the flows) ------------------------------
    public const TRANSFER_STATUSES = ['DRAFT', 'SENT', 'RECEIVED', 'CANCELLED'];
    public const TRANSFER_STAGES = ['SEND', 'RECEIVE', 'REVERSE_SEND'];
    public const BUDGET_STATUSES = ['DRAFT', 'SUBMITTED', 'REVIEWED', 'APPROVED', 'SUPERSEDED', 'CLOSED', 'CANCELLED'];
    public const SUBLEDGER_STATUSES = ['PENDING', 'RECOGNIZED', 'SETTLED', 'CANCELLED'];
    public const SETTLEMENT_DIRECTIONS = ['RECEIPT', 'PAYMENT'];
    public const ACCOUNT_KINDS = ['CASH', 'BANK'];

    /** @return list<string> rows inserted by this call */
    public static function install(Connection $db): array
    {
        return $db->transaction(function () use ($db): array {
            $now = (string) $db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
            $inserted = [];
            $stamp = ['created_at' => $now, 'lock_version' => 0];

            $currency = $db->table('currencies')->where('code', self::CURRENCY)->first();
            if ($currency === null) {
                $db->table('currencies')->insert(['code' => self::CURRENCY, 'name' => self::CURRENCY_NAME, 'minor_units' => self::MINOR_UNITS, 'is_active' => 1] + $stamp);
                $inserted[] = 'currencies:' . self::CURRENCY;
            } elseif ((int) $currency->minor_units !== self::MINOR_UNITS) {
                self::conflict('currencies:' . self::CURRENCY . ' minor_units=' . $currency->minor_units);
            }

            if (!$db->table('funds')->where('code', self::FUND_GENERAL)->exists()) {
                $db->table('funds')->insert(['code' => self::FUND_GENERAL, 'name' => 'Fundo geral', 'restriction_kind' => 'UNRESTRICTED', 'status' => 'ACTIVE'] + $stamp);
                $inserted[] = 'funds:' . self::FUND_GENERAL;
            }

            $roles = [];
            foreach (self::CHART as $role => [$class, $side, $name]) {
                $row = $db->table('chart_of_accounts')->where('code', $role)->first();
                if ($row === null) {
                    $roles[$role] = (int) $db->table('chart_of_accounts')->insertGetId(['parent_id' => null, 'code' => $role, 'name' => $name, 'account_kind' => $class,
                        'normal_side' => $side, 'system_role' => $role, 'postable' => 1, 'status' => 'ACTIVE'] + $stamp);
                    $inserted[] = 'chart_of_accounts:' . $role;
                    continue;
                }
                if ($row->account_kind !== $class || $row->normal_side !== $side || $row->system_role !== $role) {
                    self::conflict('chart_of_accounts:' . $role . ' is ' . $row->account_kind . '/' . $row->normal_side . '/' . ($row->system_role ?? 'NULL'));
                }
                $roles[$role] = (int) $row->id;
            }

            foreach (self::CATEGORIES as $group => [$groupName, $items]) {
                $parent = $db->table('financial_categories')->where('code', $group)->first();
                if ($parent === null) {
                    $parentId = (int) $db->table('financial_categories')->insertGetId(['parent_id' => null, 'code' => $group, 'name' => $groupName, 'ledger_account_id' => null,
                        'economic_nature' => null, 'classification_status' => 'APPROVED', 'status' => 'ACTIVE'] + $stamp);
                    $inserted[] = 'financial_categories:' . $group;
                } elseif ($parent->ledger_account_id !== null || $parent->economic_nature !== null) {
                    self::conflict('financial_categories:' . $group . ' is postable');
                } else {
                    $parentId = (int) $parent->id;
                }
                foreach ($items as $code => [$name, $nature, $role]) {
                    $row = $db->table('financial_categories')->where('code', $code)->first();
                    if ($row === null) {
                        $db->table('financial_categories')->insert(['parent_id' => $parentId, 'code' => $code, 'name' => $name, 'ledger_account_id' => $roles[$role],
                            'economic_nature' => $nature, 'classification_status' => 'APPROVED', 'status' => 'ACTIVE'] + $stamp);
                        $inserted[] = 'financial_categories:' . $code;
                        continue;
                    }
                    if ($row->economic_nature !== $nature || (int) $row->parent_id !== $parentId) {
                        self::conflict('financial_categories:' . $code . ' nature=' . ($row->economic_nature ?? 'NULL'));
                    }
                }
            }

            foreach (self::PERMISSIONS as $code) {
                $row = $db->table('permissions')->where('code', $code)->first();
                if ($row === null) {
                    $db->table('permissions')->insert(['code' => $code, 'action' => $code, 'data_type' => self::DATA_TYPE, 'maximum_classification' => self::PERMISSION_CLASSIFICATION] + $stamp);
                    $inserted[] = 'permissions:' . $code;
                } elseif ($row->data_type !== self::DATA_TYPE || $row->action !== $code) {
                    self::conflict('permissions:' . $code . ' data_type=' . $row->data_type);
                }
            }

            foreach (self::DOCUMENT_TYPES as $code => $name) {
                if (!$db->table('legal_document_types')->where('code', $code)->exists()) {
                    $db->table('legal_document_types')->insert(['code' => $code, 'name' => $name, 'is_active' => 1] + $stamp);
                    $inserted[] = 'legal_document_types:' . $code;
                }
            }

            foreach (self::WORKFLOWS as $code => $name) {
                if (!$db->table('workflows')->where('code', $code)->where('version', self::WORKFLOW_VERSION)->exists()) {
                    $db->table('workflows')->insert(['code' => $code, 'version' => self::WORKFLOW_VERSION, 'name' => $name, 'status' => 'ACTIVE'] + $stamp);
                    $inserted[] = 'workflows:' . $code;
                }
            }
            return $inserted;
        });
    }

    /** @return array<string, int> system_role => chart_of_accounts.id */
    public static function roleIds(Connection $db): array
    {
        $ids = [];
        foreach ($db->table('chart_of_accounts')->whereNotNull('system_role')->get(['id', 'system_role']) as $row) {
            $ids[(string) $row->system_role] = (int) $row->id;
        }
        return $ids;
    }

    /** Number of controlled rows a complete install holds (D32 catalogs). */
    public static function controlledRowCount(): int
    {
        $categories = 0;
        foreach (self::CATEGORIES as [, $items]) {
            $categories += 1 + count($items);
        }
        return 1 + 1 + count(self::CHART) + $categories + count(self::PERMISSIONS) + count(self::DOCUMENT_TYPES) + count(self::WORKFLOWS);
    }

    private static function conflict(string $what): never
    {
        throw new RuntimeException('FINANCE_CATALOG_CONFLICT: ' . $what . '; nothing installed, nothing repaired.');
    }
}
