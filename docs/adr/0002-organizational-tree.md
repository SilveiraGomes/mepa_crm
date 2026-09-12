# Árvore institucional recursiva

**Status:** Accepted  
**Data:** 2026-09-05

## Contexto

A MEPA organiza-se da Direcção Geral até à Congregação. Uma pessoa associada a uma Congregação precisa ser contabilizada nos níveis ascendentes autorizados sem novo cadastro por nível.

## Decisão

Modelar unidades organizacionais como árvore recursiva por relação `parent_id`, separando unidade administrativa/eclesiástica de local físico, propriedade ou templo.

## Consequências

A estrutura suportará níveis existentes e extensões configuráveis, escopo territorial e agregação ascendente. Consultas hierárquicas e regras de autorização deverão respeitar a árvore.

## Referência

`mepa_crm_v1.1.1.md`, secções 5, 6 e 56.


## Revisão documental P0.2 — 2026-09-12

Estado Accepted preservado. Aprofundamento técnico proposto em docs/database/01_database_principles.md e 04_database_constraints.md; não constitui nova aprovação. Alternativas, locks e limitações de infraestrutura/identidade constam desses documentos. Migrations de negócio continuam fora desta fase.
