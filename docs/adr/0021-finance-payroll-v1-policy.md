# ADR 0021: Finanças + RH/FIN-PAYROLL — política V1 (ledger, consolidação, orçamento, payroll)

**Status:** Accepted

**Data:** 2026-09-30

**Fase:** P0.10-D (preflight + decision freeze). Baseline `a3d7c5f3405c5c17ee5783251f1cf32068a7cc13` = `main` = `origin/main` (Academia, People/Families, Territorial, Physical Locations, Documents/Files e Membership integrados e publicados). Branch `p010-finance-payroll`, criado directamente desse commit com working tree limpo.

**Adenda:** D-04A (P0.10-D1, 2026-09-30) — origem, aplicação, custódia e consolidação de fundos; contas de controlo interunidades; investimento capitalizável; gate de produção de payroll. Os pontos de D08/D09/D10/D15/D16/D21/D26 que ela ajusta estão marcados "(D-04A)".

**Refina, sem reabrir:** ADR 0005 (ledger com partidas dobradas; transferências internas não duplicam receita), §15–§20, §33 e §44 de `mepa_crm_v1.1.1.md`, `04_database_constraints.md` (ledger), `11_financial_invariants_test_plan.md`, ADR 0009 (public_id), ADR 0017 (People), ADR 0018/0020 (TerritorialAuthority), ADR 0019 (Files).

## Contexto

A matriz P0.4 classifica **Finance como L0** (obrigatório para o V1, nunca FUTURE), **HR como L1** e **FIN-PAYROLL-01 como aberto, P1, obrigatório antes do fecho de Finance**. O desenho canónico da Wave 6 é maduro, mas nenhuma tabela financeira é física.

### Inventário financeiro real

Os nomes históricos pedidos (`movimentos_financeiros`, `categorias_financeiras`, `tipos_movimentos`, `investimentos`, `contas`, `caixas`, `bancos`) **não existem** em nenhum ponto do repositório: nem migrations, nem `backups/schema/laravel_schema_wamp_20260917_180403.sql`, nem documentação além das rubricas em prosa da §16. Não há modelo legado financeiro a migrar. A única fonte é o catálogo canónico Wave 6 (`docs/database/model_catalog.json`, `migration_waves.json` wave 6, `mepa_erd_financeiro.mmd`).

| Recurso (nome físico canónico) | DB | Constraints | Application | API | UI | Permission | Audit | Tests | Nível | Gap |
|---|---|---|---|---|---|---|---|---|---|---|
| `currencies` | Catálogo | Catálogo (UNIQUE code) | — | — | — | — | — | — | L0 | Sem coluna de escala; sem seed AOA |
| `accounting_periods` | Catálogo | UNIQUE (starts_on, ends_on) | — | — | — | — | — | Protótipo T10 | L0 | Sem tipo MONTH/YEAR; sem fecho por unidade |
| `chart_of_accounts` | Catálogo | UNIQUE code | — | — | — | — | — | — | L0 | Sem papel de sistema; plano nacional não aprovado |
| `accounts` (caixas/bancos) | Catálogo | UNIQUE (unit_id, code) | — | — | — | — | — | — | L0 | Sem `public_id` (F-06); sem abertura/fecho |
| `bank_account_details` / `cash_registers` | Catálogo | UNIQUE account_id | — | — | — | — | — | — | L0 | Cifra via Files D06 a reutilizar |
| `funds` | Catálogo | UNIQUE code | — | — | — | — | — | — | L0 | Sem seed |
| `financial_categories` (rubricas) | Catálogo | UNIQUE code | — | — | — | — | — | — | L0 | Sem natureza económica; seed bloqueado até agora |
| `journal_entries` / `journal_lines` | Catálogo | UNIQUE public_id/reference/idempotency; CHECKs só propostos | — | — | — | — | — | Protótipo P0.2-F (MariaDB) | L0 | Sem unidade dona, tipo, estados de submissão, UNIQUE de estorno |
| `financial_parties` | Catálogo | XOR proposto | — | — | — | — | — | — | L0 | — |
| `contributions` | Catálogo | XOR/IN_KIND propostos | — | — | — | — | — | — | L0 | Estados de valorização |
| `obligation_rules` / `obligations` / `contribution_allocations` | Catálogo | — | — | — | — | — | — | — | L0 | **Diferidos** (D05) |
| `receivables` / `payables` / `settlements` / `settlement_allocations` | Catálogo | CHECK amount>0 proposto | — | — | — | — | — | Protótipo T09 | L0 | Estados |
| `budgets` / `budget_lines` | Catálogo | UNIQUE (unit, period, fund, version) | — | — | — | — | — | — | L0 | Guarda de uma versão aprovada |
| `internal_transfers` / `transfer_postings` | Catálogo | UNIQUE (transfer, stage) | — | — | — | — | — | Protótipo T03–T08 | L0 | Unidade destino; conta destino só na recepção |
| `bank_statements` / `bank_statement_lines` / `reconciliations` / `reconciliation_matches` | Catálogo | UNIQUE hash/linha/versão | — | — | — | — | — | — | L0 | — |
| `financial_documents` | Catálogo | UNIQUE (entry, document) | — | — | — | — | — | — | L0 | Tipos de documento financeiros |
| Aprovações | `workflows`/`workflow_instances` físicos (Wave 2), vazios salvo `MEMBERSHIP_TRANSFER` | — | — | — | — | — | — | — | L1 | Workflows financeiros por instalar |
| Fechos | Só `accounting_periods` (catálogo) | — | — | — | — | — | — | — | L0 | Fecho por unidade |
| Relatórios | — | — | — | — | — | — | — | — | L0 | Tudo |
| Documentos | `legal_documents`/`document_versions`/`files` **L7** (ADR 0019) | físicas | L7 | L7 | L7 | FILES_*/DOCUMENTS_* | sim | sim | L7 | Ligação Finance |
| Autoridade | `TerritorialAuthority` **L7**, `permissions`/`roles`/`scopes`/`user_role_scopes` físicos | — | L7 | — | — | nenhuma FINANCE_* | `audit_logs` físico | — | L7 | Catálogo FINANCE/HR |
| Idempotência | `idempotency_requests` físico (Wave 2) | UNIQUE (actor, operation, client_key) | usado por outros domínios | — | — | — | — | — | L7 infra | — |

### Inventário RH real

| Recurso | Estado |
|---|---|
| `employment_types` + `person_employment` (Wave 2) | Físicos e vazios. Descrevem a **situação profissional da Pessoa** (profissão, tipo, período) — sem unidade empregadora MEPA, sem contrato, sem remuneração. Não são o vínculo laboral MEPA. |
| `person_unit_contexts` (P0.5) | Físico; `context_kind` já inclui `EMPLOYMENT` para dar autoridade People a uma unidade sobre um empregado sem outro contexto. |
| Posições/funções | `positions`, `functions`, `organizational_posts`, `ministerial_assignments`, `function_assignments` físicos: são **ministério eclesiástico**, não emprego. |
| Contratos, salário, histórico salarial, subsídios, INSS, pensões, assiduidade, férias, períodos e processamentos salariais | **Inexistentes** no schema físico e no catálogo canónico. |

### Fontes de decisão desta fase

As decisões institucionais marcadas **[DI]** abaixo foram tomadas pelo responsável do projecto nesta sessão (2026-09-30), em resposta directa às alternativas apresentadas. Ficam registadas como decisão D-04 V1. A validação posterior pelo contabilista de **nomes** de contas e rubricas é não bloqueante: os códigos são estáveis e os nomes podem mudar sem migration.

## D01 [DI]: modelo contabilístico

1. **V1 é contabilidade de partidas dobradas**, não um ledger simples de entradas/saídas. Isto já estava aceite no ADR 0005 e na §15; esta fase confirma-o expressamente.
2. **Regime: acréscimo (accrual) completo.** Receita e gasto são reconhecidos quando o facto ocorre:
   - despesa com factura/documento → `payables` reconhecido (Dr gasto / Cr fornecedores) e liquidado depois por `settlements`;
   - direito a receber → `receivables` (Dr a receber / Cr receita) e liquidado por `settlements`;
   - dízimos, ofertas e doações: reconhecidos quando recebidos (não existe direito exigível antes);
   - pagamento imediato (ex.: táxi pago em caixa) é um lançamento directo Dr gasto / Cr caixa: o facto e o pagamento coincidem;
   - payroll: gasto e passivos no lançamento do processamento; pagamento separado (D26).
3. **Não se cria plano de contas nacional completo.** V1 instala um **plano estrutural mínimo** (D08) e usa `financial_categories` (rubricas §16) como dimensão económica. Cada rubrica aponta para uma conta de controlo. Um plano completo (ex.: compatível com o PGC angolano) pode ser acrescentado depois, mapeando rubricas para novas contas sem reescrever lançamentos.
4. A estrutura suporta audit, reconciliação, consolidação, estorno, payroll e expansão futura porque todo o saldo e todo o resultado derivam de linhas equilibradas imutáveis.

## D02: moeda e representação monetária

- **V1 é mono-moeda: AOA (Kz)** (§44). `currencies` recebe só `AOA`. Nenhum desenho de FX; todas as colunas `currency_id` continuam presentes e o serviço rejeita tudo o que não seja AOA.
- Armazenamento: `DECIMAL(19,4)` (catálogo). **Nunca FLOAT/DOUBLE.**
- Escala de negócio: **2 casas decimais** (cêntimos). Nova coluna `currencies.minor_units = 2`. Valores de entrada com mais casas são rejeitados (`422 AMOUNT_SCALE`), nunca arredondados em silêncio.
- PHP: strings decimais validadas → inteiros escalados (cêntimos) em 64-bit; nenhuma aritmética em float. Limite: |valor| ≤ 999 999 999 999,99.
- Arredondamento só existe no cálculo salarial (taxas): **half-up a 2 casas por linha de componente**, depois somado. O total nunca é arredondado separadamente.

## D03: dimensões — separação obrigatória

| Dimensão | Onde vive | Nunca confundir com |
|---|---|---|
| Unidade dona | `journal_lines.unit_id` (e `journal_entries.unit_id`) | Departamento |
| Natureza económica | `financial_categories.economic_nature` (D09) | Meio de pagamento |
| Rubrica | `financial_categories` | Conta financeira |
| Meio/canal | Conta financeira `accounts.account_kind` (`CASH`/`BANK`) | Rubrica |
| Origem interna/externa | `journal_entries.entry_kind` + parte (`financial_parties`) ou `internal_transfers` | Rubrica |
| Estado operacional | `journal_entries.status` e estados dos subledgers | Natureza |
| Fundo | `fund_id` (V1: só `GENERAL`) | Rubrica |

## D04 [DI]: dinheiro vs produto (em espécie)

- Toda a contribuição em espécie é registada em `contributions` com `contribution_kind='IN_KIND'` e `in_kind_description` obrigatória.
- Só entra no ledger/DRE depois de **valorização monetária aprovada**: `valuation_amount` informado por quem tem `FINANCE_MANAGE`, aprovado por quem tem `FINANCE_POST`, com **documento de suporte obrigatório** (`VALUATION_REPORT`, D14). A aprovação publica Dr `IN_KIND_ASSETS` (ou o gasto da rubrica de consumo indicada, D08) / Cr receita `REV_IN_KIND`.
- Valor desconhecido é permitido: fica `valuation_status='UNVALUED'`, **nunca** no DRE, e aparece no relatório "Contribuições em espécie não valorizadas" (quantidade e descrição, sem valor).
- V1 não gere inventário/stock dos bens recebidos.

## D05 [DI]: âmbito V1 dos subledgers

| Subledger | V1 |
|---|---|
| Ledger, contas, rubricas, fundos, períodos, fechos | Sim |
| Contribuições (identificadas/anónimas/agregadas, monetárias e em espécie) | Sim |
| `payables`, `receivables`, `settlements`, `settlement_allocations` | **Sim** (consequência do regime de acréscimo) |
| Transferências internas | Sim |
| Extractos e reconciliação | Sim |
| Orçamento | Sim |
| Payroll (FIN-PAYROLL-01) | Sim |
| `obligation_rules`, `obligations` (quotas previstas/vencidas §18), `contribution_allocations` | **Diferidos**. As tabelas **não** são criadas nesta fase. `receivables.obligation_id` fica sempre NULL na V1. |
| Empréstimos (módulo), registo de activos fixos/depreciação, FX, fundos restritos múltiplos | Diferidos (não bloqueantes). A capitalização ao custo existe na V1 (D-04A.12); só o registo por bem e a depreciação são diferidos |

## D06: contas financeiras

- `accounts.account_kind` ∈ {`CASH`, `BANK`} (CHECK). Nenhum outro tipo sem ADR.
- Cada conta tem: `unit_id` (dona), `public_id` (novo, F-06), `code` único na unidade, `status` ∈ {`OPEN`, `CLOSED`}, `opened_on`, `closed_on`, `ledger_account_id` (conta de controlo `CASH` ou `BANK`), moeda AOA.
- `BANK` tem `bank_account_details` (número cifrado com o mecanismo de chave do ADR 0019 D06; a UI mostra só os 4 últimos dígitos). `CASH` tem `cash_registers` com custodiante (`people.id`).
- **Saldo inicial** = um lançamento `OPENING_BALANCE` (Dr conta / Cr `OPENING_NET_ASSETS`) na abertura. Nunca uma coluna.
- **Fonte canónica do saldo:** `SUM(debit) − SUM(credit)` das `journal_lines` com `financial_account_id = conta` cujo cabeçalho está `POSTED`, até à data. **Nenhuma coluna de saldo mutável existe.** Snapshots de fecho, se vierem, são derivados e verificáveis, nunca fonte.
- Fechar conta exige saldo zero e nenhum rascunho/submissão pendente; reabrir não existe (abre-se outra conta).
- Reconciliação: `BANK` contra extracto; `CASH` contra contagem física (reconciliação sem `statement_id`, com `counted_balance`).

## D07: movimento financeiro

### Modelo

Um "movimento" é um `journal_entries` com as suas `journal_lines`. A UI oferece formulários simples (receita, despesa, transferência entre contas) que geram as linhas; o utilizador comum nunca escolhe débito/crédito.

Novas colunas em `journal_entries`: `unit_id` (unidade dona; **toda a entry V1 tem todas as linhas na mesma unidade**), `entry_kind`, `submitted_by`, `submitted_at`, `reason` (obrigatório em `REVERSAL`/`ADJUSTMENT`). `UNIQUE (reversal_of_id)`.

`entry_kind` V1 (validado pelo serviço):

| Código | Gera | Origem |
|---|---|---|
| `REVENUE` | Dr caixa/banco / Cr receita | Formulário |
| `EXPENSE` | Dr gasto / Cr caixa/banco | Formulário (pagamento imediato) |
| `ACCOUNT_TRANSFER` | Dr conta B / Cr conta A, mesma unidade | Formulário (D10 caso A) |
| `OPENING_BALANCE` | Dr conta / Cr `OPENING_NET_ASSETS` | Abertura de conta, saldos iniciais de payables/receivables |
| `ADJUSTMENT` | Linhas livres equilibradas, só contas postáveis | `FINANCE_POST`, motivo obrigatório |
| `REVERSAL` | Inverso exacto de outra entry | D11 |
| `CONTRIBUTION` | Receita de `contributions` | Subledger |
| `PAYABLE_RECOGNITION` / `RECEIVABLE_RECOGNITION` / `SETTLEMENT` | Subledgers | Subledger |
| `TRANSFER_SEND` / `TRANSFER_RECEIVE` / `TRANSFER_REVERSE_SEND` | D10 caso B | `internal_transfers` |
| `PAYROLL_ACCRUAL` / `PAYROLL_PAYMENT` / `PAYROLL_REVERSAL` | D26 | Payroll |
| `LIABILITY_PAYMENT` | Dr passivo (INSS/retenções) / Cr banco | Remessa de retenções |

### Lifecycle V1

`journal_entries.status`:

| Estado | Significado |
|---|---|
| `DRAFT` | Editável por `FINANCE_MANAGE` |
| `SUBMITTED` | Aguardando publicação; linhas congeladas |
| `POSTED` | **Imutável.** Único estado que conta para saldos e relatórios |
| `DISCARDED` | Rascunho/submissão abandonado; preservado, nunca conta |

Transições: `DRAFT→SUBMITTED`, `SUBMITTED→DRAFT` (devolvido), `SUBMITTED→POSTED`, `DRAFT→POSTED` (publicação directa por quem tem `FINANCE_POST`), `DRAFT|SUBMITTED→DISCARDED`. "Aprovado" **é** a publicação: não existe estado `APPROVED` separado. "Cancelado/estornado" não é estado da entry original: o estorno é outra entry (D11).

Entries geradas por subledgers (contribuição, transferências, payroll, liquidações) nascem já `POSTED` dentro da transacção do subledger; não passam por `DRAFT`.

### Segregação [DI]

Por **permissão**: `FINANCE_MANAGE` prepara, `FINANCE_POST` publica. A mesma pessoa pode ter ambas (Congregação com um só tesoureiro). Cada publicação audita `created_by`, `submitted_by` e `posted_by`. Uma flag de configuração futura pode exigir maker≠checker sem mudar schema. **Excepções sempre com maker≠checker:** aprovação de payroll, aprovação de regras salariais, aprovação de orçamento e reabertura de período (D12, D13, D26).

### Publicação (validações sob lock)

Lock order (estende `04_database_constraints.md`): período nacional → fecho da unidade → documento de subledger (transfer/payable/receivable/run) → contas financeiras por `id` → cabeçalho/`idempotency_requests`. Verifica: ≥2 linhas; `debit ≥ 0`, `credit ≥ 0`, exactamente um positivo por linha (CHECK); Σdébito = Σcrédito (DECIMAL exacto); **equilíbrio por unidade** (V1: uma unidade por entry); moeda única AOA; contas activas/postáveis; `entry_date` não futura (data de `Africa/Luanda`) e dentro de período aberto para a unidade (D12); `lock_version` esperado; replay de idempotência com o mesmo hash devolve a mesma entry, hash diferente → `409 IDEMPOTENCY_CONFLICT`.

## D08: plano estrutural mínimo (`chart_of_accounts`)

Nova coluna `system_role` (VARCHAR(64) NULL, UNIQUE). Estas contas são instaladas por catálogo; nomes ajustáveis pelo contabilista sem mudar códigos.

| system_role | account_kind | Uso |
|---|---|---|
| `CASH` | ASSET | Controlo de todas as contas caixa (detalhe por `financial_account_id`) |
| `BANK` | ASSET | Controlo de todas as contas bancárias |
| `RECEIVABLES` | ASSET | Recebíveis |
| `IN_KIND_ASSETS` | ASSET | Contrapartida de bens em espécie valorizados enquanto não consumidos. Se a valorização indicar uma rubrica (material, investimento), o débito vai à conta de controlo dessa rubrica: gasto, ou `FIXED_ASSETS` se capitalizável (D-04A) |
| `FIXED_ASSETS` | ASSET | Bens adquiridos classificados como investimento capitalizável (D-04A.12), ao custo; sem depreciação na V1 |
| `PAYABLES` | LIABILITY | Fornecedores/pagáveis |
| `PAYROLL_NET_PAYABLE` | LIABILITY | Salários líquidos a pagar |
| `PAYROLL_WITHHOLDINGS` | LIABILITY | Retenções ao empregado (INSS trabalhador, IRT…) |
| `PAYROLL_EMPLOYER_CHARGES` | LIABILITY | Encargos da entidade a pagar (INSS entidade…) |
| `LOANS_PAYABLE` | LIABILITY | Só saldos iniciais e amortizações de empréstimos existentes (§16.10: principal não é gasto) |
| `OPENING_NET_ASSETS` | EQUITY | Contrapartida de saldos iniciais |
| `INTERUNIT_CLEARING_OUT` | INTERUNIT_CONTROL | Controlo interunidades — fundos internos enviados (D10, D-04A). Conta técnica de balanço; nunca receita, gasto nem equity |
| `INTERUNIT_CLEARING_IN` | INTERUNIT_CONTROL | Controlo interunidades — fundos internos recebidos (D10, D-04A). Conta técnica de balanço; nunca receita, gasto nem equity |
| `OPERATING_INCOME` | INCOME | Receitas §16.1 |
| `NON_OPERATING_INCOME` | INCOME | Entradas §16.10 |
| `OPERATING_EXPENSE` | EXPENSE | §16.2–16.6 |
| `INVESTMENT_EXPENSE` | EXPENSE | §16.7 e §16.9 [DI D09]; §16.8 só quando a rubrica não é capitalizável (D-04A.12) |
| `NON_OPERATING_EXPENSE` | EXPENSE | Saídas §16.10 que são gasto (juros de mora…) |

Resultado acumulado não é conta: é derivado (Σ INCOME − Σ EXPENSE). Fecho anual com apuramento de resultado é diferido (não bloqueante).

## D09 [DI]: modelo económico e rubricas

`financial_categories` recebe `economic_nature` (CHECK). Transferências internas **nunca** usam natureza de receita/gasto.

| Natureza | Conceito pedido | Conta de controlo | Secção do DRE |
|---|---|---|---|
| `OPERATING_REVENUE` | REVENUE | OPERATING_INCOME | Receitas |
| `COST_OF_SALES` | EXPENSE | OPERATING_EXPENSE | Custos com produtos e serviços |
| `ADMINISTRATIVE_EXPENSE` / `FINANCIAL_EXPENSE` / `PERSONNEL_EXPENSE` / `MATERIALS_EXPENSE` | EXPENSE | OPERATING_EXPENSE | Despesas |
| `INVESTMENT` | INVESTMENT | INVESTMENT_EXPENSE ou `FIXED_ASSETS` conforme a rubrica (D-04A.12) | **Investimentos** (após resultado operacional) quando consumidos; aquisição capitalizada fica fora do resultado (memo). Sem depreciação na V1 [DI] |
| `NON_OPERATING_INCOME` / `NON_OPERATING_EXPENSE` | — | NON_OPERATING_* | Não operacionais |
| `INTERNAL_TRANSFER` | TRANSFER | INTERUNIT_CLEARING_* | Fora do resultado (memo) |
| `BALANCE_SHEET` | — | LOANS_PAYABLE etc. | Fora do resultado (fluxo de caixa) |

- **RETURN/ROI** = rubrica `REV_INVESTMENT_RETURN` (§16.1), natureza `OPERATING_REVENUE`, linha própria no DRE.
- **ADJUSTMENT** não é natureza: é `entry_kind`; herda a natureza das rubricas das linhas.
- Catálogo instalado **exactamente** a partir da §16 (única fonte real), grupos 16.x como nós pai não postáveis:

| Grupo | Códigos (nome = texto da §16) |
|---|---|
| 16.1 Receitas | `REV_TITHES` Dízimos · `REV_SELECTIVE_TITHES` Dízimos Selectivos · `REV_OFFERINGS` Ofertas · `REV_RAISED_OFFERINGS` Ofertas Alçadas · `REV_SPECIAL_OFFERINGS` Ofertas Especiais · `REV_BUDGET_QUOTAS` Quotas Orçamentais · `REV_DEPARTMENT_QUOTAS` Quotas de Departamento · `REV_CONTRIBUTIONS` Contribuições · `REV_SELECTIVE_CONTRIBUTIONS` Contribuições Selectivas · `REV_DONATIONS` Doações · `REV_INVESTMENT_RETURN` Retorno sobre Investimentos · `REV_OTHER` Outras Entradas · `REV_IN_KIND` Contribuições em espécie valorizadas (D04) |
| 16.2 | `COS_SUPPLIERS` · `COS_TRANSPORT` · `COS_OUTSOURCED_LABOUR` |
| 16.3 | `ADM_MOBILE` · `ADM_POSTAGE` · `ADM_TAXI` · `ADM_INTERNET` · `ADM_TV` · `ADM_ELECTRICITY` · `ADM_FUEL` · `ADM_MEALS` · `ADM_WATER` · `ADM_CLEANING` · `ADM_TRAINING` · `ADM_OTHER` |
| 16.4 | `FIN_BANK_FEES` · `FIN_OTHER` |
| 16.5 | `PER_SALARY` · `PER_THIRTEENTH` · `PER_HOLIDAY_SUBSIDY` · `PER_SOCIAL_ASSISTANCE` · `PER_INSS` · `PER_HEALTH_PLAN` · `PER_TRANSPORT_MEAL` · `PER_OTHER` |
| 16.6 | `MAT_MATERIALS` · `MAT_EQUIPMENT` · `MAT_OTHER` |
| 16.7 | `INV_COM_LEAFLETS` · `INV_COM_POSTERS` · `INV_COM_DIGITAL` · `INV_COM_RADIO_TV` · `INV_COM_EVENTS` · `INV_COM_OTHER` |
| 16.8 | `INV_AST_IT` · `INV_AST_INFRASTRUCTURE` · `INV_AST_FURNITURE` · `INV_AST_ELECTRICAL` · `INV_AST_UNIFORMS` · `INV_AST_UTENSILS` · `INV_AST_OTHER` |
| 16.9 | `INV_DEV_CONSULTING` · `INV_DEV_TRAINING` · `INV_DEV_BUSINESS` · `INV_DEV_REFRESH` · `INV_DEV_OTHER` |
| 16.10 | `NOP_IN_USED_EQUIPMENT` · `NOP_IN_OTHER` · `NOP_OUT_LATE_INTEREST` · `NOP_OUT_OTHER` · `BS_LOAN_PRINCIPAL` (natureza `BALANCE_SHEET`) · `BS_PAST_DEBTS` (liquidação de payables antigos; `BALANCE_SHEET`) |
| Transferências (finalidade, D-04A.6) | `TRF_REMITTANCE` Remessa regular · `TRF_BUDGET_QUOTA` Quota orçamental entre unidades · `TRF_SPECIAL_CONTRIBUTION` Contribuição especial · `TRF_SUPPORT` Apoio · `TRF_PROJECT` Transferência de projecto · `TRF_OTHER` Outra |

- **"Arrendamento" (pendência P0-FIN) não é instalado** até classificação; entretanto usa-se `ADM_OTHER`. `classification_status` ∈ {`APPROVED`, `PENDING_REVIEW`}.
- **Regra de origem:** se a contraparte é uma unidade MEPA, a operação **tem de ser** `internal_transfers`. `payables`/`receivables`/`contributions` recusam `financial_parties` do tipo `UNIT` (`422 INTERNAL_COUNTERPARTY`). `REV_BUDGET_QUOTAS`/`REV_DEPARTMENT_QUOTAS` como receita só existem com contraparte externa ou Pessoa; quota paga por uma unidade é `TRF_BUDGET_QUOTA`.
- Fundos: V1 instala só `GENERAL` (sem restrição). Fundos restritos são não bloqueantes; `fund_id` já está em todo o lado.

## D10 [DI]: transferências

### Caso A — entre contas da mesma unidade

Uma entry `ACCOUNT_TRANSFER`: Dr conta destino / Cr conta origem, ambas da mesma unidade, sem rubrica. Não é receita nem gasto. Atómica (uma entry). Sem `internal_transfers`. Depósito em trânsito bancário não existe na V1.

### Caso B — entre duas unidades MEPA (fluxo interno nominal)

`internal_transfers` passa a ter `origin_unit_id` e `destination_unit_id` (NOT NULL, CHECK diferentes); `destination_account_id` fica **NULL até à recepção** (a origem nunca vê contas do destino). `category_id` ∈ rubricas `INTERNAL_TRANSFER`. Workflow `FINANCE_INTERNAL_TRANSFER` v1 (FK NOT NULL existente).

| Etapa | Unidade | Débito | Crédito | Quem |
|---|---|---|---|---|
| `SEND` | Origem O | `INTERUNIT_CLEARING_OUT` (contraparte D) | Caixa/Banco O | `FINANCE_TRANSFER` + `FINANCE_POST` sobre O |
| `RECEIVE` | Destino D | Caixa/Banco D | `INTERUNIT_CLEARING_IN` (contraparte O) | `FINANCE_TRANSFER` + `FINANCE_POST` sobre D |
| `REVERSE_SEND` | Origem O | Caixa/Banco O | `INTERUNIT_CLEARING_OUT` | Só antes de `RECEIVE`; motivo obrigatório |

- **Cada etapa só toca uma unidade**: cada unidade fecha o seu período sem depender da outra.
- Estados V1: `DRAFT → SENT → RECEIVED`; `DRAFT → CANCELLED`; `SENT → CANCELLED` (com `REVERSE_SEND`: devolução/recusa). `RECEIVED` é final; corrige-se com nova transferência em sentido inverso. `AUTHORIZED` e `RECONCILED` do catálogo **não** são usados: a autorização é a publicação do SEND, e a recepção exige montante igual (a conciliação é automática por par).
- `RECEIVE` recebe exactamente `amount`. Tarifa bancária é despesa `FIN_BANK_FEES` separada de quem a suporta.
- `transfer_postings` `UNIQUE (transfer_id, posting_stage)` garante SEND/RECEIVE únicos; `posting_stage` CHECK ∈ {`SEND`,`RECEIVE`,`REVERSE_SEND`}.
- `journal_lines.counterparty_unit_id` (novo, NULL) é **obrigatório** nas linhas de `INTERUNIT_CLEARING_*` e proibido nas outras.
- Datas: `RECEIVE.entry_date` = data real de recepção, ≥ data do SEND. Se o período dessa data está fechado para D, publica-se na primeira data de período aberto de D e guarda-se `received_at` real.
- Destino: qualquer unidade `ACTIVE`. A regra "só para cima" não é imposta sem fonte institucional.

## D11: estorno / reversal

- Entry `POSTED` nunca é alterada nem apagada (serviço + nenhum caminho de UPDATE/DELETE; validador de schema verifica ausência de CRUD).
- Correcção = entry `REVERSAL` com `reversal_of_id` = original, linhas inversas exactas, `reason` obrigatório; `UNIQUE (reversal_of_id)` impede estorno duplo. Não se estorna um estorno (publica-se nova entry correcta).
- Quem: `FINANCE_REVERSE` sobre a unidade da entry.
- Período: o estorno é datado num período **aberto** para a unidade (normalmente o corrente). A original em período fechado permanece; o efeito correctivo aparece no período do estorno.
- Entries de subledger (transferência, payroll, liquidação, contribuição) só se estornam pelo seu próprio fluxo (`REVERSE_SEND`, reversão de payroll, cancelamento de liquidação), nunca pelo estorno genérico (`409 SUBLEDGER_OWNED`).
- Audit: `finance.entry_reversed` na unidade da entry, com `public_id` de ambas.

## D12: períodos e fechos

- **Ano financeiro = ano civil** (Jan–Dez). Sem fonte institucional para outro.
- `accounting_periods` recebe `code` (UNIQUE, `2026-10` / `2026`) e `period_kind` ∈ {`MONTH`, `YEAR`}. **Período contabilístico base = mês.** Só `MONTH` aceita lançamentos; `YEAR` serve orçamento e relatórios. Trimestre e semestre são intervalos derivados de meses, sem linhas.
- Períodos criados por comando idempotente por ano (1 `YEAR` + 12 `MONTH`), nunca por seed de datas.
- **Dois níveis de fecho:**
  1. **Fecho da unidade** — nova tabela `accounting_period_unit_closes (period_id, unit_id, status CLOSED|REOPENED, closed_at/by, reopened_at/by, reason, lock_version)`, UNIQUE `(period_id, unit_id)`. Cada unidade fecha o seu mês com `FINANCE_PERIOD_CLOSE`. Bloqueado com entries `DRAFT`/`SUBMITTED` da unidade nesse período.
  2. **Fecho nacional** — `accounting_periods.status` `OPEN → CLOSED`, por `FINANCE_PERIOD_CLOSE` com scope sobre a Direcção Geral (raiz). Exige todas as unidades com linhas no período fechadas (a recusa lista-as). **Irreversível na V1** (protótipo T10).
- Posting permitido sse o mês nacional está `OPEN` **e** não há fecho `CLOSED` da unidade.
- **Reabertura** só do fecho da unidade, enquanto o mês nacional estiver `OPEN`: `FINANCE_PERIOD_REOPEN` (permission separada, tipicamente concedida ao nível superior), motivo obrigatório, **reabridor ≠ quem fechou**. `REOPENED` pode voltar a `CLOSED`.
- Audit: `finance.period_unit_closed`, `finance.period_unit_reopened`, `finance.period_national_closed`.

## D13: orçamento

- `budgets` por unidade × período `YEAR` × fundo × `version`; `budget_lines` por rubrica (`requested_amount`, `approved_amount`). Naturezas orçamentáveis: receitas, gastos, investimentos, não operacionais.
- Estados (§20, sem "Em Execução" persistido): `DRAFT → SUBMITTED → REVIEWED → APPROVED`; `SUBMITTED|REVIEWED → DRAFT` (devolvido); `APPROVED → SUPERSEDED` (quando a revisão seguinte é aprovada); `APPROVED → CLOSED` (fim do ano); `DRAFT → CANCELLED`. "Em execução" é derivado (aprovado + ano corrente).
- **Guarda física:** coluna gerada `approved_guard = IF(status='APPROVED',1,NULL)` + UNIQUE `(unit_id, period_id, fund_id, approved_guard)` → no máximo uma versão aprovada.
- **Revisão:** nova versão `DRAFT` copiada da aprovada; aprovar v(n+1) passa v(n) a `SUPERSEDED` na mesma transacção. Nada é sobrescrito.
- Permissions: `FINANCE_BUDGET_MANAGE` (preparar/submeter), `FINANCE_BUDGET_APPROVE` (rever/aprovar); **aprovador ≠ submissor**. O nível que aprova é o dos grants (normalmente o superior), sem roles novas.
- Comparação: `budget` (aprovado) vs `actual` (ledger POSTED pela rubrica, regime de acréscimo) → `variance = actual − budget`, `variance % = variance / budget` (NULL se budget = 0). Mês/trimestre/semestre comparam YTD real contra o anual; faseamento mensal é diferido.

## D14: documentos

- Reutiliza Files/Documents L7. Ligação por `financial_documents (entry_id, document_id)` e pelos `document_id` dos subledgers. **Só `public_id`** externo.
- Novos `legal_document_types` (catálogo idempotente): `RECEIPT` Recibo · `INVOICE` Factura · `BANK_PROOF` Comprovativo bancário · `EXPENSE_VOUCHER` Documento de despesa · `TRANSFER_PROOF` Comprovativo de transferência · `BANK_STATEMENT` Extracto bancário · `VALUATION_REPORT` Avaliação de bem em espécie · `PAYSLIP` Recibo de vencimento · `PAYROLL_SHEET` Folha salarial · `PAYROLL_RULE_SOURCE` Fonte normativa de regra salarial. O existente `CONTRACT` serve o contrato de trabalho.
- Piso de classificação: documentos financeiros `CONFIDENTIAL`; `PAYSLIP`, `PAYROLL_SHEET` e contrato de trabalho `HIGHLY_SENSITIVE`.
- Autoridade **cumulativa**: permission Finance (ou HR) sobre a unidade da entry/objecto **e** autoridade Files sobre o documento (ADR 0019 D05). O `owner_unit_id` do documento = unidade do objecto financeiro.
- Anexo obrigatório só onde a política já o exige: `payables` (catálogo `document_id` NOT NULL), extracto bancário (`file_id` NOT NULL), valorização em espécie (D04), regra salarial aprovada (D25). Nos restantes é opcional.
- Anexar depois de `POSTED` é permitido (acrescenta evidência, não muda valores), auditado; desanexar de entry `POSTED` não.

## D15: consolidação — visões e invariante

### Visões

- **A) Own view `own(U, P)`**: linhas `POSTED` com `journal_lines.unit_id = U` e período em P.
- **B) Consolidated subtree `cons(U, P)`**: linhas `POSTED` com `unit_id ∈ S`, onde `S` = U + descendentes **na árvore vigente na data final de P** (`unit_parent_periods`), com eliminação de transferências internas.

Como cada linha tem exactamente uma unidade, `cons` é a união **disjunta** de `own(u)` para `u ∈ S`: não há dupla contagem por construção.

### Invariante formal

Para qualquer perímetro S e intervalo P:

- **I1 (transferência não é resultado).** Nenhuma entry `TRANSFER_*` ou `ACCOUNT_TRANSFER` tem linha em conta `INCOME`/`EXPENSE`. Garantido pelo serviço (contas fixas por `system_role`) e verificado por validador.
- **I2 (resultado aditivo).** `Receita_cons(S,P) = Σ_{u∈S} Receita_own(u,P)`; idem gastos, investimentos, não operacionais. Por I1 não há nada a eliminar no resultado.
- **I3 (par de transferência).** Para cada transferência t (O→D): no máximo uma linha `OUT(O, cp=D) = amount(t)` (SEND) e no máximo uma `IN(D, cp=O) = amount(t)` (RECEIVE), mesmo fundo/moeda; `REVERSE_SEND` anula o OUT.
- **I4 (eliminação).** Para t com O ∈ S e D ∈ S: OUT e IN são eliminados **pelo `transfer_id`**, independentemente do período de cada etapa. Se t está `SENT` e não recebido à data final de P, o OUT não eliminado é reclassificado como **"Fundos em trânsito interno"** (activo do perímetro). Para O ∈ S, D ∉ S (ou o inverso), o OUT (IN) aparece como "Transferências internas enviadas para (recebidas de) fora do perímetro", nunca como gasto (receita).
- **I5 (nacional).** Para S = raiz nacional: transferências para/de fora do perímetro = 0; disponibilidades (caixa + banco + trânsito) = saldos iniciais + fluxos externos líquidos.
- **I6 (reconhecimento único).** Uma receita externa é reconhecida uma única vez, na unidade que a recebe. Nenhum caminho converte uma recepção interna em receita.

### Exemplos de teste obrigatórios

**F-A** — Congregação C recebe 100 externos e transfere 80 ao Município M.

| Entry | Unidade | Dr | Cr |
|---|---|---|---|
| REVENUE | C | Caixa C 100 | OPERATING_INCOME (`REV_TITHES`) 100 |
| TRANSFER_SEND | C | INTERUNIT_CLEARING_OUT (cp M) 80 | Caixa C 80 |
| TRANSFER_RECEIVE | M | Caixa M 80 | INTERUNIT_CLEARING_IN (cp C) 80 |

Own C: receita externa 100; resultado 100; transferências enviadas 80; caixa 20. Own M: receita 0; transferências recebidas 80; caixa 80. **Cons M: receita 100 (não 180)**; par eliminado; caixa 100.

**F-B** — depois, M transfere 80 à Província P. Cons P: receita 100 uma única vez; t1 e t2 eliminados; disponibilidades C 20 + M 0 + P 80 = 100. Cons nacional: idem.

**F-C** — C envia 80 a M em Março; M recebe em Abril. Cons M em 31/Mar: receita 100; caixa 20; **trânsito interno 80**; disponibilidade 100. Own M em Março: nada. Em Abril: o par é eliminado (trânsito → caixa M), receita de Abril 0. Relatório de trânsito envelhecido lista t. Se Março de M já estava fechado, nada muda: o RECEIVE entra em Abril. Se a transferência for devolvida (`REVERSE_SEND`), o trânsito desaparece e a caixa C volta a 100.

Testes adicionais: estorno de receita após transferência mantém I2; mudança de pai de uma unidade entre períodos usa a árvore da data final; `REVERSE_SEND` concorrente com `RECEIVE` (C9).

## D16: DRE / demonstrativo V1

Estrutura (§17), para `own` e `cons`, por mês/trimestre/semestre/ano:

1. Receitas operacionais (por rubrica; ROI em linha própria)
2. − Custos com produtos e serviços
3. − Despesas (administrativas, financeiras, com pessoal, materiais e equipamentos)
4. **= Resultado operacional**
5. − Investimentos consumidos (comunicação, desenvolvimento, bens materiais não capitalizáveis); aquisições capitalizadas em `FIXED_ASSETS` só em memo (D-04A.12)
6. ± Não operacionais
7. **= Excedente / Défice (resultado económico)**
8. Memo, fora do resultado: transferências internas recebidas/enviadas (own) ou para/de fora do perímetro (cons); trânsito interno; contribuições em espécie não valorizadas (contagem).
9. Posição de tesouraria: saldo inicial, entradas, saídas, saldo final por conta caixa/banco (own) e agregada (cons), + trânsito interno.
10. Posição de obrigações: payables/receivables em aberto (salário líquido, retenções e encargos incluídos).

**Estratégia SQL/read-model:** consulta directa ao ledger, **nunca** soma de dashboards. Uma transacção `REPEATABLE READ` (snapshot único): perímetro por CTE recursivo sobre `unit_parent_periods` à data final → `journal_lines ⨝ journal_entries (POSTED, período)` agrupado por `system_role`/rubrica → eliminação por `transfer_postings.transfer_id`. Índices novos: `journal_lines (unit_id, ledger_account_id)`, `journal_lines (financial_account_id)`, `journal_entries (unit_id, period_id, status)`. Materialização/snapshots oficiais de fecho ficam para o vertical Reports, com prova de igualdade contra esta consulta.

## D17: autoridade territorial

- Resolver único `TerritorialAuthority` com `data_type='FINANCE'` (e `data_type='HR'` para RH/payroll). Nenhum segundo motor.
- Autoridade = permission + scope territorial activo + **unidade dona** do objecto (conta, entry, transferência — origem ou destino conforme a etapa —, orçamento, fecho, contribuição, payable/receivable, run). Nunca URI nem `public_id`.
- **Consolidado:** `FINANCE_CONSOLIDATED_VIEW` com grant cujo conjunto coberto contém U **e todos os descendentes actuais de U** (raiz em U ou antepassado com `include_descendants=1`). O agregado inclui o perímetro histórico da data final (D15); o drill-down para uma unidade exige `FINANCE_VIEW` actual sobre ela.
- Nível superior **não** recebe acesso nacional automático: só o que os grants cobrem.
- Catálogos nacionais (rubricas, fundos, plano, regras salariais) exigem scope sobre a Direcção Geral.
- Pesquisa/listagem filtra no SQL por `coveredUnits`.

## D18: departamento financeiro

O Departamento Financeiro executa funções através de grants (appointments/RBAC decidem quem tem as permissions), mas **o dono económico é sempre a unidade**. Nenhuma coluna `department_id` é dona de ledger, conta ou orçamento. Dimensão analítica por departamento é diferida e não bloqueante.

## D19: auditoria

`audit_logs` existente; `source` `P010_FINANCE` / `P010_HR`; `unit_id` = **unidade autorizadora** (dona do objecto; nas transferências, a da etapa, com a outra em metadata); `correlation_id` partilhado por todas as linhas de uma operação composta.

Obrigatórias: `finance.entry_created`, `.entry_submitted`, `.entry_posted`, `.entry_discarded`, `.entry_reversed`, `.adjustment_posted`, `finance.transfer_sent`, `.transfer_received`, `.transfer_cancelled`, `finance.reconciliation_matched`, `.reconciliation_closed`, `finance.account_opened`, `.account_closed`, `.bank_details_changed` (sem número em claro), `finance.budget_submitted`, `.budget_approved`, `.budget_revised`, `finance.period_unit_closed`, `.period_unit_reopened`, `.period_national_closed`, `finance.contribution_valued`, `finance.payable_recognized`, `.settlement_posted`, `finance.document_attached`, `finance.export`, `hr.employment_created`, `.employment_ended`, `hr.compensation_changed`, `payroll.rule_approved`, `payroll.run_calculated`, `.run_approved`, `.run_posted`, `.run_paid`, `.run_reversed`, e **leitura sensível**: `hr.compensation_viewed`, `payroll.run_detail_viewed`, `payroll.payslip_downloaded`, `finance.contributor_detail_viewed`. Valores monetários de pessoas nunca entram em `before/after_metadata`; só indicadores de campo alterado e `public_id`.

## D20: F-06 / IDOR

- `public_id` (ULID) em: `accounts` (novo), `journal_entries`, `internal_transfers`, `budgets`, `contributions`, `payables`, `receivables`, `settlements`, `bank_statements`, `reconciliations`, `employments`, `payroll_runs`. Períodos por `code`; fechos por (`code`, unidade `public_id`); linhas nunca endereçadas isoladamente.
- Permission verificada **antes** de resolver o alvo (P07-I-02). Sem a permission em nenhum scope → `403`. Com permission: inexistente, malformado, fora do scope, acima da clearance → `404 RESOURCE_NOT_FOUND` byte-idêntico.
- Compensação/payroll: quem tem `HR_EMPLOYMENT_VIEW` mas não `HR_COMPENSATION_VIEW` recebe o emprego sem campos salariais e `404` nas rotas de compensação/detalhe de run.
- Relatórios/detalhe: unidade fora do scope → `404`; o consolidado nunca revela a lista de unidades fora do scope actual (só o agregado).

## D21: relatórios V1

Os oito relatórios obrigatórios por unidade estão em D-04A.9 e prevalecem sobre esta lista. Todos em mês/trimestre/semestre/ano, `own` e (quando aplicável) `cons`: diário (entries do período), razão por conta de controlo e por conta financeira, resumo de receitas, resumo de despesas, movimento por conta caixa/banco, orçamento vs real, DRE own, DRE consolidado, reconciliação de transferências (enviadas/recebidas/em trânsito com idade), reconciliação bancária/caixa, saldos de fecho, payables/receivables em aberto, contribuições em espécie não valorizadas, resumo de payroll (agregado, D28).

Export CSV e impressão (HTML imprimível/PDF servidor quando existir) respeitam scope, classificação e `FINANCE_REPORT`; export é auditado (`finance.export`). Detalhe identificado de contribuintes exige `FINANCE_CONTRIBUTOR_VIEW`; detalhe salarial nunca aparece em relatórios Finance.

## D22: RH — âmbito

Não se implementa RH genérico (assiduidade, férias, avaliação, recrutamento ficam fora). Só o mínimo para FIN-PAYROLL-01. `person_employment`/`employment_types` **não são reutilizados** como vínculo laboral: descrevem a situação profissional da Pessoa e continuam intactos.

## D23: empregado e vínculo

- **Employee = Person** (ADR 0001/0017). Nenhuma identidade paralela. O vínculo consome uma Pessoa existente pelo `public_id` e exige visibilidade People.
- Nova tabela `employments`: `public_id`, `person_id`, `employing_unit_id` (a unidade que paga e responde), `relationship_kind` ∈ {`EMPLOYEE`, `BENEFICIARY`} (beneficiário = reformado/pensionista/terceira idade pago pela MEPA sem contrato activo), `job_title` (texto, não cargo eclesiástico), `starts_on`, `ends_on`, `status` ∈ {`ACTIVE`, `ENDED`}, `end_reason`, `contract_document_id` (opcional), `created_by`, `lock_version`. Guarda de um vínculo aberto por (Pessoa, unidade) com coluna gerada + UNIQUE (padrão P0.9).
- Criar um vínculo abre, via serviço People, o contexto `EMPLOYMENT` em `person_unit_contexts` (tipo previsto no ADR 0017); terminar fecha-o. Nenhuma escrita em `people`.
- **Nomeação eclesiástica ≠ contrato de trabalho.** Um líder pode não ser empregado; um empregado pode não ter cargo. Nenhuma FK entre `employments` e `ministerial_assignments`/`organizational_posts`.

## D24: compensação

- `compensation_component_types` (catálogo): `code`, `name`, `nature` ∈ {`EARNING`, `EMPLOYEE_DEDUCTION`, `EMPLOYER_CHARGE`}, `calculation_method` ∈ {`FIXED_AMOUNT`, `RATE_RULE`, `BRACKET_RULE`, `MANUAL`}, `expense_category_id` (rubrica §16.5 para EARNING/EMPLOYER_CHARGE), `liability_role` (`PAYROLL_WITHHOLDINGS`/`PAYROLL_EMPLOYER_CHARGES` para deduções/encargos).
- Instalação V1 (códigos genéricos, **sem valores nem taxas**): `BASE_SALARY` (`PER_SALARY`), `THIRTEENTH_SALARY` (`PER_THIRTEENTH`), `HOLIDAY_SUBSIDY` (`PER_HOLIDAY_SUBSIDY`), `TRANSPORT_MEAL_ALLOWANCE` (`PER_TRANSPORT_MEAL`), `SOCIAL_ASSISTANCE` (`PER_SOCIAL_ASSISTANCE`), `HEALTH_PLAN` (`PER_HEALTH_PLAN`), `OTHER_EARNING` (`PER_OTHER`), `RETIREMENT_PENSION` (`PER_SOCIAL_ASSISTANCE`), `THIRD_AGE_BENEFIT` (`PER_SOCIAL_ASSISTANCE`), `INSS_EMPLOYEE` (dedução), `INCOME_TAX_WITHHOLDING` (dedução; IRT), `OTHER_DEDUCTION` (dedução), `INSS_EMPLOYER` (encargo, `PER_INSS`).
- `employment_compensations`: `employment_id`, `component_type_id`, `amount` (para `FIXED_AMOUNT`), `starts_on`, `ends_on`, `reason`, `source_document_id`, `created_by`. **Histórico salarial = estas linhas, append-only**: alterar = fechar a vigente + abrir nova, sob lock do vínculo; guarda de uma linha aberta por (vínculo, componente).
- Aplicabilidade de 13.º, subsídio de férias, pensões e benefícios de terceira idade a cada pessoa é **configuração** (existe ou não linha de compensação/regra), não código.

## D25 [DI]: regras estatutárias

- **Nenhuma taxa legal angolana é codificada nem semeada.** Nem INSS, nem IRT.
- `payroll_rules`: `code`, `component_type_id`, `method` ∈ {`FLAT_RATE`, `BRACKET`}, `rate DECIMAL(9,6)` (FLAT), `starts_on`, `ends_on`, `status` ∈ {`DRAFT`, `APPROVED`, `RETIRED`}, `source_document_id` (**obrigatório para APPROVED**, tipo `PAYROLL_RULE_SOURCE`), `created_by`, `approved_by` (≠ criador).
- `payroll_rule_base_components (rule_id, component_type_id)`: que componentes formam a base de incidência.
- `payroll_rule_brackets (rule_id, lower_bound, upper_bound NULL, rate, fixed_amount, excess_over)`: tabela progressiva (IRT). Validação: sem lacunas nem sobreposição, limites crescentes.
- Âmbito nacional (grants sobre a Direcção Geral): `PAYROLL_RULES_MANAGE` e `PAYROLL_RULES_APPROVE`. Vigência sem sobreposição por componente.
- **Configurável e necessário antes de produção (não antes de código):** taxas INSS trabalhador/entidade, base de incidência, tabela IRT e a sua aplicabilidade à MEPA, regras de 13.º/subsídio de férias, pensões e terceira idade.

## D26: pipeline de payroll

```text
vínculo → compensação vigente → regras aprovadas → run (unidade × mês)
→ CALCULATED → APPROVED → POSTED (accrual) → PAID (pagamento) 
```

- `payroll_runs`: `public_id`, `employing_unit_id`, `period_id` (MONTH de serviço), `run_kind` ∈ {`REGULAR`, `HOLIDAY_SUBSIDY`, `THIRTEENTH`, `ADJUSTMENT`}, `sequence`, `status` ∈ {`DRAFT`, `CALCULATED`, `APPROVED`, `POSTED`, `PAID`, `CANCELLED`, `REVERSED`}, `input_hash BINARY(32)`, totais (bruto, deduções, encargos, líquido, headcount), `calculated_by/at`, `approved_by/at`, `posted_by/at`, `lock_version`. UNIQUE `(employing_unit_id, period_id, run_kind, sequence)`; um `REGULAR` por unidade/mês não cancelado (coluna gerada + UNIQUE).
- `payroll_run_lines`: `run_id`, `employment_id`, `component_type_id`, `base_amount`, `rate`, `amount`, `source` ∈ {`FIXED`, `RULE`, `MANUAL`}, `rule_id`. Imutáveis a partir de `APPROVED`.
- **Calcular** (`PAYROLL_MANAGE`): lê vínculos activos no mês e compensações/regras vigentes sob lock partilhado; grava linhas e `input_hash` (hash canónico de todos os inputs; cobertura mínima em D-04A.14). Recalcular só em `CALCULATED`/`DRAFT`.
- **Aprovar** (`PAYROLL_APPROVE`, **≠ quem calculou**): recalcula o `input_hash` sob lock; se diferente → `409 STALE_CALCULATION` (C5). Aprovado é **imutável**.
- **Postar** (`PAYROLL_POST` **e** `FINANCE_POST` sobre a unidade): D27.
- **Pagar** (`PAYROLL_POST` + `FINANCE_POST`): entry `PAYROLL_PAYMENT` Dr `PAYROLL_NET_PAYABLE` / Cr caixa/banco (uma ou várias contas da unidade, total = líquido). Remessa de retenções/encargos a INSS/fisco = `LIABILITY_PAYMENT` separado, com documento.
- **Correcção:** run `POSTED` nunca é alterado. Erro → `PAYROLL_REVERSAL` (inverso exacto; só se ainda não `PAID`) ou run `ADJUSTMENT` no mês seguinte com diferenças. Sempre com motivo e auditoria.

## D27: payroll → Finance

Um run `POSTED` gera **uma** entry `PAYROLL_ACCRUAL` na unidade empregadora, **agregada por rubrica e por conta** (nunca por empregado):

| Componente | Débito | Crédito |
|---|---|---|
| Ganhos (salário, subsídios, 13.º, benefícios, pensões) | `OPERATING_EXPENSE` × rubrica `PER_*` (bruto) | — |
| Deduções ao empregado (INSS trabalhador, IRT, outras) | — | `PAYROLL_WITHHOLDINGS` |
| Encargos da entidade (INSS entidade) | `OPERATING_EXPENSE` × `PER_INSS` | `PAYROLL_EMPLOYER_CHARGES` |
| Líquido | — | `PAYROLL_NET_PAYABLE` = bruto − deduções |

Σ débito = bruto + encargos = Σ crédito. O payroll **nunca** escreve valores soltos no ledger: gera a entry pelo mesmo serviço de publicação (D07).

**Idempotência:** `payroll_postings (run_id, stage ∈ {ACCRUAL, PAYMENT, REVERSAL}, entry_id)` com UNIQUE `(run_id, stage)` e UNIQUE `entry_id` (espelho de `transfer_postings`) + `idempotency_requests` + lock `FOR UPDATE` do run + verificação de estado. O mesmo run nunca é postado duas vezes (C6). `correlation_id` único liga run, entry e auditoria.

## D28: dados sensíveis e projecção

| Permission | Vê |
|---|---|
| `HR_EMPLOYMENT_VIEW` | Vínculo (Pessoa, unidade, função, datas), sem valores |
| `HR_EMPLOYMENT_MANAGE` | Criar/terminar vínculo |
| `HR_COMPENSATION_VIEW` | Compensação, histórico salarial, linhas de run por pessoa, recibos |
| `HR_COMPENSATION_MANAGE` | Alterar compensação |
| `PAYROLL_MANAGE` / `PAYROLL_APPROVE` / `PAYROLL_POST` | Operações do run (D26) |
| `FINANCE_PAYROLL_SUMMARY_VIEW` | **Só totais do run** (bruto, deduções e encargos por componente, líquido, headcount) e a entry agregada |

Finance nunca recebe o detalhe por pessoa. Limitação conhecida e aceite: numa unidade com um único empregado o agregado equivale ao valor individual; por isso `FINANCE_PAYROLL_SUMMARY_VIEW` é `CONFIDENTIAL` e auditado, e as linhas do ledger não identificam pessoas.

## D29: payroll × período Finance

- O run referencia o **mês de serviço**; a entry referencia o **período de publicação**. Normalmente coincidem.
- Posting proibido em período fechado (nacional ou da unidade): `409 PERIOD_CLOSED`.
- Run `APPROVED` cujo mês fechou antes do posting: continua `APPROVED`; posta-se no **primeiro período aberto** da unidade, com `entry_date` nesse período, motivo obrigatório e referência ao mês de serviço. Nunca se reabre período para isso automaticamente.
- Runs `CALCULATED`/`APPROVED` do mês de serviço **não bloqueiam** o fecho da unidade: aparecem como aviso no relatório de fecho. Assim RH e Finance não ficam reféns um do outro.

## D30: concorrência (MySQL real, dois+ processos, barreira)

| ID | Cenário | Critério |
|---|---|---|
| C1 | Dois posts da mesma entry `SUBMITTED` + replay idempotente | Uma publicação; o outro `409 ALREADY_POSTED` ou replay com o mesmo `public_id`; hash diferente rejeitado; linhas intactas |
| C2 | Dois `RECEIVE` da mesma transferência | Um `transfer_postings` RECEIVE; caixa D + amount uma vez |
| C3 | Fecho de unidade/nacional vs novo posting | Ou o posting comita antes (e conta no fecho) ou é rejeitado `PERIOD_CLOSED`; nenhuma entry posted num (período, unidade) depois do fecho |
| C4 | Duas aprovações de versões do mesmo orçamento / revisão vs aprovação | No máximo um `APPROVED` (UNIQUE gerado); perdedor `409` |
| C5 | Cálculo/aprovação de payroll vs alteração de compensação | Aprovação com input mudado → `409 STALE_CALCULATION`; run aprovado nunca muda |
| C6 | Dois posts do mesmo run | Uma entry `PAYROLL_ACCRUAL`; UNIQUE `(run_id, stage)` |
| C7 | Correspondências concorrentes na mesma linha de extracto/ledger | Σ `matched_amount` ≤ montante de cada lado; excesso rejeitado |
| C8 | Consolidado nacional/subtree durante postings concorrentes | Leitura num snapshot: `cons = Σ own` no mesmo snapshot; nenhuma entry parcial visível; I1–I6 verificados |
| C9 | `REVERSE_SEND` vs `RECEIVE` da mesma transferência | Exactamente um vence; nunca ambos |
| C10 | Duas liquidações que excedem o mesmo payable | Soma alocada ≤ montante; a segunda rejeitada (protótipo T09) |

Retry limitado (3, backoff/jitter) só para deadlock/lock timeout, revalidando tudo.

## D31: permissions V1

Só instaladas; **nenhuma role criada ou alterada**. `action = code`.

`data_type='FINANCE'`: `FINANCE_VIEW`, `FINANCE_MANAGE`, `FINANCE_POST` (cobre o "FINANCE_APPROVE" pedido: aprovar = publicar), `FINANCE_REVERSE`, `FINANCE_ACCOUNT_MANAGE`, `FINANCE_TRANSFER`, `FINANCE_RECONCILE`, `FINANCE_BUDGET_MANAGE`, `FINANCE_BUDGET_APPROVE`, `FINANCE_PERIOD_CLOSE`, `FINANCE_PERIOD_REOPEN`, `FINANCE_REPORT`, `FINANCE_CONSOLIDATED_VIEW`, `FINANCE_CONTRIBUTOR_VIEW`, `FINANCE_PAYROLL_SUMMARY_VIEW`, `FINANCE_CATALOG_MANAGE`. `maximum_classification` `CONFIDENTIAL`.

`data_type='HR'`: `HR_EMPLOYMENT_VIEW`, `HR_EMPLOYMENT_MANAGE` (`CONFIDENTIAL`); `HR_COMPENSATION_VIEW`, `HR_COMPENSATION_MANAGE`, `PAYROLL_MANAGE`, `PAYROLL_APPROVE`, `PAYROLL_POST`, `PAYROLL_RULES_MANAGE`, `PAYROLL_RULES_APPROVE` (`HIGHLY_SENSITIVE`).

Operações compostas exigem permissions cumulativas (transferência: `FINANCE_TRANSFER`+`FINANCE_POST`; payroll post/pay: `PAYROLL_POST`+`FINANCE_POST`; anexos: Finance/HR + Files).

## D32: schema delta

### Reutilizadas sem alteração
`organizational_units`, `unit_parent_periods`, `people`, `households`, `person_unit_contexts`, `legal_documents`, `document_versions`, `files`, `idempotency_requests`, `workflows`, `workflow_instances`, `audit_logs`, `permissions`, `roles`, `role_permissions`, `scopes`, `user_role_scopes`, `users`, `auth_sessions`. Catálogos `legal_document_types`, `permissions`, `workflows` recebem linhas.

### Finance core (novas, do catálogo Wave 6, com os deltas indicados)
| Tabela | Delta em relação ao catálogo |
|---|---|
| `currencies` | + `minor_units` TINYINT (CHECK 0..4) |
| `accounting_periods` | + `code` UNIQUE, + `period_kind` CHECK (MONTH/YEAR); CHECK status (OPEN/CLOSED), `ends_on ≥ starts_on` |
| `chart_of_accounts` | + `system_role` UNIQUE NULL; CHECK account_kind (ASSET/LIABILITY/EQUITY/INCOME/EXPENSE/`INTERUNIT_CONTROL`, D-04A.4)/normal_side |
| `funds` | — |
| `financial_categories` | + `economic_nature` CHECK; CHECK `classification_status` |
| `accounts` | + `public_id` UNIQUE, + `opened_on`, + `closed_on`; CHECK kind CASH/BANK, status OPEN/CLOSED |
| `bank_account_details`, `cash_registers` | — |
| `journal_entries` | + `unit_id` FK, + `entry_kind`, + `submitted_by/at`, + `reason`; CHECK status (DRAFT/SUBMITTED/POSTED/DISCARDED); UNIQUE `reversal_of_id`; índice `(unit_id, period_id, status)` |
| `journal_lines` | + `counterparty_unit_id` FK NULL; CHECK debit/credit ≥0 e exactamente um >0; índices `(unit_id, ledger_account_id)`, `(financial_account_id)` |
| `financial_parties` | CHECK XOR por `party_kind` |
| `financial_documents` | — |
| `accounting_period_unit_closes` | **Nova (não catalogada)** — D12 |

### Subledgers V1
`contributions` (+ `valuation_status`, `valued_by`, `valuation_approved_by`, `valuation_document_id`; CHECK XOR IDENTIFIED/IN_KIND), `receivables`, `payables`, `settlements`, `settlement_allocations` (CHECK XOR, amount>0), `bank_statements`, `bank_statement_lines`, `reconciliations` (+ `counted_balance` NULL), `reconciliation_matches`. Estados com CHECK por tabela, vocabulário deste ADR.

### Consolidation
`internal_transfers` (+ `origin_unit_id`, `destination_unit_id`, `destination_account_id` passa a NULL, `cancel_reason`; CHECK origem≠destino, status), `transfer_postings` (CHECK stage). Consolidação é **read model sobre o ledger**; nenhuma tabela de consolidação nova.

### Budget
`budgets` (+ `approved_guard` gerada + UNIQUE; + `submitted_by`, `reviewed_by`; CHECK status), `budget_lines` (CHECK ≥0; UNIQUE existente).

### Payroll integration (novas, não catalogadas — D23–D27)
`employments`, `compensation_component_types`, `employment_compensations`, `payroll_rules`, `payroll_rule_base_components`, `payroll_rule_brackets`, `payroll_runs`, `payroll_run_lines`, `payroll_postings`.

### Não criadas
`obligation_rules`, `obligations`, `contribution_allocations` (D05).

**Contagem:** 25 tabelas do catálogo Wave 6 + 1 nova Finance + 9 novas Payroll = **35**. Nenhuma tabela especulativa fora destas.

### Catálogos (installers idempotentes, só INSERT do que falta)
`currencies` AOA; `funds` GENERAL; `chart_of_accounts` estrutural (D08); `financial_categories` §16 (D09); `legal_document_types` (D14); `workflows` `FINANCE_INTERNAL_TRANSFER` e `FINANCE_PAYABLE`; `permissions` (D31); `compensation_component_types` (D24). **Não semeados:** períodos (comando por ano), contas, regras salariais, taxas.

## D33: estratégia de migrations

Ordem: (1) catálogos de referência `currencies`, `funds`, `chart_of_accounts`, `financial_categories`, `accounting_periods`; (2) `accounts`, `bank_account_details`, `cash_registers`, `financial_parties`; (3) `journal_entries`, `journal_lines`, `financial_documents`, `accounting_period_unit_closes`; (4) `internal_transfers`, `transfer_postings`; (5) `contributions`, `receivables`, `payables`, `settlements`, `settlement_allocations`; (6) `bank_statements`, `bank_statement_lines`, `reconciliations`, `reconciliation_matches`; (7) `budgets`, `budget_lines`; (8) installers Finance; (9) `employments` … `payroll_postings`; (10) installers HR/Payroll.

- DDL MySQL explícito com CHECKs nomeados, padrão das Waves anteriores; uma tabela por migration; `down` remove só a sua tabela.
- Todas as tabelas são novas e vazias: nenhuma migration altera dados existentes. Os installers **validam pré-condições** (ex.: um `code` já existente com semântica diferente — `data_type`, `nature`, `system_role` — aborta com erro; nunca corrigem em silêncio) e são idempotentes (reexecução = zero inserções).
- Manifesto `p010_finance_delta_manifest.json` e validador de schema, como P0.5–P0.9.

## D34: UI V1

```text
Finanças                       RH / Payroll
├── Visão Geral (own/cons)     ├── Empregados (vínculos)
├── Movimentos                 ├── Remuneração (histórico)
├── Contas / Caixa / Bancos    ├── Processamento Salarial (runs)
├── Transferências             ├── Regras (nacional)
├── Pagáveis / Recebíveis      └── Histórico
├── Contribuições
├── Orçamento
├── Reconciliação
├── Fechos
└── Relatórios
```

Mobile-first (formulários de receita/despesa/transferência e confirmação de recepção em telemóvel); relatórios densos (DRE consolidado, razão, orçamento vs real) com experiência desktop mais rica e tabela com scroll horizontal controlado no mobile. `/api/` continua `NetworkOnly` no PWA; nada financeiro em cache offline.

## D-04A — Origem, Aplicação, Custódia e Consolidação de Fundos

**Fase:** P0.10-D1, 2026-09-30, sobre `f69e96b`. Adenda normativa decidida pelo responsável do projecto. Não cria tabelas nem colunas, não muda a contagem de D32 (35), não cria migrations nem permissions e não redefine o payroll. Fixa invariantes de prestação de contas. Onde toca D08/D09/D10/D15/D16/D21/D26, esses pontos foram ajustados no texto acima e marcados "(D-04A)". Os únicos ajustes de vocabulário são:

- a classe de conta `INTERUNIT_CONTROL`, que substitui a classificação EQUITY das contas de transferência;
- o papel `FIXED_ASSETS`, para o investimento capitalizável;
- três rubricas de finalidade de transferência.

Todos são linhas de catálogo ou valores de CHECK já previstos em D32.

### D-04A.1 Gestão por unidade

Cada `organizational_unit` é uma **unidade autónoma de responsabilidade e prestação de contas**: Direcção Geral, Regional, Provincial, Municipal, Centro Geral, Centro e Congregação, sem excepção. Para qualquer unidade U e intervalo P, o sistema demonstra a partir do ledger `POSTED` (nunca de colunas de saldo):

| | Elemento | Fonte |
|---|---|---|
| a | Saldo inicial | Σ linhas CASH/BANK de U antes de P |
| b | Fundos recebidos externamente | Entradas CASH/BANK de U cuja contrapartida não é transferência interna (receita, contribuição, cobrança de recebível, ingresso não operacional, ajustamento) |
| c | Fundos internos recebidos | Entries `TRANSFER_RECEIVE` de U |
| d | Origem institucional dos fundos internos | `internal_transfers.origin_unit_id` / `journal_lines.counterparty_unit_id` |
| e | Aplicações/gastos próprios | Saídas CASH/BANK de U cuja contrapartida não é transferência interna (gasto, investimento, pagamento de passivo, saída não operacional, ajustamento) |
| f | Fundos enviados a outras unidades | Entries `TRANSFER_SEND` de U líquidas de `TRANSFER_REVERSE_SEND` |
| g | Destino institucional dos fundos enviados | `internal_transfers.destination_unit_id` / `counterparty_unit_id` |
| h | Saldo final sob gestão | Σ linhas CASH/BANK de U até ao fim de P |

Identidade obrigatória (teste): **a + b + c − e − f = h**, com `ACCOUNT_TRANSFER` (D10 caso A) neutro porque ambos os lados são contas da mesma unidade. A decomposição fina de b e e está em D-04A.10.

### D-04A.2 Resultado económico ≠ movimento de fundos

Duas dimensões distintas, sempre apresentadas separadas:

| Dimensão | Conteúdo | Contas |
|---|---|---|
| **A. Resultado económico** | Receita, gasto, investimento consumido, não operacionais, excedente/défice | `INCOME`/`EXPENSE` (D08) |
| **B. Movimento de fundos sob gestão** | Recebimentos internos, remessas internas, transferências, saldo administrado (custódia) | CASH/BANK + `INTERUNIT_CONTROL` |

Uma transferência interna MEPA **não cria receita económica, não cria despesa económica e não é investimento**. Representa **mudança de custódia/gestão** de fundos já reconhecidos economicamente, uma única vez, na unidade que os recebeu de fora (I6).

### D-04A.3 Exemplo normativo (teste obrigatório F-D)

A Congregação C recebe 100 000 Kz externos, envia 60 000 Kz ao Centro X, e o Centro X envia 40 000 Kz ao Município M. Todas as transferências foram recebidas. Não houve outros movimentos.

| Relatório | Linhas | Resultado económico | Saldo sob gestão |
|---|---|---|---|
| Own C | Receita externa +100 000 · Fundos remetidos ao Centro X −60 000 | +100 000 | 40 000 |
| Own X | Fundos recebidos da Congregação C +60 000 · Fundos remetidos ao Município M −40 000 | 0 | 20 000 |
| Own M | Fundos recebidos do Centro X +40 000 | 0 | 40 000 |
| **Cons M** (C, X ∈ subárvore de M) | Receita económica externa 100 000 · receita interna adicional 0 · transferências eliminadas (par t1, par t2) | **+100 000** | 40 000 + 20 000 + 40 000 = **100 000** |

**Nunca** 160 000, 200 000 ou qualquer outra soma repetida do mesmo fundo, nem na receita nem nas disponibilidades. Os relatórios own são exactos para prestação de contas de cada gestor. O consolidado é exacto para a economia do perímetro. Os dois coexistem sem contradição, porque a transferência nunca entra na dimensão A.

### D-04A.4 Contas de controlo interunidades (substitui "EQUITY" em D08)

- As contas de transferência são **contas técnicas de balanço** da nova classe `chart_of_accounts.account_kind = 'INTERUNIT_CONTROL'`, com os papéis `INTERUNIT_CLEARING_OUT` (normal DEBIT) e `INTERUNIT_CLEARING_IN` (normal CREDIT).
- Não são receita, gasto nem equity. Nenhuma unidade usa equity como classificação económica artificial para movimentar fundos entre gestores. `OPENING_NET_ASSETS` continua a ser a única conta EQUITY.
- **SEND afecta só a unidade origem; RECEIVE afecta só a unidade destino.** Cada etapa é uma entry equilibrada própria (D07), com uma única unidade.

Semântica (os nomes exactos das contas são configuráveis no plano; o exemplo **não** é um plano de contas nacional):

| Etapa | Unidade | Débito | Crédito |
|---|---|---|---|
| SEND 80 | Origem | Interunit clearing — enviados (`INTERUNIT_CLEARING_OUT`, cp = destino) 80 | Caixa/Banco da origem 80 |
| RECEIVE 80 | Destino | Caixa/Banco do destino 80 | Interunit clearing — recebidos (`INTERUNIT_CLEARING_IN`, cp = origem) 80 |

Apresentação no balanço own:

- a secção **"Posição interunidades"** mostra IN − OUT por contraparte;
- vem depois dos activos, passivos e património líquido e fora deles;
- a equação de custódia é: disponibilidades + outros activos − passivos = saldos iniciais + resultado acumulado + posição interunidades líquida.

Se se somar a posição interunidades de todas as unidades nacionais, o resultado é −(fundos em trânsito): o trânsito é o único resíduo legítimo.

### D-04A.5 Rastreabilidade

Cada transferência interunidades preserva:

| Elemento | Onde |
|---|---|
| Identificador externo | `internal_transfers.public_id` |
| Origem / destino | `origin_unit_id`, `destination_unit_id` (D10) |
| Montante | `amount` (DECIMAL, AOA) |
| Finalidade | `category_id`, que é uma rubrica de natureza `INTERNAL_TRANSFER` (D-04A.6). **Obrigatória** a partir do SEND |
| Envio / recepção | `sent_at`, `received_at` (catálogo) e `entry_date` das entries das etapas |
| Estado | `status` (D10) |
| Estado de reconciliação | `reconciled_at` = `received_at` na recepção (montante igual, D10). Projecção derivada: `PENDING` (SENT sem RECEIVE), `MATCHED` (RECEIVED), `RETURNED` (REVERSE_SEND), `NOT_APPLICABLE` (DRAFT/CANCELLED antes de envio). **Refinado por F1B-D1** (`docs/reviews/P0.10_F1B_interunit_transfers_custody.md` §2): a reconciliação é um passo explícito, feito com `FINANCE_RECONCILE` depois do RECEIVE, que verifica o par SEND↔RECEIVE. As projecções passam a ser `NOT_APPLICABLE`, `IN_TRANSIT`, `AWAITING_RECONCILIATION`, `RECONCILED` e `RETURNED`. Nenhum estado novo é guardado |
| Contas origem / destino | `origin_account_id`; `destination_account_id` preenchida só na recepção, e nunca visível à origem (D10) |
| Etapas contabilísticas | `transfer_postings (transfer_id, posting_stage, entry_id)` |
| Correlação | Um `correlation_id` por etapa, partilhado pelo audit, pelo `idempotency_requests` e pela entry (D19). O `transfer.public_id` liga as etapas entre si |
| Documento de suporte | `internal_transfers.document_id` (comprovativo de envio) e `financial_documents` de cada entry (comprovativo de recepção) |

A unidade receptora vê sempre qual unidade enviou: o nome e o `public_id` da unidade origem, a finalidade, o montante e a data de envio. A unidade remetente vê sempre para onde enviou e se já foi recebido. Nenhuma das duas vê as contas financeiras da outra.

### D-04A.6 Finalidade da transferência

A finalidade é a rubrica `category_id` já existente no catálogo, sem coluna nova. Os nomes já existentes (`TRF_REMITTANCE`, `TRF_BUDGET_QUOTA`, `TRF_OTHER`) mantêm-se e acrescentam-se três:

| Conceito pedido | Código canónico | Classe no DOAF |
|---|---|---|
| Remessa regular | `TRF_REMITTANCE` (existente) | Remessas regulares |
| REGULAR_QUOTA | `TRF_BUDGET_QUOTA` (existente) | Remessas regulares |
| SPECIAL_CONTRIBUTION | `TRF_SPECIAL_CONTRIBUTION` (novo) | Transferências internas enviadas/recebidas |
| SUPPORT | `TRF_SUPPORT` (novo) | Transferências internas enviadas/recebidas |
| PROJECT_TRANSFER | `TRF_PROJECT` (novo) | Transferências internas enviadas/recebidas |
| OTHER | `TRF_OTHER` (existente) | Transferências internas enviadas/recebidas |

- Os códigos são estáveis. Os nomes estão sujeitos à validação do contabilista, que não bloqueia.
- Todas estas rubricas têm natureza `INTERNAL_TRANSFER` e conta de controlo `INTERUNIT_CLEARING_*`. Nunca têm natureza `REVENUE`, `EXPENSE` ou `INVESTMENT`, e o installer recusa outra natureza.
- Na recepção, a finalidade é herdada do SEND e não é reclassificável pelo destino.

### D-04A.7 Fecho independente e fundos em trânsito

Preserva D10/D12:

- o SEND pertence exclusivamente ao período da origem;
- o RECEIVE pertence exclusivamente ao período do destino;
- a origem fecha o seu mês depois do SEND sem depender do destino;
- o destino reconhece a sua etapa quando o RECEIVE ocorre, no primeiro período aberto seu se a data real cair num mês já fechado.

Enquanto não houver RECEIVE, o valor é **fundo interno em trânsito / por reconciliar**:

- no own da origem: enviado, com estado `PENDING`;
- no own do destino: nada, excepto a lista "a receber em trânsito", informativa e sem valor contabilístico;
- no consolidado que contém a origem: activo "fundos internos em trânsito" (I4);
- em todos os casos, no relatório de reconciliação interunidades, com idade.

### D-04A.8 Consolidação, DRE e perímetro

1. **Efeito económico líquido nulo.** Se origem e destino pertencem ao perímetro S, o efeito da transferência em Revenue, Expense e Economic Result é 0. É exacto por construção, porque nenhuma linha de transferência toca `INCOME`/`EXPENSE` (I1).
2. **Visibilidade mantida.** A transferência continua visível para prestação de contas, tesouraria, reconciliação, posição de custódia e auditoria.
3. **DRE consolidada = Σ resultados económicos own** das unidades do perímetro (I2). Como as transferências internas nunca entram em receita ou gasto, **não há eliminação na DRE**. Aparecem só em memo.
4. **A eliminação por `transfer_id` aplica-se às visões consolidadas de:**
   - balanço;
   - contas de controlo interunidades;
   - posição de fundos;
   - tesouraria;
   - reconciliação;
   - transferências internas.

   Um par OUT/IN com as duas pontas em S é anulado. Um OUT sem IN à data final passa a "fundos internos em trânsito".
5. **Perímetro** = a árvore organizacional vigente na data final do período (D15). Exemplo: com Congregação → Centro → Município todos no perímetro Municipal, os movimentos entre eles são mudança interna de custódia. Se só uma ponta está no perímetro, a entrada ou saída aparece como "fundos internos recebidos de / remetidos para fora do perímetro", na secção de posição interunidades e no DOAF consolidado. Nunca é convertida em receita ou despesa económica.

### D-04A.9 Relatórios obrigatórios por unidade

Prevalecem sobre D21, que continua válido para os restantes relatórios. Todos existem para qualquer unidade, em **mês, trimestre, semestre e ano** (trimestre e semestre são intervalos de meses, D12), com export auditado (`finance.export`):

1. **Resultado Económico Próprio** — DRE own (D16), transferências em memo.
2. **Origem e Aplicação de Fundos** — D-04A.10.
3. **Fundos Internos Recebidos** — por unidade de origem (nome, `public_id`, relação superior/subordinada/outra), finalidade, montante, datas de envio e recepção.
4. **Fundos Internos Enviados** — por unidade de destino, finalidade, montante, estado (`PENDING`/`MATCHED`/`RETURNED`) e datas.
5. **Reconciliação Interunidades** — pares enviado↔recebido, em trânsito com idade, devoluções.
6. **Saldos de Caixa/Banco** — por conta financeira: inicial, entradas, saídas, final.
7. **Budget vs Actual** — D13.
8. **Consolidado da Subárvore**, quando autorizado (`FINANCE_CONSOLIDATED_VIEW`, D17): DRE consolidada, DOAF consolidado, posição interunidades com eliminação e trânsito.

### D-04A.10 Demonstrativo de Origem e Aplicação de Fundos (DOAF)

É obrigatório por unidade e período, own e consolidado. A base são os fundos sob gestão, isto é, as contas financeiras CASH/BANK da unidade (D06). Cada linha CASH/BANK de uma entry `POSTED` é classificada pelo `entry_kind` e pelas linhas de contrapartida da mesma entry:

| ORIGENS | Classificação |
|---|---|
| Saldo inicial | Σ CASH/BANK antes do período |
| Receitas externas próprias | `REVENUE`, `CONTRIBUTION`, e `SETTLEMENT` de recebíveis cuja receita é operacional |
| Fundos recebidos de unidades subordinadas | `TRANSFER_RECEIVE` cuja origem é descendente de U (árvore à data final) |
| Fundos recebidos de unidades superiores | `TRANSFER_RECEIVE` cuja origem é antepassado de U |
| Outras transferências internas autorizadas | `TRANSFER_RECEIVE` de unidade que não é antepassada nem descendente |
| Outros ingressos classificados | Não operacionais, principal de empréstimo recebido, `ADJUSTMENT` a favor |

| APLICAÇÕES | Classificação |
|---|---|
| Gastos operacionais | `EXPENSE` imediato em rubricas `COST_OF_SALES`/`*_EXPENSE`, excepto os apoios |
| Investimentos | Apenas rubricas de natureza `INVESTMENT`: consumidas (gasto) ou capitalizadas (`FIXED_ASSETS`) |
| Pagamentos | `SETTLEMENT` de payables, `PAYROLL_PAYMENT`, `LIABILITY_PAYMENT`, amortização de empréstimo |
| Apoios | Rubricas de assistência e apoio (V1: `PER_SOCIAL_ASSISTANCE`; o mapeamento é do relatório, validado pelo contabilista) |
| Transferências internas enviadas | `TRANSFER_SEND` com finalidade diferente de regular, líquido de `REVERSE_SEND` |
| Remessas regulares | `TRANSFER_SEND` com `TRF_REMITTANCE`/`TRF_BUDGET_QUOTA`, líquido de `REVERSE_SEND` |
| Outras aplicações | Saídas não operacionais, `ADJUSTMENT` contra |
| Saldo final | Σ CASH/BANK no fim do período |

Regras:

- **Total das origens = total das aplicações** (teste obrigatório).
- `ACCOUNT_TRANSFER` é excluído, porque é neutro dentro da unidade.
- Um `REVERSAL` reduz a classe da entry que estorna, no período do estorno.
- Entries sem linha CASH/BANK (accrual de payables/receivables, `PAYROLL_ACCRUAL`, valorização em espécie) não entram no DOAF. Aparecem na DRE, e as contribuições em espécie aparecem também em memo.
- Numa entry com uma linha CASH/BANK e várias contrapartidas, o valor é repartido pelos montantes exactos de cada contrapartida.
- Numa entry com várias linhas de caixa e várias contrapartidas (só `ADJUSTMENT`), o valor vai a "outros".
- No DOAF **consolidado**:
  - as origens e aplicações internas entre unidades de S são eliminadas por `transfer_id`;
  - as que atravessam a fronteira de S aparecem como "recebidos de / remetidos para fora do perímetro";
  - o trânsito aparece como linha própria antes do saldo final.
- A consulta é SQL directa sobre o ledger, num único snapshot (D16).

### D-04A.11 Fungibilidade — sem falsa rastreabilidade

Para fundos comuns (fundo `GENERAL`, o único da V1) o sistema **não afirma** que uma entrada específica financiou uma despesa específica. Pode demonstrar "recebemos X destas origens" e "aplicámos Y nestes destinos", lado a lado (DOAF). Nenhum relatório, ecrã ou export usa formulações como "financiado por" ou "pago com a oferta de".

Um vínculo directo origem → aplicação só pode existir com uma relação explícita e aprovada:

- fundo restrito (`fund_id` ≠ `GENERAL`; diferido);
- projecto (diferido);
- contribuição destinada a fundo restrito (diferida);
- allocation explícita (`contribution_allocations`, diferida).

A finalidade `TRF_PROJECT`/`TRF_SUPPORT` de uma transferência é **intenção declarada pelo remetente**, não prova de aplicação, e não cria vínculo. `settlement_allocations` liga pagamento a dívida (liquidação), não fonte de financiamento. Na V1, portanto, nenhum vínculo origem→aplicação é apresentado.

### D-04A.12 Investimento ≠ gasto

- **INVESTMENT não implica EXPENSE.**
- Aquisição de activo: Dr `FIXED_ASSETS` / Cr Caixa/Banco. O impacto imediato na DRE é 0. A aquisição aparece no DOAF (aplicações → investimentos) e em memo da DRE.
- Cada rubrica de natureza `INVESTMENT` aponta, pelo `ledger_account_id` já existente, ou para `FIXED_ASSETS` (capitalizável) ou para `INVESTMENT_EXPENSE` (consumido: secção "Investimentos" da DRE, D16).
- Instalação V1:
  - §16.8 Bens materiais (`INV_AST_*`) → `FIXED_ASSETS`;
  - §16.7 Comunicação e §16.9 Desenvolvimento → `INVESTMENT_EXPENSE`.
- O contabilista pode remapear rubrica a rubrica sem migration. O remapeamento só afecta lançamentos futuros; os postados não mudam.
- A V1 não tem depreciação nem registo por bem (D05). O activo fica ao custo até existir módulo próprio, sem impacto futuro implícito na DRE.
- Rendimento ou retorno é classificado pela sua natureza económica: `REV_INVESTMENT_RETURN` (receita operacional, linha própria) ou não operacional.
- Isto ajusta, sem o reabrir, o D09 [DI]: a secção "Investimentos" do resultado mantém-se para o investimento consumido.

### D-04A.13 Partidas dobradas, contas e accrual — confirmações

- **Journal `POSTED` exige** (D07, verificado sob lock):
  - Σ débito = Σ crédito, em DECIMAL exacto;
  - cada linha com exactamente um lado > 0 e total da entry > 0;
  - moeda AOA;
  - todas as linhas com `unit_id` = unidade da entry, e `counterparty_unit_id` só nas linhas `INTERUNIT_CLEARING_*`;
  - período nacional `OPEN` e sem fecho da unidade;
  - contas `ACTIVE`: conta financeira `OPEN` e conta do plano activa e postável;
  - precisão ≤ 2 casas.
- **Depois de `POSTED`, header e `journal_lines` são imutáveis.** A correcção faz-se só por `REVERSAL` + lançamento correcto (D11).
- **Conta financeira ≠ conta do plano:**
  - a conta financeira (`accounts`: CASH/BANK) é o instrumento operacional da unidade;
  - a conta do plano (`chart_of_accounts`) é a classe contabilística: Asset, Liability, Equity, Revenue, Expense, Interunit Control, Payable/Receivable, Fixed Assets;
  - uma linha de caixa tem as duas (`ledger_account_id` = CASH/BANK, `financial_account_id` = a conta);
  - o saldo da conta financeira continua derivado do ledger `POSTED` (D06).
- **Accrual** (D01):
  - a receita pode ser reconhecida antes do recebimento (`receivables`);
  - a despesa pode ser reconhecida antes do pagamento (`payables`, `PAYROLL_*_PAYABLE`);
  - Finance não depende exclusivamente de Caixa/Banco;
  - por isso a DRE (acréscimo) e o DOAF (fundos) podem divergir legitimamente, e ambos são obrigatórios.

### D-04A.14 Payroll — agregação e `input_hash`

- O detalhe do payroll permanece no subledger RH. Finance recebe só o posting agregado por rubrica e conta (D27).
- Posting idempotente: um `payroll_run` nunca cria dois journals equivalentes. A garantia vem de `payroll_postings` UNIQUE `(run_id, stage)` + UNIQUE `entry_id` + `idempotency_requests` + lock do run.
- O `input_hash` (SHA-256 sobre serialização canónica ordenada) cobre **todos** os inputs relevantes:
  - o conjunto de vínculos elegíveis e os seus campos (`public_id`, unidade, tipo, datas, estado);
  - as compensações vigentes (tipo, montante, vigência), incluindo salário base, componentes, subsídios, 13.º e benefícios;
  - as versões das regras estatutárias usadas (id, vigência, taxa, bases de incidência, escalões completos);
  - o período de serviço e o `run_kind`/`sequence`;
  - a configuração de arredondamento e escala (D02).

  Qualquer alteração a um destes inputs muda o hash e provoca `409 STALE_CALCULATION` na aprovação (C5). Há um teste por classe de input.

### D-04A.15 Gate de produção do payroll

**PAYROLL ENGINE READY ≠ PAYROLL PRODUCTION ENABLED.**

- **Engine ready:** o código, o schema e os testes (incluindo concorrência) estão completos. Os testes usam regras sintéticas criadas pelo fixture, nunca pelos installers.
- **Production enabled:** a flag de configuração `payroll.production_enabled` fica **false por omissão**. Não é coluna nem permission. Com a flag false:
  - o cálculo é permitido para validação;
  - `APPROVE`, `POST` e `PAY` são recusados com `409 PAYROLL_PRODUCTION_DISABLED` (fail-closed).
- Independentemente da flag, um componente de método `RATE_RULE`/`BRACKET_RULE` sem regra `APPROVED` vigente no mês **recusa o cálculo** com `422 PAYROLL_RULE_MISSING`. Nunca é calculado como zero.
- A flag só é ligada depois de uma checklist operacional documentada:
  - aplicabilidade à MEPA de INSS (trabalhador e entidade), IRT, 13.º salário, subsídio de férias, pensões e benefícios de terceira idade, com a decisão "não aplicável" também documentada;
  - regras aprovadas com documento `PAYROLL_RULE_SOURCE` para cada componente aplicável.
- **Nenhuma taxa é semeada, fictícia ou de exemplo.**

### D-04A.16 Âmbito e testes acrescentados

Esta adenda:

- **não** cria tabelas, colunas, migrations, permissions nem roles;
- **não** muda as 35 tabelas de D32 nem redefine o payroll;
- **não** reabre D01–D30, salvo os ajustes marcados "(D-04A)", que foram pedidos expressamente.

O vocabulário do catálogo canónico (`model_catalog.json`, `account_kind` sem `INTERUNIT_CONTROL`) é actualizado na fase de schema do Finance Core, não aqui.

Testes obrigatórios acrescentados ao plano de implementação:

- F-D (D-04A.3), own e consolidado;
- identidade a+b+c−e−f=h e origens = aplicações, own e consolidado;
- DOAF consolidado com fronteira de perímetro e com trânsito;
- soma nacional da posição interunidades = −trânsito;
- aquisição capitalizável com DRE 0 e DOAF "investimentos";
- ausência de vínculo origem→aplicação em fundo `GENERAL`;
- `input_hash` sensível a cada classe de input;
- gate `PAYROLL_PRODUCTION_DISABLED` e `PAYROLL_RULE_MISSING`;
- o installer recusa rubrica de transferência com natureza que não seja `INTERNAL_TRANSFER`.

## Consequências

- Finance deixa de ter decisões em aberto que afectem o schema ou a contabilização V1.
- O regime de acréscimo aumenta o Finance Core (payables/receivables/settlements); quotas/obrigações continuam fora.
- Consolidação é exacta por construção (I1–I6) e testável com F-A/F-B/F-C/F-D.
- Cada unidade presta contas em duas dimensões separadas: o resultado económico (DRE) e a custódia de fundos (DOAF e posição interunidades) (D-04A).
- O protótipo P0.2-F continua evidência de mecanismos (locks, idempotência, estorno), mas a contabilização de transferências da V1 é a deste ADR (fluxo nominal por etapa numa só unidade), e não o par interunidades do protótipo.

## Decisões remanescentes (não bloqueantes para implementar)

1. Validação de nomes do plano estrutural e rubricas pelo contabilista (códigos estáveis).
2. Classificação de "Arrendamento".
3. Valores oficiais INSS/IRT, bases, aplicabilidade de 13.º, subsídio de férias, pensões e terceira idade — **bloqueiam o primeiro processamento salarial em produção**, não o código.
4. Fundos restritos, empréstimos, activos fixos/depreciação, FX, faseamento mensal de orçamento, dimensão departamental, maker≠checker configurável, apuramento anual de resultado, snapshots oficiais de fecho (Reports).
5. Quotas/obrigações §18 (vertical posterior).
6. Portal: o empregado ver o próprio recibo.
7. Regra institucional de destinos permitidos de transferência (hoje: qualquer unidade activa).
8. Validação pelo contabilista do mapeamento DOAF ("apoios"), das rubricas capitalizáveis §16.8 e dos nomes das finalidades de transferência (D-04A); códigos estáveis.
9. Checklist de activação `payroll.production_enabled` (D-04A.15) — bloqueia produção salarial, não o código.

## Referências

`mepa_crm_v1.1.1.md` §15–§20, §31–§33, §44, FIN-PAYROLL-01; ADR 0005, 0009, 0017, 0018, 0019, 0020; `docs/database/04_database_constraints.md`, `07_data_classification.md`, `09_open_database_decisions.md`, `11_financial_invariants_test_plan.md`, `model_catalog.json`, `migration_waves.json`; `docs/reviews/P0.4_global_completeness_audit.md` §5–§6.
