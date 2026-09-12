# Shared hosting como primeiro ambiente de produção

**Status:** Accepted  
**Data:** 2026-09-05

## Contexto

A primeira produção deve operar em shared hosting, mantendo uma trajectória viável de crescimento para VPS.

## Decisão

Usar Laravel/PHP, MySQL ou MariaDB, frontend compilado, Cron, abstracção de storage e serviços externos por adapters. Docker, Redis, Supervisor, Kubernetes, servidor WebSocket e processos residentes não serão requisitos iniciais.

## Consequências

Configuração será feita por ambiente, sem caminhos absolutos. O domínio permanecerá independente da infraestrutura para permitir migração posterior a VPS.

## Referência

`mepa_crm_v1.1.1.md`, secções 37, 38 e 56.


## Revisão documental P0.2 — 2026-09-12

Estado Accepted preservado. Aprofundamento técnico proposto em docs/database/01_database_principles.md e 04_database_constraints.md; não constitui nova aprovação. Alternativas, locks e limitações de infraestrutura/identidade constam desses documentos. Migrations de negócio continuam fora desta fase.
