# Órgãos permanentes e eventos concretos

**Status:** Proposed — registo da regra da baseline; desenho físico aguarda auditoria  
**Data:** 2026-09-12

## Contexto

Órgãos de decisão não são níveis territoriais e precisam preservar composição e convocação.

## Decisão

governance_bodies representa órgão permanente; governance_sessions liga reunião a events. Lista de convocados congelada/versionada e convites, resposta, check-in, credenciamento e presença separados.

## Alternativas

Órgão como tipo de unidade mistura governança e território. Evento como identidade do órgão perde continuidade.

## Consequências

Reutiliza Pessoa e credencial permanente, aceita convidado externo sem membership; prefixos de domínio evitam confusão.

## Referências

Baseline: mepa_crm_v1.1.1.md. Detalhes: docs/database/01_database_principles.md, 04_database_constraints.md e 09_open_database_decisions.md. A regra aceite não implica aprovação automática do modelo P0.2.
