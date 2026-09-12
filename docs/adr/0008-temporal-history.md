# Períodos efectivos e versões publicadas

**Status:** Proposed  
**Data:** 2026-09-12

## Contexto

Promoção, nomeação, transferência e correcção não podem sobrescrever o passado.

## Decisão

Intervalos semiabertos starts_at/ends_at com status/reason/source_document_id. Bloquear âncoras existentes contra sobreposição. Versões publicadas imutáveis; auditoria de registo separada do tempo efectivo.

## Alternativas

Coluna de cargo/congregação actual perde histórico. Bitemporalidade completa universal aumenta custo sem requisito fechado.

## Consequências

Consultas por instante; cache actual verificável. Datas desconhecidas e necessidade bitemporal em D-10.

## Referências

Baseline: mepa_crm_v1.1.1.md. Detalhes: docs/database/01_database_principles.md, 04_database_constraints.md e 09_open_database_decisions.md. A regra aceite não implica aprovação automática do modelo P0.2.
