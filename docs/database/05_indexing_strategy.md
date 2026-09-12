# Índices e capacidade

Estado: proposta. Todos os índices estão enumerados no dicionário/catálogo. PK/UNIQUE já são índices; não duplicar com INDEX idêntico. FK precisa de suporte pelo prefixo esquerdo de um índice composto ou índice simples, declarado no catálogo. Sem indexar tudo.

| Consulta | Índice crítico | Motivo/limite |
|---|---|---|
| Pessoa pública | people(public_id) UNIQUE | Igualdade; reautorizar depois |
| Nome paginado | people(full_name,id) | Prefixo/keyset; LIKE '%nome%' não usa bem B-tree |
| Documento candidato duplicado | person_documents(document_type_id,issuer_country,number_blind_index) | HMAC privado; duplicidade requer política antes de UNIQUE |
| Telefone partilhado | person_contacts(value_blind_index) | Indício, não fusão automática |
| Filhos da árvore | organizational_units(parent_id,unit_type_id,status) | Descendentes imediatos; percurso/CTE autorizado |
| Membros por Congregação | membership_periods(congregation_id,status_id,starts_at) | Restringir início; filtrar fim, intervalo não totalmente coberto |
| Nomeações do cargo/Pessoa | appointments(post_id,starts_at), (person_id,starts_at) | Consulta de períodos; lock de âncora garante exclusividade |
| QR | credentials(token_hash) UNIQUE | Token aleatório hashed |
| Entrada nominal | event_checkins(session_id,person_id) UNIQUE | Regra de negócio e conflito determinístico |
| Entradas cronológicas | event_checkins(session_id,checked_at) | Paginação local |
| Matrícula/turma | enrollments(class_id,status), UNIQUE(person_id,class_id) | Lista de turma e vínculo individual |
| Presença/tentativas | UNIQUE(enrollment_id,class_session_id); UNIQUE(assessment_id,enrollment_id,attempt_number) | Evidência nominal e limite sob lock |
| Período contabilístico | journal_entries(period_id,status,entry_date,id) | Fonte POSTED e paginação |
| Razão da unidade | journal_lines(unit_id,ledger_account_id,entry_id) | Unidade/conta; join cabeçalho para data/estado |
| Tesouraria | journal_lines(financial_account_id,entry_id) | Caixa/banco, saldo derivado |
| Dívidas vencidas | receivables/payables(unit_id,status,due_on) | Estado sozinho baixa cardinalidade |
| Transferências pendentes | internal_transfers(origin_account_id,status), (destination_account_id,status) | Origem/destino sem receita duplicada |
| Auditoria de entidade | audit_logs(entity_type,entity_id,occurred_at) | Histórico local sem indexar before/after JSON |
| Auditoria por unidade | audit_logs(unit_id,occurred_at,id) | Scope/arquivo temporal |
| Não lidas | notifications(user_id,read_at,id) | Prefixo user e NULL não lida |
| Outbox pronta | outbox_events(status,available_at,id) | Cron por pequenos lotes |
| Fecho oficial | UNIQUE(unit_id,period_id,version); UNIQUE(snapshot_id,metric_id,dimension_set_key) | Versões/valores sem duplicação |
| Locais próximos | physical_locations(latitude,longitude,id) | Longitude pode ser filtro residual; não spatial |

70 mil membros inicial; cenário 100–500 mil Pessoas, 10 milhões journal_lines/presenças e 20 milhões logs. São cenários de ensaio, não quotas. Estimativa 250–500 bytes/linha com índices: 10 milhões =2,5–5 GB antes de margem/backups. Logs 1–3 KB: 20 milhões =20–60 GB, excedendo alguns planos partilhados. Fotos separadas 70 mil ×100–300 KB =7–21 GB. Medir páginas/índices/restore, não assumir que plano 50 GB oferece isso para BD.

Antes de implantação: base sintética isolada, EXPLAIN, cardinalidade real e p95 para cadastro/lista/check-in/razão. Keyset data/id, evitar OFFSET grande, export nacional síncrono e N+1. Agregação/export por lotes e watermark consistente. Não indexar ciphertext/TEXT/JSON ou status isolado sem consulta justificada. Medir write amplification/locks antes de índices covering.

Partitioning não é requisito; pode colidir com FKs InnoDB, não prometer particionar ledger sem alterar integridade. Arquivo de logs em storage externo com manifesto/checksum e localização consultável segundo política. VPS pode acrescentar replica/cache/spatial index sem substituir fonte nacional ou scopes. Medir espaço livre, crescimento, tempo de restore e backlog para migrar antes do esgotamento.

## D-01 — índices e custo dos identificadores

PRIMARY KEY(id BIGINT UNSIGNED), UNIQUE(public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin) somente nas 54 entidades classificadas. FKs mantêm BIGINT UNSIGNED e os índices de suporte existentes. Não adicionar INDEX(public_id) além de UNIQUE(public_id). Lookup/pivot/journal_lines não ganham esse índice automaticamente.

Estimativa de payload da chave: BIGINT 8 bytes; UUID/UUIDv7/ULID binário 16; ULID ASCII textual 26. Na chave pública secundária, considerar pelo menos 26+8 bytes por registo antes de overhead/páginas; um milhão de registos representa cerca de 34 MB só desse payload, não tamanho físico total. A PK curta também reduz os índices secundários que carregam o identificador interno. [InnoDB índices](https://dev.mysql.com/doc/refman/8.4/en/innodb-index-types.html).

Cardinalidade public_id é única por tabela. Join usa id numérico; resolver URL por UNIQUE(public_id) e autorizar o recurso. ASCII evita custo de charset Unicode para uma cadeia estritamente ASCII. ULID melhora localidade aproximada face a UUIDv4 aleatório; não é prova de throughput, nem ordenação global/commit. Para listagens oficiais usar created_at/id ou data de negócio e cursor explícito. Medir EXPLAIN, páginas, write amplification e p95 no motor qualificado antes da produção. VPS mantém estratégia e pode ampliar capacidade sem converter FKs.