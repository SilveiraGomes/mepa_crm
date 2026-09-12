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

`mepa_crm_v1.0.1.md`, secções 37, 38 e 56.

