# ADR 0017: Scope, lifecycle e privacidade de People/Families

**Status:** Proposed

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
- `context_kind` com allowlist institucional;
- `status`;
- `starts_at` e `ends_at` no intervalo `[starts_at, ends_at)`;
- `reason` e `source_document_id` opcionais;
- `created_at` e `lock_version`.

A tabela não é ownership, não concede acesso sozinha e não substitui membership, enrollment, appointment, participação ou outro facto de domínio. O resolver exige simultaneamente permission, scope compatível e contexto activo. A lista inicial de `context_kind` deve ser aprovada com as operações People; não se presume que qualquer relação factual autoriza todas as acções.

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
| `MERGED` | DECISION_REQUIRED | Não se activa sem política formal de deduplicação, reversão, proveniência e conflitos. |

Transições aprovadas: `ACTIVE <-> INACTIVE` e `ACTIVE|INACTIVE -> DECEASED`. Corrigir `DECEASED` exige permission reforçada, motivo e auditoria. `MERGED` fica fora do catálogo activo.

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

`HEAD`, `OWNER`, `FATHER`, `MOTHER`, `PASTOR` e equivalentes ficam `DECISION_REQUIRED` e fora do catálogo mínimo. Parentesco pertence a `person_relationships`.

### Relações

| Código | Semântica | Inverso | Estado |
|---|---|---|---|
| `SPOUSE` | `SYMMETRIC` | `SPOUSE` | APPROVED |
| `SIBLING` | `SYMMETRIC` | `SIBLING` | APPROVED |
| `PARENT` | `INVERSE_PAIRED` | `CHILD` | APPROVED |
| `CHILD` | `INVERSE_PAIRED` | `PARENT` | APPROVED |
| `GUARDIAN` | `INVERSE_PAIRED` | `DEPENDENT` | APPROVED, apenas factual; não autoriza Children |
| `DEPENDENT` | `INVERSE_PAIRED` | `GUARDIAN` | APPROVED, apenas factual |

Uma relação `DIRECTED` não tem inverso automático. Nenhum código dirigido adicional é aprovado sem caso institucional concreto. O catálogo actual está vazio, portanto não existe conflito com taxonomia persistida.

`relationship_types` só guarda código, nome e activação. Para executar simetria e inversos sem hardcode disperso, a implementação futura deve acrescentar `semantics` e `inverse_relationship_type_id` ao catálogo ou adoptar um manifesto versionado único com validação equivalente. As colunas no catálogo são a opção recomendada e exigem migration futura.

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
| Encryption | Infraestrutura em repouso; sem cifra de campo obrigatória. | Cifra de campo para canal privado ou morada detalhada. Nome e nascimento ficam pesquisáveis sob controlo de acesso. | Cifra de campo obrigatória para payload sensível; `key_version` aponta para cofre externo. |
| Search | Só depois de scope/context. | Nome e nascimento pesquisáveis. Contacto exacto usa blind index. | Documento exacto usa blind index; sem pesquisa parcial em plaintext. |
| Audit | Writes, arquivo e export. | Writes, export e desmascaramento conforme operação. | Toda leitura/desmascaramento, write e export, com motivo quando aplicável; sem segredo no log. |
| Retention | Segue entidade e histórico. | Sem purga automática até D-06. | Sem purga automática; legal hold prevalece e backups seguem política aprovada. |
| Masking | IDs internos não saem da API. | Contacto e morada mascarados fora da permission específica. | Mascarado por defeito; documento mostra no máximo fragmento aprovado. |
| Export | Permission de export, scope/context e auditoria. | Permission por tipo de dado; ficheiro protegido e expiração operacional. | Permission reforçada, aprovação quando definida, motivo, auditoria e minimização. |

Não se cifra indiscriminadamente `full_name`, status, datas ou campos necessários à pesquisa autorizada. Quando cifra e igualdade/deduplicação forem simultaneamente necessárias, usa-se `encrypted value + derived blind/hash index`, como já acontece em contactos e documentos. O índice é sensível, não sai em payload ou log e não autoriza auto-merge.

### Exclusão, retenção e falecimento

- Hard delete de Person, Household, membros, contactos, endereços e relações não é permitido pela aplicação.
- Inactivação e arquivo preservam FKs, períodos, auditoria e identificadores públicos.
- Legal hold suspende purga. A autoridade que cria/liberta o hold, a prova e a propagação para backups dependem de D-06.
- `DECEASED` preserva identidade e histórico. Contactos deixam de aparecer por defeito, a autenticação associada deve ser revogada pelo fluxo próprio e alterações exigem permission e motivo. Relações históricas não são apagadas.
- Export não é atribuído por nome de role. Exige permission de export, scope/context, classificação compatível e auditoria. Classe C e menores exigem permission separada e gate do domínio responsável.

### Decisões humanas ainda necessárias

1. D-06: fundamento e prazo por classe, pedidos do titular, autoridade/libertação de legal hold, purga, backups e prazo após falecimento.
2. D-08: cofre/KMS real, custodiante nomeado, rotação, recuperação, restore, retirada e destruição de versões de chave.
3. D-10: início histórico desconhecido e correcções retroactivas em imports. Até decisão, o valor fica em staging; não se inventa data.
4. Export: permissions exactas, eventual aprovação a dois níveis para Classe C, formato, expiração e canal de entrega.

Por estes pontos, o ADR permanece `Proposed`. Writes de contacto/endereço/documento em produção, purga e export reforçado ficam fail closed.

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

A implementação futura precisa de migration para `person_unit_contexts` e, pela recomendação adoptada, para semântica/inverso de `relationship_types`. Não precisa nem deve adicionar `unit_id` a Person ou Household. Catálogos e permissions serão activados por dados controlados, não por valores improvisados.

D01 e D02 estão suficientemente definidas. D03 fecha classificação, minimização e arquitectura criptográfica, mas não custódia, prazos, holds, purga e export institucional. People/Families ainda não está autorizado para implementação completa ou produção.

## Referências

- `mepa_crm_v1.1.1.md`
- `docs/reviews/P0.5_people_families_vertical.md`
- `docs/contracts/people_families_contracts.json`
- `docs/reviews/evidence/P0.5/verification.json`
- `docs/database/06_history_and_temporal_data.md`
- `docs/database/07_data_classification.md`
- `docs/database/09_open_database_decisions.md`
- ADR 0001, 0008, 0011, 0015 e 0016
