# BIGINT interno e ULID público

**Status:** Accepted — decisão técnica explícita do pedido P0.2-D01  
**Data:** 2026-09-12  
**Decisão:** D-01 RESOLVED

## Contexto

O desenho relacional tem 199 tabelas e 439 FKs, com crescimento para milhões de linhas. A re-auditoria identificou D-01 como único bloqueio global ao desenho/escrita da fase física. O utilizador autorizou expressamente a estratégia neste pedido; não se exige nova aprovação MEPA para esta decisão técnica.

## Decisão

PK interna: `id BIGINT UNSIGNED AUTO_INCREMENT`, PRIMARY KEY, NOT NULL e imutável. FKs normais: BIGINT UNSIGNED para `target.id`; nunca public_id ou Número Único como chave relacional. Identidade externa quando justificada: `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutável, gerada na aplicação e não reutilizável. Não há default SQL nem índice simples redundante.

ULID canónico: 26 caracteres ASCII em maiúsculas, Crockford Base32 sem I/L/O/U e primeiro carácter 0..7; validar `^[0-7][0-9A-HJKMNP-TV-Z]{25}$`. Entrada textual pode ser normalizada para maiúsculas antes da validação/resolução; armazenamento e comparação são case-sensitive. ascii_bin é a escolha exacta de collation. CHAR continua a ter regras de padding do motor; rejeitar espaços/formatos inválidos na aplicação. Não confiar na collation como validador.

Critério de aplicabilidade: identidade autónoma em URL/API, QR/documento externo, integração ou referência opaca fora do banco. A classificação explícita está em cada tabela do dicionário e em model_catalog.tables[].identifiers: 54 SIM, 145 NÃO. Lookup usa código aprovado quando necessário; detalhes/pivots são alcançados pelo recurso pai e não ganham ULID automaticamente. Nova entidade exposta exige actualizar a classificação antes de publicar a API.

id, public_id e member_number são distintos. Número Único MEPAAAMMSSSSSS permanece identidade eclesiástica vitalícia, contador nacional contínuo; não é PK nem FK estrutural. QR de acesso/validação continua a usar token aleatório separado e hash: ULID identifica, não autoriza e não substitui esse token.

## Alternativas consideradas

- BIGINT-only: menor armazenamento, mas sem referência pública opaca independente; rejeitado para entidades externas.
- ULID-only: criação distribuída e ordenação temporal aproximada; textual 26 bytes ou binário 16, repetidos nas FKs e índices; não escolhido como PK.
- UUID/UUIDv7: 16 bytes binários, alternativa válida para escritores distribuídos; UUIDv7 melhora localidade temporal. Topologia inicial é banco nacional único, sem necessidade de PK distribuída.
- BIGINT + ULID: 8 bytes nos relacionamentos internos e identidade externa selectiva; adoptado.

## Consequências

UNIQUE(public_id) acrescenta custo só às entidades classificadas. InnoDB inclui a PK nos índices secundários: manter PK de 8 bytes evita propagar 16/26 bytes para todos os índices. Benefício estimado por largura, não benchmark de produção. Joins usam id; paginação usa chave/instante apropriado. ULID revela tempo aproximado e não garante ordem global ou de commit; created_at/id continuam necessários quando essa ordem importa.

Laravel futuro: geração comum por serviço partilhado usando Str::ulid(), registado no ponto único de criação das entidades aplicáveis. Trait/listener só como implementação futura desse contrato; não aplicar trait que converta a PK id para string. Eloquent mantém chave inteira incrementing; route binding resolve public_id e executa policy/scope. Não implementar módulos/traits/migrations nesta tarefa.

Colisão: UNIQUE é árbitro final; retry limitado apenas para colisão do índice public_id, nova geração antes do commit, sem contornar outras constraints. Replay idempotente devolve o public_id já confirmado. Import preserva public_id externo previamente validado ou usa mapa de origem; nunca reatribui identificador a outro registo. Arquivo/tombstone preserva a reserva; qualquer purga futura requer política de não reutilização D-06.

ULID não é segurança: autenticação, autorização, scopes, policies e minimização de exposição permanecem obrigatórios. Opaque não significa segredo. Hosting partilhado não exige extensão, trigger, Redis ou serviço de IDs; VPS mantém a mesma estratégia. Escrita distribuída futura exige ADR específico, sem conversão antecipada de PKs.

## Referências

[D-01 resolution](../reviews/P0.2_D01_resolution.md), [princípios](../database/01_database_principles.md), [dicionário](../database/02_data_dictionary.md), [re-auditoria](../reviews/P0.2_database_reaudit.md). Baseline: mepa_crm_v1.1.1.md alinhada tecnicamente neste ponto. D-02..D-12 não são resolvidas aqui. P0.3 não foi iniciada; próximo passo é Gate final.