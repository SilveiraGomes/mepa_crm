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

`mepa_crm_v1.0.1.md`, secções 5, 6 e 56.

