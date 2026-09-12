# Unidade organizacional separada de imóvel

**Status:** Proposed — formaliza regra da baseline e detalha desenho  
**Data:** 2026-09-12

## Contexto

Congregação não é templo e Centro não é edifício; unidade muda de local sem mudar identidade.

## Decisão

physical_locations, properties, temples, unit_location_links temporais, facility_types/facilities e property_documents separados. Geo DECIMAL portátil; alternativa spatial futura por adapter.

## Alternativas

Endereço único em unidade perde múltiplos locais/histórico. Colunas tem_escola/tem_clinica não são extensíveis.

## Consequências

Principais por intervalo, ocupação e documentos versionados; storage privado fora do DB, sem extensão obrigatória.

## Referências

Baseline: mepa_crm_v1.1.1.md. Detalhes: docs/database/01_database_principles.md, 04_database_constraints.md e 09_open_database_decisions.md. A regra aceite não implica aprovação automática do modelo P0.2.
