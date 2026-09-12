# Centro Geral opcional e dependência dos Centros

**Status:** Accepted — decisão explicitamente fornecida nesta tarefa  
**Data:** 2026-09-12

## Contexto

A v1.1.0 ainda apresentava uma cadeia que pressupunha Centro Geral obrigatório.

## Decisão

Município pode ter zero ou um Centro Geral e deve ter um ou mais Centros. Com Centro Geral, todos os Centros dependem dele; sem ele, dependem directamente da Direcção Municipal. Congregação depende de Centro. Centro é a designação corrente.

## Alternativas

Centro Geral obrigatório foi rejeitado pela decisão institucional vigente fornecida pelo utilizador.

## Consequências

v1.1.1 corrige hierarquia e drill-down. Criação/remoção de Centro Geral reparenta Centros atomicamente. Mínimo municipal valida-se na activação e em reorganizações.

## Referências

Baseline: mepa_crm_v1.1.1.md. Detalhes: docs/database/01_database_principles.md, 04_database_constraints.md e 09_open_database_decisions.md. A regra aceite não implica aprovação automática do modelo P0.2.

## Reconciliação P0.2-F

A decisão Accepted acima permanece igual. O manifesto de pares é docs/database/unit_parent_rules.json, sem seeds geográficos. DRAFT/ACTIVE/CLOSED são estados existentes; ACTIVE exige Centro vigente e reorganização é atómica sob NATIONAL_TREE. Enforcement detalhado em docs/database/04_database_constraints.md. Candidato generated+UNIQUE é proposta técnica distinta da decisão institucional aceita: exige qualificação D-08 e decisão física D-11; catálogo/raízes/códigos D-12 permanecem BLOCKED. Não se promove esta proposta a Accepted por inferência.