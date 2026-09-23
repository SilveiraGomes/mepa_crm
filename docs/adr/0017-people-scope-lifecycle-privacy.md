# ADR 0017: Scope, lifecycle e privacidade de People/Families

**Status:** Accepted

**Data:** 2026-09-23

## Contexto

`people` representa a identidade global no CRM. Membership, Academia, departamentos, eventos, Children e os restantes verticais relacionam-se com essa identidade. Nem `people` nem `households` têm uma unidade proprietária, enquanto a autorização exige permission e scope e `audit_logs.unit_id` é obrigatório.

O schema já deriva unidade em vários contextos:

| Contexto persistido | Caminho para a unidade |
|---|---|
| Membership | `people -> memberships -> membership_periods.congregation_id` |
| Academia | `people -> enrollments -> classes -> academic_units.unit_id` |
| Evento | `people -> event_registrations -> events.owner_unit_id` |
| Cargo ministerial | `people -> ministerial_assignments -> organizational_posts.unit_id` |
| Função | `people -> function_assignments.unit_id` |
| Departamento | `people -> department_memberships -> department_instances.unit_id`; appointments passam por `department_posts -> department_instances.unit_id` |
| Children | `people -> child_profiles.owner_unit_id` |
| Governação | `people -> governance_body_memberships -> governance_bodies.unit_id` |

O schema não cobre todos os casos. `person_employment`, `discipleship_enrollments`, uma Pessoa criada antes do primeiro registo de domínio e um Household sem membro contextualizado não fornecem unidade. Reunir apenas os caminhos existentes deixaria lacunas e faria a autorização variar conforme o vertical que criou primeiro a identidade.

## D01: identidade global e proveniência contextual

Ficam aceites estas decisões:

1. Person é uma identidade global e não tem unidade proprietária única.
2. Household não recebe ownership artificial.
3. `people.unit_id` e `households.unit_id` ficam rejeitados.
4. A autorização nasce de permission, scope institucional e um contexto persistido que ligue a Pessoa à unidade. Um identificador enviado pelo cliente nunca constitui autoridade.
5. Contextos de domínio que já chegam a uma unidade continuam como fonte de verdade e não são copiados para uma tabela genérica.
6. Uma Pessoa sem contexto não entra num directório nacional. A criação ocorre na mesma transacção que cria o primeiro contexto autorizador.
7. Household é alcançado através dos membros activos. A projecção só revela Pessoas que o actor pode consultar. Alterar membros ou relações exige autoridade sobre cada Pessoa afectada.

### Alteração estrutural mínima proposta

O schema actual não é suficiente para contextos sem caminho territorial. A menor alteração é uma tabela temporal `person_unit_contexts`, usada apenas quando nenhum registo de domínio já oferece proveniência:

- `id` BIGINT interno;
- `person_id` FK para `people`;
- `unit_id` FK para `organizational_units`;
- `context_kind VARCHAR(64)` limitado a `ONBOARDING`, `EMPLOYMENT`, `DISCIPLESHIP` e `LEGACY_IMPORT`;
- `status VARCHAR(64)` limitado a `ACTIVE` e `INACTIVE`;
- `starts_at` e `ends_at` no intervalo `[starts_at, ends_at)`;
- `reason` e `source_document_id` opcionais;
- `created_at` e `lock_version`.

A tabela não é ownership, não concede acesso sozinha e não substitui membership, enrollment, event participation, appointment, Children ou outro facto de domínio que já forneça proveniência. O resolver exige simultaneamente permission, scope compatível e contexto activo. O frontend não escolhe `context_kind` nem `unit_id`: o backend cria ou valida o contexto no workflow autorizado.

Não se propõe Household↔Unidade. Um Household novo deve incluir pelo menos um `household_member` cujo contexto autorize a operação. Um agregado sem membro activo fica fora das listagens operacionais até regularização.

### Unidade de auditoria

`audit_logs.unit_id` é a unidade do contexto institucional que autorizou a operação, não uma propriedade da Pessoa ou do Household. O servidor resolve a cadeia completa e guarda na metadata o tipo e identificador interno do contexto usado.

Quando mais de um contexto é elegível, a operação indica o contexto institucional de trabalho e o backend confirma que pertence à concessão activa do actor. Em operações entre unidades, o actor precisa da permission própria e de scope que cubra todos os alvos. O audit usa a unidade do contexto operacional validado e regista as outras unidades envolvidas na metadata. O cliente nunca escolhe livremente o `unit_id` de auditoria.

## D02: lifecycle e catálogos

Person não usa estados de participação como `MEMBER`, `STUDENT`, `EMPLOYEE`, `PASTOR` ou `EVANGELIST`.

### Person

| Código | Estado | Regra |
|---|---|---|
| `ACTIVE` | APPROVED | Identidade operacional disponível. |
| `INACTIVE` | APPROVED | Identidade preservada, fora do uso operacional normal. |
| `DECEASED` | APPROVED | Identidade preservada após falecimento; não equivale a arquivo ou eliminação. |
| `MERGED` | OUT_OF_SCOPE | Fora de P0.5; não pertence ao catálogo V1. |

Transições aprovadas: `ACTIVE <-> INACTIVE` e `ACTIVE|INACTIVE -> DECEASED`. Corrigir `DECEASED` exige permission reforçada, motivo e auditoria. `MERGED` fica fora de P0.5 e não existe merge automático.

### Household

| Código | Estado | Regra |
|---|---|---|
| `ACTIVE` | APPROVED | Agregado em uso. |
| `INACTIVE` | APPROVED | Sem actividade corrente, com histórico preservado. |
| `ARCHIVED` | APPROVED | Fora das operações comuns, consultável sob permission e scope. |

Não há hard delete. `ACTIVE <-> INACTIVE` é permitido. Arquivo e restauro exigem motivo e auditoria.

### Papéis factuais no Household

| Código | Estado | Significado |
|---|---|---|
| `REFERENCE_PERSON` | APPROVED | Referência operacional; não é proprietária nem recebe autoridade legal. |
| `MEMBER` | APPROVED | Integra o agregado sem outra qualificação. |
| `DEPENDENT` | APPROVED | Dependência factual declarada; não substitui guardian/consent. |

`HEAD`, `OWNER`, `FATHER`, `MOTHER`, `PASTOR` e equivalentes ficam fora do catálogo V1. Parentesco pertence a `person_relationships`.

### Relações

| Código | Semântica | Inverso | Estado |
|---|---|---|---|
| `SPOUSE` | `SYMMETRIC` | `SPOUSE` | APPROVED |
| `SIBLING` | `SYMMETRIC` | `SIBLING` | APPROVED |
| `PARENT` | `INVERSE_PAIRED` | `CHILD` | APPROVED |
| `CHILD` | `INVERSE_PAIRED` | `PARENT` | APPROVED |
| `GUARDIAN` | `INVERSE_PAIRED` | `DEPENDENT` | APPROVED, apenas factual; não autoriza Children |
| `DEPENDENT` | `INVERSE_PAIRED` | `GUARDIAN` | APPROVED, apenas factual |

V1 admite apenas `SYMMETRIC` e `INVERSE_PAIRED`. O catálogo actual está vazio, portanto não existe conflito com taxonomia persistida. Todas estas relações são factuais; em particular, `GUARDIAN` não concede autoridade no domínio Children.

`relationship_types` só guarda código, nome e activação. A implementação deve acrescentar `semantics` e `inverse_relationship_type_id` ao catálogo para executar simetria e inversos sem hardcode disperso.

## D03: privacidade, criptografia e retenção

### Classificação

| Classe | Campos existentes |
|---|---|
| A. Operacional normal | IDs internos, `public_id`, FKs de catálogo, status, períodos, `created_at`, `lock_version`, flags e códigos de catálogo. Ligados a uma Pessoa, continuam sujeitos a autorização. |
| B. Pessoal sensível | `full_name`, nascimento e precisão, sexo, estado civil, contactos, endereço/localidade, composição do Household, parentesco, profissão/emprego, motivos e documentos de origem. |
| C. Altamente sensível | Números e ficheiros de identidade, ciphertext e blind indexes, `key_version`, dados de menores, guardian/consent/custódia, contactos de emergência e notas de apoio. |

### Controlo por classe

| Regra | Classe A | Classe B | Classe C |
|---|---|---|---|
| Storage | Relacional normal, RESTRICT e histórico. | Relacional; cifra selectiva para contacto e linha de endereço já prevista. | Valor cifrado, chaves fora da BD e ficheiros em storage privado. |
| Encryption | Infraestrutura em repouso; sem cifra de campo obrigatória. | Cifra de campo para canal privado ou morada detalhada. Nome e nascimento ficam pesquisáveis sob controlo de acesso. | Cifra de campo obrigatória para payload sensível; `key_version` aponta para configuração segura fora da BD, código e Git. |
| Search | Só depois de scope/context. | Nome e nascimento pesquisáveis. Contacto exacto usa blind index. | Documento exacto usa blind index; sem pesquisa parcial em plaintext. |
| Audit | Writes, arquivo e export. | Writes, export e desmascaramento conforme operação. | Toda leitura/desmascaramento, write e export, com motivo quando aplicável; sem segredo no log. |
| Retention | Segue entidade e histórico. | Sem purga automática na V1. | Sem purga automática na V1; legal hold prevalece e backups seguem o procedimento deste ADR. |
| Masking | IDs internos não saem da API. | Contacto e morada mascarados fora da permission específica. | Mascarado por defeito; documento mostra no máximo fragmento aprovado. |
| Export | `PEOPLE_EXPORT`, scope/context e auditoria. | `PEOPLE_EXPORT`, scope/context, ficheiro protegido e minimização. | `PEOPLE_EXPORT_CLASS_C` além de `PEOPLE_EXPORT`, scope/context, motivo, auditoria e minimização. |

Não se cifra indiscriminadamente `full_name`, status, datas ou campos necessários à pesquisa autorizada. Quando cifra e igualdade/deduplicação forem simultaneamente necessárias, usa-se cifra autenticada e blind index HMAC, como já acontece em contactos e documentos. A chave do blind index é distinta da chave de cifra. O índice é sensível, não sai em payload ou log e não autoriza auto-merge. Uma versão de chave desconhecida, uma chave ausente ou uma falha criptográfica interrompe a operação; nunca existe fallback para plaintext.

### Exclusão, retenção e falecimento

- Hard delete de Person, Household, membros, contactos, endereços e relações não é permitido pela aplicação.
- Inactivação e arquivo preservam FKs, períodos, auditoria e identificadores públicos.
- Legal hold suspende qualquer purga. V1 não implementa purge automática. Uma futura operação administrativa de purge exigirá permission própria, política formal, validação de hold e auditoria.
- `DECEASED` preserva identidade e histórico. Contactos deixam de aparecer por defeito, a autenticação associada deve ser revogada pelo fluxo próprio e alterações exigem permission e motivo. Relações históricas não são apagadas.
- Export não é atribuído por nome de role. Exige permission de export, scope/context, classificação compatível e auditoria. Classe C e menores exigem permission separada e gate do domínio responsável.

### D-06: retenção V1

V1 preserva o histórico de `ACTIVE`, `INACTIVE`, `DECEASED`, `ARCHIVED` e das relações temporais. A aplicação proíbe hard delete e não executa purge automática. Prazos legais específicos, pedidos do titular e uma eventual política administrativa de purge ficam para política posterior e não bloqueiam a implementação V1, porque a V1 só preserva e nunca purga. Legal hold prevalece sobre qualquer política futura.

### D-08: chaves e operação criptográfica

A cifra ocorre na aplicação e falha fechada. O material de chave fica fora da BD, do código, do Git, do directório público e dos logs. Shared hosting V1 não exige KMS externo, mas exige um secret store, ficheiro montado ou configuração de ambiente que o processo da aplicação possa ler e que utilizadores web não possam descarregar. Se o hosting não garantir essa separação, a implantação falha fechada.

Procedimento operacional aprovado:

1. Backup: conservar cópia cifrada do key ring fora do servidor, com acesso restrito aos custodiantes designados por Operações/Segurança. A protecção da cópia usa segredo distinto, inventaria versões e inclui verificação de integridade.
2. Rotação: criar novas chaves de cifra e blind index, activar nova `key_version` para writes, manter as anteriores apenas para leitura, recifrar e recalcular blind indexes em lotes auditados e retirar a versão antiga só depois de validar dados, backups e holds.
3. Restore: recuperar chaves antes de abrir a aplicação, restaurar BD e storage num ambiente isolado, validar o mapa de `key_version`, testar decriptação e integridade com amostras controladas e só depois autorizar serviço.
4. Revogação e destruição: uma chave comprometida deixa de receber writes, dispara rotação e incidente auditado. Uma versão só pode ser destruída quando nenhuma linha, backup retido ou legal hold depender dela, com aprovação e evidência registadas.

Logs registam versão, resultado e correlação operacional, nunca plaintext sensível, chaves, ciphertext reutilizável ou blind indexes.

### D-10: precisão de datas

As precisões aprovadas são `EXACT`, `MONTH`, `YEAR` e `UNKNOWN`. Dados desconhecidos não são completados com dia, mês ou instante artificiais. Correcção retroactiva exige actor, motivo, auditoria e preservação da versão anterior quando o facto é histórico. O nascimento precisa do pequeno delta descrito abaixo porque o schema actual usa `YEAR_ONLY` e não consegue representar `MONTH` sem inventar um dia.

Os intervalos operacionais `[starts_at,ends_at)` continuam a exigir instantes `EXACT`, porque deles dependem sobreposição e autorização. Uma alegação histórica com início `MONTH`, `YEAR` ou `UNKNOWN` fica preservada na origem/import staging e não é activada como intervalo até existir fronteira exacta documentada. A aplicação nunca converte essa precisão em primeiro dia do mês, primeiro dia do ano ou data de importação.

### Permissions V1

O catálogo aprovado é `PEOPLE_VIEW`, `PEOPLE_CREATE`, `PEOPLE_EDIT`, `PEOPLE_SENSITIVE_VIEW`, `PEOPLE_CONTACT_MANAGE`, `PEOPLE_ADDRESS_MANAGE`, `HOUSEHOLD_VIEW`, `HOUSEHOLD_MANAGE`, `RELATIONSHIP_MANAGE`, `PEOPLE_EXPORT` e `PEOPLE_EXPORT_CLASS_C`. Nenhuma permission concede scope. Toda autoridade exige permission, scope institucional activo e contexto persistido compatível.

## Delta estrutural aprovado para implementação

Nenhuma migration é criada por este ADR. A migration futura deve limitar-se a:

### A. `person_unit_contexts`

| Coluna | Definição |
|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `person_id` | `BIGINT UNSIGNED NOT NULL`, FK `people.id`, `ON DELETE/UPDATE RESTRICT` |
| `unit_id` | `BIGINT UNSIGNED NOT NULL`, FK `organizational_units.id`, `ON DELETE/UPDATE RESTRICT` |
| `context_kind` | `VARCHAR(64) NOT NULL`; `ONBOARDING`, `EMPLOYMENT`, `DISCIPLESHIP`, `LEGACY_IMPORT` |
| `status` | `VARCHAR(64) NOT NULL`; `ACTIVE`, `INACTIVE` |
| `starts_at` | `DATETIME(6) NOT NULL` |
| `ends_at` | `DATETIME(6) NULL`; fim exclusivo |
| `reason` | `TEXT NULL` |
| `source_document_id` | `BIGINT UNSIGNED NULL`, FK `legal_documents.id`, `ON DELETE/UPDATE RESTRICT` |
| `created_at` | `DATETIME(6) NOT NULL` |
| `lock_version` | `INT UNSIGNED NOT NULL DEFAULT 0` |

CHECK: `ends_at IS NULL OR ends_at > starts_at`. Índices: `(person_id,status,starts_at)`, `(unit_id,context_kind,status,starts_at)` e `(source_document_id)`. Sobreposições e alterações são validadas pelo serviço sob lock da Pessoa. A tabela não recebe contextos de Membership, Academy, Events, Departments/Appointments ou Children.

### B e C. `relationship_types`

- `semantics VARCHAR(32) NOT NULL`, limitado a `SYMMETRIC` e `INVERSE_PAIRED`;
- `inverse_relationship_type_id BIGINT UNSIGNED NULL`, self-FK para `relationship_types.id`, `ON DELETE/UPDATE RESTRICT`, com índice próprio.

O campo do inverso permanece nullable durante carga controlada do catálogo. Um tipo só pode ficar activo depois de o serviço validar: `SYMMETRIC` aponta para si; `INVERSE_PAIRED` aponta para outro tipo que aponta de volta. O par é criado ou actualizado numa transacção.

### D. Precisão de nascimento

- adicionar `people.birth_month TINYINT UNSIGNED NULL`;
- substituir `YEAR_ONLY` por `YEAR` em `birth_precision`;
- substituir o CHECK actual por:

```text
(birth_precision = 'EXACT' AND birth_date IS NOT NULL AND birth_year IS NULL AND birth_month IS NULL)
OR (birth_precision = 'MONTH' AND birth_date IS NULL AND birth_year IS NOT NULL AND birth_month BETWEEN 1 AND 12)
OR (birth_precision = 'YEAR' AND birth_date IS NULL AND birth_year IS NOT NULL AND birth_month IS NULL)
OR (birth_precision = 'UNKNOWN' AND birth_date IS NULL AND birth_year IS NULL AND birth_month IS NULL)
```

Este é o único delta adicional encontrado. `person_documents` já tem `number_ciphertext`, `number_blind_index` e `key_version`; `person_contacts` já tem `value_ciphertext`, `value_blind_index` e `key_version`; `addresses` já tem `line1_ciphertext` e `key_version`. V1 não pesquisa linha de morada por igualdade, portanto não adiciona blind index a `addresses`. Não são necessárias outras colunas de cifra ou pesquisa.

## Matriz conceptual de scope

`P` é permission própria, `S` scope activo, `C` contexto persistido activo e `X` permission explícita entre unidades.

| Actor context | Person context | List | Search | View | Edit | Contact | Address | Household | Relationship |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `P + S` na unidade | `C` directo na unidade | Sim | Sim | Sim | Com P edit | Com P sensível | Com P sensível | Com P e membro contextual | Com P e ambos os alvos |
| `P + S(include_descendants)` | `C` numa descendente | Sim | Sim | Sim | Conforme P | Conforme P | Conforme P | Conforme P | Conforme P e cobertura de ambos |
| `P + S` sem `C` | Nenhum contexto | Não | Não | Não | Não | Não | Não | Não | Não |
| `P + S` Academy e assignment | Enrollment na turma | Projecção Academy | Fluxo Academy | Projecção contextual | Só operação Academy | Não por defeito | Não por defeito | Não | Não |
| `P + S` Children | Child profile e gate Children | Projecção mínima | Restrita | Projecção mínima | Só domínio próprio | Não por defeito | Não por defeito | Mínima | Guardian/consent fora de People |
| `P + S + X` | Unidades distintas cobertas | Conforme P | Conforme P | Conforme P | Todos os alvos | Conforme classe | Conforme classe | Membros autorizados | Só com autoridade nos dois lados |
| `P + S` | Household misto | Household elegível | Sem revelar terceiros | Comum + membros autorizados | Só campos comuns | Só Pessoa autorizada | Com P sensível | Sem ownership | Não sobre alvo fora do scope |

Todas as consultas aplicam permission + scope + contexto no backend antes da paginação e projecção. Target inexistente e fora de scope mantêm a mesma resposta externa quando F-06 exigir concealment.

## Menores

A projecção People mínima contém apenas `public_id`, nome de apresentação, `birth_precision`, estado e, quando necessário, faixa etária derivada pelo domínio responsável. Nunca inclui por defeito data exacta de nascimento, contactos, morada, documentos, Household completo, parentescos, guardian authorizations, consentimentos, custódia/visitas, contactos de emergência, notas de apoio ou member number.

People não cria nem interpreta guardian/consent. Children ou Academy valida o gate existente e devolve apenas a projecção necessária.

## Wave 1 drift

A validação read-only no MySQL 8.4.7 encontrou 31 tabelas, 267 colunas, 51 FKs, 25 CHECKs e `strict_errors: []`. O manifesto guarda `catalog_sha256=237478eba6accf72c150f6268ab22c448f9937bf11b394c9e273b4c71157358e`; o catálogo actual tem `9da2b2f72bb74fd1b655961775a5647efce65368c4246c2ec9879cc53c757158`.

Classificação: `STALE_MANIFEST_HASH`. Não há `REAL_SCHEMA_DRIFT`. O hash antigo não é bloqueio de produto e não foi alterado nesta fase.

## Consequências

A implementação futura precisa de migration para `person_unit_contexts`, semântica/inverso de `relationship_types` e a representação `MONTH` de nascimento. Não precisa nem deve adicionar `unit_id` a Person ou Household. Catálogos e permissions serão activados por dados controlados, não por valores improvisados.

D01, D02 e D03 estão fechadas para V1. Os prazos legais específicos e uma eventual purge administrativa continuam como política posterior, sem bloquear a implementação porque a V1 proíbe hard delete e purge automática. A qualificação do secret store continua a ser requisito de deployment fail-closed, não uma decisão de produto pendente. Não resta blocker institucional para implementar People/Families conforme este ADR.

## Referências

- `mepa_crm_v1.1.1.md`
- `docs/reviews/P0.5_people_families_vertical.md`
- `docs/contracts/people_families_contracts.json`
- `docs/reviews/evidence/P0.5/verification.json`
- `docs/database/06_history_and_temporal_data.md`
- `docs/database/07_data_classification.md`
- `docs/database/09_open_database_decisions.md`
- ADR 0001, 0008, 0011, 0015 e 0016
