# ADR 0019: Documents / Files — política V1 da infraestrutura transversal

**Status:** Accepted

**Data:** 2026-09-30

**Fase:** P0.8-D (decision freeze). Baseline `365a70fc45b3e1f810299b52c902feb46b403461` (`main` = `origin/main`, com Academia, People/Families, Territorial e Physical Locations integrados), branch `p08-documents-files`.

## Contexto

O schema Wave 1 já instala `files`, `legal_document_types`, `legal_documents`, `document_versions`, `person_documents` e `person_files`. A Wave 3 acrescentou `event_documents`. Nenhuma destas tabelas tem serviço de escrita, API, permissions, storage ou upload. `legal_document_types` está vazia, e nenhum código de produção insere em `files` ou `legal_documents`.

Inventário relevante:

| Objecto | Estado actual |
|---|---|
| `files` | `public_id`, `owner_unit_id` (NOT NULL), `owner_department_id` (FK desde a Wave 2), `created_by`, `classification` (texto livre), `disk`, `storage_key`, `original_name`, `mime_type`, `size_bytes`, `checksum BINARY(32)`, `status` com CHECK `QUARANTINED/AVAILABLE/TOMBSTONE/PURGED`, `deleted_at`, `purged_at`, `key_version` (nullable), `lock_version`. UNIQUE `(disk, storage_key)`. |
| `legal_documents` | `public_id`, `document_type_id`, `owner_unit_id`, `reference`, `title`, `status` sem vocabulário. Sem classificação nem ponteiro de versão corrente. |
| `document_versions` | `public_id`, `document_id`, `version >= 1`, `file_id` NOT NULL, `issued_on`, `supersedes_id` (self-FK). UNIQUE `(document_id, version)`. |
| `person_documents` | Número cifrado, blind index, `key_version`, `file_id` opcional. |
| `person_files` | `(person_id, file_id, purpose)`, com `purpose` livre. |
| `event_documents` | `(event_id, document_id)` para `legal_documents`. |
| FKs para `files` | 9: `person_documents`, `person_files`, `document_versions`, `import_batches`, `credential_templates`, `credentials.render_file_id`, `resources`, `certificates`, `transcripts`. |
| FKs para `legal_documents` | 28: 21 `source_document_id` opcionais (People, Membership, Territorial, Physical, Governação, Children, Academy) e `document_id` em `document_versions`, `event_documents`, `governance_resolutions`, `decisions`, `person_consents`, `person_qualifications` e `integration_events`. |
| `AcademyResourceGuard` | Resolve `files`/`legal_documents` por id, exige `owner_unit_id` numa unidade do alvo, recusa ficheiros com `owner_department_id` e não interpreta `classification` (A2R-09 INFO). |
| Filesystem | Discos Laravel `local` (`storage/app`), `public` e `s3` por omissão; nenhum disco privado dedicado; `storage/app` ignorado pelo Git. Nenhum código usa `Storage`, upload ou stream. |
| Crypto | `PeopleCrypto`: AES-256-GCM sobre strings completas em memória, key ring JSON fora da BD/código/Git/public, `key_version`, fail-closed. Não serve para ficheiros grandes. |
| Audit | `audit_logs` append-only com `unit_id` obrigatório, `entity_id`, metadados JSON, `reason`, `source`, `correlation_id`, `session_id`, `ip_hash`. |
| Permissions | `permissions(code, action, data_type, maximum_classification)`. `maximum_classification` já contém três vocabulários: `INTERNAL` (Territorial), `CLASS_B`/`CLASS_C` (People) e `RESTRICTED`/`CONFIDENTIAL` (Physical). |
| Runtime | PHP 8.1 com `sodium` (secretstream), `fileinfo`, `gd` (JPEG/PNG/WebP) e `openssl`. O PWA já trata `/api/` como `NetworkOnly`. |

A especificação canónica exige storage privado por adapter, dono lógico, scope, classificação, checksum, tamanho, MIME, disco, path e `created_by` (§25, §40), e as classes Público institucional, Interno, Restrito, Confidencial e Altamente sensível (§31).

Este ADR fecha as decisões necessárias para implementar Documents/Files como infraestrutura transversal. Não implementa código, não cria migrations e não altera consumidores.

## D01: classificação V1 (fecha A2R-09 ao nível da política)

`files.classification` passa a ter um vocabulário fechado e ordenado. É código estável na aplicação, não ENUM nem tabela.

| Ordem | Código | Uso |
|---:|---|---|
| 1 | `INTERNAL` | Material institucional não pessoal: modelos, formulários, materiais de curso, documentos normativos internos. |
| 2 | `RESTRICTED` | Documentos operacionais da unidade: actas, resoluções, nomeações, contratos, títulos, correspondência. **Omissão do upload.** |
| 3 | `CONFIDENTIAL` | Ficheiros ligados a uma Pessoa adulta (fotografia, qualificação, emprego) ou com dados pessoais Classe B do ADR 0017. |
| 4 | `HIGHLY_SENSITIVE` | Documentos de identidade, ficheiros de menores, guardian/consent/custódia, apoio e qualquer conteúdo Classe C do ADR 0017. |

`PUBLIC` não existe na V1: nenhum ficheiro é servido anonimamente.

### Clearance

A clearance de um actor sobre um ficheiro é calculada por unidade proprietária:

- a permission da operação (D09), com scope que cubra `owner_unit_id`, dá clearance até `RESTRICTED`;
- `FILES_CONFIDENTIAL_ACCESS`, com scope que cubra a mesma unidade, eleva a clearance até `CONFIDENTIAL`;
- `FILES_HIGHLY_SENSITIVE_ACCESS`, com scope que cubra a mesma unidade, eleva a clearance até `HIGHLY_SENSITIVE`.

Uma permission de clearance sozinha não concede nenhuma operação.

### Metadata e download por classe

| Classe | Ver metadata | Download | Auditoria de leitura |
|---|---|---|---|
| `INTERNAL` | `FILES_VIEW` + scope | `FILES_DOWNLOAD` + scope | download |
| `RESTRICTED` | `FILES_VIEW` + scope | `FILES_DOWNLOAD` + scope | download |
| `CONFIDENTIAL` | `FILES_VIEW` + `FILES_CONFIDENTIAL_ACCESS` | `FILES_DOWNLOAD` + `FILES_CONFIDENTIAL_ACCESS` | detalhe de metadata e download |
| `HIGHLY_SENSITIVE` | `FILES_VIEW` + `FILES_HIGHLY_SENSITIVE_ACCESS` | `FILES_DOWNLOAD` + `FILES_HIGHLY_SENSITIVE_ACCESS` + gate do consumidor | detalhe de metadata e download, com motivo obrigatório no download |

Um ficheiro acima da clearance do actor não aparece em list/search nem em contagens, e o detalhe devolve o `404` de F-06.

### Pisos por consumidor (menores e Classe C)

A classificação nunca fica abaixo do piso do consumidor que referencia o ficheiro:

| Referência | Piso |
|---|---|
| `person_documents.file_id` | `HIGHLY_SENSITIVE` |
| Ficheiro de uma Pessoa menor, `child_profiles`, guardian, consent ou custódia | `HIGHLY_SENSITIVE` |
| `person_files` (outras finalidades) | `CONFIDENTIAL` |
| Credenciais renderizadas (`credentials.render_file_id`) | `CONFIDENTIAL` |
| Certificados e transcripts da Academia | `CONFIDENTIAL` |
| `document_versions`, `resources`, `credential_templates`, `import_batches` | sem piso adicional (`import_batches` segue R-IMPORTACAO e o seu próprio domínio) |

Anexar um ficheiro a um consumidor com piso superior eleva a classificação na mesma transacção, com auditoria. Para um ficheiro `HIGHLY_SENSITIVE` ligado a uma Pessoa, o download exige também a autoridade do domínio dono da relação: `PEOPLE_SENSITIVE_VIEW` sobre a Pessoa e, para menores, o gate Children/Academy existente. People nunca interpreta guardian/consent (ADR 0017).

### `permissions.maximum_classification`

As novas permissions Files/Documents usam este vocabulário. Os valores `RESTRICTED` e `CONFIDENTIAL` da P0.7 ficam aprovados (fecha a dívida P0.7 n.º 2). Para leitura, os valores existentes equivalem assim: `CLASS_B` ≈ `CONFIDENTIAL` e `CLASS_C` ≈ `HIGHLY_SENSITIVE`. A normalização das linhas People fica como dívida não bloqueante, porque nenhum código interpreta `maximum_classification` de outro domínio.

## D02: storage V1 (shared-hosting-first)

- Um disco Laravel dedicado, `files_private`, driver `local`, com raiz em `FILES_STORAGE_ROOT`. Nunca o disco `public`, nunca `url` configurado e nunca `storage:link`.
- Em produção, `FILES_STORAGE_ROOT` fica fora do document root e do directório da aplicação publicada. No arranque, uma raiz ausente, não gravável ou dentro de `public_path()` faz as operações de ficheiro falhar fechadas.
- `storage_key = v1/<aaaa>/<mm>/<32 hex aleatórios>.bin`. Não deriva do nome original nem do `public_id`. `disk` guarda o nome lógico do disco.
- `storage_key`, `disk`, a PK interna e o checksum nunca saem da API.
- Sem URL pública nem URL assinada na V1: todo o conteúdo passa pelo endpoint autorizado (D05).
- O driver `local` é suficiente para a V1. S3-compatible entra mais tarde como outro disco, com os mesmos `files.disk`/`storage_key`, sem mudar o domínio.
- Espaço: a especificação prevê pelo menos 50 GB e, de preferência, 100 GB. Um objecto por ficheiro, repartido por `aaaa/mm`, controla os inodes.

### Limites e quotas (config, com tectos fixos)

| Limite | Omissão | Tecto |
|---|---|---|
| Tamanho máximo por ficheiro | 10 MiB | 20 MiB |
| Imagem | 40 megapixels; lado máximo de 10 000 px | — |
| Quota por unidade proprietária | 2 GiB (`size_bytes` de `QUARANTINED`+`AVAILABLE`+`TOMBSTONE`) | configurável |
| Quota nacional | `FILES_TOTAL_QUOTA_BYTES` (obrigatória em produção) | — |
| Reserva livre de disco | 1 GiB | — |
| Rate limit | `files-upload` 20/min e `files-download` 60/min por actor | — |

A quota é verificada com lock na linha da unidade proprietária (`FOR UPDATE`), o que serializa uploads concorrentes da mesma unidade. Excedida, devolve `422 FILES_QUOTA_EXCEEDED`, sem linha nem objecto.

### Storage indisponível

- **Upload:** raiz inacessível, disco cheio abaixo da reserva, key ring indisponível ou falha de escrita → `503 FILES_STORAGE_UNAVAILABLE`. Não fica linha `files` nem objecto parcial: o objecto temporário é removido.
- **Download:** a autorização vem primeiro. Se o objecto de um ficheiro `AVAILABLE` faltar ou falhar a integridade, a resposta é `503 FILE_CONTENT_UNAVAILABLE`, com incidente auditado (D10). Nunca há fallback.

## D03: pipeline de upload

A inspecção corre de forma síncrona no pedido, sem worker residente:

1. **Autorização**, antes de gravar bytes (D05/D09): permission, scope sobre a unidade proprietária, clearance para a classificação pedida e relação do consumidor, quando o upload vem de um fluxo consumidor.
2. **Validação:** tamanho, nome, extensão, MIME real por `finfo` e assinatura (magic bytes). O MIME declarado pelo cliente é ignorado.
3. **Custódia em quarentena:** o conteúdo é cifrado em streaming (D06) para o `storage_key` final, e o SHA-256 do plaintext é calculado no mesmo passo. Numa transacção, verifica-se a quota, insere-se `files` com `status='QUARANTINED'` e audita-se `file.uploaded`.
4. **Inspecção de segurança:** estrutural por tipo e, quando configurado, antivírus.
5. **Resultado:**
   - aprovado: `QUARANTINED → AVAILABLE`, com auditoria `file.available`;
   - rejeitado: o objecto é destruído imediatamente e a linha passa a `QUARANTINED → PURGED`, com `purged_at` definido e auditoria `file.security_rejected` com código de motivo. A linha e o checksum ficam como tombstone (ADR 0011). O conteúdo nunca esteve disponível, e nenhuma FK o pode referenciar, porque os consumidores só aceitam `AVAILABLE`.
6. **Recuperação:** uma linha `QUARANTINED` com mais de 15 minutos (queda do processo) é tratada por um job Cron idempotente, que repete a inspecção ou rejeita. `QUARANTINED` nunca é descarregável nem anexável.

### Política de tipos V1

| Extensão final | MIME real | Tratamento |
|---|---|---|
| `.pdf` | `application/pdf` | Rejeita PDF cifrado ou com `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile`, `/RichMedia`, `/XFA` ou `/OpenAction` activo. |
| `.jpg`, `.jpeg` | `image/jpeg` | Descodificação com GD, limites de dimensão e **re-encode**, que remove EXIF/GPS/metadata e polyglots. |
| `.png` | `image/png` | Igual ao JPEG. |
| `.webp` | `image/webp` | Igual ao JPEG. |

Tudo o resto é rejeitado com `422 FILE_TYPE_NOT_ALLOWED`, em particular:

- executáveis e scripts (`MZ`, ELF, Mach-O, `#!`, `<?php`, `.exe/.dll/.bat/.cmd/.ps1/.sh/.js/.vbs/.jar/.msi/.apk`, entre outros);
- HTML, SVG e XML;
- **arquivos compactados** (`zip`, `rar`, `7z`, `gz`, `tar`), o que inclui OOXML/ODF: DOCX, XLSX e ODT ficam fora da V1;
- HEIC e TIFF.

O checksum guardado é o do conteúdo final. Nas imagens, é o do ficheiro depois do re-encode.

### Nome do ficheiro

- Normalização NFC e remoção de componentes de caminho, caracteres de controlo, `\ / : * ? " < > |` e nomes reservados do Windows. Espaços consecutivos passam a um só.
- O nome fica limitado a 150 caracteres mais a extensão.
- A extensão final tem de estar na allowlist e ser coerente com o MIME real.
- **Dupla extensão:** rejeitado quando qualquer segmento interior pertence à denylist de executáveis/scripts (`relatorio.php.jpg`, `acta.pdf.exe`).
- O nome sanitizado vai para `original_name` e é classificado com o ficheiro. Nunca entra em logs nem em auditoria.

### Antivírus

- Shared hosting normalmente não tem scanner. A V1 **não afirma** que os ficheiros foram analisados por antivírus.
- A inspecção de base é estrutural: allowlist, assinatura, re-encode de imagens e rejeição de conteúdo activo em PDF. Fica registada como `inspection=STRUCTURAL`.
- Um adapter opcional `clamd` (socket ou TCP) só é usado quando configurado. Nesse caso, a inspecção fica `inspection=STRUCTURAL+AV`. Se o scanner estiver configurado mas indisponível, a operação falha fechada: o ficheiro fica `QUARANTINED`, o pedido devolve `503` e o Cron volta a tentar.
- O nível de inspecção fica na auditoria de cada ficheiro. A UI mostra “verificação estrutural” e nunca “sem vírus”.

## D04: F-06 e identificadores

- Todas as operações externas usam `public_id` (ULID do ADR 0009) de `files`, `legal_documents` e `document_versions`. Um inteiro nunca é aceite como referência de ficheiro ou documento.
- A permission é verificada **antes** de resolver o alvo (lição P07-I-02). Inexistente, malformado, fora do scope, acima da clearance, com consumidor não autorizado, `QUARANTINED`, `PURGED` ou `TOMBSTONE` (para quem não tem `FILES_MANAGE`) produzem o mesmo `404 RESOURCE_NOT_FOUND`, byte-idêntico.
- O download resolve o `public_id`, autoriza sob lock partilhado e só então abre o stream.
- O duplicado técnico nunca é revelado entre unidades (D08).

## D05: autoridade de download e escrita

Download = permission + scope + classificação + relação de consumidor, todas cumulativas:

1. `FILES_DOWNLOAD` (ou `DOCUMENTS_VIEW` + `FILES_DOWNLOAD` para versões de documento), com role activa e grant vigente;
2. scope territorial que cubra `owner_unit_id`. O resolver é o `TerritorialAuthority` com `data_type='FILES'`, e não há um segundo motor de scope;
3. clearance ≥ classificação (D01);
4. relação válida do domínio consumidor, quando existir. Se uma ligação a um consumidor ainda não integrado exigir gate próprio (People, Children), a resposta é `404` por **fail closed**;
5. `status='AVAILABLE'`, `deleted_at` e `purged_at` nulos.

### Owner department

`owner_department_id` fica **sempre NULL** na V1. O resolver aprovado só avalia scopes `UNIT`. Uma linha com departamento, que hoje não existe, fica inacessível (`404`), como no `AcademyResourceGuard`. A autoridade por departamento exige um ADR próprio.

### Resposta de download

- `GET /api/v1/files/{public_id}/content` (e o equivalente por versão de documento). Não há `Range`.
- `Content-Type` vem do MIME armazenado, que está na allowlist.
- `Content-Disposition: attachment` com `filename*` RFC 5987 sanitizado.
- Cabeçalhos: `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`, `Content-Security-Policy: sandbox`, `Cross-Origin-Resource-Policy: same-origin`.
- O PWA nunca guarda conteúdo em cache (`/api/` já é `NetworkOnly`).

## D06: cifra (D-08 para ficheiros)

**Modelo C, combinação:** cifra do ficheiro inteiro na aplicação, em storage privado. A cifra em repouso do host, quando existir, é uma camada adicional e nunca a única.

- **Primitiva:** libsodium `crypto_secretstream_xchacha20poly1305`, em blocos de 64 KiB, com memória constante. Cada bloco é autenticado, e `TAG_FINAL` detecta truncagem. O AES-256-GCM de campos pequenos do `PeopleCrypto` **não** é reutilizado, porque cifra strings completas em memória.
- **Envelope:** cada ficheiro tem uma DEK aleatória de 32 bytes. A DEK é cifrada pela KEK da `key_version` activa com `crypto_aead_xchacha20poly1305_ietf` e AAD `mepa.files.content.v1|<files.public_id>|<key_version>`.
- **Contentor autodescritivo:** magic `MEPAF1`, `key_version`, nonce+DEK cifrada, header secretstream e blocos. Não é preciso nova coluna.
- **`files.key_version`:** obrigatório em todo o ficheiro novo (regra da aplicação). Todos os ficheiros são cifrados, qualquer que seja a classificação, por isso reclassificar nunca obriga a recifrar.
- **Key ring:** separado, em `FILES_KEYRING_PATH`, com o mesmo formato e as mesmas regras de localização do ADR 0017 D-08 (fora da BD, do código, do Git, do public e dos logs). As chaves são distintas das chaves People.
- **Rotação:** a nova `key_version` passa a cifrar os writes, e as anteriores ficam só para leitura. O re-wrap é um procedimento operacional em lote e auditado: escreve um novo objecto com a nova versão, verifica o checksum do plaintext, actualiza `storage_key`/`key_version` sob lock e remove o objecto antigo. O conteúdo não muda, e não se trata de uma nova versão documental. A V1 não precisa do job de re-wrap para operar.
- **Backup e restore:**
  - BD, storage e key ring são copiados separadamente; a cópia do key ring é cifrada e fica com os custodiantes do ADR 0017.
  - O restore corre num ambiente isolado, pela ordem: chaves, BD e storage.
  - Depois valida-se o mapa de `key_version` e testa-se a decifra e o checksum por amostragem, antes de autorizar o serviço.
- **Fail closed:** key ring ausente, mal colocado ou malformado, `key_version` desconhecida ou falha de autenticação produzem `503`, sem plaintext parcial servido depois do erro e sem fallback. O stream é interrompido e o incidente é auditado.

## D07: retenção (D-06), tombstone, purge e legal hold

A V1 preserva por omissão.

| Estado | Significado |
|---|---|
| `QUARANTINED` | Custódia em inspecção. Não é visível a consumidores nem descarregável. |
| `AVAILABLE` | Conteúdo utilizável. |
| `TOMBSTONE` | Retirado do uso normal, com `deleted_at` definido. O **conteúdo e o objecto são preservados**. |
| `PURGED` | Objecto destruído, com `purged_at` definido. A linha, o checksum e a auditoria ficam como prova. |

| Transição | V1 |
|---|---|
| `QUARANTINED → AVAILABLE` | Inspecção aprovada. |
| `QUARANTINED → PURGED` | **Só** por rejeição de segurança de conteúdo que nunca esteve disponível. |
| `AVAILABLE → TOMBSTONE` | `FILES_MANAGE`, clearance, motivo e auditoria. Recusada (`409 FILE_IN_USE`) se houver referência activa de um consumidor. Versões de documento não são tombstoned individualmente. |
| `TOMBSTONE → AVAILABLE` | Restauro com `FILES_MANAGE`, motivo e auditoria. |
| `AVAILABLE/TOMBSTONE → PURGED` | **Não existe operação produtiva na V1.** |
| Hard delete de linha | Proibido. |

- **Legal hold.** Na V1, todo o ficheiro está implicitamente em hold, porque nenhuma purga institucional é alcançável. Não se cria representação física especulativa. O ADR futuro que introduzir purga administrativa tem de criar, antes dela, uma representação explícita de hold (alvo, motivo, responsável, período), e essa representação prevalece sobre qualquer prazo. O tombstone não é afectado por hold, porque preserva o conteúdo.
- Os prazos por política (R-INSTITUCIONAL, R-PESSOA, R-MENOR…) ficam para a política posterior. Não bloqueiam a V1, que só preserva.

## D08: versionamento

`legal_documents` → `document_versions` → `files`.

- A versão é **imutável**: `file_id`, `version`, `issued_on` e `supersedes_id` não mudam depois de criados. O ficheiro físico de uma versão nunca é sobrescrito.
- **Versão corrente** = a de maior `version` do documento. É derivada pelo UNIQUE `(document_id, version)`, sem ponteiro nem coluna nova.
- **Substituição** = novo upload + nova linha de versão, numa transacção sob lock `FOR UPDATE` de `legal_documents` com `lock_version`:
  - `version = max + 1`;
  - `supersedes_id` = versão corrente anterior;
  - a versão anterior fica preservada e descarregável sob as mesmas regras.
- **Proveniência:** `files.created_by`, `files.created_at`, `issued_on`, `supersedes_id` e a correlação de auditoria (`document.version_created`, com o `public_id` da versão anterior).
- `legal_documents.status` V1: `ACTIVE` e `ARCHIVED`. Arquivar ou restaurar exige `DOCUMENTS_MANAGE`, motivo e auditoria. Um documento arquivado sai das listas comuns e só é consultável com `DOCUMENTS_MANAGE`.
- O ficheiro de uma versão herda o `owner_unit_id` do documento. A classificação de cada versão pode ser igual ou superior à da anterior. O **acesso à metadata do documento** exige clearance ≥ à maior classificação entre as suas versões.
- Uma versão é sempre um ficheiro novo: a V1 não reutiliza o mesmo `file_id` em duas versões ou documentos.

### Catálogo `legal_document_types` V1

Dados controlados e idempotentes, como na P0.7:

| code | name |
|---|---|
| `MINUTES` | Acta |
| `RESOLUTION` | Resolução / deliberação |
| `APPOINTMENT` | Nomeação / credencial de cargo |
| `CORRESPONDENCE` | Ofício / carta / declaração |
| `CONTRACT` | Contrato / arrendamento / cedência |
| `PROPERTY_TITLE` | Título / registo de propriedade |
| `CONSENT` | Consentimento / autorização |
| `OTHER` | Outro |

Os nomes podem ser ajustados institucionalmente sem mudar os códigos. `OTHER` exige um `title` descritivo.

## D09: deduplicação

- **Não há deduplicação lógica.** Dois uploads com o mesmo conteúdo geram duas linhas, dois objectos e duas DEKs.
- O checksum só serve para integridade e para um **aviso** dentro da mesma unidade proprietária. O aviso `DUPLICATE_CONTENT_IN_UNIT` aparece apenas quando o actor já pode ver um ficheiro existente com o mesmo checksum.
- Nunca se revela nem se funde um duplicado entre unidades ou acima da clearance (seria um oráculo). Um ficheiro de menor nunca partilha ACL (07_data_classification).
- **Reutilização explícita** = um consumidor autorizado anexa um ficheiro existente pelo `public_id` (como a Academia já faz). É uma decisão do fluxo consumidor, não da deduplicação.

## D10: ownership e autoridade entre domínios

- Todo o ficheiro e documento tem `owner_unit_id` NOT NULL e coberto pelo scope do actor na criação.
  - Nos uploads avulsos, o cliente indica a unidade por `public_id`, e o backend valida a cobertura.
  - Nos uploads feitos a partir de um consumidor, a unidade vem do alvo do consumidor e nunca do cliente.
- Não existe ficheiro sem dono nem “ficheiro nacional” implícito.
- **Mudança de owner:**
  - exige `FILES_MANAGE`, ou `DOCUMENTS_MANAGE` para documentos, sobre a unidade de origem **e** a de destino, mais motivo;
  - fica auditada nas duas unidades;
  - não é permitida enquanto um consumidor referenciar o ficheiro (`409 FILE_IN_USE`);
  - mudar o owner de um documento move, na mesma transacção, o documento e os ficheiros de todas as versões.
- Files permanece **transversal**: nenhum domínio é dono da infraestrutura. Cada consumidor mantém a sua FK e a sua regra de relação, e chama o serviço Files com a decisão de autorização já tomada no domínio.

| Consumidor | Integração |
|---|---|
| People (`person_files`, `person_documents`) | Piso `CONFIDENTIAL`/`HIGHLY_SENSITIVE`, autoridade People sobre a Pessoa e gate Children para menores. |
| Academy (`certificates`, `transcripts`, `resources`, `source_document_id` de instructors) | O `AcademyResourceGuard` passa a exigir também `AVAILABLE`, clearance e o piso `CONFIDENTIAL` para ficheiros de pessoa. As referências deixam de ser inteiros (P08-D-F01). |
| Events (`event_documents`, credenciais) | Autoridade do evento (`events.owner_unit_id`) + Documents. |
| Physical (`unit_location_links.source_document_id`) | Continua a rejeitar o campo até existir o fluxo explícito; a referência será por `public_id` de documento com autoridade sobre o vínculo **e** sobre o documento. |
| Restantes `source_document_id` | Continuam nullable. Cada domínio decide quando os aceita. |

**Nenhum consumidor tem de migrar para fechar a fundação P0.8.**

## D11: permissions V1

`data_type='FILES'`, `action=code`. São só instaladas: não se cria nem altera nenhuma role.

| Code | Concede | `maximum_classification` |
|---|---|---|
| `FILES_VIEW` | list/detail de metadata | `RESTRICTED` |
| `FILES_UPLOAD` | upload avulso e conteúdo de versões | `RESTRICTED` |
| `FILES_DOWNLOAD` | conteúdo | `RESTRICTED` |
| `FILES_MANAGE` | tombstone/restore, reclassificação, mudança de owner | `RESTRICTED` |
| `FILES_CONFIDENTIAL_ACCESS` | eleva a clearance a `CONFIDENTIAL` | `CONFIDENTIAL` |
| `FILES_HIGHLY_SENSITIVE_ACCESS` | eleva a clearance a `HIGHLY_SENSITIVE` | `HIGHLY_SENSITIVE` |
| `DOCUMENTS_VIEW` | documentos, versões e histórico | `RESTRICTED` |
| `DOCUMENTS_MANAGE` | criar documento (com a 1.ª versão), editar título/referência/tipo, arquivar/restaurar, mudar owner | `RESTRICTED` |
| `DOCUMENTS_VERSION_MANAGE` | nova versão | `RESTRICTED` |

Reclassificação:

- **Subir** exige `FILES_MANAGE` + clearance ≥ classe nova.
- **Descer** exige `FILES_MANAGE` + clearance ≥ classe actual + motivo, e nunca fica abaixo do piso de um consumidor.

Ambas são auditadas.

## D12: auditoria

`source='P08_FILES'`. O `unit_id` é o `owner_unit_id`; nas mudanças de owner, há uma linha por unidade com a mesma correlação.

| Evento | Quando |
|---|---|
| `file.uploaded` | Linha `QUARANTINED` criada. |
| `file.available` | Inspecção aprovada, com o nível de inspecção. |
| `file.security_rejected` | Rejeição, com código de motivo e nível de inspecção. |
| `file.downloaded` | Todo o download. |
| `file.metadata_read` | Detalhe de ficheiro `CONFIDENTIAL`/`HIGHLY_SENSITIVE`. |
| `file.tombstoned`, `file.restored` | Com motivo. |
| `file.classification_changed` | Classe antes/depois e motivo. |
| `file.ownership_changed` | Unidades antes/depois e motivo. |
| `file.integrity_failure`, `file.content_unavailable` | Incidente no download ou no Cron. |
| `document.created`, `document.version_created`, `document.updated`, `document.archived`, `document.restored` | Com o `public_id` das versões. |

Na metadata permitida entram `public_id`, classe, MIME, tamanho, `key_version`, estados, código de motivo, nível de inspecção e `public_id` de documento/versão/consumidor. **Nunca** entram conteúdo, `original_name`, título de documento Confidencial ou superior, checksum, `storage_key`, DEK/KEK, ciphertext ou caminhos.

## Schema delta

**O schema actual é suficiente. Não há DDL.**

| Pergunta | Resposta |
|---|---|
| Colunas ou tabelas em falta? | Nenhuma. A versão corrente é derivada, a DEK vai no contentor, a proveniência está em `created_by`/`created_at`/`supersedes_id` + auditoria, e a inspecção fica na auditoria. |
| Legal hold precisa de schema? | Não na V1 (hold implícito universal; D07). Será obrigatório no ADR de purga. |
| Classificação como catálogo? | Não. Vocabulário ordinal fixo no código, validado pelo serviço e pelo validador/probes. Um CHECK em `files.classification` não é adicionado, para manter a paridade com o catálogo. |
| Metadata de upload/segurança? | Estados de `files` + auditoria. Nenhuma coluna nova. |
| `key_version` nullable? | Mantém-se. A aplicação exige-o nos ficheiros novos. |

### Migrations necessárias (só dados controlados, idempotentes)

1. `FilesCatalog::install()`: 9 permissions (D11) + 8 `legal_document_types` (D08), só inserir o que falta, sem apagar e sem roles.
2. Passo correspondente em `migrate_pool_db.php` e manifest delta para a paridade (0 tabelas novas, 17 linhas controladas).

Sem ALTER em `files`, `legal_documents`, `document_versions`, `person_documents`, `person_files`, `event_documents` ou qualquer tabela consumidora.

## Compatibilidade com shared hosting e dependências operacionais

- **Sem requisitos de infraestrutura adicional:** sem worker residente, Redis, fila obrigatória ou serviço externo obrigatório.
- **Cron** (ADR 0004) para:
  - a reconciliação de `QUARANTINED`;
  - a verificação de integridade por amostragem;
  - o alerta de quota.
- **PHP:** `sodium` (secretstream), `fileinfo`, `gd` com JPEG/PNG/WebP e `openssl`. `upload_max_filesize` e `post_max_size` ≥ limite + margem; `memory_limit` ≥ 128M. Um preflight de deployment verifica tudo e falha fechado.
- **Directório privado `FILES_STORAGE_ROOT`,** fora do document root, com espaço (≥ 50 GB, preferível 100 GB) e inodes compatíveis.
- **`FILES_KEYRING_PATH`,** em secret store ou ficheiro legível só pelo processo PHP (ADR 0017 D-08). Sem essa separação, a implantação falha fechada.
- **Backups separados** de BD, storage e key ring, com o restore testado (D06).
- **Opcional:** `clamd`.

## Achados do inventário

- **P08-D-F01 (PRODUCT, pré-existente, não bloqueante para a P0.8-D):** `InstructorAssignRequest` aceita `source_document_id` como **inteiro** (PK interna), contra o ADR 0009 e o D04. O domínio distingue `REFERENCE_NOT_FOUND` de `OUT_OF_SCOPE`. Hoje não é explorável, porque nenhum writer cria `legal_documents`, mas tem de migrar para o `public_id` de documento antes de o writer de Documents chegar à produção. Pertence à integração Academy de D10.
- **P08-D-F02 (INFO):** `permissions.maximum_classification` tem valores heterogéneos (D01). A normalização é dívida não bloqueante.

## Consequências

- Files/Documents fica implementável sem DDL, com autoridade pelo motor territorial existente, cifra de ficheiros própria e em streaming, e um pipeline honesto de inspecção estrutural.
- O custo:
  - DOCX/XLSX/ODT, HEIC e arquivos compactados ficam fora da V1;
  - não há `Range` nem pré-visualização inline;
  - departamentos não são donos de ficheiros;
  - não há purga institucional.
- A paridade da P0.8 compara 0 tabelas novas e as 17 linhas controladas. As probes de mutação devem cobrir, no mínimo:
  - F-06 da clearance;
  - fail closed da cifra;
  - `QUARANTINED` não descarregável;
  - ausência de `AVAILABLE→PURGED`;
  - imutabilidade de versão;
  - storage fora de `public`;
  - ausência de fuga de `storage_key` e da PK;
  - dupla extensão.

## Decisões remanescentes (não bloqueantes para a P0.8)

- Prazos legais por política (D-06) e um ADR de purga administrativa com representação de legal hold.
- Autoridade por departamento (`owner_department_id`).
- Novos tipos (DOCX/ODT por conversão ou sandbox, HEIC com conversão no cliente).
- URL assinada curta, pré-visualização inline e `Range`.
- Integração de cada consumidor (People, Academy, Events, Physical), fora da fundação.
- Normalização de `maximum_classification` das permissions existentes.
- Qualificação do secret store, do espaço e do scanner no hosting real (D-08 de deployment, fail closed).

Não resta nenhum blocker institucional para implementar a fundação Documents/Files conforme este ADR.

## Referências

`mepa_crm_v1.1.1.md` §25, §31, §40; ADR 0004, 0009, 0011, 0014, 0017, 0018; `docs/database/model_catalog.json` (`files`, `legal_document_types`, `legal_documents`, `document_versions`, `person_documents`, `person_files`, `event_documents`); `docs/database/07_data_classification.md`; `docs/database/09_open_database_decisions.md` (D-06, D-08); `docs/reviews/P0.3.5_A2_1_remediation.md` (A2R-09); `apps/api/app/Domain/Academy/AcademyResourceGuard.php`; `apps/api/app/Domain/People/PeopleCrypto.php`.
