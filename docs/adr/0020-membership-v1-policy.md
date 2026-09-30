# ADR 0020: Membership — política V1 (admissão, número, lifecycle, transferência, legado)

**Status:** Accepted

**Data:** 2026-09-30

**Fase:** P0.9-D (preflight + decision freeze). Baseline `55c0821048a7035f63d7f1ffa3a21a2abc133e26` (`main` local, com Academia, People/Families, Territorial, Physical Locations e Documents/Files integrados), branch `p09-membership`. `origin/main` está em `365a70f`: os 7 commits da P0.8 ainda não foram enviados (ver "Baseline").

## Contexto

A matriz P0.4 classifica Membership como **L2**. O schema físico da Wave 2 existe e dois serviços têm provas de concorrência reais. Não existem catálogo, permissions, autoridade, auditoria, API, UI nem fluxo de admissão.

### Inventário

| Área | Estado actual |
|---|---|
| `memberships` | 1 linha por Pessoa (`uq_memberships_person_id`), `public_id`, `status_id` → `membership_statuses`, `admitted_on` DATE + `date_precision`, `approved_by`/`approved_at` nullable, `source_document_id` → `legal_documents`, `origin` sem CHECK. |
| `membership_periods` | Histórico estado × Congregação: `congregation_id`, `status_id`, `[starts_at, ends_at)` com CHECK, `reason`, `source_document_id`. **Sem guarda física de período aberto único.** |
| `member_number_sequences` | Singleton nacional (`code` UNIQUE). **Nenhuma linha instalada fora dos testes.** |
| `member_numbers` | `number` CHAR(14) UNIQUE, `sequence_value` UNIQUE e 1..999999, `membership_id` UNIQUE, `issued_year`/`issued_month`, CHECK `origin IN ('APPROVED_ADMISSION','APPROVED_LEGACY_MAPPING')`. |
| `legacy_member_numbers` | `raw_number`, `normalized_number`, `source_system`, `status`, `import_record_id`. Índice `(source_system, normalized_number)` **não UNIQUE** por desenho: os duplicados legados são sinalizados, não rejeitados. |
| `milestone_types` / `ecclesiastical_milestones` | Facto da **Pessoa**, com `occurred_on` + `date_precision`, `unit_id` opcional e `source_document_id`. Catálogo vazio; nenhum writer. |
| `transfers` | `public_id`, origem ≠ destino (CHECK), `workflow_instance_id` **NOT NULL**, `status` VARCHAR livre, `effective_at`, `closed_at`, e guarda `uq_transfers_membership_open` (P0.3.2-M1). |
| `workflows` / `workflow_instances` | Físicas e vazias. `membership_workflows`, `workflow_steps`, `workflow_tasks` e `workflow_decisions` são da Wave 7 e não existem fisicamente. |
| `MemberNumberGenerator` | Lock `memberships` → singleton; exige `approved_at`; replay idempotente; nunca faz reset; falha fechada em 999999. |
| `TransferService` | `request` / `effectuate` / `cancel` com guarda de pedido em curso. **Não valida a origem contra o período actual, nem o estado da membership, nem o tipo de unidade, e `effectuate` não fecha nem abre `membership_periods`.** Não tem rota HTTP. |
| Consumidores existentes | `PeopleAuthority` já usa `membership_periods` como contexto `MEMBERSHIP` (período vigente, qualquer estado). Events/`CredentialService` lê `member_numbers`, `approved_at` e o estado via `EventPolicy`. |
| Permissions / audit / API / UI | Nenhuma. |
| Testes | `WaveTwoConcurrencyTest` (gerador, 2–50 workers, kill, fronteira Dez→Jan), `WaveTwoTransferConcurrencyTest` e `WaveTwoM1IndependentAuditTest` (pedido duplo, SQL cru, kill, rollback). Reexecutados nesta fase em MySQL 8.4.7: **24/24 PASS, 237 asserções** (`docs/reviews/evidence/P0.9-D/`). |

### Nomes pedidos vs nomes reais

`membership_numbers` = `member_numbers`; `membership_number_counters` = `member_number_sequences`; `membership_legacy_identifiers` = `legacy_member_numbers`; `membership_milestones` = `ecclesiastical_milestones` (+ `milestone_types`); `membership_transfers` = `transfers`. `membership_transfer_history` **não existe**: o histórico da transferência é a própria linha `transfers` (append-only por estado, com `closed_at`) + `membership_periods` + auditoria. Não se cria tabela nova para isso.

## Decisões que este ADR não reabre

Person como raiz (ADR 0001/0017), formato `MEPAAAMMSSSSSS` (ADR 0003), TerritorialAuthority (ADR 0017/0018), segurança Files (ADR 0019) e D-01 (ADR 0009).

## D01: identidade

1. Membership consome uma Pessoa existente pelo `public_id` de People. **Membership nunca cria Pessoa.** Se a Pessoa não existe, é criada primeiro em People, com o contexto `ONBOARDING` do ADR 0017.
2. O actor tem de conseguir ver a Pessoa pela `PeopleAuthority`. Caso contrário, `404` ocultado.
3. `uq_memberships_person_id` impede uma segunda membership: no máximo uma relação eclesiástica por Pessoa, para toda a vida. Duas submissões concorrentes para a mesma Pessoa resultam numa linha; a outra recebe `409 MEMBERSHIP_EXISTS` (o actor já vê a Pessoa, pelo que isto não é oráculo).
4. **Membro** significa `member_numbers` existente **e** estado `ACTIVE` ou `INACTIVE`. Uma Pessoa com candidatura (`SUBMITTED`/`VALIDATED`/`REJECTED`/`WITHDRAWN`) continua não membro e não tem número.
5. Pessoa `ARCHIVED` ou fundida: qualquer escrita de Membership é recusada. Pessoa `DECEASED`: ver D03.
6. Nada escreve `people.unit_id` (coluna rejeitada no ADR 0017) nem `person_unit_contexts`.

## D02: número único

Confirmado sem alteração (ADR 0003 + gerador existente):

- `MEPA` + `AA` + `MM` + `SSSSSS` (singleton `MEPA_NATIONAL`, contínuo, nunca faz reset no mês ou no ano).
- Gerado **só** na transacção que aprova a admissão, depois de `approved_at`/`approved_by` preenchidos sob o mesmo lock. Nenhum outro caminho chama o gerador.
- Imutável: nenhum UPDATE em `member_numbers`. Transferência, inactivação, fim, readmissão e falecimento não o alteram. Readmissão reutiliza o número existente.
- Separado de `id` e `public_id`. A API nunca usa o número como chave de rota: o número é um termo de pesquisa.

**Fechado agora (técnico):** `AA`/`MM` são o ano e o mês civis de **`Africa/Luanda`** do instante da aprovação que emite o número (o mesmo fuso de `config/people.php`). `issued_at` fica guardado em UTC; `issued_year`/`issued_month` são coerentes com `AA`/`MM`. Uma aprovação às 00:30 de 1 de Outubro em Luanda produz `MM=10`, embora seja 23:30 UTC de 30 de Setembro. Esta regra fecha a ambiguidade de fuso do gerador actual, que usa o fuso do `DateTimeImmutable` recebido.

**Emissão vs data de admissão:** `admitted_on` é a data institucional da admissão (por exemplo, a data da acta), com `date_precision` EXACT/MONTH/YEAR/UNKNOWN (vocabulário do P0.5), e pode ser anterior à aprovação. **Não altera `AA`/`MM`**, que são sempre os da emissão (ADR 0003: "ano da emissão/admissão aprovada"). Nunca se inventa `admitted_on`: sem data conhecida fica `NULL` + `UNKNOWN`. `admitted_on` posterior ao dia da aprovação é rejeitado.

**Instalação:** a linha `MEPA_NATIONAL` é inserida com `last_value = 0` **só se não existir**. A instalação nunca faz UPDATE nessa linha.

## D03: lifecycle V1

A fonte são o fluxo "Novo membro" da §33 (Rascunho → Submetido → Validação → Aprovação → Número Único → Credencial), o dicionário ("perfil preservado após suspensão/saída") e a lista de estados desta fase. Nenhum estado disciplinar é criado.

### `membership_statuses` V1

| Código | Nome | Membro? | Origem |
|---|---|---|---|
| `SUBMITTED` | Candidatura submetida | Não | §33 Submetido |
| `VALIDATED` | Candidatura validada | Não | §33 Validação |
| `REJECTED` | Candidatura não aprovada | Não | Resultado negativo da Aprovação |
| `WITHDRAWN` | Candidatura retirada | Não | Desistência antes da Aprovação |
| `ACTIVE` | Membro activo | Sim | §33 Aprovação |
| `INACTIVE` | Membro inactivo | Sim | Inactivação administrativa, reversível |
| `ENDED` | Membresia terminada | Ex-membro (mantém o número) | Saída, falecimento ou outro fim, com motivo |

- **Rascunho não é persistido** como membership na V1. É o formulário multi-etapas ainda não submetido. Evita criar contexto `MEMBERSHIP` em People a partir de um rascunho.
- **Credencial** continua no vertical Events (`CredentialService`). Membership só entrega a pré-condição: número + `approved_at`.
- Suspensão e exclusão disciplinares **não** existem na V1. `INACTIVE` é administrativo; não é sanção.

### Transições

| De | Para | Acção | Permission |
|---|---|---|---|
| — | `SUBMITTED` | submeter candidatura | `MEMBERSHIP_ADMISSION_MANAGE` |
| `SUBMITTED` | `VALIDATED` | validar | `MEMBERSHIP_ADMISSION_MANAGE` |
| `SUBMITTED`/`VALIDATED` | `WITHDRAWN` | retirar | `MEMBERSHIP_ADMISSION_MANAGE` |
| `VALIDATED` | `REJECTED` | não aprovar | `MEMBERSHIP_APPROVE` |
| `VALIDATED` | `ACTIVE` | **aprovar → número** | `MEMBERSHIP_APPROVE` |
| `REJECTED`/`WITHDRAWN` | `SUBMITTED` | nova candidatura (mesma linha) | `MEMBERSHIP_ADMISSION_MANAGE` |
| `ACTIVE` | `INACTIVE` | inactivar | `MEMBERSHIP_MANAGE` |
| `INACTIVE` | `ACTIVE` | reactivar | `MEMBERSHIP_MANAGE` |
| `ACTIVE`/`INACTIVE` | `ENDED` | terminar (motivo obrigatório) | `MEMBERSHIP_MANAGE` |
| `ENDED` | `ACTIVE` | readmitir (mesmo número, motivo obrigatório) | `MEMBERSHIP_APPROVE` |

Qualquer outra transição → `409 TRANSITION_NOT_ALLOWED`. `SUBMITTED` → `ACTIVE` directo não existe: a validação é obrigatória, como na §33.

### Períodos

- **Invariante:** toda a membership tem **exactamente um** período aberto (`ends_at IS NULL`) desde a submissão. Cada mudança de estado ou de Congregação fecha o período corrente em `T` e abre outro em `T` (histórico contíguo, sem sobreposição e sem lacuna).
- `memberships.status_id` é a cópia do estado do período aberto, escrita na mesma transacção. O validador verifica a igualdade.
- O período aberto em `REJECTED`, `WITHDRAWN` ou `ENDED` mantém-se. A Congregação continua a ver o histórico dessa Pessoa pelo contexto `MEMBERSHIP` já existente em `PeopleAuthority` (período vigente, qualquer estado). Isto é intencional e não altera People.
- `approved_at`/`approved_by` registam a **primeira** aprovação e não são reescritos. A readmissão fica no novo período, com `reason`, `source_document_id` e auditoria.
- Os instantes dos períodos são os do commit (servidor). Transição retroactiva ou com data futura é D-10 e fica fora da V1. A data institucional vai no documento e em `admitted_on`.

### Falecimento

`DECEASED` é estado da **Pessoa** (ADR 0017) e não da membership. Membership não escreve em People e People não escreve em Membership.

- Pessoa `DECEASED`: submeter, validar, aprovar, reactivar, readmitir e transferir são recusados (`409 PERSON_DECEASED`). Só é permitido terminar (`ENDED`).
- Uma membership `ACTIVE`/`INACTIVE` de uma Pessoa `DECEASED` aparece como "Falecido(a)" e sai das contagens de membros activos (junção com `people.status_id`). Nada é apagado; número, períodos e marcos ficam preservados.

## D04: admissão

| Pergunta | Regra V1 |
|---|---|
| Quem propõe | Actor com `MEMBERSHIP_ADMISSION_MANAGE` cujo scope cubra a Congregação de admissão, e que veja a Pessoa em People. |
| Quem valida | Idem (`SUBMITTED` → `VALIDATED`). |
| Quem aprova | Actor com `MEMBERSHIP_APPROVE` cujo scope cubra a Congregação. O nível institucional (Congregação, Centro, Município…) é definido pelos grants, não por código. Sem roles novas. |
| Unidade de origem | A Congregação da candidatura, indicada por `public_id` e validada pelo backend como `CONGREGATION` `ACTIVE` coberta pelo scope. Fica no período aberto. |
| Data efectiva | `admitted_on` + `date_precision` (D02). Por defeito é a data de Luanda da aprovação, com `EXACT`. |
| Número | Gerado na mesma transacção da aprovação (D02). |
| Documento | Opcional (D09). |
| Auditoria | Uma linha por transição (D11). |
| `memberships.origin` | `ADMISSION` (→ `member_numbers.origin = APPROVED_ADMISSION`) ou `LEGACY_IMPORT` (regularização de membro histórico, com ≥ 1 identificador legado e `admitted_on` que pode ser `UNKNOWN`; → `APPROVED_LEGACY_MAPPING`). Validado pelo serviço; sem CHECK novo. |
| Menores | Sem idade mínima na V1, porque não há regra aprovada. A exposição segue a projecção minor-safe de `PersonRecords` (P0.5). |
| Segregação | Permissions separadas para propor/validar e aprovar. Não há bloqueio "aprovador ≠ proponente" na V1 (segregação D-11 fica em aberto e não bloqueia). |

Revalidação no commit, sob lock: Pessoa não `DECEASED`/arquivada/fundida, transição permitida, Congregação `ACTIVE`, grant vigente (`TerritorialAuthority::authorize(..., lock: true)`) e `lock_version` esperado (`409 STALE_WRITE`).

### Admissão colectiva

O texto do Estatuto **não está no repositório**. A representação V1 não depende do órgão exacto:

- Um comando `collective-approval` recebe uma lista ordenada de memberships `VALIDATED` (por `public_id`, máximo 200), um `admitted_on` + precisão comuns e um `source_document` opcional (acta/resolução).
- A lista é **tudo ou nada** numa única transacção. Se um item falhar, nada é aprovado e a resposta lista os erros por item.
- **Lock order:** as Pessoas e depois as memberships, **por `id` ascendente**, e só então o singleton, uma vez. Os números são atribuídos pela **ordem da lista** (a da acta). Lotes concorrentes com interseção não fazem deadlock.
- O aprovador precisa de `MEMBERSHIP_APPROVE` sobre cada Congregação envolvida. O lote pode abranger várias.
- Cada membership recebe a sua linha de auditoria (unidade = a sua Congregação), com o mesmo `correlation_id` e o `public_id` do documento.
- Uma admissão individual é o caso de lista com 1 elemento. Não há segundo caminho de geração.

## D05: autoridade territorial

- Resolver único: `TerritorialAuthority` com `data_type='MEMBERSHIP'`, como Physical e Files. Não há segundo motor.
- **Unidade autorizadora** = `congregation_id` do período aberto. Nas transferências é a origem ou o destino, conforme a etapa (D06).
- A permission nunca substitui o scope. `public_id` e URI nunca concedem autoridade.
- Histórico de períodos: só quem tem autoridade sobre a unidade do período **aberto** o vê. A Congregação de origem, depois de uma transferência concluída, deixa de ver a membership, mas continua a ver a linha `transfers` em que foi parte.
- A pesquisa filtra por scope no SQL (`coveredUnits`), nunca em memória depois da query.

## D06: transferências

### Estados de `transfers.status` (V1, validados pelo serviço)

`REQUESTED` → `ORIGIN_VALIDATED` → `DESTINATION_ACCEPTED` → `COMPLETED` (§33: Pedido → Validação de Origem → Aceitação do Destino → Efectivação). Os estados terminais sem efeito são `REJECTED` e `CANCELLED`. `closed_at` é preenchido nos três terminais; `effective_at` só em `COMPLETED`. O default `'PENDING'` do `TransferService` actual passa a `REQUESTED`.

| Etapa | Quem (`MEMBERSHIP_TRANSFER` +) | Unidade de audit |
|---|---|---|
| pedir | scope sobre a origem **ou** o destino | a do requerente |
| validar origem / rejeitar | scope sobre a origem | origem |
| aceitar / rejeitar | scope sobre o destino | destino |
| efectivar | scope sobre o destino | destino (origem em metadata) |
| cancelar (antes de `COMPLETED`) | scope sobre a origem ou o destino | a do actor |

### Regras

- Só membership `ACTIVE`, de Pessoa não `DECEASED`. A origem tem de ser **igual** ao `congregation_id` do período aberto. O destino tem de ser `CONGREGATION` `ACTIVE`, diferente da origem.
- Cada transferência cria um `workflow_instances` (workflow `MEMBERSHIP_TRANSFER` v1, `unit_id` = origem, `requested_by` = actor), porque a FK é NOT NULL. O estado da instância acompanha o da transferência na mesma transacção.
- **Efectivação atómica:** lock Pessoa → membership → transferência. Revalida **no commit**: transferência aberta em `DESTINATION_ACCEPTED`, membership `ACTIVE`, origem = período aberto, destino `ACTIVE`, grant vigente. Depois fecha o período de origem em `T`, abre o período `ACTIVE` no destino em `T`, fecha a transferência (`effective_at = closed_at = T`) e completa o workflow. **Não toca em `member_numbers`.** Replay de uma efectivação concluída é no-op.
- Com uma transferência aberta, inactivar, terminar e nova transferência são recusados (`409 TRANSFER_IN_PROGRESS`). O pedido duplo já é recusado fisicamente por `uq_transfers_membership_open`.
- O histórico nunca é reescrito: a linha `transfers` fica, com o estado final, mais os dois períodos.

### Transferência de membership ≠ `person_unit_contexts`

| | Transferência de membership | `person_unit_contexts` (People) |
|---|---|---|
| O que muda | A Congregação eclesiástica da membership (`membership_periods`) | Nada. Não é escrita por Membership |
| Natureza | Facto institucional com workflow, documento e auditoria | Proveniência para Pessoas **sem** outro contexto de domínio (`ONBOARDING`, `EMPLOYMENT`, `DISCIPLESHIP`, `LEGACY_IMPORT`) |
| Efeito em People | O contexto `MEMBERSHIP` da `PeopleAuthority` passa automaticamente para o destino | Continua independente. Um contexto `ONBOARDING` na origem, se existir, segue o seu próprio lifecycle em People |

## D07: identificadores legados

- Nunca substituem o número oficial nem geram número. São preservados (`raw_number` exactamente como recebido), pesquisáveis (`normalized_number`) e auditáveis.
- `source_system` V1: `MEPA_LEGACY_V1` (o gerador antigo da §42). Outros sistemas exigem uma adenda.
- Normalização: NFKC, maiúsculas e remoção de espaços e dos separadores `- . /`.
- `status` V1: `ACTIVE`, `CONFLICT` e `REVOKED`.
  - Registar o mesmo `(source_system, normalized_number)` numa membership que já o tem → `409 LEGACY_ID_EXISTS`.
  - Se o valor existe noutra membership, **as duas linhas passam a `CONFLICT`**, sem bloquear. Só um actor com scope sobre as duas vê o detalhe; os outros vêem apenas "identificador em conflito", sem identidade.
  - A resolução é revogar a linha errada (`REVOKED`, com motivo). A linha fica para sempre e o valor não é reciclado: um `REVOKED` não pode ser reactivado nem reutilizado noutra membership sem nova linha auditada, que volta a entrar na detecção de conflito.
- Sem hard delete. Endereçamento externo pela chave natural `(membership public_id, source_system, normalized_number)`, porque a tabela não tem `public_id` e o `id` interno nunca é exposto.
- A importação em massa (`import_record_id`, pipeline da §41) é D-02 e fica fora da V1.

## D08: marcos eclesiásticos

- São da **Pessoa**, não estado de Membership, e nunca alteram `membership_statuses`.
- `milestone_types` V1: `CONVERSION` (Conversão) e `BAPTISM` (Baptismo). **Admissão não é marco:** vive em `memberships.admitted_on`, para não duplicar o facto.
- Precisão: `EXACT`/`MONTH`/`YEAR`/`UNKNOWN`, como em `BirthDate`. `UNKNOWN` guarda `occurred_on = NULL`. Datas futuras são rejeitadas e nunca se inventa um dia ou mês.
- No máximo 1 marco por tipo e por Pessoa (serviço, sob lock da Pessoa). A correcção é UPDATE com motivo e auditoria antes/depois.
- Na V1, registo e consulta passam pela membership (membro ou candidato). A autoridade é a Congregação do período aberto. `unit_id` é informativo (unidade territorial por `public_id`) e não concede autoridade. Marcos de não-membros sem candidatura ficam para Evangelism.

## D09: documentos (integração mínima com Files)

- `source_document` **opcional** em: aprovação individual ou colectiva (`memberships.source_document_id`), transições de estado e readmissão (`membership_periods`), pedido e aceitação de transferência (`transfers`) e marcos. Nenhum é obrigatório na V1, porque não há regra institucional que o exija.
- Só `public_id` de `legal_documents`. Um inteiro é campo desconhecido (`422`), como na correcção P08-D-F01.
- Autoridade **cumulativa**: permission Membership sobre a unidade do alvo **e** `FilesConsumers::documentAuthority` (`DOCUMENTS_VIEW` + scope sobre `owner_unit_id` + clearance + documento `ACTIVE`). O documento é resolvido **depois** da decisão de autoridade Membership. Inexistente, malformado, de outra unidade, acima da clearance ou arquivado → o mesmo `404` ocultado.
- Tipos existentes suficientes (`MINUTES`, `RESOLUTION`, `CORRESPONDENCE`, `OTHER`). Sem tipos novos e sem piso de classificação acima do default `RESTRICTED`.
- A projecção de detalhe só inclui o `public_id` do documento se o actor passar a autoridade Files. Caso contrário, mostra apenas `has_document: true`.
- Não se prevê alteração ao código Files. Se for preciso registar um consumidor, é uma mudança revista à parte.

## D10: permissions V1

`data_type='MEMBERSHIP'`, `action=code`, `maximum_classification='RESTRICTED'` (vocabulário de Physical/Files). Só instaladas: 0 roles.

| Code | Concede |
|---|---|
| `MEMBERSHIP_VIEW` | lista, pesquisa (nome, número, legado), detalhe, períodos, marcos, identificadores legados, transferências visíveis |
| `MEMBERSHIP_ADMISSION_MANAGE` | submeter, validar, retirar e re-submeter candidatura |
| `MEMBERSHIP_APPROVE` | aprovar (individual ou colectiva) → número; não aprovar; readmitir |
| `MEMBERSHIP_MANAGE` | inactivar, reactivar, terminar; registar e corrigir marcos |
| `MEMBERSHIP_TRANSFER` | todas as etapas de transferência, conforme a tabela D06 |
| `MEMBERSHIP_LEGACY_MANAGE` | registar e revogar identificadores legados |

### F-06 / IDOR

- A permission é verificada **antes** de resolver o alvo (lição P07-I-02).
- Membership, transferência, candidatura, marco ou identificador legado inexistente, malformado ou fora do scope → `404 RESOURCE_NOT_FOUND` byte-idêntico.
- **Pesquisa por número ou legado** devolve uma lista filtrada por scope. Fora do scope dá lista vazia, igual a "não existe". Nunca `403` e nunca `404` distinto.
- Rotas só por `public_id` (`memberships`, `transfers`) ou por chave natural sob um `public_id` (legado, marco). Nenhuma PK sai na API.
- Rate limiters nomeados `membership`, `membership-write` e `membership-search`. A pesquisa por número é limitada contra enumeração.

## D11: auditoria

`source='P09_MEMBERSHIP'`. `unit_id` é a unidade autorizadora (D05/D06); as outras unidades vão na metadata.

Eventos: `membership.submitted`, `.validated`, `.withdrawn`, `.rejected`, `.approved` (com o número emitido e `correlation_id` no caso colectivo), `.inactivated`, `.reactivated`, `.ended`, `.readmitted`, `membership_transfer.requested`, `.origin_validated`, `.accepted`, `.completed`, `.rejected`, `.cancelled`, `legacy_identifier.registered`, `.conflict_flagged`, `.revoked`, `milestone.recorded` e `.corrected`.

Metadata permitida: `public_id` (membership, Pessoa, unidade, transferência, documento), estados antes/depois, número oficial, `source_system` + valor normalizado, e código e texto de motivo. **Nunca:** dados sensíveis de People, título de documento Confidencial ou superior, nem PKs como identidade externa.

## D12: concorrência (plano de testes reais)

Processos PHP separados com barreira ready/go em MySQL 8.4, no padrão dos workers da Wave 2. **Global lock order:** Pessoa → membership(s) por `id` ascendente → transferência → períodos → singleton.

| ID | Corrida | Esperado |
|---|---|---|
| C1a | N aprovações simultâneas de candidatos distintos (N = 2, 10, 30) | N números distintos, `sequence_value` contíguos, singleton = máx. Já provado ao nível do gerador; repetir pelo serviço de admissão |
| C1b | Duas aprovações da mesma membership | 1 número; a outra dá replay ou `409 TRANSITION_NOT_ALLOWED`. Nunca dois |
| C1c | Aprovação colectiva vs aprovação individual de um candidato do lote | Sem deadlock e sem número duplo. Ou o lote inteiro, ou o individual primeiro com o lote a falhar tudo |
| C1d | Dois lotes colectivos com interseção | Sem deadlock; um vence e o outro falha inteiro |
| C2a | Efectivar transferência vs inactivar ou terminar | Um só resultado coerente: `COMPLETED` + destino `ACTIVE`, com inactivação `409 TRANSFER_IN_PROGRESS`; nunca período aberto na origem e no destino ao mesmo tempo |
| C2b | Pedir transferência vs terminar | Pedido + fim recusado, ou fim + pedido `409 MEMBERSHIP_NOT_ACTIVE` |
| C3a | Dois pedidos de transferência concorrentes | 1 aberta (guarda física). Já provado; repetir pelo serviço novo |
| C3b | Duas efectivações da mesma transferência | 1 par de períodos; a outra é no-op |
| C3c | Efectivar vs cancelar | Exactamente um terminal |
| C4 | Duas submissões para a mesma Pessoa | 1 membership |
| C5 | Dois registos do mesmo legado em memberships diferentes | As duas linhas `CONFLICT`, nenhuma perdida |

Em todos os casos, o validador confirma que há exactamente um período aberto por membership, histórico contíguo, `status_id` igual ao do período aberto e que `member_numbers` não muda.

## D13: UI (Web/PWA, mobile-first)

| Rota | Conteúdo |
|---|---|
| `/membros` | Lista e pesquisa única: nome, número oficial ou identificador legado. Filtros de estado e Congregação dentro do scope. Cartões no telemóvel, tabela a partir de `md` |
| `/membros/admissoes` | Fila `SUBMITTED`/`VALIDATED`: submeter a partir de uma Pessoa existente, validar, aprovar individualmente ou em lote (selecção múltipla + acta opcional) |
| `/membros/:publicId` | Resumo da Pessoa (projecção People, minor-safe), número, estado, badge "Falecido(a)" e acções permitidas |
| `…/historico` | Períodos (estado × Congregação × datas) e transferências |
| `…/identificadores` | Identificadores anteriores, conflitos e revogações |
| `…/marcos` | Conversão e baptismo com precisão |
| `/membros/transferencias` | Recebidas e enviadas, com as acções da etapa |

Esconder botões é só usabilidade: a autoridade é sempre do backend. Não há cache PWA de `/api/` (já é `NetworkOnly`).

## Schema delta

**O schema actual basta, com uma excepção.**

| Pergunta | Resposta |
|---|---|
| Tabelas em falta? | Nenhuma. `membership_workflows` (Wave 7) não é necessária: a admissão usa `membership_statuses`, e a transferência já tem FK para `workflow_instances`. `membership_transfer_history` não é criada (D06). |
| Guarda física de período aberto único | **Em falta e necessária.** É a protecção física de C2/C3 ("nunca membership activa em duas unidades"), com o mesmo precedente de F-W2F-01 / `uq_transfers_membership_open`. |
| CHECK em `transfers.status`, `memberships.origin` ou `legacy_member_numbers.status`? | Não. O vocabulário é validado pelo serviço, pelo validador e pelas probes, como em ADR 0019. |
| Índice para pesquisa legada sem `source_system`? | Não. Com um só `source_system` V1, a pesquisa usa `(source_system, normalized_number)` com `IN`. |
| Colunas `submitted_by`/`validated_by`? | Não. A auditoria regista o actor. A segregação fica em aberto (D04). |

### Migrations necessárias

1. **DDL (1):** `membership_periods` recebe `open_flag TINYINT UNSIGNED GENERATED ALWAYS AS (IF(ends_at IS NULL, 1, NULL)) STORED` + `UNIQUE KEY uq_membership_periods_membership_open (membership_id, open_flag)`, num único `ALTER`. Antes, faz um pre-check que aborta sem alterar dados se existir mais de um período aberto por membership. `down` remove as duas coisas.
2. **Dados controlados (idempotente, só inserir o que falta, nunca apagar, 0 roles):** `MembershipCatalog::install()`:
   - 7 `membership_statuses`;
   - 2 `milestone_types`;
   - 6 permissions;
   - `workflows` `MEMBERSHIP_TRANSFER` v1 `ACTIVE`;
   - singleton `MEPA_NATIONAL` (`last_value = 0` só se não existir).
3. Passos correspondentes em `migrate_pool_db.php` e no manifest delta de paridade: 0 tabelas novas, 1 coluna generated, 1 UNIQUE e as linhas controladas.

Nenhuma alteração a `people`, `person_unit_contexts`, tabelas Territorial ou Files.

## Achados do inventário

- **P09-D-F01 (DESIGN, bloqueia só a efectivação):** `TransferService::effectuate` fecha a transferência sem mover `membership_periods` e sem revalidar a origem ou o estado. Hoje não é explorável, porque não há rota. A implementação P0.9 tem de o substituir pela efectivação atómica de D06, mantendo a guarda e os testes existentes.
- **P09-D-F02 (DESIGN):** o gerador deriva `AA`/`MM` do fuso do `DateTimeImmutable` recebido. D02 fixa `Africa/Luanda`; a implementação converte antes de chamar o gerador e testa a fronteira de fim de mês (23:30 UTC → mês seguinte).
- **P09-D-F03 (INFO):** `member_number_sequences` não tem linha fora dos testes. A instalação é obrigatória antes da primeira aprovação; hoje o gerador falha fechado com `MEMBER_NUMBER_SEQUENCE_NOT_PROVISIONED`.
- **P09-D-F04 (INFO):** o contexto `MEMBERSHIP` de `PeopleAuthority` não filtra por estado. É coerente com D03 (o período aberto mantém a proveniência) e não se altera.

## Consequências

- Membership fica implementável até L7 com 1 DDL pequena e um catálogo instalado, reutilizando `TerritorialAuthority`, `PeopleAuthority`, `FilesConsumers`, o gerador e a guarda de transferências.
- Custos da V1:
  - sem rascunho persistido;
  - sem estados disciplinares;
  - sem segregação forçada entre proponente e aprovador;
  - sem transição retroactiva;
  - sem importação em massa de legados;
  - marcos só via membership.

## Decisões institucionais remanescentes (não bloqueiam a V1)

| ID | Pergunta | Owner | Efeito quando decidida |
|---|---|---|---|
| N1 | Idade mínima e consentimento do responsável para admitir menores | Secretaria/Children | Nova validação no serviço, sem schema |
| N2 | O Estatuto exige acta e um órgão específico para a admissão colectiva? | Secretaria | `source_document` passa a obrigatório no comando colectivo, sem schema |
| N3 | Segregação proponente ≠ aprovador (D-11) | Secretaria/segurança | Regra de serviço; se precisar de actor persistido, entra `membership_workflows` (Wave 7) |
| N4 | Suspensão e exclusão disciplinares | Direcção | Novos estados por adenda |
| N5 | D-02: critério e fonte da importação em massa de legados; D-03: extensão antes de 999999 e século de `AA` | Secretaria/arquitectura | Job de importação; alerta de capacidade |
| N6 | D-10: transferências e mudanças de estado retroactivas | Secretaria/arquitectura | Semântica temporal adicional |
| N7 | Outros `source_system` legados e outros marcos | Secretaria | Adenda de catálogo |

Não resta nenhum blocker institucional para implementar Membership V1 conforme este ADR.

## Baseline

- `p09-membership` = `main` = `55c0821`, working tree limpa.
- `origin/main` = `365a70f`: os 7 commits da P0.8 (`c280c49`…`55c0821`) estão só no `main` local. **O push de `main` deve ser feito, com autorização, antes do merge final da P0.9.** Não bloqueia a implementação.

## Referências

`mepa_crm_v1.1.1.md` §4, §7, §8, §24.5, §30–33, §41–42; ADR 0001, 0003, 0008, 0009, 0017, 0018, 0019; `docs/database/02_data_dictionary.md` (memberships…transfers, membership_workflows); `docs/database/09_open_database_decisions.md`; `docs/reviews/P0.4_global_completeness_audit.md` §4.2; `docs/reviews/P0.3.2_M1_transfer_concurrency_fix.md`; evidência `docs/reviews/evidence/P0.9-D/`.
