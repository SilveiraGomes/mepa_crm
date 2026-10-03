<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Finance\FinanceCatalog;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Controlled vocabulary of RH / Payroll V1 (ADR 0021 D22-D28, D31 + D-04A.14/15).
 *
 * Installed: the 9 HR permissions (data_type HR, action = code) and the 13 generic compensation component types.
 * NEVER installed: roles, employments, payroll rules, rates, brackets, minimum wage, 13.º / holiday / pension values or any
 * other statutory value (D25, D-04A.15). Those exist only after an official load with a PAYROLL_RULE_SOURCE document.
 */
final class PayrollCatalog
{
    public const DATA_TYPE = 'HR';

    public const HR_EMPLOYMENT_VIEW = 'HR_EMPLOYMENT_VIEW';
    public const HR_EMPLOYMENT_MANAGE = 'HR_EMPLOYMENT_MANAGE';
    public const HR_COMPENSATION_VIEW = 'HR_COMPENSATION_VIEW';
    public const HR_COMPENSATION_MANAGE = 'HR_COMPENSATION_MANAGE';
    public const PAYROLL_MANAGE = 'PAYROLL_MANAGE';
    public const PAYROLL_APPROVE = 'PAYROLL_APPROVE';
    public const PAYROLL_POST = 'PAYROLL_POST';
    public const PAYROLL_RULES_MANAGE = 'PAYROLL_RULES_MANAGE';
    public const PAYROLL_RULES_APPROVE = 'PAYROLL_RULES_APPROVE';

    /** D31: permission => permissions.maximum_classification. */
    public const PERMISSIONS = [
        self::HR_EMPLOYMENT_VIEW => 'CONFIDENTIAL',
        self::HR_EMPLOYMENT_MANAGE => 'CONFIDENTIAL',
        self::HR_COMPENSATION_VIEW => 'HIGHLY_SENSITIVE',
        self::HR_COMPENSATION_MANAGE => 'HIGHLY_SENSITIVE',
        self::PAYROLL_MANAGE => 'HIGHLY_SENSITIVE',
        self::PAYROLL_APPROVE => 'HIGHLY_SENSITIVE',
        self::PAYROLL_POST => 'HIGHLY_SENSITIVE',
        self::PAYROLL_RULES_MANAGE => 'HIGHLY_SENSITIVE',
        self::PAYROLL_RULES_APPROVE => 'HIGHLY_SENSITIVE',
    ];

    // ---- D23 employment ------------------------------------------------------------------------------------------------
    public const RELATIONSHIP_KINDS = ['EMPLOYEE', 'BENEFICIARY'];
    public const EMPLOYMENT_ACTIVE = 'ACTIVE';
    public const EMPLOYMENT_ENDED = 'ENDED';
    /** ADR 0017 / D23: the People context opened by an employment (an approved person_unit_contexts kind). */
    public const PEOPLE_CONTEXT = 'EMPLOYMENT';

    // ---- D24 compensation ----------------------------------------------------------------------------------------------
    public const EARNING = 'EARNING';
    public const EMPLOYEE_DEDUCTION = 'EMPLOYEE_DEDUCTION';
    public const EMPLOYER_CHARGE = 'EMPLOYER_CHARGE';
    public const FIXED_AMOUNT = 'FIXED_AMOUNT';
    public const RATE_RULE = 'RATE_RULE';
    public const BRACKET_RULE = 'BRACKET_RULE';
    public const MANUAL = 'MANUAL';
    /** Components whose amount comes from an approved payroll rule (never from the compensation line). */
    public const RULE_METHODS = [self::RATE_RULE, self::BRACKET_RULE];

    /**
     * D24 V1 components: code => [name, nature, calculation_method, expense rubric (§16.5) | null, liability role | null].
     * The calculation method is a classification, not a value (F2A-D1): 13.º / holiday subsidy / INSS are computed by an
     * approved rule when (and only when) one is loaded; pensions and third-age benefits are configured amounts per person.
     */
    public const COMPONENTS = [
        'BASE_SALARY' => ['Salário base', self::EARNING, self::FIXED_AMOUNT, 'PER_SALARY', null],
        'THIRTEENTH_SALARY' => ['13.º salário', self::EARNING, self::RATE_RULE, 'PER_THIRTEENTH', null],
        'HOLIDAY_SUBSIDY' => ['Subsídio de férias', self::EARNING, self::RATE_RULE, 'PER_HOLIDAY_SUBSIDY', null],
        'TRANSPORT_MEAL_ALLOWANCE' => ['Subsídio de transporte / refeição', self::EARNING, self::FIXED_AMOUNT, 'PER_TRANSPORT_MEAL', null],
        'SOCIAL_ASSISTANCE' => ['Assistência social', self::EARNING, self::FIXED_AMOUNT, 'PER_SOCIAL_ASSISTANCE', null],
        'HEALTH_PLAN' => ['Plano de saúde', self::EARNING, self::FIXED_AMOUNT, 'PER_HEALTH_PLAN', null],
        'OTHER_EARNING' => ['Outros abonos', self::EARNING, self::MANUAL, 'PER_OTHER', null],
        'RETIREMENT_PENSION' => ['Pensão de reforma', self::EARNING, self::FIXED_AMOUNT, 'PER_SOCIAL_ASSISTANCE', null],
        'THIRD_AGE_BENEFIT' => ['Benefício de terceira idade', self::EARNING, self::FIXED_AMOUNT, 'PER_SOCIAL_ASSISTANCE', null],
        'INSS_EMPLOYEE' => ['INSS (trabalhador)', self::EMPLOYEE_DEDUCTION, self::RATE_RULE, null, 'PAYROLL_WITHHOLDINGS'],
        'INCOME_TAX_WITHHOLDING' => ['Retenção de IRT', self::EMPLOYEE_DEDUCTION, self::BRACKET_RULE, null, 'PAYROLL_WITHHOLDINGS'],
        'OTHER_DEDUCTION' => ['Outros descontos', self::EMPLOYEE_DEDUCTION, self::MANUAL, null, 'PAYROLL_WITHHOLDINGS'],
        'INSS_EMPLOYER' => ['INSS (entidade)', self::EMPLOYER_CHARGE, self::RATE_RULE, 'PER_INSS', 'PAYROLL_EMPLOYER_CHARGES'],
    ];

    // ---- D25 rules -------------------------------------------------------------------------------------------------------
    public const RULE_DRAFT = 'DRAFT';
    public const RULE_APPROVED = 'APPROVED';
    public const RULE_RETIRED = 'RETIRED';
    public const FLAT_RATE = 'FLAT_RATE';
    public const BRACKET = 'BRACKET';
    public const RULE_SOURCE_DOCUMENT = 'PAYROLL_RULE_SOURCE';
    public const RULE_CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,63}$/D';
    /** Rate scale of DECIMAL(9,6): a fraction in [0, 1]. */
    public const RATE_SCALE = 6;

    // ---- D26 runs (structure only in F2A; the pipeline is F2B) -----------------------------------------------------------
    public const RUN_KINDS = ['REGULAR', 'HOLIDAY_SUBSIDY', 'THIRTEENTH', 'ADJUSTMENT'];
    public const RUN_STATUSES = ['DRAFT', 'CALCULATED', 'APPROVED', 'POSTED', 'PAID', 'CANCELLED', 'REVERSED'];

    // ---- D-04A.14 / D02 rounding -----------------------------------------------------------------------------------------
    /** Business scale of every payroll amount (AOA, 2 decimals) and the one rounding mode: half-up per component line. */
    public const MONEY_SCALE = 2;
    public const ROUNDING = ['mode' => 'HALF_UP', 'scale' => self::MONEY_SCALE, 'unit' => 'COMPONENT_LINE', 'currency' => FinanceCatalog::CURRENCY];
    public const INPUT_HASH_VERSION = 'P010-PAYROLL-INPUT-V1';

    public const PUBLIC_ID_PATTERN = FinanceCatalog::PUBLIC_ID_PATTERN;

    /** @return list<string> inserted rows (code list) */
    public static function install(Connection $db): array
    {
        return $db->transaction(function () use ($db): array {
            $now = (string) $db->selectOne('SELECT UTC_TIMESTAMP(6) AS n')->n;
            $inserted = [];
            $stamp = ['created_at' => $now, 'lock_version' => 0];
            foreach (self::PERMISSIONS as $code => $classification) {
                $row = $db->table('permissions')->where('code', $code)->first();
                if ($row === null) {
                    $db->table('permissions')->insert(['code' => $code, 'action' => $code, 'data_type' => self::DATA_TYPE, 'maximum_classification' => $classification] + $stamp);
                    $inserted[] = 'permissions:' . $code;
                } elseif ($row->data_type !== self::DATA_TYPE || $row->action !== $code || $row->maximum_classification !== $classification) {
                    self::conflict('permissions:' . $code . ' data_type=' . $row->data_type . ' classification=' . $row->maximum_classification);
                }
            }
            foreach (self::COMPONENTS as $code => [$name, $nature, $method, $rubric, $liability]) {
                $category = $rubric === null ? null : $db->table('financial_categories')->where('code', $rubric)->value('id');
                if ($rubric !== null && $category === null) {
                    self::conflict('financial_categories:' . $rubric . ' missing (install the Finance catalog first)');
                }
                $row = $db->table('compensation_component_types')->where('code', $code)->first();
                if ($row === null) {
                    $db->table('compensation_component_types')->insert(['code' => $code, 'name' => $name, 'nature' => $nature, 'calculation_method' => $method,
                        'expense_category_id' => $category, 'liability_role' => $liability, 'is_active' => 1] + $stamp);
                    $inserted[] = 'compensation_component_types:' . $code;
                } elseif ($row->nature !== $nature || $row->calculation_method !== $method || ($row->liability_role ?? null) !== $liability
                    || ($row->expense_category_id === null ? null : (int) $row->expense_category_id) !== ($category === null ? null : (int) $category)) {
                    self::conflict('compensation_component_types:' . $code . ' nature=' . $row->nature . ' method=' . $row->calculation_method);
                }
            }
            return $inserted;
        });
    }

    private static function conflict(string $what): never
    {
        throw new RuntimeException('P010_PAYROLL_CATALOG_CONFLICT: ' . $what . '; nothing repaired.');
    }
}
