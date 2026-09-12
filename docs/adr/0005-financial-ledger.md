# Ledger financeiro consistente

**Status:** Accepted  
**Data:** 2026-09-05

## Contexto

Finanças nacionais exigem rastreabilidade e relatórios coerentes entre unidades, fundos, rubricas, obrigações e transferências.

## Decisão

Construir a camada financeira futura sobre um ledger consistente com suporte a partidas dobradas. Transferências internas serão movimentos relacionados e não duplicarão receita.

## Consequências

A interface poderá ser simples, mas lançamentos, conciliação, orçamento, tesouraria e relatórios compartilharão a mesma base contabilística. A classificação de rubricas pendentes deverá ser validada antes de seeds definitivos.

## Referência

`mepa_crm_v1.1.1.md`, secções 15 a 20 e 56.


## Revisão documental P0.2 — 2026-09-12

Estado Accepted preservado. Aprofundamento técnico proposto em docs/database/01_database_principles.md e 04_database_constraints.md; não constitui nova aprovação. Alternativas, locks e limitações de infraestrutura/identidade constam desses documentos. Migrations de negócio continuam fora desta fase.
