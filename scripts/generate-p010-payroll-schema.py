"""P0.10-F2A payroll schema generator (ADR 0021 D23-D27, D32/D33 + D-04A.14/15).

One specification of the 9 approved Payroll tables produces, consistently:
  - the 9 CREATE TABLE migrations + the PayrollCatalog installer migration (apps/api/database/migrations);
  - the 9 logical entries of docs/database/model_catalog.json (2-space JSON, UTF-8, trailing newline);
  - their docs/database/02_data_dictionary.md sections and docs/diagrams/mepa_erd_master.mmd entities/relations;
  - the physical deltas of docs/database/physical/p010_finance_delta_manifest.json (generated guards, their UNIQUE
    keys, CHECK names, F2A migrations and materialized tables, controlled data, forbidden columns);
  - the refreshed catalog_sha256 of the manifests that pinned the previous catalog (wave2, wave5 migrations).
Run once: it refuses to run when any of the 9 tables is already in the catalog. No statutory value is generated.
"""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIG = ROOT / "apps/api/database/migrations"
CATALOG = ROOT / "docs/database/model_catalog.json"
DICT = ROOT / "docs/database/02_data_dictionary.md"
ERD = ROOT / "docs/diagrams/mepa_erd_master.mmd"
MANIFEST = ROOT / "docs/database/physical/p010_finance_delta_manifest.json"
PUBLIC = "CHAR(26) CHARACTER SET ascii COLLATE ascii_bin"
FK_REASON = "Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo."
UTF = "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"


def col(name, type_, nullable=False, default=None, fk=None, desc=None, example="", sens="Confidencial", unique=(), index=()):
    return {"name": name, "type": type_, "nullable": nullable, "default": default, "fk": fk, "desc": desc or name.replace("_", " "), "example": example, "sens": sens,
            "unique": list(unique), "index": list(index)}


def audit_cols(*extra):
    return [*extra]


ID = lambda: col("id", "BIGINT UNSIGNED", default="AUTO_INCREMENT", desc="Chave interna imutável", example="123", sens="Interno")
CREATED = lambda: col("created_at", "DATETIME(6)", default="nenhum; relógio UTC do serviço", desc="Data de registo, distinta da data histórica", example="2026-10-02 10:00:00.000000", sens="Interno")
LOCK = lambda: col("lock_version", "INT UNSIGNED", default="0", desc="Controlo optimista de edição; não permite editar fechos", example="0", sens="Interno")
VC = "VARCHAR(64)"
MONEY = "DECIMAL(19,4)"
RATE = "DECIMAL(9,6)"

TABLES = [
    {
        "name": "employments", "purpose": "Vínculo laboral MEPA (Pessoa + unidade empregadora); não é cargo eclesiástico nem situação profissional da Pessoa",
        "requires": ["people", "organizational_units", "legal_documents", "users"], "sensitivity": "Confidencial",
        "public": "Vínculo laboral referido na API de RH e em recibos",
        "columns": [
            ID(),
            col("public_id", PUBLIC, default="nenhum; gerado na aplicacao", unique=["uq_employments_public_id"], example="01ARZ3NDEKTSV4RRFFQ69G5FAV"),
            col("person_id", "BIGINT UNSIGNED", fk="people", desc="Pessoa empregada (identidade global; nunca duplicada)"),
            col("employing_unit_id", "BIGINT UNSIGNED", fk="organizational_units", desc="Unidade que emprega, paga e responde", index=["ix_employments_employing_unit_id_status"]),
            col("relationship_kind", VC, desc="EMPLOYEE ou BENEFICIARY", example="EMPLOYEE"),
            col("job_title", "VARCHAR(160)", nullable=True, default="NULL", desc="Função laboral (texto); não é cargo eclesiástico", example="Secretária administrativa"),
            col("starts_on", "DATE", desc="Início do vínculo", example="2026-01-01"),
            col("ends_on", "DATE", nullable=True, default="NULL", desc="Fim do vínculo", example="2026-12-31"),
            col("status", VC, desc="ACTIVE ou ENDED", example="ACTIVE", index=["ix_employments_employing_unit_id_status"]),
            col("end_reason", "TEXT", nullable=True, default="NULL", desc="Motivo da cessação", example="Fim de contrato"),
            col("contract_document_id", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="legal_documents", desc="Contrato de trabalho (documento Files)", index=["ix_employments_contract_document_id"]),
            col("created_by", "BIGINT UNSIGNED", fk="users", index=["ix_employments_created_by"]),
            CREATED(),
            col("ended_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_employments_ended_by"]),
            col("ended_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-12-31 10:00:00.000000"),
            LOCK(),
        ],
        "unique": [["public_id"]],
        "indexes": [["employing_unit_id", "status"], ["contract_document_id"], ["created_by"], ["ended_by"]],
        "generated": [("open_guard", "IF(`status` = 'ACTIVE', 1, NULL)", "uq_employments_person_id_unit_open", ["person_id", "employing_unit_id", "open_guard"])],
        "checks": [
            ("ck_employments_relationship_kind", "`relationship_kind` IN ('EMPLOYEE','BENEFICIARY')"),
            ("ck_employments_status", "`status` IN ('ACTIVE','ENDED')"),
            ("ck_employments_dates", "`ends_on` IS NULL OR `ends_on` >= `starts_on`"),
            ("ck_employments_ended", "(`status` = 'ENDED') = (`ends_on` IS NOT NULL AND `ended_at` IS NOT NULL AND `ended_by` IS NOT NULL AND `end_reason` IS NOT NULL)"),
        ],
    },
    {
        "name": "compensation_component_types", "purpose": "Catálogo de componentes remuneratórios (natureza, método, rubrica e passivo); sem valores nem taxas",
        "requires": ["financial_categories"], "sensitivity": "Interno", "public": None,
        "columns": [
            ID(),
            col("code", VC, desc="Código estável do componente", example="BASE_SALARY", sens="Interno", unique=["uq_compensation_component_types_code"]),
            col("name", "VARCHAR(160)", desc="Nome institucional", example="Salário base", sens="Interno"),
            col("nature", VC, desc="EARNING, EMPLOYEE_DEDUCTION ou EMPLOYER_CHARGE", example="EARNING", sens="Interno"),
            col("calculation_method", VC, desc="FIXED_AMOUNT, RATE_RULE, BRACKET_RULE ou MANUAL", example="FIXED_AMOUNT", sens="Interno"),
            col("expense_category_id", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="financial_categories", desc="Rubrica PER_* (ganhos e encargos)", sens="Interno",
                index=["ix_compensation_component_types_expense_category_id"]),
            col("liability_role", VC, nullable=True, default="NULL", desc="PAYROLL_WITHHOLDINGS ou PAYROLL_EMPLOYER_CHARGES", example="PAYROLL_WITHHOLDINGS", sens="Interno"),
            col("is_active", "TINYINT UNSIGNED", default="1", desc="Disponível para novas configurações", example="1", sens="Interno"),
            CREATED(), LOCK(),
        ],
        "unique": [["code"]],
        "indexes": [["expense_category_id"]],
        "generated": [],
        "checks": [
            ("ck_compensation_component_types_nature", "`nature` IN ('EARNING','EMPLOYEE_DEDUCTION','EMPLOYER_CHARGE')"),
            ("ck_compensation_component_types_calculation_method", "`calculation_method` IN ('FIXED_AMOUNT','RATE_RULE','BRACKET_RULE','MANUAL')"),
            ("ck_compensation_component_types_liability_role", "`liability_role` IS NULL OR `liability_role` IN ('PAYROLL_WITHHOLDINGS','PAYROLL_EMPLOYER_CHARGES')"),
            ("ck_compensation_component_types_posting", "(`nature` = 'EARNING' AND `expense_category_id` IS NOT NULL AND `liability_role` IS NULL) OR (`nature` = 'EMPLOYEE_DEDUCTION' AND `expense_category_id` IS NULL AND `liability_role` = 'PAYROLL_WITHHOLDINGS') OR (`nature` = 'EMPLOYER_CHARGE' AND `expense_category_id` IS NOT NULL AND `liability_role` = 'PAYROLL_EMPLOYER_CHARGES')"),
            ("ck_compensation_component_types_is_active", "`is_active` IN (0,1)"),
        ],
    },
    {
        "name": "employment_compensations", "purpose": "Histórico salarial append-only: componente por vínculo com vigência; alterar = fechar a vigente e abrir nova",
        "requires": ["employments", "compensation_component_types", "legal_documents", "users"], "sensitivity": "Altamente sensível", "public": None,
        "columns": [
            ID(),
            col("employment_id", "BIGINT UNSIGNED", fk="employments", sens="Altamente sensível", unique=["uq_employment_compensations_start"]),
            col("component_type_id", "BIGINT UNSIGNED", fk="compensation_component_types", sens="Altamente sensível", unique=["uq_employment_compensations_start"],
                index=["ix_employment_compensations_component_type_id"]),
            col("amount", MONEY, nullable=True, default="NULL", desc="Montante (FIXED_AMOUNT/MANUAL); NULL = aplicável por regra", example="150000.0000", sens="Altamente sensível"),
            col("starts_on", "DATE", desc="Início de vigência", example="2026-01-01", sens="Altamente sensível", unique=["uq_employment_compensations_start"]),
            col("ends_on", "DATE", nullable=True, default="NULL", desc="Fim de vigência", example="2026-06-30", sens="Altamente sensível"),
            col("reason", "TEXT", desc="Motivo da configuração", example="Revisão salarial", sens="Altamente sensível"),
            col("source_document_id", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="legal_documents", sens="Altamente sensível", index=["ix_employment_compensations_source_document_id"]),
            col("created_by", "BIGINT UNSIGNED", fk="users", index=["ix_employment_compensations_created_by"]),
            CREATED(),
            col("closed_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_employment_compensations_closed_by"]),
            col("closed_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-06-30 10:00:00.000000"),
            LOCK(),
        ],
        "unique": [["employment_id", "component_type_id", "starts_on"]],
        "indexes": [["component_type_id"], ["source_document_id"], ["created_by"], ["closed_by"]],
        "generated": [("open_guard", "IF(`ends_on` IS NULL, 1, NULL)", "uq_employment_compensations_open", ["employment_id", "component_type_id", "open_guard"])],
        "checks": [
            ("ck_employment_compensations_dates", "`ends_on` IS NULL OR `ends_on` >= `starts_on`"),
            ("ck_employment_compensations_amount", "`amount` IS NULL OR (`amount` >= 0 AND `amount` = ROUND(`amount`, 2))"),
            ("ck_employment_compensations_closed", "(`closed_at` IS NULL) = (`closed_by` IS NULL) AND (`closed_at` IS NULL OR `ends_on` IS NOT NULL)"),
        ],
    },
    {
        "name": "payroll_rules", "purpose": "Regras estatutárias versionadas (INSS, IRT, 13.º, férias, pensões…); nenhuma taxa é semeada; APPROVED exige fonte normativa",
        "requires": ["compensation_component_types", "legal_documents", "users"], "sensitivity": "Confidencial", "public": None,
        "columns": [
            ID(),
            col("code", VC, desc="Código da regra (estável entre versões)", example="INSS_EMPLOYEE_RATE", unique=["uq_payroll_rules_code_version"]),
            col("version", "INT UNSIGNED", desc="Versão da regra", example="1", unique=["uq_payroll_rules_code_version"]),
            col("component_type_id", "BIGINT UNSIGNED", fk="compensation_component_types", desc="Componente calculado pela regra", index=["ix_payroll_rules_component_type_id_status"]),
            col("method", VC, desc="FLAT_RATE ou BRACKET", example="FLAT_RATE"),
            col("rate", RATE, nullable=True, default="NULL", desc="Taxa (fracção) para FLAT_RATE; carregada da fonte oficial", example="0.030000"),
            col("starts_on", "DATE", desc="Início de vigência", example="2026-01-01"),
            col("ends_on", "DATE", nullable=True, default="NULL", desc="Fim de vigência", example="2026-12-31"),
            col("status", VC, desc="DRAFT, APPROVED ou RETIRED", example="DRAFT", index=["ix_payroll_rules_component_type_id_status"]),
            col("source_document_id", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="legal_documents", desc="Fonte normativa PAYROLL_RULE_SOURCE", index=["ix_payroll_rules_source_document_id"]),
            col("created_by", "BIGINT UNSIGNED", fk="users", index=["ix_payroll_rules_created_by"]),
            CREATED(),
            col("approved_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_payroll_rules_approved_by"]),
            col("approved_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-10-02 10:00:00.000000"),
            col("retired_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_payroll_rules_retired_by"]),
            col("retired_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-10-02 10:00:00.000000"),
            LOCK(),
        ],
        "unique": [["code", "version"]],
        "indexes": [["component_type_id", "status"], ["source_document_id"], ["created_by"], ["approved_by"], ["retired_by"]],
        "generated": [],
        "checks": [
            ("ck_payroll_rules_method", "`method` IN ('FLAT_RATE','BRACKET')"),
            ("ck_payroll_rules_status", "`status` IN ('DRAFT','APPROVED','RETIRED')"),
            ("ck_payroll_rules_version", "`version` >= 1"),
            ("ck_payroll_rules_dates", "`ends_on` IS NULL OR `ends_on` >= `starts_on`"),
            ("ck_payroll_rules_rate", "`rate` IS NULL OR (`rate` >= 0 AND `rate` <= 1)"),
            ("ck_payroll_rules_method_rate", "(`method` = 'FLAT_RATE') = (`rate` IS NOT NULL)"),
            ("ck_payroll_rules_approved", "(`approved_at` IS NULL) = (`approved_by` IS NULL) AND (`status` = 'DRAFT' OR `approved_at` IS NOT NULL OR `status` = 'RETIRED') AND (`approved_at` IS NULL OR `source_document_id` IS NOT NULL)"),
            ("ck_payroll_rules_segregation", "`approved_by` IS NULL OR `approved_by` <> `created_by`"),
            ("ck_payroll_rules_retired", "(`status` = 'RETIRED') = (`retired_at` IS NOT NULL) AND (`retired_at` IS NULL) = (`retired_by` IS NULL)"),
        ],
    },
    {
        "name": "payroll_rule_base_components", "purpose": "Componentes que formam a base de incidência de uma versão de regra",
        "requires": ["payroll_rules", "compensation_component_types"], "sensitivity": "Confidencial", "public": None,
        "columns": [
            ID(),
            col("rule_id", "BIGINT UNSIGNED", fk="payroll_rules", unique=["uq_payroll_rule_base_components_rule_component"]),
            col("component_type_id", "BIGINT UNSIGNED", fk="compensation_component_types", unique=["uq_payroll_rule_base_components_rule_component"], index=["ix_payroll_rule_base_components_component_type_id"]),
            CREATED(),
        ],
        "unique": [["rule_id", "component_type_id"]],
        "indexes": [["component_type_id"]],
        "generated": [], "checks": [],
    },
    {
        "name": "payroll_rule_brackets", "purpose": "Escalões de uma regra progressiva (BRACKET), sem lacunas nem sobreposição; valores só da fonte oficial",
        "requires": ["payroll_rules"], "sensitivity": "Confidencial", "public": None,
        "columns": [
            ID(),
            col("rule_id", "BIGINT UNSIGNED", fk="payroll_rules", unique=["uq_payroll_rule_brackets_rule_id_lower_bound"]),
            col("lower_bound", MONEY, desc="Limite inferior (inclusivo)", example="0.0000", unique=["uq_payroll_rule_brackets_rule_id_lower_bound"]),
            col("upper_bound", MONEY, nullable=True, default="NULL", desc="Limite superior (exclusivo); NULL = sem limite", example="100000.0000"),
            col("rate", RATE, desc="Taxa marginal (fracção)", example="0.100000"),
            col("fixed_amount", MONEY, default="0", desc="Parcela fixa do escalão", example="0.0000"),
            col("excess_over", MONEY, default="0", desc="Valor sobre o qual incide a taxa marginal", example="0.0000"),
            CREATED(),
        ],
        "unique": [["rule_id", "lower_bound"]],
        "indexes": [],
        "generated": [],
        "checks": [
            ("ck_payroll_rule_brackets_bounds", "`lower_bound` >= 0 AND (`upper_bound` IS NULL OR `upper_bound` > `lower_bound`)"),
            ("ck_payroll_rule_brackets_rate", "`rate` >= 0 AND `rate` <= 1"),
            ("ck_payroll_rule_brackets_amounts", "`fixed_amount` >= 0 AND `excess_over` >= 0 AND `fixed_amount` = ROUND(`fixed_amount`, 2) AND `excess_over` = ROUND(`excess_over`, 2) AND `lower_bound` = ROUND(`lower_bound`, 2) AND (`upper_bound` IS NULL OR `upper_bound` = ROUND(`upper_bound`, 2))"),
        ],
    },
    {
        "name": "payroll_runs", "purpose": "Processamento salarial por unidade empregadora e mês de serviço (F2B); totais agregados e input_hash",
        "requires": ["organizational_units", "accounting_periods", "users"], "sensitivity": "Altamente sensível",
        "public": "Folha salarial referida na API de RH e no posting agregado",
        "columns": [
            ID(),
            col("public_id", PUBLIC, default="nenhum; gerado na aplicacao", unique=["uq_payroll_runs_public_id"], example="01ARZ3NDEKTSV4RRFFQ69G5FAV", sens="Altamente sensível"),
            col("employing_unit_id", "BIGINT UNSIGNED", fk="organizational_units", unique=["uq_payroll_runs_unit_period_kind_sequence"], sens="Altamente sensível"),
            col("period_id", "BIGINT UNSIGNED", fk="accounting_periods", desc="Mês de serviço", unique=["uq_payroll_runs_unit_period_kind_sequence"], index=["ix_payroll_runs_period_id"], sens="Altamente sensível"),
            col("run_kind", VC, desc="REGULAR, HOLIDAY_SUBSIDY, THIRTEENTH ou ADJUSTMENT", example="REGULAR", unique=["uq_payroll_runs_unit_period_kind_sequence"], sens="Altamente sensível"),
            col("sequence", "INT UNSIGNED", desc="Sequência do run no mês", example="1", unique=["uq_payroll_runs_unit_period_kind_sequence"], sens="Altamente sensível"),
            col("status", VC, desc="DRAFT, CALCULATED, APPROVED, POSTED, PAID, CANCELLED ou REVERSED", example="DRAFT", sens="Altamente sensível"),
            col("input_hash", "BINARY(32)", nullable=True, default="NULL", desc="SHA-256 canónico dos inputs (D-04A.14)", example="0x9f…", sens="Altamente sensível"),
            col("gross_amount", MONEY, default="0", desc="Total bruto", example="150000.0000", sens="Altamente sensível"),
            col("deductions_amount", MONEY, default="0", desc="Total de deduções ao empregado", example="10000.0000", sens="Altamente sensível"),
            col("employer_charges_amount", MONEY, default="0", desc="Total de encargos da entidade", example="12000.0000", sens="Altamente sensível"),
            col("net_amount", MONEY, default="0", desc="Líquido = bruto − deduções", example="140000.0000", sens="Altamente sensível"),
            col("headcount", "INT UNSIGNED", default="0", desc="Número de vínculos processados", example="3", sens="Altamente sensível"),
            col("created_by", "BIGINT UNSIGNED", fk="users", index=["ix_payroll_runs_created_by"]),
            CREATED(),
            col("calculated_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_payroll_runs_calculated_by"]),
            col("calculated_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-10-02 10:00:00.000000"),
            col("approved_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_payroll_runs_approved_by"]),
            col("approved_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-10-02 10:00:00.000000"),
            col("posted_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_payroll_runs_posted_by"]),
            col("posted_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-10-02 10:00:00.000000"),
            col("paid_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_payroll_runs_paid_by"]),
            col("paid_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-10-02 10:00:00.000000"),
            col("cancelled_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_payroll_runs_cancelled_by"]),
            col("cancelled_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-10-02 10:00:00.000000"),
            col("cancel_reason", "TEXT", nullable=True, default="NULL", example="Run substituído"),
            col("reversed_by", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="users", index=["ix_payroll_runs_reversed_by"]),
            col("reversed_at", "DATETIME(6)", nullable=True, default="NULL", example="2026-10-02 10:00:00.000000"),
            col("reversal_reason", "TEXT", nullable=True, default="NULL", example="Erro de cálculo"),
            LOCK(),
        ],
        "unique": [["employing_unit_id", "period_id", "run_kind", "sequence"], ["public_id"]],
        "indexes": [["period_id"], ["created_by"], ["calculated_by"], ["approved_by"], ["posted_by"], ["paid_by"], ["cancelled_by"], ["reversed_by"]],
        "generated": [("regular_guard", "IF(`run_kind` = 'REGULAR' AND `status` <> 'CANCELLED', 1, NULL)", "uq_payroll_runs_unit_period_regular", ["employing_unit_id", "period_id", "regular_guard"])],
        "checks": [
            ("ck_payroll_runs_run_kind", "`run_kind` IN ('REGULAR','HOLIDAY_SUBSIDY','THIRTEENTH','ADJUSTMENT')"),
            ("ck_payroll_runs_status", "`status` IN ('DRAFT','CALCULATED','APPROVED','POSTED','PAID','CANCELLED','REVERSED')"),
            ("ck_payroll_runs_sequence", "`sequence` >= 1"),
            ("ck_payroll_runs_amounts", "`gross_amount` >= 0 AND `deductions_amount` >= 0 AND `employer_charges_amount` >= 0 AND `net_amount` >= 0 AND `net_amount` = `gross_amount` - `deductions_amount`"),
            ("ck_payroll_runs_calculated", "`status` IN ('DRAFT','CANCELLED') OR (`calculated_at` IS NOT NULL AND `calculated_by` IS NOT NULL AND `input_hash` IS NOT NULL)"),
            ("ck_payroll_runs_approved", "`status` NOT IN ('APPROVED','POSTED','PAID','REVERSED') OR (`approved_at` IS NOT NULL AND `approved_by` IS NOT NULL)"),
            ("ck_payroll_runs_segregation", "`approved_by` IS NULL OR `calculated_by` IS NULL OR `approved_by` <> `calculated_by`"),
            ("ck_payroll_runs_posted", "`status` NOT IN ('POSTED','PAID','REVERSED') OR (`posted_at` IS NOT NULL AND `posted_by` IS NOT NULL)"),
            ("ck_payroll_runs_paid", "(`status` = 'PAID') = (`paid_at` IS NOT NULL AND `paid_by` IS NOT NULL)"),
            ("ck_payroll_runs_cancelled", "(`status` = 'CANCELLED') = (`cancelled_at` IS NOT NULL AND `cancelled_by` IS NOT NULL AND `cancel_reason` IS NOT NULL)"),
            ("ck_payroll_runs_reversed", "(`status` = 'REVERSED') = (`reversed_at` IS NOT NULL AND `reversed_by` IS NOT NULL AND `reversal_reason` IS NOT NULL)"),
        ],
    },
    {
        "name": "payroll_run_lines", "purpose": "Linhas por vínculo e componente de um run (F2B); imutáveis a partir de APPROVED",
        "requires": ["payroll_runs", "employments", "compensation_component_types", "payroll_rules"], "sensitivity": "Altamente sensível", "public": None,
        "columns": [
            ID(),
            col("run_id", "BIGINT UNSIGNED", fk="payroll_runs", unique=["uq_payroll_run_lines_run_employment_component"], sens="Altamente sensível"),
            col("employment_id", "BIGINT UNSIGNED", fk="employments", unique=["uq_payroll_run_lines_run_employment_component"], index=["ix_payroll_run_lines_employment_id"], sens="Altamente sensível"),
            col("component_type_id", "BIGINT UNSIGNED", fk="compensation_component_types", unique=["uq_payroll_run_lines_run_employment_component"], index=["ix_payroll_run_lines_component_type_id"], sens="Altamente sensível"),
            col("base_amount", MONEY, nullable=True, default="NULL", desc="Base de incidência", example="150000.0000", sens="Altamente sensível"),
            col("rate", RATE, nullable=True, default="NULL", desc="Taxa aplicada", example="0.030000", sens="Altamente sensível"),
            col("amount", MONEY, desc="Montante da linha (arredondado half-up por componente)", example="4500.0000", sens="Altamente sensível"),
            col("source", VC, desc="FIXED, RULE ou MANUAL", example="FIXED", sens="Altamente sensível"),
            col("rule_id", "BIGINT UNSIGNED", nullable=True, default="NULL", fk="payroll_rules", index=["ix_payroll_run_lines_rule_id"], sens="Altamente sensível"),
            CREATED(),
        ],
        "unique": [["run_id", "employment_id", "component_type_id"]],
        "indexes": [["employment_id"], ["component_type_id"], ["rule_id"]],
        "generated": [],
        "checks": [
            ("ck_payroll_run_lines_source", "`source` IN ('FIXED','RULE','MANUAL')"),
            ("ck_payroll_run_lines_rule", "(`source` = 'RULE') = (`rule_id` IS NOT NULL)"),
            ("ck_payroll_run_lines_amount", "`amount` >= 0 AND `amount` = ROUND(`amount`, 2) AND (`base_amount` IS NULL OR `base_amount` >= 0)"),
        ],
    },
    {
        "name": "payroll_postings", "purpose": "Idempotência do posting do payroll no Finance (ACCRUAL, PAYMENT, REVERSAL); espelho de transfer_postings",
        "requires": ["payroll_runs", "journal_entries"], "sensitivity": "Confidencial", "public": None,
        "columns": [
            ID(),
            col("run_id", "BIGINT UNSIGNED", fk="payroll_runs", unique=["uq_payroll_postings_run_id_stage"]),
            col("stage", VC, desc="ACCRUAL, PAYMENT ou REVERSAL", example="ACCRUAL", unique=["uq_payroll_postings_run_id_stage"]),
            col("entry_id", "BIGINT UNSIGNED", fk="journal_entries", unique=["uq_payroll_postings_entry_id"]),
            CREATED(), LOCK(),
        ],
        "unique": [["run_id", "stage"], ["entry_id"]],
        "indexes": [],
        "generated": [],
        "checks": [("ck_payroll_postings_stage", "`stage` IN ('ACCRUAL','PAYMENT','REVERSAL')")],
    },
]

INSTALLER = "2026_10_02_200010_p010_install_payroll_catalog.php"


def sql_type(t):
    return t + " " + UTF if t.startswith(("VARCHAR", "TEXT")) else t


def default_sql(c):
    if c["name"] == "id":
        return "NOT NULL AUTO_INCREMENT"
    if c["nullable"]:
        return "NULL DEFAULT NULL"
    if c["default"] in ("0", "1") and c["type"] in ("INT UNSIGNED", "TINYINT UNSIGNED"):
        return "NOT NULL DEFAULT " + c["default"]
    if c["default"] == "0" and c["type"] == MONEY:
        return "NOT NULL DEFAULT 0.0000"
    return "NOT NULL"


def index_name(t, cols):
    for c in t["columns"]:
        if c["name"] == cols[0]:
            for n in c["unique"] + c["index"]:
                owners = [x["name"] for x in t["columns"] if n in x["unique"] + x["index"]]
                if sorted(owners) == sorted(cols):
                    return n, n in c["unique"]
    raise SystemExit(f"no index name for {t['name']} {cols}")


def migration(t, n):
    lines = []
    for c in t["columns"]:
        lines.append(f"  `{c['name']}` {sql_type(c['type'])} {default_sql(c)}")
        if c["name"] == "lock_version" or (c["name"] == "created_at" and not any(x["name"] == "lock_version" for x in t["columns"])):
            pass
    # generated guards right before created_at
    gen_lines = [f"  `{g[0]}` TINYINT UNSIGNED GENERATED ALWAYS AS ({g[1]}) STORED" for g in t["generated"]]
    if gen_lines:
        at = next(i for i, c in enumerate(t["columns"]) if c["name"] == "created_by")
        lines[at:at] = gen_lines
    keys = ["  PRIMARY KEY (`id`)"]
    for cols in t["unique"]:
        name, _ = index_name(t, cols)
        keys.append(f"  UNIQUE KEY `{name}` (" + ",".join(f"`{c}`" for c in cols) + ")")
    for g in t["generated"]:
        keys.append(f"  UNIQUE KEY `{g[2]}` (" + ",".join(f"`{c}`" for c in g[3]) + ")")
    for cols in t["indexes"]:
        name, _ = index_name(t, cols)
        keys.append(f"  KEY `{name}` (" + ",".join(f"`{c}`" for c in cols) + ")")
    for c in t["columns"]:
        if c["fk"]:
            keys.append(f"  CONSTRAINT `fk_{t['name']}_{c['name']}` FOREIGN KEY (`{c['name']}`) REFERENCES `{c['fk']}` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT")
    for name, expr in t["checks"]:
        keys.append(f"  CONSTRAINT `{name}` CHECK ({expr})")
    body = ",\n".join(lines + keys)
    req = ", ".join(f"'{r}'" for r in t["requires"])
    return f"""<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

// P0.10-F2A Payroll (ADR 0021 D23-D27, D32/D33 + D-04A.14/15): `{t['name']}` -- {t['purpose']}.
// Generated by scripts/generate-p010-payroll-schema.py (one specification for migration, catalog, dictionary, ERD, manifest).
// Preconditions are explicit: qualified MySQL driver, the table must not exist, every referenced table must exist.
// down() refuses to drop a table that holds rows (no silent data loss); it drops only this table.
return new class extends Migration
{{
    public function up(): void
    {{
        if (DB::getDriverName() !== 'mysql') {{
            throw new RuntimeException('P0.10 requires the qualified MySQL driver; SQLite is not supported.');
        }}
        if (Schema::hasTable('{t['name']}')) {{
            throw new RuntimeException('P010_PRECONDITION_FAILED: table {t['name']} already exists; nothing changed.');
        }}
        foreach ([{req}] as $required) {{
            if (!Schema::hasTable($required)) {{
                throw new RuntimeException('P010_PRECONDITION_FAILED: {t['name']} requires table ' . $required . '; nothing changed.');
            }}
        }}

        DB::statement(<<<'SQL'
CREATE TABLE `{t['name']}` (
{body}
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL
        );
    }}

    public function down(): void
    {{
        if (DB::table('{t['name']}')->exists()) {{
            throw new RuntimeException('P010_ROLLBACK_REFUSED: {t['name']} holds rows; rollback never deletes payroll data.');
        }}
        DB::statement('DROP TABLE `{t['name']}`');
    }}
}};
"""


def catalog_entry(t):
    columns = []
    for c in t["columns"]:
        e = {"name": c["name"], "type": c["type"], "nullable": c["nullable"], "default": c["default"] or "nenhum", "pk": c["name"] == "id"}
        if c["name"] not in ("id", "created_at", "lock_version"):
            e["fk"] = c["fk"]
        e.update({"description": c["desc"], "example": c["example"], "sensitivity": c["sens"], "unique": c["unique"], "index": c["index"]})
        if c["name"] == "public_id":
            e["description"] = "ULID publico imutavel; gerado na aplicacao, nunca reutilizado; " + t["public"]
            e.update({"immutable": True, "generated_by": "APPLICATION_SHARED_ULID_GENERATOR", "never_reuse": True})
        if c["fk"]:
            e.update({"target": c["fk"] + ".id", "cardinality": ("0..1" if c["nullable"] else "1") + " alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado)",
                      "on_delete": "RESTRICT", "on_update": "RESTRICT", "fk_reason": FK_REASON})
        columns.append(e)
    ident = {"internal_pk": "id", "internal_pk_type": "BIGINT UNSIGNED AUTO_INCREMENT", "public_id_required": t["public"] is not None}
    if t["public"]:
        ident.update({"public_id_reason": t["public"], "public_id_type": PUBLIC})
    else:
        ident["public_id_reason"] = "Registo interno: " + t["purpose"] + "; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada."
    ident.update({"immutable": True, "decision": "D-01 RESOLVED; ADR 0009 Accepted"})
    return {"name": t["name"], "purpose": t["purpose"], "domain": "RH / Payroll", "owner": "RH da unidade empregadora; regras estatutárias a nível nacional",
            "retention": "R-FINANCEIRO", "volume": "Até 10 mil vínculos; 1 milhão de linhas salariais", "sensitivity": t["sensitivity"],
            "columns": columns, "unique": t["unique"], "indexes": t["indexes"], "temporal": t["name"] in ("employments", "employment_compensations", "payroll_rules"), "identifiers": ident}


def dictionary_section(t, entry):
    ident = entry["identifiers"]
    if ident["public_id_required"]:
        idline = f"Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id {PUBLIC}`, NOT NULL, UNIQUE, imutavel, aplicacao; {t['public']}."
    else:
        idline = f"Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: {ident['public_id_reason']}"
    rows = ["| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |", "|---|---|---|---|---|---|---|---|---|---|---|"]
    for c in entry["columns"]:
        nul = ("nao" if c["name"] == "public_id" else "não") if not c["nullable"] else "sim"
        pk = "sim" if c["pk"] else ("nao" if c["name"] == "public_id" else "não")
        rows.append("| " + " | ".join([c["name"], c["type"], nul, c["default"], pk, c.get("target", "—") if c.get("fk") else "—",
                                         ", ".join(c["unique"]) or "—", ("PRIMARY" if c["pk"] else ", ".join(c["index"]) or "—"), c["description"], c["example"], c["sensitivity"]]) + " |")
    fks = [f"- FK `{c['name']}` → `{c['target']}`: {c['cardinality']}; ON DELETE RESTRICT; ON UPDATE RESTRICT. {FK_REASON}" for c in entry["columns"] if c.get("fk")]
    gen = [f"- Guarda física (delta P0.10-F2A): `{g[0]}` STORED GENERATED `{g[1]}` + UNIQUE `{g[2]}` ({', '.join(g[3])})." for g in t["generated"]]
    checks = [f"- CHECK `{n}`." for n, _ in t["checks"]]
    return (f"## {t['name']}\n\n{t['purpose']}.\n\nDomínio: RH / Payroll. Owner lógico: {entry['owner']}. Retenção: {entry['retention']}. Volume esperado: {entry['volume']}. "
            f"Sensibilidade base: {entry['sensitivity']}.\n\n{idline}\n\n" + "\n".join(rows) + "\n\n" + "\n".join(fks + gen + checks) + "\n")


def erd_entity(entry):
    out = [f"    {entry['name']} {{", "        bigint id PK"]
    for c in entry["columns"]:
        if c["name"] == "public_id":
            out.append("        char public_id UK")
        elif c.get("fk"):
            out.append(f"        bigint {c['name']} FK")
    out.append("    }")
    return out


def erd_relations(entry):
    return [f"    {c['fk']} {'|o' if c['nullable'] else '||'}--o{{ {entry['name']} : \"{c['name']}\"" for c in entry["columns"] if c.get("fk")]


def main():
    catalog_raw = CATALOG.read_bytes()
    catalog = json.loads(catalog_raw)
    names = {t["name"] for t in catalog["tables"]}
    if any(t["name"] in names for t in TABLES):
        raise SystemExit("payroll tables already in the catalog; generator refuses to run twice")
    files = []
    for n, t in enumerate(TABLES, start=1):
        f = f"2026_10_02_2000{n:02d}_p010_create_{t['name']}.php"
        (MIG / f).write_text(migration(t, n), encoding="utf-8", newline="\n")
        files.append(f)
    (MIG / INSTALLER).write_text("""<?php

declare(strict_types=1);

use App\\Domain\\Payroll\\PayrollCatalog;
use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;

// P0.10-F2A controlled data (ADR 0021 D24, D25, D31 + D-04A.15): the 9 HR permissions (data_type HR) and the 13 generic
// compensation component types. Idempotent, INSERT of missing rows only; an existing code with a different meaning aborts
// (never repaired). No role, no employment, no payroll rule, no rate, no bracket and no amount is seeded.
return new class extends Migration
{
    public function up(): void { PayrollCatalog::install(DB::connection()); }
    public function down(): void { /* controlled payroll data is preserved (never reset, no hard delete) */ }
};
""", encoding="utf-8", newline="\n")
    files.append(INSTALLER)

    entries = [catalog_entry(t) for t in TABLES]
    catalog["tables"].extend(entries)
    CATALOG.write_text(json.dumps(catalog, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    new_hash = hashlib.sha256(CATALOG.read_bytes()).hexdigest()
    old_hash = hashlib.sha256(catalog_raw).hexdigest()

    d = DICT.read_text(encoding="utf-8")
    marker = "\n## P0.2-F: candidatos fisicos e configuracao"
    assert marker in d
    sections = "".join("\n" + dictionary_section(t, e) for t, e in zip(TABLES, entries))
    DICT.write_text(d.replace(marker, sections + marker, 1), encoding="utf-8", newline="\n")

    erd = ERD.read_text(encoding="utf-8").split("\n")
    first_rel = next(i for i, l in enumerate(erd) if "{" not in l and ' : "' in l)
    entities = [l for e in entries for l in erd_entity(e)]
    erd[first_rel:first_rel] = entities
    while erd and erd[-1] == "":
        erd.pop()
    erd += [l for e in entries for l in erd_relations(e)]
    ERD.write_text("\n".join(erd) + "\n", encoding="utf-8", newline="\n")

    m = json.loads(MANIFEST.read_text(encoding="utf-8"))
    m["phase"] = "P0.10-F1A+F1B+F1C+F2A"
    m["migrations"] += files
    m["materialized_tables_f2a"] = [t["name"] for t in TABLES]
    for t in TABLES:
        for g in t["generated"]:
            m["column_deltas"].append(f"{t['name']}:{g[0]}:YES:tinyint unsigned")
            m["index_deltas"].append(f"{t['name']}:{g[2]}:{','.join(g[3])}")
            m["generated_columns"][f"{t['name']}.{g[0]}"] = g[1]
        m["checks"][t["name"]] = [n for n, _ in t["checks"]]
    m["controlled_data_counts"].update({"hr_permissions": 9, "compensation_component_types": 13, "payroll_rules": 0, "payroll_rule_brackets": 0})
    m["forbidden_columns"] += ["employments.full_name", "employments.sex_id", "employments.birth_date", "employments.phone", "employments.department_id", "employments.ministerial_assignment_id",
                               "employments.organizational_post_id", "employments.person_employment_id", "employment_compensations.currency_code", "payroll_runs.department_id"]
    MANIFEST.write_text(json.dumps(m, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")

    refreshed = []
    for name in ("wave2_manifest.json", "wave5_migrations_manifest.json"):
        p = ROOT / "docs/database/physical" / name
        text = p.read_text(encoding="utf-8")
        if old_hash in text:
            p.write_text(text.replace(old_hash, new_hash), encoding="utf-8", newline="\n")
            refreshed.append(name)
    print(json.dumps({"migrations": files, "catalog_sha256": {"before": old_hash, "after": new_hash}, "refreshed": refreshed, "tables": [t["name"] for t in TABLES]}, indent=2))


if __name__ == "__main__":
    main()
