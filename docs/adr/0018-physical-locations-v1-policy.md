# ADR 0018: Physical Locations, Properties, Temples e vínculos Unit ↔ Location — política V1

**Status:** Accepted

**Data:** 2026-09-29

**Fase:** P0.7-D (decision freeze). Baseline `162a6958ab92fe260e18f9b729915b1f9e5eed92` (`main` com Academia, People/Families e Territorial integrados), branch `p07-physical-locations`.

## Contexto

O ADR 0010 separa unidade organizacional de imóvel. O schema Wave 1 já instala `addresses`, `physical_locations`, `properties`, `occupation_types` e `unit_location_links`. `temples` está especificada no dicionário e no `model_catalog.json`, mas ainda não existe fisicamente; o plano previa-a para a Wave 8.

Nenhuma destas tabelas tem serviço, API, UI, permissions ou testes. `physical_locations` e `properties` não têm unidade proprietária. O modelo não define vocabulário para `status`, `ownership_status` nem `geocode_accuracy`: o dicionário mostra apenas o exemplo `DRAFT`.

Este ADR fecha as decisões necessárias para levar ao nível L7 Physical Locations, Properties, Temples e os vínculos Unit ↔ Location. Não trata Documents/Files, que continua a ser a P0.8 transversal.

## D01: separação de identidades

- `organizational_unit` ≠ `physical_location`, e Congregação ≠ templo. Centro não é edifício.
- Uma unidade relaciona-se com locais apenas por `unit_location_links`, que são temporais. A mudança de local fecha um vínculo e abre outro, sem alterar a identidade nem o `public_id` da unidade ou dos seus membros.
- Não se adiciona `physical_locations.unit_id`, `organizational_units.location_id` nem outra FK directa entre as duas árvores.
- `temples` representa o uso religioso de um local físico. Um local pode ter vários templos, e vários templos não criam Congregações. `temples` não tem `parent_id`, `unit_id` nem relação hierárquica: não é uma árvore organizacional.
- `properties` identifica o imóvel sobre um local (`properties.location_id`, obrigatório). A situação documental não altera a hierarquia territorial.

## D02: catálogo `occupation_types` V1

`occupation_type` representa a forma como a unidade ocupa ou usa o local. Não classifica o tipo de edifício; tipo de edifício e instalações ficam para `facility_types`/`facilities`, fora da P0.7.

| code | name |
|---|---|
| OWNED | Próprio |
| RENTED | Arrendado |
| CEDED | Cedido |
| BORROWED | Emprestado |
| TEMPORARY | Provisório |
| OTHER | Outro |

- É compatível com a finalidade normativa do catálogo ("Próprio, arrendado, cedido, provisório e regimes aprovados"). BORROWED e OTHER são os regimes que este ADR aprova.
- As linhas são dados de catálogo, instalados de forma idempotente pela migration de catálogo da P0.7. `is_active=1`, e o código é estável (não é ENUM).
- `OTHER` exige `reason` preenchido no vínculo.
- `occupation_type_id` é obrigatório em todos os vínculos, e só é aceite um tipo com `is_active=1`.

## D03: authority e scope

- `physical_locations` e `properties` **não têm** `owner_unit_id`, e este ADR não o acrescenta.
- A autoridade sobre um local deriva **exclusivamente** dos `unit_location_links` com `status='ACTIVE'` e período vigente (`starts_at <= agora < ends_at`, ou `ends_at` nulo). Pelo menos um desses vínculos tem de estar numa unidade coberta pelo scope institucional do actor.
- O scope reutiliza a autoridade territorial já aprovada: permission + role activa + `user_role_scopes` + a cobertura da unidade e dos seus descendentes.
- Uma permission sozinha nunca concede acesso. Um vínculo `ENDED`, ou fora do período, não concede autoridade.
- A autoridade sobre uma Property é a autoridade sobre o seu `location_id`. A autoridade sobre um Temple é a autoridade sobre o seu `location_id`.
- Uma unidade pode ter vários locais, e um local pode ter vários vínculos, simultâneos (unidades diferentes) ou sucessivos no histórico.
- **Anti-escalada.** Para criar um vínculo a um local já existente, o actor tem de ter `UNIT_LOCATION_LINK_MANAGE` sobre a unidade de destino **e** autoridade prévia sobre o local, através de outro vínculo activo dentro do scope. Conhecer um `public_id` nunca basta para anexar um local alheio à própria unidade.
- **F-06.** Um local, imóvel, templo ou vínculo inexistente ou fora do scope devolve o mesmo `404 RESOURCE_NOT_FOUND`. Um identificador de unidade na URI não concede autoridade.
- **Unidade de auditoria.** `audit_logs.unit_id` regista a unidade do vínculo activo que autorizou a operação. Para operações de vínculo, regista a unidade do vínculo alterado.

## D04: locais sem vínculo

- Local ou imóvel sem vínculo institucional activo **não** aparece em list/search/detail operacional.
- A criação normal de um local é uma única transacção que cria `addresses` + `physical_locations` + o primeiro `unit_location_links` autorizado. Não existe criação de local solto na API V1.
- O último vínculo activo de um local só pode terminar de duas formas:
  1. transferência atómica: termina o vínculo e cria outro vínculo activo, com autoridade do actor sobre ambas as unidades;
  2. encerramento do local (`CLOSED`) na mesma transacção.
  Qualquer outro caso é recusado com `LAST_ACTIVE_LINK_REQUIRED`.
- Um local `CLOSED` sem vínculo activo fica preservado em arquivo e invisível para o fluxo operacional. Um eventual registo ou consulta administrativa sem vínculo exige um fluxo separado, controlado e auditado, que fica **fora da V1**. Nunca fica visível globalmente.

## D05: estados e lifecycle

O modelo não define vocabulário próprio. Reutilizam-se os vocabulários já normativos no projecto, validados na aplicação e **sem CHECK novo** nas tabelas existentes:

| Tabela | Estados | Origem | Acções explícitas |
|---|---|---|---|
| `physical_locations` | DRAFT, ACTIVE, CLOSED | `ck_organizational_units_status` (ADR 0006) | activate, close |
| `properties` | DRAFT, ACTIVE, CLOSED | idem | activate, close |
| `temples` | DRAFT, ACTIVE, CLOSED | idem | activate, close |
| `unit_location_links` | ACTIVE, ENDED | `unit_parent_periods` (P0.6) | link, end-link, transfer |

- Transições permitidas: DRAFT→ACTIVE, DRAFT→CLOSED, ACTIVE→CLOSED. CLOSED é terminal na V1. Não há reabertura nem hard delete.
- Fechar um local exige que não existam Properties ou Temples `ACTIVE` sobre ele, e força `public_visibility='PRIVATE'` na mesma transacção.
- Retenção R-INSTITUCIONAL em regime D-06 V1, tal como no ADR 0017: preservar, nunca purgar. Não há apagamento físico de registos com histórico.
- `geocode_accuracy` não tem vocabulário aprovado. Na V1 fica `NULL` e não é interpretado.

## D06: `properties.ownership_status` V1

O campo é NOT NULL e não tinha vocabulário. Congela-se o catálogo mínimo V1 abaixo, validado na aplicação e sem CHECK no schema:

| code | Significado |
|---|---|
| REGISTERED | Situação registada/titulada |
| IN_REGULARIZATION | Processo de legalização em curso |
| UNREGISTERED | Sem registo formal |
| UNKNOWN | Ainda não apurado; valor por omissão na criação |

- É um campo **documental**: não concede nem retira autoridade, não altera a publicação e não é exposto na projecção pública.
- A alteração é uma acção explícita, auditada e com `lock_version`.
- Pode ser estendido por addendum quando `property_documents` existir (P0.8+).

## D07: proprietário do imóvel

- `owner_person_id`: Physical **não** ganha acesso a dados pessoais só por referenciar uma Person.
  - A projecção do proprietário é delegada ao People: devolve apenas o `public_id` da Person, e o nome mínimo só quando o actor tem, independentemente, `PEOPLE_VIEW` com scope sobre essa Person segundo o ADR 0017.
  - Sem essa autoridade, a resposta indica apenas que há um proprietário registado, sem identificador.
  - Nunca se projecta o PK interno.
- `owner_name_external` é dado Restrito sobre um terceiro.
  - Só é visível para quem tem `PROPERTY_MANAGE` no scope; a leitura é auditada.
  - Nunca é pesquisável nem aparece em list, export ou projecção pública.
- `owner_person_id` e `owner_name_external` são mutuamente exclusivos por linha; ambos nulos significa proprietário não registado.

## D08: coordenadas e publicação

- `public_visibility` por omissão é `PRIVATE`, em todas as criações.
- `APPROVED_PUBLIC` só se obtém pela acção explícita `publish`, que exige cumulativamente:
  - permission `PHYSICAL_LOCATION_PUBLISH`;
  - actor no scope de um vínculo activo do local;
  - local `ACTIVE`;
  - latitude e longitude ambas preenchidas e dentro do CHECK existente;
  - `lock_version`;
  - recheck no commit;
  - auditoria com antes/depois e motivo.
- A acção explícita `unpublish` volta a `PRIVATE`, com os mesmos requisitos de permission, scope, lock e auditoria.
- Qualquer alteração de coordenadas num local `APPROVED_PUBLIC` volta a `PRIVATE` na mesma transacção. A nova posição exige nova publicação.
- A projecção pública contém apenas `public_id`, `name`, `latitude` e `longitude` de um local `ACTIVE` + `APPROVED_PUBLIC`. **Nunca** expõe `owner_person_id`, dados do proprietário externo, `source_document_id`, a linha da morada (cifrada ou em claro), `locality`, `key_version`, estado documental, vínculos nem PKs internos.
- A P0.7 define o contrato da projecção pública. Um endpoint anónimo (mapa público) fica fora da V1 e exige decisão própria de cache e rate limit.

## D09: cifra da morada institucional

- Reutiliza-se a infraestrutura aprovada na P0.5 (ADR 0017 D-08):
  - AES-256-GCM na aplicação, com fail-closed;
  - key ring fora da BD, do código, do Git e do directório público;
  - `key_version` por linha;
  - logs sem plaintext, chaves, ciphertext nem blind index.
- A AAD é **própria**: `mepa.physical.address.line1.v1|<physical_locations.public_id>`. Não depende de Person, e um ciphertext de morada pessoal nunca decifra como morada institucional nem o inverso. O `public_id` é gerado antes da cifra, na mesma transacção.
- Cada linha de `addresses` usada por um local é exclusiva desse local e nunca é partilhada com `person_addresses`.
- **Sem blind index** para `line1` na V1: não há pesquisa por linha de morada.
- A linha em claro só é devolvida no detalhe a quem tem `PHYSICAL_LOCATION_MANAGE` no scope, e é registada como leitura sensível. `locality` segue a mesma regra, porque é Confidencial no catálogo.
- A reutilização é da primitiva criptográfica e do key ring. Os erros são mapeados para o domínio Physical; não se importa a semântica de erro de People.

## D10: invariantes de `unit_location_links`

1. `ends_at IS NULL OR ends_at > starts_at` (CHECK existente); o intervalo é semiaberto `[starts_at, ends_at)`.
2. Só vínculos `ACTIVE` e vigentes concedem autoridade. `ENDED` é histórico, imutável e fica sempre com `ends_at` preenchido.
3. **Principal:** no máximo um vínculo `ACTIVE` com `is_primary=1` por unidade, em cada instante. Promover um novo vínculo principal despromove o anterior na mesma transacção. O primeiro vínculo activo de uma unidade é principal.
4. No máximo um vínculo `ACTIVE` por par (unit, location) em cada instante.
5. `occupation_type_id` é obrigatório e tem de estar activo; `OTHER` exige `reason`.
6. `property_id`, se existir, tem de referir uma Property cujo `location_id` seja igual ao `location_id` do vínculo.
7. `source_document_id` continua nullable e fica sempre `NULL` na P0.7; a API V1 não o aceita.
8. `end-link` e `transfer` fecham com `ends_at`, `status='ENDED'` e motivo obrigatório. Não se edita `starts_at` retroactivamente.
9. Todas as mudanças são auditadas: actor, sessão, unidade, alvo, antes/depois, motivo e timestamp.

## D11: concorrência

- `lock_version` optimista em `physical_locations`, `properties`, `temples` e `unit_location_links`. Um update condicional sem efeito devolve `STALE_WRITE`.
- Ordem de locks pessimistas, para evitar deadlock:
  1. `organizational_structure_lock` `NATIONAL_TREE` em modo partilhado, para serializar com moves territoriais;
  2. linhas de `organizational_units` afectadas, por id crescente;
  3. `physical_locations`;
  4. `properties`/`temples`;
  5. `unit_location_links`.
- Recheck no commit, com os locks mantidos: scope do actor, vínculo activo autorizante, invariantes de principal e unicidade (D10.3/D10.4), último vínculo (D04) e requisitos de publicação (D08).
- Prova exigida no gate: dois `link`/`set-primary` simultâneos na mesma unidade resultam em OK + `STALE_WRITE` (ou recusa determinística), com exactamente um principal activo. `end-link` concorrente com `transfer` não deixa o local sem vínculo.

## D12: Documents/Files

- A P0.7 **não** implementa upload, download, pré-visualização nem storage.
- `source_document_id` (vínculos) continua opcional e nulo. Não se cria storage paralelo nem `property_documents`/`facilities`.
- Documents/Files continua a ser a P0.8 transversal, sem subordinação ao património.
- Nenhuma decisão deste ADR interpreta `files.classification` (A2R-09 continua INFO).

## D13: permissions V1

As permissions seguem o padrão `DOMÍNIO_ACÇÃO` já usado (`TERRITORIAL_*`, `PEOPLE_*`). Só são instaladas; nenhuma role é criada ou alterada.

| Code | Concede (sempre com scope + vínculo activo) |
|---|---|
| PHYSICAL_LOCATION_VIEW | list/detail de locais sem dados sensíveis |
| PHYSICAL_LOCATION_MANAGE | criar local (+ primeiro vínculo), editar, activate/close, ler a morada em claro |
| PHYSICAL_LOCATION_PUBLISH | publish/unpublish de coordenadas |
| PROPERTY_VIEW | list/detail de imóveis sem o proprietário externo |
| PROPERTY_MANAGE | criar/editar, activate/close, ownership_status, ler o proprietário externo |
| TEMPLE_VIEW | list/detail de templos |
| TEMPLE_MANAGE | criar/editar, activate/close |
| UNIT_LOCATION_LINK_MANAGE | link, end-link, transfer, set-primary |

A autoridade é sempre permission + role activa + scope + vínculo activo. Para a projecção do proprietário, acresce a autoridade independente do People (D07).

## Delta estrutural aprovado para implementação

### A. `temples` (nova tabela, exactamente o desenho do catálogo)

```sql
CREATE TABLE `temples` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `location_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacity` INT UNSIGNED NULL DEFAULT NULL,
  `status` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_temples_public_id` (`public_id`),
  KEY `ix_temples_location_id` (`location_id`),
  CONSTRAINT `fk_temples_location_id` FOREIGN KEY (`location_id`) REFERENCES `physical_locations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
```

Não há colunas adicionais nem CHECK que não esteja no catálogo. `status` segue D05 na aplicação.

### B. Dados controlados (migration de catálogo idempotente, sem DDL)

- 6 linhas em `occupation_types` (D02);
- 8 permissions (D13).

### Fora do delta

- Sem ALTER em `addresses`, `physical_locations`, `properties`, `unit_location_links` ou `occupation_types`.
- Sem `owner_unit_id`, `unit_id`, blind index nem índices parciais.
- `property_documents` e `facilities` não entram.

## Consequências

- Physical fica autorizável sem dono próprio. O custo é que todo o acesso passa por vínculos activos, e um local sem vínculo fica arquivado e inacessível na V1.
- Os invariantes de principal e unicidade vivem na aplicação, sob locks. Um índice único parcial (coluna gerada) fica como opção futura, com schema delta próprio.
- A parity de schema da P0.7 compara `temples` com o catálogo nos dois sentidos, e as 14 linhas controladas (6 tipos + 8 permissions).

## Decisões remanescentes (não bloqueantes para a P0.7)

- Endpoint público anónimo de mapa (cache e rate limit).
- Fluxo administrativo de registo sem vínculo.
- Vocabulário de `geocode_accuracy`.
- Extensão de `ownership_status` com `property_documents` (P0.8+).
- D12 institucional das raízes nacionais, que não afecta este vertical.

## Referências

ADR 0006, 0009, 0010, 0011, 0017; `docs/database/model_catalog.json` (`temples`, `occupation_types`, `physical_locations`, `properties`, `unit_location_links`, `addresses`); `docs/database/02_data_dictionary.md`; `docs/reviews/P0.3.5_A2_1_remediation.md` (A2R-09).
