# Exclusão, arquivo e preservação institucional

**Status:** Proposed  
**Data:** 2026-09-12

## Contexto

Cascades e soft-delete indiscriminado podem destruir provas ou ocultar dados necessários ao ledger.

## Decisão

FKs RESTRICT em delete/update; arquivo lógico específico por entidade; histórico publicado e ledger sem hard-delete; files conserva tombstone depois de purga física autorizada.

## Alternativas

CASCADE generalizado destrói histórico. deleted_at em tudo não define retenção ou imutabilidade.

## Consequências

Catálogos desactivados, versões/estornos, política institucional de prazo/legal hold D-06; acesso aos dados arquivados segue scope.

## Referências

Baseline: mepa_crm_v1.1.1.md. Detalhes: docs/database/01_database_principles.md, 04_database_constraints.md e 09_open_database_decisions.md. A regra aceite não implica aprovação automática do modelo P0.2.
