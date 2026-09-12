# Dicionário mestre de dados

Estado: proposta P0.2 para auditoria; não constitui schema aprovado. O [catálogo estruturado](model_catalog.json) contém os mesmos campos para revisão mecânica. Tamanho/precisão está no tipo SQL. Índice composto marcado numa coluna não significa índice isolado. Os índices de suporte FK podem ser cobertos pelo prefixo esquerdo de outro índice.

Cada tabela herda somente `id`, `created_at` e `lock_version`, aqui repetidos integralmente. Não há `updated_at` implícito: alterações críticas são versionadas/auditadas. Tabelas temporais repetem início, fim, motivo e documento. Os estados em VARCHAR são códigos de catálogos fechados por domínio; a implementação deverá usar lookup/FK quando configurável, nunca aceitar texto livre. Não usar ENUM indiscriminadamente.

Retenção, volumes e exemplos são propostas de dimensionamento; exemplos sintéticos. Políticas R-* em [classificação](07_data_classification.md). Nenhuma duração legal é assumida. Nullability é estrutural; regras XOR, estados e compatibilidade semântica estão em [constraints](04_database_constraints.md). Uma FK simples só prova existência.

## person_statuses

Estados do cadastro, separados da condição de membro.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Estados do cadastro, separados da condição de membro; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_person_statuses_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## sex_types

Categorias institucionais para estatística; catálogo por validar.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Categorias institucionais para estatística; catálogo por validar; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_sex_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## civil_status_types

Estados civis aprovados.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Estados civis aprovados; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_civil_status_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## education_types

Habilitações literárias/teológicas.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Habilitações literárias/teológicas; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_education_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## employment_types

Situações laborais.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Situações laborais; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_employment_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## people

Identidade raiz de membro, aluno externo IBT, pai/mãe não membro, convidado, visitante ou contacto.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Pessoa referenciada por API e integracoes autorizadas.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_people_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Pessoa referenciada por API e integracoes autorizadas | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| full_name | VARCHAR(191) | não | nenhum | não | — | — | ix_people_full_name_id | Nome registado | Ana Exemplo | Confidencial |
| birth_date | DATE | sim | NULL | não | — | — | ix_people_birth_date_id | Nascimento conhecido, sem data inventada | 2026-09-12 | Confidencial |
| birth_precision | VARCHAR(64) | não | nenhum | não | — | — | — | EXACT, YEAR_ONLY ou UNKNOWN | EXEMPLO | Confidencial |
| birth_year | SMALLINT UNSIGNED | sim | NULL | não | — | — | — | Ano quando apenas o ano é conhecido | 1 | Confidencial |
| sex_type_id | BIGINT UNSIGNED | sim | NULL | não | sex_types.id | — | ix_people_sex_type_id | sex type id | 123 | Confidencial |
| civil_status_type_id | BIGINT UNSIGNED | sim | NULL | não | civil_status_types.id | — | ix_people_civil_status_type_id | civil status type id | 123 | Confidencial |
| status_id | BIGINT UNSIGNED | não | nenhum | não | person_statuses.id | — | ix_people_status_id | status id | 123 | Confidencial |
| merged_into_id | BIGINT UNSIGNED | sim | NULL | não | people.id | — | ix_people_merged_into_id | Pessoa sobrevivente após fusão auditada | 123 | Confidencial |
| archived_at | DATETIME(6) | sim | NULL | não | — | — | — | Arquivo lógico | 2026-09-12 10:00:00.000000 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `sex_type_id` → `sex_types.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `civil_status_type_id` → `civil_status_types.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `status_id` → `person_statuses.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `merged_into_id` → `people.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## person_qualifications

Habilitações da Pessoa.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Habilitações da Pessoa; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_qualifications_person_id | person id | 123 | Confidencial |
| education_type_id | BIGINT UNSIGNED | não | nenhum | não | education_types.id | — | ix_person_qualifications_education_type_id | education type id | 123 | Confidencial |
| institution_name | VARCHAR(191) | sim | NULL | não | — | — | — | institution name | EXEMPLO | Confidencial |
| completed_year | SMALLINT UNSIGNED | sim | NULL | não | — | — | — | completed year | 1 | Confidencial |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_person_qualifications_document_id | document id | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `education_type_id` → `education_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## person_employment

Profissão e situação laboral ao longo do tempo.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Profissão e situação laboral ao longo do tempo; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_employment_person_id_starts_at | person id | 123 | Confidencial |
| employment_type_id | BIGINT UNSIGNED | não | nenhum | não | employment_types.id | — | ix_person_employment_employment_type_id | employment type id | 123 | Confidencial |
| profession | VARCHAR(191) | sim | NULL | não | — | — | — | profession | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_person_employment_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_person_employment_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `employment_type_id` → `employment_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## identity_document_types

Tipos de BI e outros documentos.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Tipos de BI e outros documentos; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_identity_document_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## person_documents

Documentos de identidade privados; número cifrado e índice cego.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Documentos de identidade privados; número cifrado e índice cego; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_documents_person_id | person id | 123 | Confidencial |
| document_type_id | BIGINT UNSIGNED | não | nenhum | não | identity_document_types.id | — | ix_person_docs_type_country_blind | document type id | 123 | Confidencial |
| issuer_country | CHAR(3) | não | nenhum | não | — | — | ix_person_docs_type_country_blind | Código de país ISO alpha-3 | AOA | Confidencial |
| number_ciphertext | VARBINARY(2048) | não | nenhum | não | — | — | — | Cifra autenticada de documento | bytes | Altamente sensível |
| number_blind_index | BINARY(32) | não | nenhum | não | — | — | ix_person_docs_type_country_blind | HMAC com chave privada para detectar duplicados | SHA-256, 32 bytes | Confidencial |
| key_version | SMALLINT UNSIGNED | não | nenhum | não | — | — | — | key version | 1 | Confidencial |
| issued_on | DATE | sim | NULL | não | — | — | — | issued on | 2026-09-12 | Confidencial |
| expires_on | DATE | sim | NULL | não | — | — | — | expires on | 2026-09-12 | Confidencial |
| file_id | BIGINT UNSIGNED | sim | NULL | não | files.id | — | ix_person_documents_file_id | file id | 123 | Confidencial |
| verification_status | VARCHAR(64) | não | nenhum | não | — | — | — | verification status | EXEMPLO | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_type_id` → `identity_document_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `file_id` → `files.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## contact_types

Telefone, email e contacto de emergência.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Telefone, email e contacto de emergência; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_contact_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## person_contacts

Contactos privados normalizados e verificáveis.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Contactos privados normalizados e verificáveis; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_contacts_person_id_contact_type_id | person id | 123 | Confidencial |
| contact_type_id | BIGINT UNSIGNED | não | nenhum | não | contact_types.id | — | ix_person_contacts_person_id_contact_type_id, ix_person_contacts_contact_type_id | contact type id | 123 | Confidencial |
| value_ciphertext | VARBINARY(2048) | não | nenhum | não | — | — | — | value ciphertext | EXEMPLO | Confidencial |
| value_blind_index | BINARY(32) | não | nenhum | não | — | — | ix_person_contacts_value_blind_index | value blind index | SHA-256, 32 bytes | Confidencial |
| key_version | SMALLINT UNSIGNED | não | nenhum | não | — | — | — | key version | 1 | Confidencial |
| is_primary | TINYINT UNSIGNED | não | nenhum | não | — | — | — | is primary | 1 | Confidencial |
| verified_at | DATETIME(6) | sim | NULL | não | — | — | — | verified at | 2026-09-12 10:00:00.000000 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `contact_type_id` → `contact_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## addresses

Endereço estruturado reutilizável, protegido.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Endereço estruturado reutilizável, protegido; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| country_code | CHAR(3) | não | nenhum | não | — | — | — | country code | AOA | Confidencial |
| province_id | BIGINT UNSIGNED | sim | NULL | não | territorial_areas.id | — | ix_addresses_province_id | province id | 123 | Confidencial |
| municipality_id | BIGINT UNSIGNED | sim | NULL | não | territorial_areas.id | — | ix_addresses_municipality_id | municipality id | 123 | Confidencial |
| line1_ciphertext | VARBINARY(2048) | não | nenhum | não | — | — | — | line1 ciphertext | EXEMPLO | Confidencial |
| locality | VARCHAR(191) | sim | NULL | não | — | — | — | locality | EXEMPLO | Confidencial |
| key_version | SMALLINT UNSIGNED | não | nenhum | não | — | — | — | key version | 1 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `province_id` → `territorial_areas.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `municipality_id` → `territorial_areas.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## person_addresses

Residências históricas.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Residências históricas; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_addresses_person_id_starts_at | person id | 123 | Confidencial |
| address_id | BIGINT UNSIGNED | não | nenhum | não | addresses.id | — | ix_person_addresses_address_id | address id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_person_addresses_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_person_addresses_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `address_id` → `addresses.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## households

Agregado familiar; não exige membership.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Agregado em recurso autorizado de cadastro.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_households_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Agregado em recurso autorizado de cadastro | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| code | VARCHAR(64) | não | nenhum | não | — | uq_households_code | — | code | CAT_EXEMPLO | Confidencial |
| name | VARCHAR(191) | sim | NULL | não | — | — | — | name | Designacao de exemplo | Confidencial |
| address_id | BIGINT UNSIGNED | sim | NULL | não | addresses.id | — | ix_households_address_id | address id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `address_id` → `addresses.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## household_role_types

Papéis no agregado.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Papéis no agregado; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_household_role_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## household_members

Participação da Pessoa no agregado.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Participação da Pessoa no agregado; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| household_id | BIGINT UNSIGNED | não | nenhum | não | households.id | — | ix_household_members_household_id_starts_at | household id | 123 | Confidencial |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_household_members_person_id_starts_at | person id | 123 | Confidencial |
| role_type_id | BIGINT UNSIGNED | não | nenhum | não | household_role_types.id | — | ix_household_members_role_type_id | role type id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_household_members_person_id_starts_at, ix_household_members_household_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_household_members_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `household_id` → `households.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `role_type_id` → `household_role_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## relationship_types

Filiação, responsabilidade e relações familiares direccionadas.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Filiação, responsabilidade e relações familiares direccionadas; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_relationship_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## person_relationships

Ana filha de Manuel e Isabel; todos são Pessoas independentes de membership.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Ana filha de Manuel e Isabel; todos são Pessoas independentes de membership; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| subject_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_relationships_subject_person_id_relationship_type_id | subject person id | 123 | Confidencial |
| related_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_relationships_related_person_id_relationship_type_id | related person id | 123 | Confidencial |
| relationship_type_id | BIGINT UNSIGNED | não | nenhum | não | relationship_types.id | — | ix_person_relationships_subject_person_id_relationship_type_id, ix_person_relationships_related_person_id_relationship_type_id, ix_person_relationships_relationship_type_id | relationship type id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | — | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_person_relationships_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `subject_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `related_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `relationship_type_id` → `relationship_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## person_files

Ligação explícita entre Pessoa e metadados de ficheiros.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Ligação explícita entre Pessoa e metadados de ficheiros; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_person_files_person_id_file_id_purpose | — | person id | 123 | Confidencial |
| file_id | BIGINT UNSIGNED | não | nenhum | não | files.id | uq_person_files_person_id_file_id_purpose | ix_person_files_file_id | file id | 123 | Confidencial |
| purpose | VARCHAR(64) | não | nenhum | não | — | uq_person_files_person_id_file_id_purpose | — | purpose | EXEMPLO | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `file_id` → `files.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## person_merges

Fusões reversíveis com proveniência e validação humana.

Domínio: Identidade. Owner lógico: Cadastro nacional. Retenção: R-PESSOA. Volume esperado: 100 mil–500 mil Pessoas; relações até 2 milhões. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Fusões reversíveis com proveniência e validação humana; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| source_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_merges_source_person_id | source person id | 123 | Confidencial |
| target_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_merges_target_person_id | target person id | 123 | Confidencial |
| approved_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_person_merges_approved_by | approved by | 123 | Confidencial |
| reason | TEXT | não | nenhum | não | — | — | — | reason | Decisao documentada | Confidencial |
| mapping_metadata | JSON | não | nenhum | não | — | — | — | Somente mapa de referências e proveniência; source não apagada | {} | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `source_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `target_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## territorial_area_types

Região geográfica, província e município civil; separados da MEPA.

Domínio: Estrutura. Owner lógico: Direcção administrativa. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Região geográfica, província e município civil; separados da MEPA; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_territorial_area_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## territorial_areas

Catálogo geográfico versionável.

Domínio: Estrutura. Owner lógico: Direcção administrativa. Retenção: R-INSTITUCIONAL. Volume esperado: Até 30 mil unidades; histórico até 300 mil vínculos. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Catálogo geográfico versionável; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| parent_id | BIGINT UNSIGNED | sim | NULL | não | territorial_areas.id | — | ix_territorial_areas_parent_id_area_type_id | parent id | 123 | Interno |
| area_type_id | BIGINT UNSIGNED | não | nenhum | não | territorial_area_types.id | — | ix_territorial_areas_parent_id_area_type_id, ix_territorial_areas_area_type_id | area type id | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_territorial_areas_code | — | code | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Interno |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `parent_id` → `territorial_areas.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `area_type_id` → `territorial_area_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## organizational_unit_types

Direcções Geral/Regional/Provincial/Municipal, Centro Geral, Centro, Congregação.

Domínio: Estrutura. Owner lógico: Direcção administrativa. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Direcções Geral/Regional/Provincial/Municipal, Centro Geral, Centro, Congregação; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_organizational_unit_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## unit_parent_rules

Pares de tipos permitidos; regras Centro dependem também do Município.

Domínio: Estrutura. Owner lógico: Direcção administrativa. Retenção: R-INSTITUCIONAL. Volume esperado: Até 30 mil unidades; histórico até 300 mil vínculos. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Pares de tipos permitidos; regras Centro dependem também do Município; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| child_type_id | BIGINT UNSIGNED | não | nenhum | não | organizational_unit_types.id | uq_unit_parent_rules_child_type_id_parent_type_id | — | child type id | 123 | Interno |
| parent_type_id | BIGINT UNSIGNED | não | nenhum | não | organizational_unit_types.id | uq_unit_parent_rules_child_type_id_parent_type_id | ix_unit_parent_rules_parent_type_id | parent type id | 123 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `child_type_id` → `organizational_unit_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `parent_type_id` → `organizational_unit_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## organizational_units

Identidade e pai actual da árvore nacional; histórico separado.

Domínio: Estrutura. Owner lógico: Direcção administrativa. Retenção: R-INSTITUCIONAL. Volume esperado: Até 30 mil unidades; histórico até 300 mil vínculos. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Unidade em URLs, documentos e integracoes institucionais.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_organizational_units_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Unidade em URLs, documentos e integracoes institucionais | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Interno |
| parent_id | BIGINT UNSIGNED | sim | NULL | não | organizational_units.id | — | ix_organizational_units_parent_id_unit_type_id_status | parent id | 123 | Interno |
| unit_type_id | BIGINT UNSIGNED | não | nenhum | não | organizational_unit_types.id | — | ix_organizational_units_parent_id_unit_type_id_status, ix_organizational_units_municipality_id_unit_type_id, ix_organizational_units_unit_type_id | unit type id | 123 | Interno |
| municipality_id | BIGINT UNSIGNED | sim | NULL | não | territorial_areas.id | — | ix_organizational_units_municipality_id_unit_type_id | municipality id | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_organizational_units_code | — | code | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Interno |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_organizational_units_parent_id_unit_type_id_status | DRAFT, ACTIVE, CLOSED | DRAFT | Interno |
| opened_on | DATE | sim | NULL | não | — | — | — | opened on | 2026-09-12 | Interno |
| closed_on | DATE | sim | NULL | não | — | — | — | closed on | 2026-09-12 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `parent_id` → `organizational_units.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_type_id` → `organizational_unit_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `municipality_id` → `territorial_areas.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## unit_parent_periods

Histórico dos pais territoriais; cache parent_id muda na mesma transacção.

Domínio: Estrutura. Owner lógico: Direcção administrativa. Retenção: R-INSTITUCIONAL. Volume esperado: Até 30 mil unidades; histórico até 300 mil vínculos. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Histórico dos pais territoriais; cache parent_id muda na mesma transacção; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_unit_parent_periods_unit_id_starts_at | unit id | 123 | Interno |
| parent_unit_id | BIGINT UNSIGNED | sim | NULL | não | organizational_units.id | — | ix_unit_parent_periods_parent_unit_id | parent unit id | 123 | Interno |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Interno |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_unit_parent_periods_unit_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_unit_parent_periods_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `parent_unit_id` → `organizational_units.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## organizational_structure_lock

Linha singleton preexistente serializa mutações da árvore.

Domínio: Estrutura. Owner lógico: Direcção administrativa. Retenção: R-INSTITUCIONAL. Volume esperado: Até 30 mil unidades; histórico até 300 mil vínculos. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Linha singleton preexistente serializa mutações da árvore; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_organizational_structure_lock_code | — | NATIONAL_TREE | CAT_EXEMPLO | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## files

Metadados de storage privado; ficheiro pesado fora da BD.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Referencia opaca de ficheiro na API de download autorizada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_files_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Referencia opaca de ficheiro na API de download autorizada | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| owner_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_files_owner_unit_id_classification_status | owner unit id | 123 | Restrito |
| owner_department_id | BIGINT UNSIGNED | sim | NULL | não | department_instances.id | — | ix_files_owner_department_id | owner department id | 123 | Restrito |
| created_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_files_created_by | created by | 123 | Restrito |
| classification | VARCHAR(64) | não | nenhum | não | — | — | ix_files_owner_unit_id_classification_status | classification | EXEMPLO | Restrito |
| disk | VARCHAR(64) | não | nenhum | não | — | uq_files_disk_storage_key | — | disk | EXEMPLO | Restrito |
| storage_key | VARCHAR(191) | não | nenhum | não | — | uq_files_disk_storage_key | — | storage key | EXEMPLO | Restrito |
| original_name | VARCHAR(191) | não | nenhum | não | — | — | — | original name | EXEMPLO | Restrito |
| mime_type | VARCHAR(191) | não | nenhum | não | — | — | — | mime type | EXEMPLO | Restrito |
| size_bytes | BIGINT UNSIGNED | não | nenhum | não | — | — | — | size bytes | 1 | Restrito |
| checksum | BINARY(32) | não | nenhum | não | — | — | — | checksum | SHA-256, 32 bytes | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_files_owner_unit_id_classification_status | QUARANTINED, AVAILABLE, TOMBSTONE, PURGED | DRAFT | Restrito |
| deleted_at | DATETIME(6) | sim | NULL | não | — | — | — | deleted at | 2026-09-12 10:00:00.000000 | Restrito |
| purged_at | DATETIME(6) | sim | NULL | não | — | — | — | purged at | 2026-09-12 10:00:00.000000 | Restrito |
| key_version | SMALLINT UNSIGNED | sim | NULL | não | — | — | — | key version | 1 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `owner_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `owner_department_id` → `department_instances.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `created_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## legal_document_types

Título, declaração, arrendamento, superfície, licença, croquis, certidão e outro.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Título, declaração, arrendamento, superfície, licença, croquis, certidão e outro; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_legal_document_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## legal_documents

Identidade documental e referência normativa; versões preservadas.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Documento institucional em referencia externa e API autorizada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_legal_documents_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Documento institucional em referencia externa e API autorizada | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| document_type_id | BIGINT UNSIGNED | não | nenhum | não | legal_document_types.id | — | ix_legal_documents_document_type_id | document type id | 123 | Restrito |
| owner_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_legal_documents_owner_unit_id_reference | owner unit id | 123 | Restrito |
| reference | VARCHAR(64) | não | nenhum | não | — | — | ix_legal_documents_owner_unit_id_reference | reference | EXEMPLO | Restrito |
| title | VARCHAR(191) | não | nenhum | não | — | — | — | title | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `document_type_id` → `legal_document_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `owner_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## document_versions

Versões imutáveis do documento.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Versao imutavel do documento em referencia externa.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_document_versions_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Versao imutavel do documento em referencia externa | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| document_id | BIGINT UNSIGNED | não | nenhum | não | legal_documents.id | uq_document_versions_document_id_version | — | document id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_document_versions_document_id_version | — | version | 1 | Restrito |
| file_id | BIGINT UNSIGNED | não | nenhum | não | files.id | — | ix_document_versions_file_id | file id | 123 | Restrito |
| issued_on | DATE | sim | NULL | não | — | — | — | issued on | 2026-09-12 | Restrito |
| supersedes_id | BIGINT UNSIGNED | sim | NULL | não | document_versions.id | — | ix_document_versions_supersedes_id | supersedes id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `document_id` → `legal_documents.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `file_id` → `files.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `supersedes_id` → `document_versions.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## physical_locations

Local físico georreferenciado separado da unidade.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Recurso de localizacao em API e mapa com exposicao aprovada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_physical_locations_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Recurso de localizacao em API e mapa com exposicao aprovada | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| address_id | BIGINT UNSIGNED | não | nenhum | não | addresses.id | — | ix_physical_locations_address_id | address id | 123 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| latitude | DECIMAL(9,6) | sim | NULL | não | — | — | ix_physical_locations_latitude_longitude_id | latitude | 1.0000 | Restrito |
| longitude | DECIMAL(10,6) | sim | NULL | não | — | — | ix_physical_locations_latitude_longitude_id | longitude | 1.0000 | Restrito |
| geocode_accuracy | VARCHAR(64) | sim | NULL | não | — | — | — | geocode accuracy | EXEMPLO | Restrito |
| public_visibility | VARCHAR(64) | não | nenhum | não | — | — | — | PRIVATE, APPROVED_PUBLIC | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `address_id` → `addresses.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## properties

Identidade do imóvel e situação documental; proprietário pode não ser membro.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Imovel em ficha patrimonial/API e documentos.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_properties_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Imovel em ficha patrimonial/API e documentos | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_properties_code | — | code | CAT_EXEMPLO | Restrito |
| location_id | BIGINT UNSIGNED | não | nenhum | não | physical_locations.id | — | ix_properties_location_id | location id | 123 | Restrito |
| owner_person_id | BIGINT UNSIGNED | sim | NULL | não | people.id | — | ix_properties_owner_person_id | owner person id | 123 | Restrito |
| owner_name_external | VARCHAR(191) | sim | NULL | não | — | — | — | owner name external | EXEMPLO | Restrito |
| ownership_status | VARCHAR(64) | não | nenhum | não | — | — | — | ownership status | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `location_id` → `physical_locations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `owner_person_id` → `people.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## property_documents

Documentação extensível de legalização.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Documentação extensível de legalização; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| property_id | BIGINT UNSIGNED | não | nenhum | não | properties.id | uq_property_documents_property_id_document_id | — | property id | 123 | Restrito |
| document_id | BIGINT UNSIGNED | não | nenhum | não | legal_documents.id | uq_property_documents_property_id_document_id | ix_property_documents_document_id | document id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `property_id` → `properties.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## occupation_types

Próprio, arrendado, cedido, provisório e regimes aprovados.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Próprio, arrendado, cedido, provisório e regimes aprovados; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_occupation_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## unit_location_links

Múltiplos locais e principal por período; mudança não muda identidade.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Múltiplos locais e principal por período; mudança não muda identidade; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_unit_location_links_unit_id_starts_at | unit id | 123 | Restrito |
| location_id | BIGINT UNSIGNED | não | nenhum | não | physical_locations.id | — | ix_unit_location_links_location_id_starts_at | location id | 123 | Restrito |
| property_id | BIGINT UNSIGNED | sim | NULL | não | properties.id | — | ix_unit_location_links_property_id | property id | 123 | Restrito |
| occupation_type_id | BIGINT UNSIGNED | não | nenhum | não | occupation_types.id | — | ix_unit_location_links_occupation_type_id | occupation type id | 123 | Restrito |
| is_primary | TINYINT UNSIGNED | não | nenhum | não | — | — | — | is primary | 1 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_unit_location_links_unit_id_starts_at, ix_unit_location_links_location_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_unit_location_links_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `location_id` → `physical_locations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `property_id` → `properties.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `occupation_type_id` → `occupation_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## temples

Uso religioso do local físico; vários templos não criam novas Congregações.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Templo em ficha/API de patrimonio.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_temples_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Templo em ficha/API de patrimonio | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| location_id | BIGINT UNSIGNED | não | nenhum | não | physical_locations.id | — | ix_temples_location_id | location id | 123 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| capacity | INT UNSIGNED | sim | NULL | não | — | — | — | capacity | 1 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `location_id` → `physical_locations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## facility_types

Escola, biblioteca, gabinete, salão, formação, clínica, residência e outro.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Escola, biblioteca, gabinete, salão, formação, clínica, residência e outro; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_facility_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## facilities

Instalações extensíveis por tipo, sem colunas tem_escola.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Instalacao em ficha/API de patrimonio.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_facilities_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Instalacao em ficha/API de patrimonio | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| property_id | BIGINT UNSIGNED | não | nenhum | não | properties.id | — | ix_facilities_property_id | property id | 123 | Restrito |
| facility_type_id | BIGINT UNSIGNED | não | nenhum | não | facility_types.id | — | ix_facilities_facility_type_id | facility type id | 123 | Restrito |
| parent_facility_id | BIGINT UNSIGNED | sim | NULL | não | facilities.id | — | ix_facilities_parent_facility_id | parent facility id | 123 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| capacity | INT UNSIGNED | sim | NULL | não | — | — | — | capacity | 1 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `property_id` → `properties.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `facility_type_id` → `facility_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `parent_facility_id` → `facilities.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## asset_types

Tipos de património móvel.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Tipos de património móvel; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_asset_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## assets

Bens móveis e referência documental de aquisição.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Bem movel em inventario/documento de custodia.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_assets_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Bem movel em inventario/documento de custodia | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| asset_type_id | BIGINT UNSIGNED | não | nenhum | não | asset_types.id | — | ix_assets_asset_type_id | asset type id | 123 | Restrito |
| owner_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_assets_owner_unit_id_status | owner unit id | 123 | Restrito |
| location_id | BIGINT UNSIGNED | sim | NULL | não | physical_locations.id | — | ix_assets_location_id | location id | 123 | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_assets_code | — | code | CAT_EXEMPLO | Restrito |
| description | TEXT | não | nenhum | não | — | — | — | description | EXEMPLO | Restrito |
| acquired_on | DATE | sim | NULL | não | — | — | — | acquired on | 2026-09-12 | Restrito |
| acquisition_value | DECIMAL(19,4) | sim | NULL | não | — | — | — | acquisition value | 1500.0000 | Restrito |
| currency_code | CHAR(3) | não | nenhum | não | — | — | — | currency code | AOA | Restrito |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_assets_document_id | document id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_assets_owner_unit_id_status | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `asset_type_id` → `asset_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `owner_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `location_id` → `physical_locations.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## asset_custody_periods

Histórico de localização/custódia do bem.

Domínio: Storage e património. Owner lógico: Património / gestão documental. Retenção: R-INSTITUCIONAL. Volume esperado: Até 100 mil locais; 1 milhão de documentos/ficheiros. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Histórico de localização/custódia do bem; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| asset_id | BIGINT UNSIGNED | não | nenhum | não | assets.id | — | ix_asset_custody_periods_asset_id_starts_at | asset id | 123 | Restrito |
| location_id | BIGINT UNSIGNED | não | nenhum | não | physical_locations.id | — | ix_asset_custody_periods_location_id | location id | 123 | Restrito |
| custodian_person_id | BIGINT UNSIGNED | sim | NULL | não | people.id | — | ix_asset_custody_periods_custodian_person_id | custodian person id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_asset_custody_periods_asset_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_asset_custody_periods_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `asset_id` → `assets.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `location_id` → `physical_locations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `custodian_person_id` → `people.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## membership_statuses

Estados eclesiásticos aprovados.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Estados eclesiásticos aprovados; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_membership_statuses_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## memberships

Perfil eclesiástico único da Pessoa, preservado após suspensão/saída.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Perfil eclesiastico referenciado na API sem usar o Numero Unico como chave.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_memberships_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Perfil eclesiastico referenciado na API sem usar o Numero Unico como chave | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_memberships_person_id | — | person id | 123 | Restrito |
| status_id | BIGINT UNSIGNED | não | nenhum | não | membership_statuses.id | — | ix_memberships_status_id | status id | 123 | Restrito |
| admitted_on | DATE | sim | NULL | não | — | — | — | admitted on | 2026-09-12 | Restrito |
| date_precision | VARCHAR(64) | não | nenhum | não | — | — | — | date precision | EXEMPLO | Restrito |
| approved_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_memberships_approved_by | approved by | 123 | Restrito |
| approved_at | DATETIME(6) | sim | NULL | não | — | — | — | approved at | 2026-09-12 10:00:00.000000 | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_memberships_source_document_id | source document id | 123 | Restrito |
| origin | VARCHAR(64) | não | nenhum | não | — | — | — | origin | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `status_id` → `membership_statuses.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## membership_periods

Histórico de estado e Congregação; sem sobrescrita.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Histórico de estado e Congregação; sem sobrescrita; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| membership_id | BIGINT UNSIGNED | não | nenhum | não | memberships.id | — | ix_membership_periods_membership_id_starts_at | membership id | 123 | Restrito |
| congregation_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_membership_periods_congregation_id_status_id_starts_at | congregation id | 123 | Restrito |
| status_id | BIGINT UNSIGNED | não | nenhum | não | membership_statuses.id | — | ix_membership_periods_congregation_id_status_id_starts_at, ix_membership_periods_status_id | status id | 123 | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_membership_periods_membership_id_starts_at, ix_membership_periods_congregation_id_status_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_membership_periods_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `membership_id` → `memberships.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `congregation_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `status_id` → `membership_statuses.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## member_number_sequences

Singleton nacional; nunca chave por mês/ano.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Singleton nacional; nunca chave por mês/ano; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_member_number_sequences_code | — | MEPA_NATIONAL | CAT_EXEMPLO | Restrito |
| last_value | INT UNSIGNED | não | nenhum | não | — | — | — | Último contador confirmado | 70000 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## member_numbers

Um número MEPA por membership durante toda a vida; revogar credencial não número.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Um número MEPA por membership durante toda a vida; revogar credencial não número; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| membership_id | BIGINT UNSIGNED | não | nenhum | não | memberships.id | uq_member_numbers_membership_id | — | membership id | 123 | Restrito |
| number | CHAR(14) | não | nenhum | não | — | uq_member_numbers_number | — | MEPAAAMMSSSSSS | MEPA2609000001 | Restrito |
| sequence_value | INT UNSIGNED | não | nenhum | não | — | uq_member_numbers_sequence_value | — | sequence value | 1 | Restrito |
| issued_year | SMALLINT UNSIGNED | não | nenhum | não | — | — | — | Ano completo | 2026 | Restrito |
| issued_month | SMALLINT UNSIGNED | não | nenhum | não | — | — | — | Mês 1–12 | 9 | Restrito |
| issued_at | DATETIME(6) | não | nenhum | não | — | — | — | issued at | 2026-09-12 10:00:00.000000 | Restrito |
| origin | VARCHAR(64) | não | nenhum | não | — | — | — | APPROVED_ADMISSION ou APPROVED_LEGACY_MAPPING | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `membership_id` → `memberships.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## legacy_member_numbers

Preserva texto bruto e origem; duplicados legados ficam sinalizados.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Preserva texto bruto e origem; duplicados legados ficam sinalizados; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| membership_id | BIGINT UNSIGNED | não | nenhum | não | memberships.id | — | ix_legacy_member_numbers_membership_id | membership id | 123 | Restrito |
| source_system | VARCHAR(64) | não | nenhum | não | — | — | ix_legacy_member_numbers_source_system_normalized_number | source system | EXEMPLO | Restrito |
| raw_number | VARCHAR(191) | não | nenhum | não | — | — | — | raw number | EXEMPLO | Restrito |
| normalized_number | VARCHAR(191) | não | nenhum | não | — | — | ix_legacy_member_numbers_source_system_normalized_number | normalized number | EXEMPLO | Restrito |
| import_record_id | BIGINT UNSIGNED | sim | NULL | não | import_records.id | — | ix_legacy_member_numbers_import_record_id | import record id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `membership_id` → `memberships.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `import_record_id` → `import_records.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## milestone_types

Baptismo, conversão e marcos eclesiásticos.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Baptismo, conversão e marcos eclesiásticos; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_milestone_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## ecclesiastical_milestones

Factos históricos com precisão explícita.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Factos históricos com precisão explícita; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_ecclesiastical_milestones_person_id | person id | 123 | Restrito |
| milestone_type_id | BIGINT UNSIGNED | não | nenhum | não | milestone_types.id | — | ix_ecclesiastical_milestones_milestone_type_id | milestone type id | 123 | Restrito |
| occurred_on | DATE | sim | NULL | não | — | — | — | occurred on | 2026-09-12 | Restrito |
| date_precision | VARCHAR(64) | não | nenhum | não | — | — | — | date precision | EXEMPLO | Restrito |
| unit_id | BIGINT UNSIGNED | sim | NULL | não | organizational_units.id | — | ix_ecclesiastical_milestones_unit_id | unit id | 123 | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_ecclesiastical_milestones_source_document_id | source document id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `milestone_type_id` → `milestone_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## transfers

Pedido institucional entre Congregações; efectivação muda períodos, nunca número.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Pedido de transferencia eclesiastica em API e documento.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_transfers_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Pedido de transferencia eclesiastica em API e documento | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| membership_id | BIGINT UNSIGNED | não | nenhum | não | memberships.id | — | ix_transfers_membership_id_status | membership id | 123 | Restrito |
| origin_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_transfers_origin_unit_id | origin unit id | 123 | Restrito |
| destination_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_transfers_destination_unit_id_status | destination unit id | 123 | Restrito |
| requested_at | DATETIME(6) | não | nenhum | não | — | — | — | requested at | 2026-09-12 10:00:00.000000 | Restrito |
| effective_at | DATETIME(6) | sim | NULL | não | — | — | — | effective at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_transfers_membership_id_status, ix_transfers_destination_unit_id_status | status | DRAFT | Restrito |
| workflow_instance_id | BIGINT UNSIGNED | não | nenhum | não | workflow_instances.id | — | ix_transfers_workflow_instance_id | workflow instance id | 123 | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_transfers_source_document_id | source document id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `membership_id` → `memberships.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `origin_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `destination_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `workflow_instance_id` → `workflow_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## credential_types

Permanente MEPA e temporária para convidados, sem confundir evento.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Permanente MEPA e temporária para convidados, sem confundir evento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | NAO | AUTO_INCREMENT | SIM | — | — | — | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | NAO | nenhum | NAO | — | uq_credential_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | NAO | nenhum | NAO | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | NAO | nenhum | NAO | — | — | — | Disponível para novas relações | 1 | Interno |
| requires_member_number | TINYINT UNSIGNED | NAO | nenhum; configurar antes de activar o tipo | NAO | — | — | — | 0/1; permanente MEPA exige numero; temporaria nao cria numero | 1 | Interno |
| default_validity_days | INT UNSIGNED | SIM | NULL; politica D-05 pendente | NAO | — | — | — | Dias positivos quando definidos; NULL nao autoriza validade ilimitada por omissao | NULL | Interno |
| requires_formal_approval | TINYINT UNSIGNED | NAO | nenhum; configurar antes de activar o tipo | NAO | — | — | — | 0/1; aprovacao formal validada pelo emissor; configuracao D-05 | 1 | Interno |
| created_at | DATETIME(6) | NAO | nenhum; relógio UTC do serviço | NAO | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | NAO | 0 | NAO | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## credential_classes

Classe visual configurável.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Classe visual configurável; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_credential_classes_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## credential_templates

Templates versionados; nenhum rendering funcional nesta fase.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Templates versionados; nenhum rendering funcional nesta fase; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| credential_type_id | BIGINT UNSIGNED | não | nenhum | não | credential_types.id | uq_credential_templates_credential_type_id_version | — | credential type id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_credential_templates_credential_type_id_version | — | version | 1 | Restrito |
| file_id | BIGINT UNSIGNED | não | nenhum | não | files.id | — | ix_credential_templates_file_id | file id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `credential_type_id` → `credential_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `file_id` → `files.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## credential_class_styles

Cor aprovada por versão de template e classe.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Cor aprovada por versão de template e classe; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| template_id | BIGINT UNSIGNED | não | nenhum | não | credential_templates.id | uq_credential_class_styles_template_id_class_id | — | template id | 123 | Restrito |
| class_id | BIGINT UNSIGNED | não | nenhum | não | credential_classes.id | uq_credential_class_styles_template_id_class_id | ix_credential_class_styles_class_id | class id | 123 | Restrito |
| color | CHAR(7) | não | nenhum | não | — | — | — | color | EXEMPLO | Restrito |
| text_color | CHAR(7) | não | nenhum | não | — | — | — | text color | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `template_id` → `credential_templates.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `class_id` → `credential_classes.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## credentials

Credencial permanente/temporária da Pessoa; token não contém dados pessoais.

Domínio: Membros e credenciais. Owner lógico: Secretaria nacional. Retenção: R-INSTITUCIONAL. Volume esperado: 70 mil membros inicial; até 500 mil; 2 milhões de períodos. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Credencial referida na API de emissao/revogacao; QR usa token separado.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_credentials_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Credencial referida na API de emissao/revogacao; QR usa token separado | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_credentials_person_id_credential_type_id_version | ix_credentials_person_id_status | person id | 123 | Restrito |
| credential_type_id | BIGINT UNSIGNED | não | nenhum | não | credential_types.id | uq_credentials_person_id_credential_type_id_version | ix_credentials_credential_type_id | credential type id | 123 | Restrito |
| member_number_id | BIGINT UNSIGNED | sim | NULL | não | member_numbers.id | — | ix_credentials_member_number_id | member number id | 123 | Restrito |
| template_id | BIGINT UNSIGNED | não | nenhum | não | credential_templates.id | — | ix_credentials_template_id | template id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_credentials_person_id_credential_type_id_version | — | version | 1 | Restrito |
| token_hash | BINARY(32) | não | nenhum | não | — | uq_credentials_token_hash | — | token hash | SHA-256, 32 bytes | Restrito |
| issued_at | DATETIME(6) | não | nenhum | não | — | — | — | issued at | 2026-09-12 10:00:00.000000 | Restrito |
| expires_at | DATETIME(6) | sim | NULL | não | — | — | — | expires at | 2026-09-12 10:00:00.000000 | Restrito |
| revoked_at | DATETIME(6) | sim | NULL | não | — | — | — | revoked at | 2026-09-12 10:00:00.000000 | Restrito |
| revoked_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_credentials_revoked_by | revoked by | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_credentials_person_id_status | status | DRAFT | Restrito |
| render_file_id | BIGINT UNSIGNED | sim | NULL | não | files.id | — | ix_credentials_render_file_id | render file id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `credential_type_id` → `credential_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `member_number_id` → `member_numbers.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `template_id` → `credential_templates.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `revoked_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `render_file_id` → `files.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## ministerial_classes

Classe ministerial separada de cargo e função.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Classe ministerial separada de cargo e função; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_ministerial_classes_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## positions

Definições institucionais de cargos.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Definições institucionais de cargos; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_positions_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## functions

Funções exercidas, separadas da classe.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Funções exercidas, separadas da classe; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_functions_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## ministerial_class_periods

Classe histórica da Pessoa; uma classe principal por intervalo.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Classe histórica da Pessoa; uma classe principal por intervalo; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_ministerial_class_periods_person_id_starts_at | person id | 123 | Restrito |
| class_id | BIGINT UNSIGNED | não | nenhum | não | ministerial_classes.id | — | ix_ministerial_class_periods_class_id | class id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_ministerial_class_periods_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_ministerial_class_periods_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `class_id` → `ministerial_classes.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## organizational_posts

Lugares de cargo esperados numa unidade, incluindo vaga.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Lugar territorial em URL administrativa.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | NAO | AUTO_INCREMENT | SIM | — | — | — | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_organizational_posts_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Lugar territorial em URL administrativa | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| unit_id | BIGINT UNSIGNED | NAO | nenhum | NAO | organizational_units | uq_organizational_posts_unit_id_position_id_slot | — | unit id | 123 | Restrito |
| position_id | BIGINT UNSIGNED | NAO | nenhum | NAO | positions | uq_organizational_posts_unit_id_position_id_slot | ix_organizational_posts_position_id | position id | 123 | Restrito |
| slot | INT UNSIGNED | NAO | nenhum | NAO | — | uq_organizational_posts_unit_id_position_id_slot | — | slot | 1 | Restrito |
| occupancy_status | VARCHAR(64) | NAO | nenhum | NAO | — | — | — | Cache de interface VACANT/FILLED/INTERIM/INACTIVE; fonte autoritativa ministerial_assignments vigentes; proibido em relatorios oficiais e autorizacao | EXEMPLO | Restrito |
| created_at | DATETIME(6) | NAO | nenhum; relógio UTC do serviço | NAO | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | NAO | 0 | NAO | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `position_id` → `positions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## ministerial_assignments

Nomeação territorial por lugar de cargo.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Nomeacao em despacho externo e API autorizada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_ministerial_assignments_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Nomeacao em despacho externo e API autorizada | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| post_id | BIGINT UNSIGNED | não | nenhum | não | organizational_posts.id | — | ix_ministerial_assignments_post_id_starts_at | post id | 123 | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_ministerial_assignments_person_id_starts_at | person id | 123 | Restrito |
| appointment_kind | VARCHAR(64) | não | nenhum | não | — | — | — | SUBSTANTIVE ou INTERIM | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_ministerial_assignments_post_id_starts_at, ix_ministerial_assignments_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_ministerial_assignments_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `post_id` → `organizational_posts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## function_assignments

Funções históricas; sem agrupar todos os atributos ministeriais numa linha.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Funções históricas; sem agrupar todos os atributos ministeriais numa linha; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_function_assignments_person_id_starts_at | person id | 123 | Restrito |
| function_id | BIGINT UNSIGNED | não | nenhum | não | functions.id | — | ix_function_assignments_function_id | function id | 123 | Restrito |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_function_assignments_unit_id | unit id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_function_assignments_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_function_assignments_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `function_id` → `functions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## department_categories

Categorias de departamentos aprovadas.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Categorias de departamentos aprovadas; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_department_categories_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## departments

Definição nacional do departamento.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Definição nacional do departamento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| category_id | BIGINT UNSIGNED | não | nenhum | não | department_categories.id | — | ix_departments_category_id | category id | 123 | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_departments_code | — | code | CAT_EXEMPLO | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `category_id` → `department_categories.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## department_applicability

Quais tipos de unidade esperam representação do departamento.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Quais tipos de unidade esperam representação do departamento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| department_id | BIGINT UNSIGNED | não | nenhum | não | departments.id | uq_department_applicability_department_id_unit_type_id | — | department id | 123 | Restrito |
| unit_type_id | BIGINT UNSIGNED | não | nenhum | não | organizational_unit_types.id | uq_department_applicability_department_id_unit_type_id | ix_department_applicability_unit_type_id | unit type id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `department_id` → `departments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_type_id` → `organizational_unit_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## department_instances

Instância por unidade; NON_CONSTITUTED é estado explícito.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Instancia local em URL administrativa.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_department_instances_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Instancia local em URL administrativa | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| department_id | BIGINT UNSIGNED | não | nenhum | não | departments.id | uq_department_instances_department_id_unit_id | — | department id | 123 | Restrito |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | uq_department_instances_department_id_unit_id | ix_department_instances_unit_id_status | unit id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_department_instances_unit_id_status | NON_CONSTITUTED, ACTIVE, INACTIVE | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `department_id` → `departments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## department_posts

Cargo esperado por instância e lugar; VACANT = SEM NOMEAÇÃO.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Lugar departamental em URL administrativa.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_department_posts_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Lugar departamental em URL administrativa | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| instance_id | BIGINT UNSIGNED | não | nenhum | não | department_instances.id | uq_department_posts_instance_id_position_id_slot | — | instance id | 123 | Restrito |
| position_id | BIGINT UNSIGNED | não | nenhum | não | positions.id | uq_department_posts_instance_id_position_id_slot | ix_department_posts_position_id | position id | 123 | Restrito |
| slot | INT UNSIGNED | não | nenhum | não | — | uq_department_posts_instance_id_position_id_slot | — | slot | 1 | Restrito |
| occupancy_status | VARCHAR(64) | não | nenhum | não | — | — | — | Cache de interface VACANT/FILLED/INTERIM/INACTIVE; fonte autoritativa department_appointments vigentes; proibido em relatorios oficiais e autorizacao | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `instance_id` → `department_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `position_id` → `positions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## department_appointments

Nomeações históricas definitivas/interinas sem Pessoa fictícia.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Nomeacao departamental em despacho externo.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_department_appointments_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Nomeacao departamental em despacho externo | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| post_id | BIGINT UNSIGNED | não | nenhum | não | department_posts.id | — | ix_department_appointments_post_id_starts_at | post id | 123 | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_department_appointments_person_id_starts_at | person id | 123 | Restrito |
| appointment_kind | VARCHAR(64) | não | nenhum | não | — | — | — | appointment kind | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_department_appointments_post_id_starts_at, ix_department_appointments_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_department_appointments_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `post_id` → `department_posts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## department_memberships

Adesões históricas autorizadas; elegibilidade não altera silenciosamente.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Adesões históricas autorizadas; elegibilidade não altera silenciosamente; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| instance_id | BIGINT UNSIGNED | não | nenhum | não | department_instances.id | — | ix_department_memberships_instance_id_starts_at | instance id | 123 | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_department_memberships_person_id_starts_at | person id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_department_memberships_instance_id_starts_at, ix_department_memberships_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_department_memberships_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `instance_id` → `department_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## department_activities

Actividade departamental concretizada como evento.

Domínio: Ministério e departamentos. Owner lógico: Secretaria / departamento titular. Retenção: R-INSTITUCIONAL. Volume esperado: Até 2 milhões de vínculos históricos; catálogos <1 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Actividade departamental concretizada como evento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| instance_id | BIGINT UNSIGNED | não | nenhum | não | department_instances.id | uq_department_activities_instance_id_event_id | — | instance id | 123 | Restrito |
| event_id | BIGINT UNSIGNED | não | nenhum | não | events.id | uq_department_activities_instance_id_event_id | ix_department_activities_event_id | event id | 123 | Restrito |
| description | TEXT | sim | NULL | não | — | — | — | description | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `instance_id` → `department_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `event_id` → `events.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## governance_body_types

CONGRESSO, ASSEMBLEIA_GERAL, CONSELHO_MINISTROS, CONSELHO_DIRECCAO, JUNTA_OFICIAL, COMISSAO_AUDITORIA_ETICA.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: CONGRESSO, ASSEMBLEIA_GERAL, CONSELHO_MINISTROS, CONSELHO_DIRECCAO, JUNTA_OFICIAL, COMISSAO_AUDITORIA_ETICA; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_governance_body_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## governance_bodies

Órgão permanente, separado da árvore territorial.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Orgao em URL administrativa e documentos.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_governance_bodies_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Orgao em URL administrativa e documentos | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| body_type_id | BIGINT UNSIGNED | não | nenhum | não | governance_body_types.id | — | ix_governance_bodies_body_type_id | body type id | 123 | Restrito |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_governance_bodies_unit_id | unit id | 123 | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_governance_bodies_code | — | code | CAT_EXEMPLO | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `body_type_id` → `governance_body_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## governance_body_memberships

Composição histórica do órgão.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Composição histórica do órgão; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| body_id | BIGINT UNSIGNED | não | nenhum | não | governance_bodies.id | — | ix_governance_body_memberships_body_id_starts_at | body id | 123 | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_governance_body_memberships_person_id_starts_at | person id | 123 | Restrito |
| position_id | BIGINT UNSIGNED | sim | NULL | não | positions.id | — | ix_governance_body_memberships_position_id | position id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_governance_body_memberships_body_id_starts_at, ix_governance_body_memberships_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_governance_body_memberships_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `body_id` → `governance_bodies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `position_id` → `positions.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_types

Culto, reunião, congresso e actividades.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Culto, reunião, congresso e actividades; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_event_types_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## events

Evento concreto; reunião de órgão usa vínculo governance_sessions.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Evento em URLs de inscricao e API.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_events_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Evento em URLs de inscricao e API | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| event_type_id | BIGINT UNSIGNED | não | nenhum | não | event_types.id | — | ix_events_event_type_id | event type id | 123 | Restrito |
| owner_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_events_owner_unit_id_starts_at | owner unit id | 123 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_events_owner_unit_id_starts_at | starts at | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | não | nenhum | não | — | — | — | ends at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| eligibility_policy_version | VARCHAR(64) | não | nenhum | não | — | — | — | eligibility policy version | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `event_type_id` → `event_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `owner_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_sessions

Sessões com regra explícita de unicidade nominal.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Sessao em API de agenda e check-in.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_event_sessions_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Sessao em API de agenda e check-in | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| event_id | BIGINT UNSIGNED | não | nenhum | não | events.id | — | ix_event_sessions_event_id_starts_at | event id | 123 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_event_sessions_event_id_starts_at | starts at | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | não | nenhum | não | — | — | — | ends at | 2026-09-12 10:00:00.000000 | Restrito |
| checkin_policy | VARCHAR(64) | não | nenhum | não | — | — | — | SINGLE_PERSON_SESSION | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `event_id` → `events.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## governance_sessions

Reunião do órgão = evento; não duplicar agenda.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Reunião do órgão = evento; não duplicar agenda; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| body_id | BIGINT UNSIGNED | não | nenhum | não | governance_bodies.id | uq_governance_sessions_body_id_event_id | — | body id | 123 | Restrito |
| event_id | BIGINT UNSIGNED | não | nenhum | não | events.id | uq_governance_sessions_body_id_event_id | ix_governance_sessions_event_id | event id | 123 | Restrito |
| session_reference | VARCHAR(64) | não | nenhum | não | — | — | — | session reference | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `body_id` → `governance_bodies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `event_id` → `events.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## governance_resolutions

Deliberações da reunião com documentos e versão.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Deliberacao em documento institucional externo.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_governance_resolutions_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Deliberacao em documento institucional externo | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| governance_session_id | BIGINT UNSIGNED | não | nenhum | não | governance_sessions.id | uq_governance_resolutions_governance_session_id_reference | — | governance session id | 123 | Restrito |
| reference | VARCHAR(64) | não | nenhum | não | — | uq_governance_resolutions_governance_session_id_reference | — | reference | EXEMPLO | Restrito |
| document_id | BIGINT UNSIGNED | não | nenhum | não | legal_documents.id | — | ix_governance_resolutions_document_id | document id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `governance_session_id` → `governance_sessions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_organizers

Pessoas responsáveis pelo evento.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Pessoas responsáveis pelo evento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| event_id | BIGINT UNSIGNED | não | nenhum | não | events.id | uq_event_organizers_event_id_person_id | — | event id | 123 | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_event_organizers_event_id_person_id | ix_event_organizers_person_id | person id | 123 | Restrito |
| function_id | BIGINT UNSIGNED | sim | NULL | não | functions.id | — | ix_event_organizers_function_id | function id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `event_id` → `events.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `function_id` → `functions.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_locations

Múltiplos locais de um evento.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Múltiplos locais de um evento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| event_id | BIGINT UNSIGNED | não | nenhum | não | events.id | uq_event_locations_event_id_location_id | — | event id | 123 | Restrito |
| location_id | BIGINT UNSIGNED | não | nenhum | não | physical_locations.id | uq_event_locations_event_id_location_id | ix_event_locations_location_id | location id | 123 | Restrito |
| is_primary | TINYINT UNSIGNED | não | nenhum | não | — | — | — | is primary | 1 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `event_id` → `events.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `location_id` → `physical_locations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_documents

Documentos da reunião/evento.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Documentos da reunião/evento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| event_id | BIGINT UNSIGNED | não | nenhum | não | events.id | uq_event_documents_event_id_document_id | — | event id | 123 | Restrito |
| document_id | BIGINT UNSIGNED | não | nenhum | não | legal_documents.id | uq_event_documents_event_id_document_id | ix_event_documents_document_id | document id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `event_id` → `events.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_invitation_lists

Lista versionada e congelada de convocados; critérios normalizados.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Lista versionada e congelada de convocados; critérios normalizados; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| event_id | BIGINT UNSIGNED | não | nenhum | não | events.id | uq_event_invitation_lists_event_id_version | — | event id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_event_invitation_lists_event_id_version | — | version | 1 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| frozen_at | DATETIME(6) | sim | NULL | não | — | — | — | frozen at | 2026-09-12 10:00:00.000000 | Restrito |
| selection_at | DATETIME(6) | não | nenhum | não | — | — | — | selection at | 2026-09-12 10:00:00.000000 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `event_id` → `events.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## invitation_criteria

Critério tipado por cargo, classe, órgão, unidade ou departamento; XOR.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Critério tipado por cargo, classe, órgão, unidade ou departamento; XOR; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| list_id | BIGINT UNSIGNED | não | nenhum | não | event_invitation_lists.id | — | ix_invitation_criteria_list_id | list id | 123 | Restrito |
| criterion_kind | VARCHAR(64) | não | nenhum | não | — | — | — | criterion kind | EXEMPLO | Restrito |
| position_id | BIGINT UNSIGNED | sim | NULL | não | positions.id | — | ix_invitation_criteria_position_id | position id | 123 | Restrito |
| class_id | BIGINT UNSIGNED | sim | NULL | não | ministerial_classes.id | — | ix_invitation_criteria_class_id | class id | 123 | Restrito |
| body_id | BIGINT UNSIGNED | sim | NULL | não | governance_bodies.id | — | ix_invitation_criteria_body_id | body id | 123 | Restrito |
| unit_id | BIGINT UNSIGNED | sim | NULL | não | organizational_units.id | — | ix_invitation_criteria_unit_id | unit id | 123 | Restrito |
| department_id | BIGINT UNSIGNED | sim | NULL | não | department_instances.id | — | ix_invitation_criteria_department_id | department id | 123 | Restrito |
| include_descendants | TINYINT UNSIGNED | não | nenhum | não | — | — | — | include descendants | 1 | Restrito |
| operator | VARCHAR(64) | não | nenhum | não | — | — | — | INCLUDE ou EXCLUDE | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `list_id` → `event_invitation_lists.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `position_id` → `positions.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `class_id` → `ministerial_classes.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `body_id` → `governance_bodies.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `department_id` → `department_instances.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_invitees

Pessoa convocada, incluindo convidado externo; lista congelada mantém evidência.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Pessoa convocada, incluindo convidado externo; lista congelada mantém evidência; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| list_id | BIGINT UNSIGNED | não | nenhum | não | event_invitation_lists.id | uq_event_invitees_list_id_person_id | — | list id | 123 | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_event_invitees_list_id_person_id | ix_event_invitees_person_id | person id | 123 | Restrito |
| selection_origin | VARCHAR(64) | não | nenhum | não | — | — | — | CRITERIA, MANUAL, EXTERNAL | EXEMPLO | Restrito |
| eligibility_evidence | JSON | não | nenhum | não | — | — | — | Evidência variável da decisão, sem cópia de BI | {} | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `list_id` → `event_invitation_lists.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_registrations

Participante único por evento; inscrição manual/convidado usa Pessoa.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Inscricao em comprovativo e API de participante.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_event_registrations_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Inscricao em comprovativo e API de participante | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| event_id | BIGINT UNSIGNED | não | nenhum | não | events.id | uq_event_registrations_event_id_person_id | — | event id | 123 | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_event_registrations_event_id_person_id | ix_event_registrations_person_id | person id | 123 | Restrito |
| invitee_id | BIGINT UNSIGNED | sim | NULL | não | event_invitees.id | — | ix_event_registrations_invitee_id | invitee id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `event_id` → `events.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `invitee_id` → `event_invitees.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_invitations

Envios da convocação; múltiplas tentativas não duplicam Pessoa.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Envios da convocação; múltiplas tentativas não duplicam Pessoa; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| invitee_id | BIGINT UNSIGNED | não | nenhum | não | event_invitees.id | — | ix_event_invitations_invitee_id | invitee id | 123 | Restrito |
| message_id | BIGINT UNSIGNED | sim | NULL | não | messages.id | — | ix_event_invitations_message_id | message id | 123 | Restrito |
| sent_at | DATETIME(6) | sim | NULL | não | — | — | — | sent at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `invitee_id` → `event_invitees.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `message_id` → `messages.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_confirmations

Resposta versionada da Pessoa.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Resposta versionada da Pessoa; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| registration_id | BIGINT UNSIGNED | não | nenhum | não | event_registrations.id | uq_event_confirmations_registration_id_version | — | registration id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_event_confirmations_registration_id_version | — | version | 1 | Restrito |
| response | VARCHAR(64) | não | nenhum | não | — | — | — | response | EXEMPLO | Restrito |
| responded_at | DATETIME(6) | não | nenhum | não | — | — | — | responded at | 2026-09-12 10:00:00.000000 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `registration_id` → `event_registrations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## devices

Dispositivos conhecidos para rastrear check-in/sincronização.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Dispositivo referido externamente na sincronizacao e no manifesto manual.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | NAO | AUTO_INCREMENT | SIM | — | — | — | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_devices_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Dispositivo referido externamente na sincronizacao e no manifesto manual | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| registered_by | BIGINT UNSIGNED | NAO | nenhum | NAO | users | — | ix_devices_registered_by | registered by | 123 | Restrito |
| unit_id | BIGINT UNSIGNED | NAO | nenhum | NAO | organizational_units | — | ix_devices_unit_id | unit id | 123 | Restrito |
| status | VARCHAR(64) | NAO | nenhum | NAO | — | — | — | Estado aprovado D-11; device manual por unidade pre-registado segundo protocolo F09 | DRAFT | Restrito |
| created_at | DATETIME(6) | NAO | nenhum; relógio UTC do serviço | NAO | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | NAO | 0 | NAO | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `registered_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_checkins

Entrada nominal transaccional; permanente ou credencial de evento identificada.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Entrada nominal transaccional; permanente ou credencial de evento identificada; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| session_id | BIGINT UNSIGNED | não | nenhum | não | event_sessions.id | uq_event_checkins_session_id_person_id | ix_event_checkins_session_id_checked_at | session id | 123 | Restrito |
| registration_id | BIGINT UNSIGNED | não | nenhum | não | event_registrations.id | — | ix_event_checkins_registration_id | registration id | 123 | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_event_checkins_session_id_person_id | ix_event_checkins_person_id | person id | 123 | Restrito |
| credential_id | BIGINT UNSIGNED | sim | NULL | não | credentials.id | — | ix_event_checkins_credential_id | credential id | 123 | Restrito |
| event_credential_id | BIGINT UNSIGNED | sim | NULL | não | event_credentials.id | — | ix_event_checkins_event_credential_id | event credential id | 123 | Restrito |
| checked_at | DATETIME(6) | não | nenhum | não | — | — | ix_event_checkins_session_id_checked_at | checked at | 2026-09-12 10:00:00.000000 | Restrito |
| device_id | BIGINT UNSIGNED | não | nenhum | não | devices.id | — | ix_event_checkins_device_id | device id | 123 | Restrito |
| actor_id | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_event_checkins_actor_id | actor id | 123 | Restrito |
| idempotency_request_id | BIGINT UNSIGNED | não | nenhum | não | idempotency_requests.id | uq_event_checkins_idempotency_request_id | — | idempotency request id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `session_id` → `event_sessions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `registration_id` → `event_registrations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `credential_id` → `credentials.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `event_credential_id` → `event_credentials.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `device_id` → `devices.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `actor_id` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `idempotency_request_id` → `idempotency_requests.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_credentials

Credenciamento do evento; não substitui credencial MEPA permanente.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Credencial de evento referida na API; QR usa token separado.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_event_credentials_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Credencial de evento referida na API; QR usa token separado | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| registration_id | BIGINT UNSIGNED | não | nenhum | não | event_registrations.id | uq_event_credentials_registration_id_version | — | registration id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_event_credentials_registration_id_version | — | version | 1 | Restrito |
| token_hash | BINARY(32) | não | nenhum | não | — | uq_event_credentials_token_hash | — | token hash | SHA-256, 32 bytes | Restrito |
| issued_at | DATETIME(6) | não | nenhum | não | — | — | — | issued at | 2026-09-12 10:00:00.000000 | Restrito |
| expires_at | DATETIME(6) | não | nenhum | não | — | — | — | expires at | 2026-09-12 10:00:00.000000 | Restrito |
| revoked_at | DATETIME(6) | sim | NULL | não | — | — | — | revoked at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `registration_id` → `event_registrations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_attendance

Presença apurada; entrada e presença têm significados distintos.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Presença apurada; entrada e presença têm significados distintos; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| session_id | BIGINT UNSIGNED | não | nenhum | não | event_sessions.id | uq_event_attendance_session_id_person_id | — | session id | 123 | Restrito |
| registration_id | BIGINT UNSIGNED | não | nenhum | não | event_registrations.id | — | ix_event_attendance_registration_id | registration id | 123 | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_event_attendance_session_id_person_id | ix_event_attendance_person_id | person id | 123 | Restrito |
| checkin_id | BIGINT UNSIGNED | sim | NULL | não | event_checkins.id | — | ix_event_attendance_checkin_id | checkin id | 123 | Restrito |
| attendance_status | VARCHAR(64) | não | nenhum | não | — | — | — | attendance status | EXEMPLO | Restrito |
| recorded_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_event_attendance_recorded_by | recorded by | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `session_id` → `event_sessions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `registration_id` → `event_registrations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `checkin_id` → `event_checkins.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `recorded_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## event_counts

Contagem agregada de culto sem inventar participantes; revisões explícitas.

Domínio: Governança e eventos. Owner lógico: Secretaria / coordenação do evento. Retenção: R-EVENTO. Volume esperado: Até 100 mil eventos; 10 milhões check-ins/presenças. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Contagem agregada de culto sem inventar participantes; revisões explícitas; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| session_id | BIGINT UNSIGNED | não | nenhum | não | event_sessions.id | uq_event_counts_session_id_version | — | session id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_event_counts_session_id_version | — | version | 1 | Restrito |
| count | INT UNSIGNED | não | nenhum | não | — | — | — | count | 1 | Restrito |
| method | VARCHAR(64) | não | nenhum | não | — | — | — | method | EXEMPLO | Restrito |
| approved_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_event_counts_approved_by | approved by | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `session_id` → `event_sessions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## child_profiles

Perfil infantil da Pessoa sem tabela paralela de identidades.

Domínio: Crianças. Owner lógico: Coordenação infantil autorizada. Retenção: R-MENOR. Volume esperado: Até 150 mil menores; 5 milhões de recolhas/presenças. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Perfil infantil da Pessoa sem tabela paralela de identidades; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_child_profiles_person_id | — | person id | 123 | Altamente sensível |
| owner_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_child_profiles_owner_unit_id | owner unit id | 123 | Altamente sensível |
| support_notes_ciphertext | VARBINARY(2048) | sim | NULL | não | — | — | — | support notes ciphertext | EXEMPLO | Altamente sensível |
| key_version | SMALLINT UNSIGNED | sim | NULL | não | — | — | — | key version | 1 | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `owner_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## guardian_authorizations

Responsável Pessoa autorizado para recolha; parentesco não autoriza por si só.

Domínio: Crianças. Owner lógico: Coordenação infantil autorizada. Retenção: R-MENOR. Volume esperado: Até 150 mil menores; 5 milhões de recolhas/presenças. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Autorizacao de recolha em documento/API restrita.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_guardian_authorizations_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Autorizacao de recolha em documento/API restrita | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Altamente sensível |
| child_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_guardian_authorizations_child_person_id_starts_at | child person id | 123 | Altamente sensível |
| guardian_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_guardian_authorizations_guardian_person_id | guardian person id | 123 | Altamente sensível |
| relationship_id | BIGINT UNSIGNED | sim | NULL | não | person_relationships.id | — | ix_guardian_authorizations_relationship_id | relationship id | 123 | Altamente sensível |
| authorization_kind | VARCHAR(64) | não | nenhum | não | — | — | — | authorization kind | EXEMPLO | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_guardian_authorizations_child_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_guardian_authorizations_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `child_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `guardian_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `relationship_id` → `person_relationships.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## person_consents

Consentimento por finalidade e responsável, com revogação e evidência.

Domínio: Crianças. Owner lógico: Coordenação infantil autorizada. Retenção: R-MENOR. Volume esperado: Até 150 mil menores; 5 milhões de recolhas/presenças. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Consentimento em comprovativo e pedidos do titular.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_person_consents_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Consentimento em comprovativo e pedidos do titular | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Altamente sensível |
| subject_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_consents_subject_person_id | subject person id | 123 | Altamente sensível |
| given_by_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_person_consents_given_by_person_id | given by person id | 123 | Altamente sensível |
| purpose | VARCHAR(64) | não | nenhum | não | — | — | — | purpose | EXEMPLO | Altamente sensível |
| policy_version | VARCHAR(64) | não | nenhum | não | — | — | — | policy version | EXEMPLO | Altamente sensível |
| granted_at | DATETIME(6) | não | nenhum | não | — | — | — | granted at | 2026-09-12 10:00:00.000000 | Altamente sensível |
| revoked_at | DATETIME(6) | sim | NULL | não | — | — | — | revoked at | 2026-09-12 10:00:00.000000 | Altamente sensível |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_person_consents_document_id | document id | 123 | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `subject_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `given_by_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## child_emergency_contacts

Contacto de emergência é uma Pessoa e respectivo contacto.

Domínio: Crianças. Owner lógico: Coordenação infantil autorizada. Retenção: R-MENOR. Volume esperado: Até 150 mil menores; 5 milhões de recolhas/presenças. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Contacto de emergência é uma Pessoa e respectivo contacto; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| child_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_child_emergency_contacts_child_person_id | child person id | 123 | Altamente sensível |
| contact_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_child_emergency_contacts_contact_person_id | contact person id | 123 | Altamente sensível |
| contact_id | BIGINT UNSIGNED | não | nenhum | não | person_contacts.id | — | ix_child_emergency_contacts_contact_id | contact id | 123 | Altamente sensível |
| priority | SMALLINT UNSIGNED | não | nenhum | não | — | — | — | priority | 1 | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `child_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `contact_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `contact_id` → `person_contacts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## child_custody_visits

Ciclo de entrega/recolha; quem entrega e recolhe fica identificado.

Domínio: Crianças. Owner lógico: Coordenação infantil autorizada. Retenção: R-MENOR. Volume esperado: Até 150 mil menores; 5 milhões de recolhas/presenças. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Ciclo de entrega/recolha; quem entrega e recolhe fica identificado; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| session_id | BIGINT UNSIGNED | não | nenhum | não | event_sessions.id | — | ix_child_custody_visits_session_id_child_person_id_checked_in_at | session id | 123 | Altamente sensível |
| child_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_child_custody_visits_session_id_child_person_id_checked_in_at, ix_child_custody_visits_child_person_id | child person id | 123 | Altamente sensível |
| delivered_by_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_child_custody_visits_delivered_by_person_id | delivered by person id | 123 | Altamente sensível |
| authorization_id | BIGINT UNSIGNED | não | nenhum | não | guardian_authorizations.id | — | ix_child_custody_visits_authorization_id | authorization id | 123 | Altamente sensível |
| checked_in_at | DATETIME(6) | não | nenhum | não | — | — | ix_child_custody_visits_session_id_child_person_id_checked_in_at | checked in at | 2026-09-12 10:00:00.000000 | Altamente sensível |
| checked_in_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_child_custody_visits_checked_in_by | checked in by | 123 | Altamente sensível |
| collected_by_person_id | BIGINT UNSIGNED | sim | NULL | não | people.id | — | ix_child_custody_visits_collected_by_person_id | collected by person id | 123 | Altamente sensível |
| collection_authorization_id | BIGINT UNSIGNED | sim | NULL | não | guardian_authorizations.id | — | ix_child_custody_visits_collection_authorization_id | collection authorization id | 123 | Altamente sensível |
| checked_out_at | DATETIME(6) | sim | NULL | não | — | — | — | checked out at | 2026-09-12 10:00:00.000000 | Altamente sensível |
| checked_out_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_child_custody_visits_checked_out_by | checked out by | 123 | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `session_id` → `event_sessions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `child_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `delivered_by_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `authorization_id` → `guardian_authorizations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `checked_in_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `collected_by_person_id` → `people.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `collection_authorization_id` → `guardian_authorizations.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `checked_out_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## age_band_rules

Faixas oficiais versionadas; catálogo e limites aguardam decisão.

Domínio: Crianças. Owner lógico: Coordenação infantil autorizada. Retenção: R-MENOR. Volume esperado: Até 150 mil menores; 5 milhões de recolhas/presenças. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Faixas oficiais versionadas; catálogo e limites aguardam decisão; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| department_id | BIGINT UNSIGNED | não | nenhum | não | departments.id | uq_age_band_rules_department_id_version | — | department id | 123 | Altamente sensível |
| version | INT UNSIGNED | não | nenhum | não | — | uq_age_band_rules_department_id_version | — | version | 1 | Altamente sensível |
| min_age_months | INT UNSIGNED | não | nenhum | não | — | — | — | min age months | 1 | Altamente sensível |
| max_age_months | INT UNSIGNED | sim | NULL | não | — | — | — | max age months | 1 | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `department_id` → `departments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## department_transition_recommendations

Recomendação de mudança por faixa; decisão humana necessária.

Domínio: Crianças. Owner lógico: Coordenação infantil autorizada. Retenção: R-MENOR. Volume esperado: Até 150 mil menores; 5 milhões de recolhas/presenças. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Recomendação de mudança por faixa; decisão humana necessária; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_department_transition_recommendations_person_id | person id | 123 | Altamente sensível |
| from_instance_id | BIGINT UNSIGNED | não | nenhum | não | department_instances.id | — | ix_department_transition_recommendations_from_instance_id | from instance id | 123 | Altamente sensível |
| to_instance_id | BIGINT UNSIGNED | não | nenhum | não | department_instances.id | — | ix_department_transition_recommendations_to_instance_id | to instance id | 123 | Altamente sensível |
| rule_id | BIGINT UNSIGNED | não | nenhum | não | age_band_rules.id | — | ix_department_transition_recommendations_rule_id | rule id | 123 | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| decided_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_department_transition_recommendations_decided_by | decided by | 123 | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `from_instance_id` → `department_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `to_instance_id` → `department_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `rule_id` → `age_band_rules.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `decided_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## outreach_campaigns

Campanha evangelística da unidade.

Domínio: Evangelismo. Owner lógico: Evangelismo autorizado. Retenção: R-PESSOA. Volume esperado: 500 mil contactos; 3 milhões acompanhamentos. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Campanha em recurso autorizado de evangelismo.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_outreach_campaigns_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Campanha em recurso autorizado de evangelismo | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_outreach_campaigns_unit_id | unit id | 123 | Confidencial |
| event_id | BIGINT UNSIGNED | sim | NULL | não | events.id | — | ix_outreach_campaigns_event_id | event id | 123 | Confidencial |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Confidencial |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | — | starts at | 2026-09-12 10:00:00.000000 | Confidencial |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | ends at | 2026-09-12 10:00:00.000000 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `event_id` → `events.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## outreach_contacts

Contacto evangelístico reutiliza Pessoa.

Domínio: Evangelismo. Owner lógico: Evangelismo autorizado. Retenção: R-PESSOA. Volume esperado: 500 mil contactos; 3 milhões acompanhamentos. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Contacto evangelístico reutiliza Pessoa; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| campaign_id | BIGINT UNSIGNED | não | nenhum | não | outreach_campaigns.id | uq_outreach_contacts_campaign_id_person_id | — | campaign id | 123 | Confidencial |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_outreach_contacts_campaign_id_person_id | ix_outreach_contacts_person_id | person id | 123 | Confidencial |
| assigned_to_person_id | BIGINT UNSIGNED | sim | NULL | não | people.id | — | ix_outreach_contacts_assigned_to_person_id | assigned to person id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `campaign_id` → `outreach_campaigns.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `assigned_to_person_id` → `people.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## followups

Acompanhamentos com acesso reservado.

Domínio: Evangelismo. Owner lógico: Evangelismo autorizado. Retenção: R-PESSOA. Volume esperado: 500 mil contactos; 3 milhões acompanhamentos. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Acompanhamentos com acesso reservado; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| contact_id | BIGINT UNSIGNED | não | nenhum | não | outreach_contacts.id | — | ix_followups_contact_id_occurred_at | contact id | 123 | Confidencial |
| performed_by_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_followups_performed_by_person_id | performed by person id | 123 | Confidencial |
| occurred_at | DATETIME(6) | não | nenhum | não | — | — | ix_followups_contact_id_occurred_at | occurred at | 2026-09-12 10:00:00.000000 | Confidencial |
| outcome | VARCHAR(64) | não | nenhum | não | — | — | — | outcome | EXEMPLO | Confidencial |
| notes_ciphertext | VARBINARY(2048) | sim | NULL | não | — | — | — | notes ciphertext | EXEMPLO | Confidencial |
| key_version | SMALLINT UNSIGNED | sim | NULL | não | — | — | — | key version | 1 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `contact_id` → `outreach_contacts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `performed_by_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## decisions

Decisão/conversão não cria membership automaticamente.

Domínio: Evangelismo. Owner lógico: Evangelismo autorizado. Retenção: R-PESSOA. Volume esperado: 500 mil contactos; 3 milhões acompanhamentos. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Decisão/conversão não cria membership automaticamente; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_decisions_person_id | person id | 123 | Confidencial |
| contact_id | BIGINT UNSIGNED | sim | NULL | não | outreach_contacts.id | — | ix_decisions_contact_id | contact id | 123 | Confidencial |
| decision_type | VARCHAR(64) | não | nenhum | não | — | — | — | decision type | EXEMPLO | Confidencial |
| occurred_on | DATE | sim | NULL | não | — | — | — | occurred on | 2026-09-12 | Confidencial |
| date_precision | VARCHAR(64) | não | nenhum | não | — | — | — | date precision | EXEMPLO | Confidencial |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_decisions_document_id | document id | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `contact_id` → `outreach_contacts.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## discipleship_tracks

Percurso de discipulado versionado.

Domínio: Evangelismo. Owner lógico: Evangelismo autorizado. Retenção: R-PESSOA. Volume esperado: 500 mil contactos; 3 milhões acompanhamentos. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Percurso de discipulado versionado; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_discipleship_tracks_code_version | — | code | CAT_EXEMPLO | Confidencial |
| version | INT UNSIGNED | não | nenhum | não | — | uq_discipleship_tracks_code_version | — | version | 1 | Confidencial |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## discipleship_steps

Etapas normalizadas do percurso.

Domínio: Evangelismo. Owner lógico: Evangelismo autorizado. Retenção: R-PESSOA. Volume esperado: 500 mil contactos; 3 milhões acompanhamentos. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Etapas normalizadas do percurso; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| track_id | BIGINT UNSIGNED | não | nenhum | não | discipleship_tracks.id | uq_discipleship_steps_track_id_sequence | — | track id | 123 | Confidencial |
| sequence | INT UNSIGNED | não | nenhum | não | — | uq_discipleship_steps_track_id_sequence | — | sequence | 1 | Confidencial |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Confidencial |
| course_id | BIGINT UNSIGNED | sim | NULL | não | courses.id | — | ix_discipleship_steps_course_id | course id | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `track_id` → `discipleship_tracks.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `course_id` → `courses.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## discipleship_enrollments

Pessoa em percurso, membro ou externo.

Domínio: Evangelismo. Owner lógico: Evangelismo autorizado. Retenção: R-PESSOA. Volume esperado: 500 mil contactos; 3 milhões acompanhamentos. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Inscricao em portal/API de discipulado.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_discipleship_enrollments_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Inscricao em portal/API de discipulado | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_discipleship_enrollments_person_id_starts_at | person id | 123 | Confidencial |
| track_id | BIGINT UNSIGNED | não | nenhum | não | discipleship_tracks.id | — | ix_discipleship_enrollments_track_id | track id | 123 | Confidencial |
| mentor_person_id | BIGINT UNSIGNED | sim | NULL | não | people.id | — | ix_discipleship_enrollments_mentor_person_id | mentor person id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_discipleship_enrollments_person_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_discipleship_enrollments_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `track_id` → `discipleship_tracks.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `mentor_person_id` → `people.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## discipleship_progress

Evidência de conclusão de etapa.

Domínio: Evangelismo. Owner lógico: Evangelismo autorizado. Retenção: R-PESSOA. Volume esperado: 500 mil contactos; 3 milhões acompanhamentos. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Evidência de conclusão de etapa; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| enrollment_id | BIGINT UNSIGNED | não | nenhum | não | discipleship_enrollments.id | uq_discipleship_progress_enrollment_id_step_id | — | enrollment id | 123 | Confidencial |
| step_id | BIGINT UNSIGNED | não | nenhum | não | discipleship_steps.id | uq_discipleship_progress_enrollment_id_step_id | ix_discipleship_progress_step_id | step id | 123 | Confidencial |
| completed_at | DATETIME(6) | sim | NULL | não | — | — | — | completed at | 2026-09-12 10:00:00.000000 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `enrollment_id` → `discipleship_enrollments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `step_id` → `discipleship_steps.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## integration_events

Integração documentada, ligada ao workflow de membro quando aplicável.

Domínio: Evangelismo. Owner lógico: Evangelismo autorizado. Retenção: R-PESSOA. Volume esperado: 500 mil contactos; 3 milhões acompanhamentos. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Integração documentada, ligada ao workflow de membro quando aplicável; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_integration_events_person_id | person id | 123 | Confidencial |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_integration_events_unit_id | unit id | 123 | Confidencial |
| membership_id | BIGINT UNSIGNED | sim | NULL | não | memberships.id | — | ix_integration_events_membership_id | membership id | 123 | Confidencial |
| occurred_at | DATETIME(6) | não | nenhum | não | — | — | — | occurred at | 2026-09-12 10:00:00.000000 | Confidencial |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_integration_events_document_id | document id | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `membership_id` → `memberships.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## academic_units

EBD, IBT, RH e unidades académicas aprovadas.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: EBD, IBT, RH e unidades académicas aprovadas; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_academic_units_unit_id | unit id | 123 | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_academic_units_code | — | code | CAT_EXEMPLO | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## programs

Programa académico.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Programa em URL do catalogo academico.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_programs_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Programa em URL do catalogo academico | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| academic_unit_id | BIGINT UNSIGNED | não | nenhum | não | academic_units.id | — | ix_programs_academic_unit_id | academic unit id | 123 | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_programs_code | — | code | CAT_EXEMPLO | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `academic_unit_id` → `academic_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## courses

Curso reutilizável em programas; elegibilidade é política versionada.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Curso em URL de catalogo e API academica.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_courses_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Curso em URL de catalogo e API academica | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_courses_code | — | code | CAT_EXEMPLO | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| eligibility_policy_version | VARCHAR(64) | não | nenhum | não | — | — | — | eligibility policy version | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## curricula

Versão de currículo do programa, imutável após uso.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Versão de currículo do programa, imutável após uso; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| program_id | BIGINT UNSIGNED | não | nenhum | não | programs.id | uq_curricula_program_id_version | — | program id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_curricula_program_id_version | — | version | 1 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| published_at | DATETIME(6) | sim | NULL | não | — | — | — | published at | 2026-09-12 10:00:00.000000 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `program_id` → `programs.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## curriculum_courses

Cursos ordenados e requisitos do currículo.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Cursos ordenados e requisitos do currículo; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| curriculum_id | BIGINT UNSIGNED | não | nenhum | não | curricula.id | uq_curriculum_courses_curriculum_id_course_id, uq_curriculum_courses_curriculum_id_sequence | — | curriculum id | 123 | Restrito |
| course_id | BIGINT UNSIGNED | não | nenhum | não | courses.id | uq_curriculum_courses_curriculum_id_course_id | ix_curriculum_courses_course_id | course id | 123 | Restrito |
| sequence | INT UNSIGNED | não | nenhum | não | — | uq_curriculum_courses_curriculum_id_sequence | — | sequence | 1 | Restrito |
| required | TINYINT UNSIGNED | não | nenhum | não | — | — | — | required | 1 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `curriculum_id` → `curricula.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `course_id` → `courses.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## course_versions

Conteúdo do curso congelado por versão.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Conteúdo do curso congelado por versão; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| course_id | BIGINT UNSIGNED | não | nenhum | não | courses.id | uq_course_versions_course_id_version | — | course id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_course_versions_course_id_version | — | version | 1 | Restrito |
| completion_policy_metadata | JSON | não | nenhum | não | — | — | — | Parâmetros variáveis de critérios aprovados | {} | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `course_id` → `courses.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## course_prerequisites

Pré-requisitos entre cursos; sem ciclos.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Pré-requisitos entre cursos; sem ciclos; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| course_id | BIGINT UNSIGNED | não | nenhum | não | courses.id | uq_course_prerequisites_course_id_required_course_id | — | course id | 123 | Restrito |
| required_course_id | BIGINT UNSIGNED | não | nenhum | não | courses.id | uq_course_prerequisites_course_id_required_course_id | ix_course_prerequisites_required_course_id | required course id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `course_id` → `courses.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `required_course_id` → `courses.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## course_modules

Módulos da versão do curso.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Módulos da versão do curso; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| course_version_id | BIGINT UNSIGNED | não | nenhum | não | course_versions.id | uq_course_modules_course_version_id_sequence | — | course version id | 123 | Restrito |
| sequence | INT UNSIGNED | não | nenhum | não | — | uq_course_modules_course_version_id_sequence | — | sequence | 1 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `course_version_id` → `course_versions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## lessons

Aulas de conteúdo; não confundir com sessão lectiva de turma.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Aulas de conteúdo; não confundir com sessão lectiva de turma; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| module_id | BIGINT UNSIGNED | não | nenhum | não | course_modules.id | uq_lessons_module_id_sequence | — | module id | 123 | Restrito |
| sequence | INT UNSIGNED | não | nenhum | não | — | uq_lessons_module_id_sequence | — | sequence | 1 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| required | TINYINT UNSIGNED | não | nenhum | não | — | — | — | required | 1 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `module_id` → `course_modules.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## resources

Metadados de vídeo externo ou material privado.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Material em URL de acesso autorizado.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_resources_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Material em URL de acesso autorizado | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| resource_kind | VARCHAR(64) | não | nenhum | não | — | — | — | resource kind | EXEMPLO | Restrito |
| provider | VARCHAR(64) | sim | NULL | não | — | — | — | provider | EXEMPLO | Restrito |
| external_id | VARCHAR(191) | sim | NULL | não | — | — | — | external id | EXEMPLO | Restrito |
| external_url | VARCHAR(191) | sim | NULL | não | — | — | — | external url | EXEMPLO | Restrito |
| file_id | BIGINT UNSIGNED | sim | NULL | não | files.id | — | ix_resources_file_id | file id | 123 | Restrito |
| duration_seconds | INT UNSIGNED | sim | NULL | não | — | — | — | duration seconds | 1 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `file_id` → `files.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## lesson_resources

Recursos da aula por ordem.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Recursos da aula por ordem; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| lesson_id | BIGINT UNSIGNED | não | nenhum | não | lessons.id | uq_lesson_resources_lesson_id_resource_id, uq_lesson_resources_lesson_id_sequence | — | lesson id | 123 | Restrito |
| resource_id | BIGINT UNSIGNED | não | nenhum | não | resources.id | uq_lesson_resources_lesson_id_resource_id | ix_lesson_resources_resource_id | resource id | 123 | Restrito |
| sequence | INT UNSIGNED | não | nenhum | não | — | uq_lesson_resources_lesson_id_sequence | — | sequence | 1 | Restrito |
| required | TINYINT UNSIGNED | não | nenhum | não | — | — | — | required | 1 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `lesson_id` → `lessons.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `resource_id` → `resources.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## cohorts

Coorte do programa/currículo.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Coorte em portal/API academica.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_cohorts_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Coorte em portal/API academica | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| academic_unit_id | BIGINT UNSIGNED | não | nenhum | não | academic_units.id | — | ix_cohorts_academic_unit_id | academic unit id | 123 | Restrito |
| curriculum_id | BIGINT UNSIGNED | não | nenhum | não | curricula.id | — | ix_cohorts_curriculum_id | curriculum id | 123 | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_cohorts_code | — | code | CAT_EXEMPLO | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | — | starts at | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | ends at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `academic_unit_id` → `academic_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `curriculum_id` → `curricula.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## classes

Turma de uma versão do curso, opcionalmente numa coorte.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Turma em portal/API academica.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_classes_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Turma em portal/API academica | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| cohort_id | BIGINT UNSIGNED | sim | NULL | não | cohorts.id | — | ix_classes_cohort_id | cohort id | 123 | Restrito |
| academic_unit_id | BIGINT UNSIGNED | não | nenhum | não | academic_units.id | — | ix_classes_academic_unit_id | academic unit id | 123 | Restrito |
| course_version_id | BIGINT UNSIGNED | não | nenhum | não | course_versions.id | — | ix_classes_course_version_id | course version id | 123 | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_classes_code | — | code | CAT_EXEMPLO | Restrito |
| capacity | INT UNSIGNED | sim | NULL | não | — | — | — | capacity | 1 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `cohort_id` → `cohorts.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `academic_unit_id` → `academic_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `course_version_id` → `course_versions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## instructors

Professor reutiliza Pessoa; perfil não exige membership.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Professor reutiliza Pessoa; perfil não exige membership; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_instructors_person_id | — | person id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## class_instructors

Professor atribuído à turma por período.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Professor atribuído à turma por período; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| class_id | BIGINT UNSIGNED | não | nenhum | não | classes.id | — | ix_class_instructors_class_id_starts_at | class id | 123 | Restrito |
| instructor_id | BIGINT UNSIGNED | não | nenhum | não | instructors.id | — | ix_class_instructors_instructor_id | instructor id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_class_instructors_class_id_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_class_instructors_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `class_id` → `classes.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `instructor_id` → `instructors.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## enrollments

Matrícula directa Pessoa+turma; aluno externo não exige membership.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Matricula em portal/API e comprovativo externo.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_enrollments_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Matricula em portal/API e comprovativo externo | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_enrollments_person_id_class_id | — | person id | 123 | Restrito |
| class_id | BIGINT UNSIGNED | não | nenhum | não | classes.id | uq_enrollments_person_id_class_id | ix_enrollments_class_id_status | class id | 123 | Restrito |
| enrolled_at | DATETIME(6) | não | nenhum | não | — | — | — | enrolled at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_enrollments_class_id_status | status | DRAFT | Restrito |
| approved_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_enrollments_approved_by | approved by | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `class_id` → `classes.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## class_sessions

Sessão lectiva concreta da turma.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Sessão lectiva concreta da turma; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| class_id | BIGINT UNSIGNED | não | nenhum | não | classes.id | — | ix_class_sessions_class_id_starts_at | class id | 123 | Restrito |
| lesson_id | BIGINT UNSIGNED | sim | NULL | não | lessons.id | — | ix_class_sessions_lesson_id | lesson id | 123 | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_class_sessions_class_id_starts_at | starts at | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | não | nenhum | não | — | — | — | ends at | 2026-09-12 10:00:00.000000 | Restrito |
| event_session_id | BIGINT UNSIGNED | sim | NULL | não | event_sessions.id | — | ix_class_sessions_event_session_id | event session id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `class_id` → `classes.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `lesson_id` → `lessons.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `event_session_id` → `event_sessions.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## academic_attendance

Presença por matrícula e sessão lectiva, sem duplicação de eventos.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Presença por matrícula e sessão lectiva, sem duplicação de eventos; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| enrollment_id | BIGINT UNSIGNED | não | nenhum | não | enrollments.id | uq_academic_attendance_enrollment_id_class_session_id | — | enrollment id | 123 | Restrito |
| class_session_id | BIGINT UNSIGNED | não | nenhum | não | class_sessions.id | uq_academic_attendance_enrollment_id_class_session_id | ix_academic_attendance_class_session_id | class session id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| recorded_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_academic_attendance_recorded_by | recorded by | 123 | Restrito |
| recorded_at | DATETIME(6) | não | nenhum | não | — | — | — | recorded at | 2026-09-12 10:00:00.000000 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `enrollment_id` → `enrollments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `class_session_id` → `class_sessions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `recorded_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## assessments

Avaliação da versão do curso e limites aprovados.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Avaliacao em URL de portal academico.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_assessments_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Avaliacao em URL de portal academico | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| course_version_id | BIGINT UNSIGNED | não | nenhum | não | course_versions.id | — | ix_assessments_course_version_id | course version id | 123 | Restrito |
| lesson_id | BIGINT UNSIGNED | sim | NULL | não | lessons.id | — | ix_assessments_lesson_id | lesson id | 123 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| max_score | DECIMAL(9,4) | não | nenhum | não | — | — | — | max score | 1.0000 | Restrito |
| pass_score | DECIMAL(9,4) | não | nenhum | não | — | — | — | pass score | 1.0000 | Restrito |
| max_attempts | INT UNSIGNED | não | nenhum | não | — | — | — | max attempts | 1 | Restrito |
| weight | DECIMAL(7,4) | não | nenhum | não | — | — | — | weight | 1.0000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `course_version_id` → `course_versions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `lesson_id` → `lessons.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## assessment_attempts

Tentativas numeradas da matrícula.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Tentativas numeradas da matrícula; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| assessment_id | BIGINT UNSIGNED | não | nenhum | não | assessments.id | uq_assess_attempt_enrollment_number | — | assessment id | 123 | Restrito |
| enrollment_id | BIGINT UNSIGNED | não | nenhum | não | enrollments.id | uq_assess_attempt_enrollment_number | ix_assessment_attempts_enrollment_id | enrollment id | 123 | Restrito |
| attempt_number | INT UNSIGNED | não | nenhum | não | — | uq_assess_attempt_enrollment_number | — | attempt number | 1 | Restrito |
| started_at | DATETIME(6) | não | nenhum | não | — | — | — | started at | 2026-09-12 10:00:00.000000 | Restrito |
| submitted_at | DATETIME(6) | sim | NULL | não | — | — | — | submitted at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| answers_metadata | JSON | sim | NULL | não | — | — | — | Respostas variáveis, privadas e versionadas | {} | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `assessment_id` → `assessments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `enrollment_id` → `enrollments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## grades

Nota versionada e homologação por tentativa.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Nota versionada e homologação por tentativa; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| attempt_id | BIGINT UNSIGNED | não | nenhum | não | assessment_attempts.id | uq_grades_attempt_id_version | — | attempt id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_grades_attempt_id_version | — | version | 1 | Restrito |
| score | DECIMAL(9,4) | não | nenhum | não | — | — | — | score | 1.0000 | Restrito |
| graded_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_grades_graded_by | graded by | 123 | Restrito |
| graded_at | DATETIME(6) | não | nenhum | não | — | — | — | graded at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | reason | Decisao documentada | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `attempt_id` → `assessment_attempts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `graded_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## progress

Progresso derivado de evidências; cache reproduzível, não diploma por si só.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Progresso derivado de evidências; cache reproduzível, não diploma por si só; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| enrollment_id | BIGINT UNSIGNED | não | nenhum | não | enrollments.id | uq_progress_enrollment_id_lesson_id | — | enrollment id | 123 | Restrito |
| lesson_id | BIGINT UNSIGNED | não | nenhum | não | lessons.id | uq_progress_enrollment_id_lesson_id | ix_progress_lesson_id | lesson id | 123 | Restrito |
| completed_at | DATETIME(6) | sim | NULL | não | — | — | — | completed at | 2026-09-12 10:00:00.000000 | Restrito |
| completion_ratio | DECIMAL(7,4) | não | nenhum | não | — | — | — | completion ratio | 1.0000 | Restrito |
| source_version | VARCHAR(64) | não | nenhum | não | — | — | — | source version | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `enrollment_id` → `enrollments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `lesson_id` → `lessons.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## resource_progress

Evidências de acesso/vídeo por matrícula e recurso.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Evidências de acesso/vídeo por matrícula e recurso; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| enrollment_id | BIGINT UNSIGNED | não | nenhum | não | enrollments.id | uq_resource_progress_enrollment_id_resource_id | — | enrollment id | 123 | Restrito |
| resource_id | BIGINT UNSIGNED | não | nenhum | não | resources.id | uq_resource_progress_enrollment_id_resource_id | ix_resource_progress_resource_id | resource id | 123 | Restrito |
| watched_seconds | INT UNSIGNED | não | nenhum | não | — | — | — | watched seconds | 1 | Restrito |
| verified_at | DATETIME(6) | sim | NULL | não | — | — | — | verified at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `enrollment_id` → `enrollments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `resource_id` → `resources.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## certificates

Certificado versionado após homologação; QR mínimo, revogação preservada.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Certificado em documento externo e validacao autorizada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| enrollment_id | BIGINT UNSIGNED | não | nenhum | não | enrollments.id | uq_certificates_enrollment_id_version | — | enrollment id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_certificates_enrollment_id_version | — | version | 1 | Restrito |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_certificates_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Certificado em documento externo e validacao autorizada | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| token_hash | BINARY(32) | não | nenhum | não | — | uq_certificates_token_hash | — | token hash | SHA-256, 32 bytes | Restrito |
| issued_at | DATETIME(6) | não | nenhum | não | — | — | — | issued at | 2026-09-12 10:00:00.000000 | Restrito |
| approved_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_certificates_approved_by | approved by | 123 | Restrito |
| file_id | BIGINT UNSIGNED | não | nenhum | não | files.id | — | ix_certificates_file_id | file id | 123 | Restrito |
| revoked_at | DATETIME(6) | sim | NULL | não | — | — | — | revoked at | 2026-09-12 10:00:00.000000 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `enrollment_id` → `enrollments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `file_id` → `files.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## transcripts

Histórico oficial emitido e congelado por Pessoa/currículo.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Historico oficial emitido e referenciado externamente.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_transcripts_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Historico oficial emitido e referenciado externamente | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_transcripts_person_id_curriculum_id_version | — | person id | 123 | Restrito |
| curriculum_id | BIGINT UNSIGNED | não | nenhum | não | curricula.id | uq_transcripts_person_id_curriculum_id_version | ix_transcripts_curriculum_id | curriculum id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_transcripts_person_id_curriculum_id_version | — | version | 1 | Restrito |
| issued_at | DATETIME(6) | não | nenhum | não | — | — | — | issued at | 2026-09-12 10:00:00.000000 | Restrito |
| file_id | BIGINT UNSIGNED | não | nenhum | não | files.id | — | ix_transcripts_file_id | file id | 123 | Restrito |
| approved_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_transcripts_approved_by | approved by | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `curriculum_id` → `curricula.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `file_id` → `files.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## transcript_lines

Resultados históricos do transcript, referenciando versões de notas/certificados.

Domínio: Academia. Owner lógico: MEPA Academia. Retenção: R-ACADEMIA. Volume esperado: Até 300 mil matrículas; 10 milhões presenças/tentativas. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Resultados históricos do transcript, referenciando versões de notas/certificados; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| transcript_id | BIGINT UNSIGNED | não | nenhum | não | transcripts.id | uq_transcript_lines_transcript_id_enrollment_id | — | transcript id | 123 | Restrito |
| enrollment_id | BIGINT UNSIGNED | não | nenhum | não | enrollments.id | uq_transcript_lines_transcript_id_enrollment_id | ix_transcript_lines_enrollment_id | enrollment id | 123 | Restrito |
| certificate_id | BIGINT UNSIGNED | sim | NULL | não | certificates.id | — | ix_transcript_lines_certificate_id | certificate id | 123 | Restrito |
| final_score | DECIMAL(9,4) | sim | NULL | não | — | — | — | final score | 1.0000 | Restrito |
| result_status | VARCHAR(64) | não | nenhum | não | — | — | — | result status | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `transcript_id` → `transcripts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `enrollment_id` → `enrollments.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `certificate_id` → `certificates.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## currencies

Catálogo de moeda e escala de arredondamento aprovada.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Catálogo de moeda e escala de arredondamento aprovada; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_currencies_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## accounting_periods

Períodos de fecho contabilístico nacional.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Períodos de fecho contabilístico nacional; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| starts_on | DATE | não | nenhum | não | — | uq_accounting_periods_starts_on_ends_on | — | starts on | 2026-09-12 | Confidencial |
| ends_on | DATE | não | nenhum | não | — | uq_accounting_periods_starts_on_ends_on | — | ends on | 2026-09-12 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | OPEN, CLOSED | DRAFT | Confidencial |
| closed_at | DATETIME(6) | sim | NULL | não | — | — | — | closed at | 2026-09-12 10:00:00.000000 | Confidencial |
| closed_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_accounting_periods_closed_by | closed by | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `closed_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## chart_of_accounts

Plano de contas contabilístico hierárquico nacional.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Plano de contas contabilístico hierárquico nacional; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| parent_id | BIGINT UNSIGNED | sim | NULL | não | chart_of_accounts.id | — | ix_chart_of_accounts_parent_id | parent id | 123 | Confidencial |
| code | VARCHAR(64) | não | nenhum | não | — | uq_chart_of_accounts_code | — | code | CAT_EXEMPLO | Confidencial |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Confidencial |
| account_kind | VARCHAR(64) | não | nenhum | não | — | — | — | ASSET, LIABILITY, EQUITY, INCOME, EXPENSE | EXEMPLO | Confidencial |
| normal_side | VARCHAR(64) | não | nenhum | não | — | — | — | DEBIT ou CREDIT | EXEMPLO | Confidencial |
| postable | TINYINT UNSIGNED | não | nenhum | não | — | — | — | postable | 1 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `parent_id` → `chart_of_accounts.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## accounts

Conta financeira caixa/banco da unidade associada ao plano.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Conta financeira caixa/banco da unidade associada ao plano; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | uq_accounts_unit_id_code | ix_accounts_unit_id_status | unit id | 123 | Confidencial |
| ledger_account_id | BIGINT UNSIGNED | não | nenhum | não | chart_of_accounts.id | — | ix_accounts_ledger_account_id | ledger account id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_accounts_currency_id | currency id | 123 | Confidencial |
| code | VARCHAR(64) | não | nenhum | não | — | uq_accounts_unit_id_code | — | code | CAT_EXEMPLO | Confidencial |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Confidencial |
| account_kind | VARCHAR(64) | não | nenhum | não | — | — | — | CASH ou BANK | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_accounts_unit_id_status | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `ledger_account_id` → `chart_of_accounts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## bank_account_details

Dados bancários reservados, sem secrets de autenticação.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Dados bancários reservados, sem secrets de autenticação; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| account_id | BIGINT UNSIGNED | não | nenhum | não | accounts.id | uq_bank_account_details_account_id | — | account id | 123 | Confidencial |
| bank_name | VARCHAR(191) | não | nenhum | não | — | — | — | bank name | EXEMPLO | Confidencial |
| account_number_ciphertext | VARBINARY(2048) | não | nenhum | não | — | — | — | account number ciphertext | EXEMPLO | Confidencial |
| key_version | SMALLINT UNSIGNED | não | nenhum | não | — | — | — | key version | 1 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `account_id` → `accounts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## cash_registers

Caixa físico/responsável da conta financeira.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Caixa físico/responsável da conta financeira; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| account_id | BIGINT UNSIGNED | não | nenhum | não | accounts.id | uq_cash_registers_account_id | — | account id | 123 | Confidencial |
| custodian_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_cash_registers_custodian_person_id | custodian person id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `account_id` → `accounts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `custodian_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## funds

Fundo e restrição de utilização nacional.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Fundo e restrição de utilização nacional; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_funds_code | — | code | CAT_EXEMPLO | Confidencial |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Confidencial |
| restriction_kind | VARCHAR(64) | não | nenhum | não | — | — | — | restriction kind | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## financial_categories

Rubricas catálogo, não nomes de coluna; classificação inicial sujeita a auditoria.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Rubricas catálogo, não nomes de coluna; classificação inicial sujeita a auditoria; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| parent_id | BIGINT UNSIGNED | sim | NULL | não | financial_categories.id | — | ix_financial_categories_parent_id | parent id | 123 | Confidencial |
| code | VARCHAR(64) | não | nenhum | não | — | uq_financial_categories_code | — | code | CAT_EXEMPLO | Confidencial |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Confidencial |
| ledger_account_id | BIGINT UNSIGNED | sim | NULL | não | chart_of_accounts.id | — | ix_financial_categories_ledger_account_id | ledger account id | 123 | Confidencial |
| classification_status | VARCHAR(64) | não | nenhum | não | — | — | — | classification status | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `parent_id` → `financial_categories.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `ledger_account_id` → `chart_of_accounts.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## journal_entries

Cabeçalho contabilístico nacional; rascunho editável, POSTED imutável.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Lancamento identificado na API financeira autorizada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_journal_entries_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Lancamento identificado na API financeira autorizada | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| period_id | BIGINT UNSIGNED | não | nenhum | não | accounting_periods.id | — | ix_journal_entries_period_id_status_entry_date_id | period id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_journal_entries_currency_id | currency id | 123 | Confidencial |
| entry_date | DATE | não | nenhum | não | — | — | ix_journal_entries_period_id_status_entry_date_id | entry date | 2026-09-12 | Confidencial |
| reference | VARCHAR(64) | não | nenhum | não | — | uq_journal_entries_reference | — | reference | EXEMPLO | Confidencial |
| description | TEXT | não | nenhum | não | — | — | — | description | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_journal_entries_period_id_status_entry_date_id | DRAFT, POSTED | DRAFT | Confidencial |
| posted_at | DATETIME(6) | sim | NULL | não | — | — | — | posted at | 2026-09-12 10:00:00.000000 | Confidencial |
| posted_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_journal_entries_posted_by | posted by | 123 | Confidencial |
| created_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_journal_entries_created_by | created by | 123 | Confidencial |
| reversal_of_id | BIGINT UNSIGNED | sim | NULL | não | journal_entries.id | — | ix_journal_entries_reversal_of_id | reversal of id | 123 | Confidencial |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_journal_entries_document_id | document id | 123 | Confidencial |
| idempotency_request_id | BIGINT UNSIGNED | não | nenhum | não | idempotency_requests.id | uq_journal_entries_idempotency_request_id | — | idempotency request id | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `period_id` → `accounting_periods.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `posted_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `created_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `reversal_of_id` → `journal_entries.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `idempotency_request_id` → `idempotency_requests.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## journal_lines

Partidas dobradas; uma unidade por linha; unidade pode diferir dentro de transferência.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Partidas dobradas; uma unidade por linha; unidade pode diferir dentro de transferência; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| entry_id | BIGINT UNSIGNED | não | nenhum | não | journal_entries.id | uq_journal_lines_entry_id_line_number | ix_journal_lines_unit_id_ledger_account_id_entry_id, ix_journal_lines_fund_id_entry_id, ix_journal_lines_financial_account_id_entry_id | entry id | 123 | Confidencial |
| line_number | INT UNSIGNED | não | nenhum | não | — | uq_journal_lines_entry_id_line_number | — | line number | 1 | Confidencial |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_journal_lines_unit_id_ledger_account_id_entry_id | unit id | 123 | Confidencial |
| ledger_account_id | BIGINT UNSIGNED | não | nenhum | não | chart_of_accounts.id | — | ix_journal_lines_unit_id_ledger_account_id_entry_id, ix_journal_lines_ledger_account_id | ledger account id | 123 | Confidencial |
| financial_account_id | BIGINT UNSIGNED | sim | NULL | não | accounts.id | — | ix_journal_lines_financial_account_id_entry_id | financial account id | 123 | Confidencial |
| fund_id | BIGINT UNSIGNED | não | nenhum | não | funds.id | — | ix_journal_lines_fund_id_entry_id | fund id | 123 | Confidencial |
| category_id | BIGINT UNSIGNED | sim | NULL | não | financial_categories.id | — | ix_journal_lines_category_id | category id | 123 | Confidencial |
| debit | DECIMAL(19,4) | não | nenhum | não | — | — | — | debit | 1500.0000 | Confidencial |
| credit | DECIMAL(19,4) | não | nenhum | não | — | — | — | credit | 1500.0000 | Confidencial |
| description | VARCHAR(191) | sim | NULL | não | — | — | — | description | EXEMPLO | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `entry_id` → `journal_entries.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `ledger_account_id` → `chart_of_accounts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `financial_account_id` → `accounts.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `fund_id` → `funds.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `category_id` → `financial_categories.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## financial_parties

Titular financeiro tipado Pessoa/agregado/unidade/instância ou externo; XOR.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Titular financeiro tipado Pessoa/agregado/unidade/instância ou externo; XOR; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| party_kind | VARCHAR(64) | não | nenhum | não | — | — | — | party kind | EXEMPLO | Confidencial |
| person_id | BIGINT UNSIGNED | sim | NULL | não | people.id | — | ix_financial_parties_person_id | person id | 123 | Confidencial |
| household_id | BIGINT UNSIGNED | sim | NULL | não | households.id | — | ix_financial_parties_household_id | household id | 123 | Confidencial |
| unit_id | BIGINT UNSIGNED | sim | NULL | não | organizational_units.id | — | ix_financial_parties_unit_id | unit id | 123 | Confidencial |
| department_instance_id | BIGINT UNSIGNED | sim | NULL | não | department_instances.id | — | ix_financial_parties_department_instance_id | department instance id | 123 | Confidencial |
| external_name | VARCHAR(191) | sim | NULL | não | — | — | — | external name | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `household_id` → `households.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `department_instance_id` → `department_instances.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## contributions

Dízimo, oferta, quota, doação identificada/anónima/agregada ou em espécie.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Contribuicao em recibo e API restrita.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_contributions_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Contribuicao em recibo e API restrita | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| receiving_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_contributions_receiving_unit_id_received_at | receiving unit id | 123 | Confidencial |
| party_id | BIGINT UNSIGNED | sim | NULL | não | financial_parties.id | — | ix_contributions_party_id_received_at | party id | 123 | Confidencial |
| category_id | BIGINT UNSIGNED | não | nenhum | não | financial_categories.id | — | ix_contributions_category_id | category id | 123 | Confidencial |
| fund_id | BIGINT UNSIGNED | não | nenhum | não | funds.id | — | ix_contributions_fund_id | fund id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_contributions_currency_id | currency id | 123 | Confidencial |
| contribution_kind | VARCHAR(64) | não | nenhum | não | — | — | — | MONETARY, IN_KIND | EXEMPLO | Confidencial |
| identification_kind | VARCHAR(64) | não | nenhum | não | — | — | — | IDENTIFIED, ANONYMOUS, AGGREGATED | EXEMPLO | Confidencial |
| amount | DECIMAL(19,4) | sim | NULL | não | — | — | — | amount | 1500.0000 | Confidencial |
| valuation_amount | DECIMAL(19,4) | sim | NULL | não | — | — | — | valuation amount | 1500.0000 | Confidencial |
| in_kind_description | TEXT | sim | NULL | não | — | — | — | in kind description | EXEMPLO | Confidencial |
| received_at | DATETIME(6) | não | nenhum | não | — | — | ix_contributions_receiving_unit_id_received_at, ix_contributions_party_id_received_at | received at | 2026-09-12 10:00:00.000000 | Confidencial |
| journal_entry_id | BIGINT UNSIGNED | sim | NULL | não | journal_entries.id | — | ix_contributions_journal_entry_id | journal entry id | 123 | Confidencial |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_contributions_document_id | document id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `receiving_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `party_id` → `financial_parties.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `category_id` → `financial_categories.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `fund_id` → `funds.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `journal_entry_id` → `journal_entries.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## obligation_rules

Regras versionadas de quota por classe/unidade/departamento.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Regras versionadas de quota por classe/unidade/departamento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_obligation_rules_code_version | — | code | CAT_EXEMPLO | Confidencial |
| version | INT UNSIGNED | não | nenhum | não | — | uq_obligation_rules_code_version | — | version | 1 | Confidencial |
| class_id | BIGINT UNSIGNED | sim | NULL | não | ministerial_classes.id | — | ix_obligation_rules_class_id | class id | 123 | Confidencial |
| department_id | BIGINT UNSIGNED | sim | NULL | não | departments.id | — | ix_obligation_rules_department_id | department id | 123 | Confidencial |
| unit_type_id | BIGINT UNSIGNED | sim | NULL | não | organizational_unit_types.id | — | ix_obligation_rules_unit_type_id | unit type id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_obligation_rules_currency_id | currency id | 123 | Confidencial |
| amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | amount | 1500.0000 | Confidencial |
| frequency | VARCHAR(64) | não | nenhum | não | — | — | — | frequency | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `class_id` → `ministerial_classes.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `department_id` → `departments.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_type_id` → `organizational_unit_types.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## obligations

Obrigação prevista por titular e regra/período; pago deriva de alocações.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Obrigação prevista por titular e regra/período; pago deriva de alocações; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| party_id | BIGINT UNSIGNED | não | nenhum | não | financial_parties.id | uq_obligations_party_id_rule_id_period_id | — | party id | 123 | Confidencial |
| rule_id | BIGINT UNSIGNED | não | nenhum | não | obligation_rules.id | uq_obligations_party_id_rule_id_period_id | ix_obligations_rule_id | rule id | 123 | Confidencial |
| period_id | BIGINT UNSIGNED | não | nenhum | não | accounting_periods.id | uq_obligations_party_id_rule_id_period_id | ix_obligations_period_id | period id | 123 | Confidencial |
| owning_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_obligations_owning_unit_id_status_due_on | owning unit id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_obligations_currency_id | currency id | 123 | Confidencial |
| amount_due | DECIMAL(19,4) | não | nenhum | não | — | — | — | amount due | 1500.0000 | Confidencial |
| due_on | DATE | não | nenhum | não | — | — | ix_obligations_owning_unit_id_status_due_on | due on | 2026-09-12 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_obligations_owning_unit_id_status_due_on | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `party_id` → `financial_parties.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `rule_id` → `obligation_rules.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `period_id` → `accounting_periods.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `owning_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## receivables

Direito a receber; obrigação opcional, não receita automaticamente.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Recebivel em documento e API financeira.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_receivables_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Recebivel em documento e API financeira | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| party_id | BIGINT UNSIGNED | não | nenhum | não | financial_parties.id | — | ix_receivables_party_id | party id | 123 | Confidencial |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_receivables_unit_id_status_due_on | unit id | 123 | Confidencial |
| obligation_id | BIGINT UNSIGNED | sim | NULL | não | obligations.id | — | ix_receivables_obligation_id | obligation id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_receivables_currency_id | currency id | 123 | Confidencial |
| amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | amount | 1500.0000 | Confidencial |
| due_on | DATE | não | nenhum | não | — | — | ix_receivables_unit_id_status_due_on | due on | 2026-09-12 | Confidencial |
| recognition_entry_id | BIGINT UNSIGNED | sim | NULL | não | journal_entries.id | — | ix_receivables_recognition_entry_id | recognition entry id | 123 | Confidencial |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_receivables_document_id | document id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_receivables_unit_id_status_due_on | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `party_id` → `financial_parties.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `obligation_id` → `obligations.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `recognition_entry_id` → `journal_entries.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## payables

Dívida a pagar com reconhecimento contabilístico separado da liquidação.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Pagavel em documento e API financeira.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_payables_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Pagavel em documento e API financeira | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| party_id | BIGINT UNSIGNED | não | nenhum | não | financial_parties.id | — | ix_payables_party_id | party id | 123 | Confidencial |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_payables_unit_id_status_due_on | unit id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_payables_currency_id | currency id | 123 | Confidencial |
| amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | amount | 1500.0000 | Confidencial |
| due_on | DATE | não | nenhum | não | — | — | ix_payables_unit_id_status_due_on | due on | 2026-09-12 | Confidencial |
| recognition_entry_id | BIGINT UNSIGNED | sim | NULL | não | journal_entries.id | — | ix_payables_recognition_entry_id | recognition entry id | 123 | Confidencial |
| document_id | BIGINT UNSIGNED | não | nenhum | não | legal_documents.id | — | ix_payables_document_id | document id | 123 | Confidencial |
| workflow_instance_id | BIGINT UNSIGNED | não | nenhum | não | workflow_instances.id | — | ix_payables_workflow_instance_id | workflow instance id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_payables_unit_id_status_due_on | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `party_id` → `financial_parties.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `recognition_entry_id` → `journal_entries.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `workflow_instance_id` → `workflow_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## settlements

Recebimento/pagamento efectivado por lançamento; não actualiza amount_paid manualmente.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Liquidacao em comprovativo e API financeira.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_settlements_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Liquidacao em comprovativo e API financeira | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| account_id | BIGINT UNSIGNED | não | nenhum | não | accounts.id | — | ix_settlements_account_id_settled_at | account id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_settlements_currency_id | currency id | 123 | Confidencial |
| amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | amount | 1500.0000 | Confidencial |
| settled_at | DATETIME(6) | não | nenhum | não | — | — | ix_settlements_account_id_settled_at | settled at | 2026-09-12 10:00:00.000000 | Confidencial |
| entry_id | BIGINT UNSIGNED | não | nenhum | não | journal_entries.id | — | ix_settlements_entry_id | entry id | 123 | Confidencial |
| direction | VARCHAR(64) | não | nenhum | não | — | — | — | RECEIPT ou PAYMENT | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `account_id` → `accounts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `entry_id` → `journal_entries.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## settlement_allocations

Alocação parcial a receber/pagar; XOR e mesma moeda/titular.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Alocação parcial a receber/pagar; XOR e mesma moeda/titular; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| settlement_id | BIGINT UNSIGNED | não | nenhum | não | settlements.id | — | ix_settlement_allocations_settlement_id | settlement id | 123 | Confidencial |
| receivable_id | BIGINT UNSIGNED | sim | NULL | não | receivables.id | — | ix_settlement_allocations_receivable_id | receivable id | 123 | Confidencial |
| payable_id | BIGINT UNSIGNED | sim | NULL | não | payables.id | — | ix_settlement_allocations_payable_id | payable id | 123 | Confidencial |
| amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | amount | 1500.0000 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `settlement_id` → `settlements.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `receivable_id` → `receivables.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `payable_id` → `payables.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## contribution_allocations

Liga contribuição a recebimento e quota sem contar nova receita.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Liga contribuição a recebimento e quota sem contar nova receita; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| contribution_id | BIGINT UNSIGNED | não | nenhum | não | contributions.id | — | ix_contribution_allocations_contribution_id | contribution id | 123 | Confidencial |
| settlement_id | BIGINT UNSIGNED | não | nenhum | não | settlements.id | — | ix_contribution_allocations_settlement_id | settlement id | 123 | Confidencial |
| obligation_id | BIGINT UNSIGNED | sim | NULL | não | obligations.id | — | ix_contribution_allocations_obligation_id | obligation id | 123 | Confidencial |
| amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | amount | 1500.0000 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `contribution_id` → `contributions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `settlement_id` → `settlements.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `obligation_id` → `obligations.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## budgets

Versões orçamentais aprovadas por unidade/período/fundo.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Versao orcamental em documento externo e API.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_budgets_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Versao orcamental em documento externo e API | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | uq_budgets_unit_id_period_id_fund_id_version | — | unit id | 123 | Confidencial |
| period_id | BIGINT UNSIGNED | não | nenhum | não | accounting_periods.id | uq_budgets_unit_id_period_id_fund_id_version | ix_budgets_period_id | period id | 123 | Confidencial |
| fund_id | BIGINT UNSIGNED | não | nenhum | não | funds.id | uq_budgets_unit_id_period_id_fund_id_version | ix_budgets_fund_id | fund id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_budgets_currency_id | currency id | 123 | Confidencial |
| version | INT UNSIGNED | não | nenhum | não | — | uq_budgets_unit_id_period_id_fund_id_version | — | version | 1 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| approved_at | DATETIME(6) | sim | NULL | não | — | — | — | approved at | 2026-09-12 10:00:00.000000 | Confidencial |
| approved_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_budgets_approved_by | approved by | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `period_id` → `accounting_periods.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `fund_id` → `funds.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## budget_lines

Rubricas do orçamento; realizado deriva do ledger.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Rubricas do orçamento; realizado deriva do ledger; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| budget_id | BIGINT UNSIGNED | não | nenhum | não | budgets.id | uq_budget_lines_budget_id_category_id | — | budget id | 123 | Confidencial |
| category_id | BIGINT UNSIGNED | não | nenhum | não | financial_categories.id | uq_budget_lines_budget_id_category_id | ix_budget_lines_category_id | category id | 123 | Confidencial |
| requested_amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | requested amount | 1500.0000 | Confidencial |
| approved_amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | approved amount | 1500.0000 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `budget_id` → `budgets.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `category_id` → `financial_categories.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## internal_transfers

Transferência MEPA com origem/destino, moeda e fundo; nunca contribuição.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Transferencia identificada na API financeira e comprovativos.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_internal_transfers_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Transferencia identificada na API financeira e comprovativos | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| origin_account_id | BIGINT UNSIGNED | não | nenhum | não | accounts.id | — | ix_internal_transfers_origin_account_id_status | origin account id | 123 | Confidencial |
| destination_account_id | BIGINT UNSIGNED | não | nenhum | não | accounts.id | — | ix_internal_transfers_destination_account_id_status | destination account id | 123 | Confidencial |
| currency_id | BIGINT UNSIGNED | não | nenhum | não | currencies.id | — | ix_internal_transfers_currency_id | currency id | 123 | Confidencial |
| fund_id | BIGINT UNSIGNED | não | nenhum | não | funds.id | — | ix_internal_transfers_fund_id | fund id | 123 | Confidencial |
| category_id | BIGINT UNSIGNED | sim | NULL | não | financial_categories.id | — | ix_internal_transfers_category_id | category id | 123 | Confidencial |
| period_id | BIGINT UNSIGNED | não | nenhum | não | accounting_periods.id | — | ix_internal_transfers_period_id | period id | 123 | Confidencial |
| amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | amount | 1500.0000 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_internal_transfers_origin_account_id_status, ix_internal_transfers_destination_account_id_status | DRAFT, AUTHORIZED, SENT, RECEIVED, RECONCILED, CANCELLED | DRAFT | Confidencial |
| sent_at | DATETIME(6) | sim | NULL | não | — | — | — | sent at | 2026-09-12 10:00:00.000000 | Confidencial |
| received_at | DATETIME(6) | sim | NULL | não | — | — | — | received at | 2026-09-12 10:00:00.000000 | Confidencial |
| reconciled_at | DATETIME(6) | sim | NULL | não | — | — | — | reconciled at | 2026-09-12 10:00:00.000000 | Confidencial |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_internal_transfers_document_id | document id | 123 | Confidencial |
| workflow_instance_id | BIGINT UNSIGNED | não | nenhum | não | workflow_instances.id | — | ix_internal_transfers_workflow_instance_id | workflow instance id | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `origin_account_id` → `accounts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `destination_account_id` → `accounts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `currency_id` → `currencies.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `fund_id` → `funds.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `category_id` → `financial_categories.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `period_id` → `accounting_periods.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `workflow_instance_id` → `workflow_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## transfer_postings

Etapas contabilísticas da transferência e estorno; várias etapas, uma por tipo.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Etapas contabilísticas da transferência e estorno; várias etapas, uma por tipo; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| transfer_id | BIGINT UNSIGNED | não | nenhum | não | internal_transfers.id | uq_transfer_postings_transfer_id_posting_stage | — | transfer id | 123 | Confidencial |
| posting_stage | VARCHAR(64) | não | nenhum | não | — | uq_transfer_postings_transfer_id_posting_stage | — | SEND, RECEIVE, REVERSE_SEND, REVERSE_RECEIVE | EXEMPLO | Confidencial |
| entry_id | BIGINT UNSIGNED | não | nenhum | não | journal_entries.id | uq_transfer_postings_entry_id | — | entry id | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `transfer_id` → `internal_transfers.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `entry_id` → `journal_entries.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## bank_statements

Extracto externo importado e preservado.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Extracto em processo de conciliacao/API restrita.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_bank_statements_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Extracto em processo de conciliacao/API restrita | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| account_id | BIGINT UNSIGNED | não | nenhum | não | accounts.id | uq_bank_statements_account_id_source_hash | — | account id | 123 | Confidencial |
| starts_on | DATE | não | nenhum | não | — | — | — | starts on | 2026-09-12 | Confidencial |
| ends_on | DATE | não | nenhum | não | — | — | — | ends on | 2026-09-12 | Confidencial |
| opening_balance | DECIMAL(19,4) | não | nenhum | não | — | — | — | opening balance | 1500.0000 | Confidencial |
| closing_balance | DECIMAL(19,4) | não | nenhum | não | — | — | — | closing balance | 1500.0000 | Confidencial |
| file_id | BIGINT UNSIGNED | não | nenhum | não | files.id | — | ix_bank_statements_file_id | file id | 123 | Confidencial |
| source_hash | BINARY(32) | não | nenhum | não | — | uq_bank_statements_account_id_source_hash | — | source hash | SHA-256, 32 bytes | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `account_id` → `accounts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `file_id` → `files.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## bank_statement_lines

Linhas de extracto idempotentes.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Linhas de extracto idempotentes; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| statement_id | BIGINT UNSIGNED | não | nenhum | não | bank_statements.id | uq_bank_statement_lines_statement_id_line_number | — | statement id | 123 | Confidencial |
| line_number | INT UNSIGNED | não | nenhum | não | — | uq_bank_statement_lines_statement_id_line_number | — | line number | 1 | Confidencial |
| external_reference | VARCHAR(191) | sim | NULL | não | — | — | — | external reference | EXEMPLO | Confidencial |
| occurred_on | DATE | não | nenhum | não | — | — | — | occurred on | 2026-09-12 | Confidencial |
| amount_signed | DECIMAL(19,4) | não | nenhum | não | — | — | — | amount signed | 1500.0000 | Confidencial |
| description | VARCHAR(191) | não | nenhum | não | — | — | — | description | EXEMPLO | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `statement_id` → `bank_statements.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## reconciliations

Fecho de conciliação por conta/extracto, segregado.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Conciliacao versionada em documento/API restrita.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_reconciliations_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Conciliacao versionada em documento/API restrita | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| account_id | BIGINT UNSIGNED | não | nenhum | não | accounts.id | uq_reconciliations_account_id_period_id_version | — | account id | 123 | Confidencial |
| statement_id | BIGINT UNSIGNED | sim | NULL | não | bank_statements.id | — | ix_reconciliations_statement_id | statement id | 123 | Confidencial |
| period_id | BIGINT UNSIGNED | não | nenhum | não | accounting_periods.id | uq_reconciliations_account_id_period_id_version | ix_reconciliations_period_id | period id | 123 | Confidencial |
| version | INT UNSIGNED | não | nenhum | não | — | uq_reconciliations_account_id_period_id_version | — | version | 1 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| approved_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_reconciliations_approved_by | approved by | 123 | Confidencial |
| closed_at | DATETIME(6) | sim | NULL | não | — | — | — | closed at | 2026-09-12 10:00:00.000000 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `account_id` → `accounts.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `statement_id` → `bank_statements.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `period_id` → `accounting_periods.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## reconciliation_matches

Correspondência parcial/múltipla entre extracto e ledger.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Correspondência parcial/múltipla entre extracto e ledger; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| reconciliation_id | BIGINT UNSIGNED | não | nenhum | não | reconciliations.id | uq_recon_statement_journal_match | — | reconciliation id | 123 | Confidencial |
| statement_line_id | BIGINT UNSIGNED | não | nenhum | não | bank_statement_lines.id | uq_recon_statement_journal_match | ix_reconciliation_matches_statement_line_id | statement line id | 123 | Confidencial |
| journal_line_id | BIGINT UNSIGNED | não | nenhum | não | journal_lines.id | uq_recon_statement_journal_match | ix_reconciliation_matches_journal_line_id | journal line id | 123 | Confidencial |
| matched_amount | DECIMAL(19,4) | não | nenhum | não | — | — | — | matched amount | 1500.0000 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `reconciliation_id` → `reconciliations.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `statement_line_id` → `bank_statement_lines.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `journal_line_id` → `journal_lines.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## financial_documents

Comprovativos ligados explicitamente ao lançamento.

Domínio: Financeiro. Owner lógico: Contabilidade / Tesouraria segregadas. Retenção: R-FINANCEIRO. Volume esperado: Até 1 milhão lançamentos; 10 milhões linhas; catálogos <10 mil. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Comprovativos ligados explicitamente ao lançamento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| entry_id | BIGINT UNSIGNED | não | nenhum | não | journal_entries.id | uq_financial_documents_entry_id_document_id | — | entry id | 123 | Confidencial |
| document_id | BIGINT UNSIGNED | não | nenhum | não | legal_documents.id | uq_financial_documents_entry_id_document_id | ix_financial_documents_document_id | document id | 123 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `entry_id` → `journal_entries.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## communication_templates

Template de mensagem versionado por canal.

Domínio: Comunicação. Owner lógico: Comunicação autorizada. Retenção: R-COMUNICACAO. Volume esperado: Até 10 milhões mensagens/notificações e tentativas. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Template de mensagem versionado por canal; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_communication_templates_code_version_channel | — | code | CAT_EXEMPLO | Confidencial |
| version | INT UNSIGNED | não | nenhum | não | — | uq_communication_templates_code_version_channel | — | version | 1 | Confidencial |
| channel | VARCHAR(64) | não | nenhum | não | — | uq_communication_templates_code_version_channel | — | channel | EXEMPLO | Confidencial |
| body | TEXT | não | nenhum | não | — | — | — | body | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## audiences

Segmento autorizado da base Pessoa, critérios variáveis não cadastro paralelo.

Domínio: Comunicação. Owner lógico: Comunicação autorizada. Retenção: R-COMUNICACAO. Volume esperado: Até 10 milhões mensagens/notificações e tentativas. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Segmento autorizado da base Pessoa, critérios variáveis não cadastro paralelo; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| owner_unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_audiences_owner_unit_id | owner unit id | 123 | Confidencial |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Confidencial |
| criteria_metadata | JSON | não | nenhum | não | — | — | — | criteria metadata | {} | Confidencial |
| policy_version | VARCHAR(64) | não | nenhum | não | — | — | — | policy version | EXEMPLO | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `owner_unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## communication_campaigns

Campanha de comunicação.

Domínio: Comunicação. Owner lógico: Comunicação autorizada. Retenção: R-COMUNICACAO. Volume esperado: Até 10 milhões mensagens/notificações e tentativas. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Campanha em URL administrativa.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_communication_campaigns_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Campanha em URL administrativa | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Confidencial |
| audience_id | BIGINT UNSIGNED | não | nenhum | não | audiences.id | — | ix_communication_campaigns_audience_id | audience id | 123 | Confidencial |
| template_id | BIGINT UNSIGNED | não | nenhum | não | communication_templates.id | — | ix_communication_campaigns_template_id | template id | 123 | Confidencial |
| created_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_communication_campaigns_created_by | created by | 123 | Confidencial |
| scheduled_at | DATETIME(6) | sim | NULL | não | — | — | — | scheduled at | 2026-09-12 10:00:00.000000 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `audience_id` → `audiences.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `template_id` → `communication_templates.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `created_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## messages

Mensagem por destinatário Pessoa; contacto referenciado, acesso limitado.

Domínio: Comunicação. Owner lógico: Comunicação autorizada. Retenção: R-COMUNICACAO. Volume esperado: Até 10 milhões mensagens/notificações e tentativas. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Mensagem por destinatário Pessoa; contacto referenciado, acesso limitado; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| campaign_id | BIGINT UNSIGNED | sim | NULL | não | communication_campaigns.id | — | ix_messages_campaign_id | campaign id | 123 | Confidencial |
| recipient_person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_messages_recipient_person_id_queued_at | recipient person id | 123 | Confidencial |
| contact_id | BIGINT UNSIGNED | sim | NULL | não | person_contacts.id | — | ix_messages_contact_id | contact id | 123 | Confidencial |
| template_id | BIGINT UNSIGNED | não | nenhum | não | communication_templates.id | — | ix_messages_template_id | template id | 123 | Confidencial |
| idempotency_request_id | BIGINT UNSIGNED | não | nenhum | não | idempotency_requests.id | uq_messages_idempotency_request_id | — | idempotency request id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_messages_status_queued_at_id | status | DRAFT | Confidencial |
| queued_at | DATETIME(6) | não | nenhum | não | — | — | ix_messages_recipient_person_id_queued_at, ix_messages_status_queued_at_id | queued at | 2026-09-12 10:00:00.000000 | Confidencial |
| payload_ciphertext | VARBINARY(2048) | sim | NULL | não | — | — | — | payload ciphertext | EXEMPLO | Confidencial |
| key_version | SMALLINT UNSIGNED | sim | NULL | não | — | — | — | key version | 1 | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `campaign_id` → `communication_campaigns.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `recipient_person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `contact_id` → `person_contacts.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `template_id` → `communication_templates.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `idempotency_request_id` → `idempotency_requests.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## delivery_logs

Tentativas de envio com chave externa e erro sanitizado.

Domínio: Comunicação. Owner lógico: Comunicação autorizada. Retenção: R-COMUNICACAO. Volume esperado: Até 10 milhões mensagens/notificações e tentativas. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Tentativas de envio com chave externa e erro sanitizado; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| message_id | BIGINT UNSIGNED | não | nenhum | não | messages.id | uq_delivery_logs_message_id_attempt_number | — | message id | 123 | Confidencial |
| attempt_number | INT UNSIGNED | não | nenhum | não | — | uq_delivery_logs_message_id_attempt_number | — | attempt number | 1 | Confidencial |
| provider | VARCHAR(64) | não | nenhum | não | — | — | — | provider | EXEMPLO | Confidencial |
| external_reference | VARCHAR(191) | sim | NULL | não | — | — | — | external reference | EXEMPLO | Confidencial |
| attempted_at | DATETIME(6) | não | nenhum | não | — | — | — | attempted at | 2026-09-12 10:00:00.000000 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| error_code | VARCHAR(64) | sim | NULL | não | — | — | — | error code | EXEMPLO | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `message_id` → `messages.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## notifications

Notificação interna por utilizador; payload mínimo.

Domínio: Comunicação. Owner lógico: Comunicação autorizada. Retenção: R-COMUNICACAO. Volume esperado: Até 10 milhões mensagens/notificações e tentativas. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Notificação interna por utilizador; payload mínimo; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| user_id | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_notifications_user_id_read_at_id | user id | 123 | Confidencial |
| message_id | BIGINT UNSIGNED | sim | NULL | não | messages.id | — | ix_notifications_message_id | message id | 123 | Confidencial |
| type | VARCHAR(64) | não | nenhum | não | — | — | — | type | EXEMPLO | Confidencial |
| read_at | DATETIME(6) | sim | NULL | não | — | — | ix_notifications_user_id_read_at_id | read at | 2026-09-12 10:00:00.000000 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| metadata | JSON | sim | NULL | não | — | — | — | metadata | {} | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `user_id` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `message_id` → `messages.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## communication_preferences

Preferência e consentimento por Pessoa/canal/finalidade.

Domínio: Comunicação. Owner lógico: Comunicação autorizada. Retenção: R-COMUNICACAO. Volume esperado: Até 10 milhões mensagens/notificações e tentativas. Sensibilidade base: Confidencial.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Preferência e consentimento por Pessoa/canal/finalidade; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | uq_communication_preferences_person_id_channel_purpose | — | person id | 123 | Confidencial |
| channel | VARCHAR(64) | não | nenhum | não | — | uq_communication_preferences_person_id_channel_purpose | — | channel | EXEMPLO | Confidencial |
| purpose | VARCHAR(64) | não | nenhum | não | — | uq_communication_preferences_person_id_channel_purpose | — | purpose | EXEMPLO | Confidencial |
| consent_id | BIGINT UNSIGNED | sim | NULL | não | person_consents.id | — | ix_communication_preferences_consent_id | consent id | 123 | Confidencial |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Confidencial |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `consent_id` → `person_consents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## metric_definitions

Indicador catálogo e definição versionada, nunca coluna por indicador.

Domínio: Estatística. Owner lógico: Departamento de Estatística autorizado. Retenção: R-INSTITUCIONAL. Volume esperado: Até 10 milhões valores de snapshot; catálogo <5 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Indicador catálogo e definição versionada, nunca coluna por indicador; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_metric_definitions_code_version | — | code | CAT_EXEMPLO | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_metric_definitions_code_version | — | version | 1 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| aggregation_kind | VARCHAR(64) | não | nenhum | não | — | — | — | aggregation kind | EXEMPLO | Restrito |
| source_definition | TEXT | não | nenhum | não | — | — | — | source definition | EXEMPLO | Restrito |
| classification | VARCHAR(64) | não | nenhum | não | — | — | — | classification | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## statistical_periods

Período oficial de referência.

Domínio: Estatística. Owner lógico: Departamento de Estatística autorizado. Retenção: R-INSTITUCIONAL. Volume esperado: Até 10 milhões valores de snapshot; catálogo <5 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Período oficial de referência; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| starts_on | DATE | não | nenhum | não | — | — | — | starts on | 2026-09-12 | Restrito |
| ends_on | DATE | não | nenhum | não | — | — | — | ends on | 2026-09-12 | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_statistical_periods_code | — | code | CAT_EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## dimension_types

Dimensões aprovadas como sexo, faixa etária, classe.

Domínio: Estatística. Owner lógico: Departamento de Estatística autorizado. Retenção: R-INSTITUCIONAL. Volume esperado: Até 10 milhões valores de snapshot; catálogo <5 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Dimensões aprovadas como sexo, faixa etária, classe; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_dimension_types_code | — | code | CAT_EXEMPLO | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| classification | VARCHAR(64) | não | nenhum | não | — | — | — | classification | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## dimension_values

Membros da dimensão, catálogo não EAV da Pessoa.

Domínio: Estatística. Owner lógico: Departamento de Estatística autorizado. Retenção: R-INSTITUCIONAL. Volume esperado: Até 10 milhões valores de snapshot; catálogo <5 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Membros da dimensão, catálogo não EAV da Pessoa; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| dimension_type_id | BIGINT UNSIGNED | não | nenhum | não | dimension_types.id | uq_dimension_values_dimension_type_id_code | — | dimension type id | 123 | Restrito |
| code | VARCHAR(64) | não | nenhum | não | — | uq_dimension_values_dimension_type_id_code | — | code | CAT_EXEMPLO | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `dimension_type_id` → `dimension_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## statistical_snapshots

Fecho versionado por unidade/período; fonte temporal identificada.

Domínio: Estatística. Owner lógico: Departamento de Estatística autorizado. Retenção: R-INSTITUCIONAL. Volume esperado: Até 10 milhões valores de snapshot; catálogo <5 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Fecho oficial em documento/export com referencia externa.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_statistical_snapshots_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Fecho oficial em documento/export com referencia externa | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | uq_statistical_snapshots_unit_id_period_id_version | — | unit id | 123 | Restrito |
| period_id | BIGINT UNSIGNED | não | nenhum | não | statistical_periods.id | uq_statistical_snapshots_unit_id_period_id_version | ix_statistical_snapshots_period_id | period id | 123 | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_statistical_snapshots_unit_id_period_id_version | — | version | 1 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| source_cutoff_at | DATETIME(6) | não | nenhum | não | — | — | — | source cutoff at | 2026-09-12 10:00:00.000000 | Restrito |
| definition_version | VARCHAR(64) | não | nenhum | não | — | — | — | definition version | EXEMPLO | Restrito |
| tree_version_reference | VARCHAR(64) | não | nenhum | não | — | — | — | tree version reference | EXEMPLO | Restrito |
| approved_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_statistical_snapshots_approved_by | approved by | 123 | Restrito |
| closed_at | DATETIME(6) | sim | NULL | não | — | — | — | closed at | 2026-09-12 10:00:00.000000 | Restrito |
| supersedes_id | BIGINT UNSIGNED | sim | NULL | não | statistical_snapshots.id | — | ix_statistical_snapshots_supersedes_id | supersedes id | 123 | Restrito |
| checksum | BINARY(32) | sim | NULL | não | — | — | — | checksum | SHA-256, 32 bytes | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `period_id` → `statistical_periods.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `supersedes_id` → `statistical_snapshots.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## snapshot_values

Valor de métrica por conjunto de dimensões; COUNT DISTINCT Pessoa onde aplicável.

Domínio: Estatística. Owner lógico: Departamento de Estatística autorizado. Retenção: R-INSTITUCIONAL. Volume esperado: Até 10 milhões valores de snapshot; catálogo <5 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Valor de métrica por conjunto de dimensões; COUNT DISTINCT Pessoa onde aplicável; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| snapshot_id | BIGINT UNSIGNED | não | nenhum | não | statistical_snapshots.id | uq_snapshot_values_snapshot_id_metric_id_dimension_set_key | — | snapshot id | 123 | Restrito |
| metric_id | BIGINT UNSIGNED | não | nenhum | não | metric_definitions.id | uq_snapshot_values_snapshot_id_metric_id_dimension_set_key | ix_snapshot_values_metric_id | metric id | 123 | Restrito |
| dimension_set_key | BINARY(32) | não | nenhum | não | — | uq_snapshot_values_snapshot_id_metric_id_dimension_set_key | — | dimension set key | SHA-256, 32 bytes | Restrito |
| value | DECIMAL(24,6) | não | nenhum | não | — | — | — | value | 1.0000 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `snapshot_id` → `statistical_snapshots.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `metric_id` → `metric_definitions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## snapshot_value_dimensions

Dimensões normalizadas de cada valor; uma por tipo.

Domínio: Estatística. Owner lógico: Departamento de Estatística autorizado. Retenção: R-INSTITUCIONAL. Volume esperado: Até 10 milhões valores de snapshot; catálogo <5 mil. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Dimensões normalizadas de cada valor; uma por tipo; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| value_id | BIGINT UNSIGNED | não | nenhum | não | snapshot_values.id | uq_snapshot_value_dimensions_value_id_dimension_type_id | — | value id | 123 | Restrito |
| dimension_type_id | BIGINT UNSIGNED | não | nenhum | não | dimension_types.id | uq_snapshot_value_dimensions_value_id_dimension_type_id | ix_snapshot_value_dimensions_dimension_type_id | dimension type id | 123 | Restrito |
| dimension_value_id | BIGINT UNSIGNED | não | nenhum | não | dimension_values.id | — | ix_snapshot_value_dimensions_dimension_value_id | dimension value id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `value_id` → `snapshot_values.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `dimension_type_id` → `dimension_types.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `dimension_value_id` → `dimension_values.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## users

Autenticação separada de Pessoa; humano pode ser não membro.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Utilizador em API administrativa, sem expor PK.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | NAO | AUTO_INCREMENT | SIM | — | — | — | Chave interna imutável | 123 | Interno |
| person_id | BIGINT UNSIGNED | SIM | NULL | NAO | people | uq_users_person_id | — | person id | 123 | Restrito |
| account_kind | VARCHAR(64) | NAO | nenhum | NAO | — | — | — | HUMAN ou SERVICE | EXEMPLO | Restrito |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_users_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Utilizador em API administrativa, sem expor PK | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| login | VARCHAR(191) | NAO | nenhum | NAO | — | uq_users_login | — | login | EXEMPLO | Restrito |
| password_hash | VARCHAR(255) | NAO | nenhum | NAO | — | — | — | Hash forte; nunca senha em claro | hash | Altamente sensível |
| status | VARCHAR(64) | NAO | nenhum | NAO | — | — | — | status | DRAFT | Restrito |
| mfa_required | TINYINT UNSIGNED | NAO | nenhum | NAO | — | — | — | mfa required | 1 | Restrito |
| archived_at | DATETIME(6) | SIM | NULL | NAO | — | — | — | Arquivo logico revoga auth_sessions na mesma transaccao; servidor verifica user activo a cada pedido | 2026-09-12 10:00:00.000000 | Restrito |
| created_at | DATETIME(6) | NAO | nenhum; relógio UTC do serviço | NAO | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | NAO | 0 | NAO | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## auth_sessions

Sessões revogáveis; somente hashes de tokens.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Sessões revogáveis; somente hashes de tokens; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| user_id | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_auth_sessions_user_id_expires_at | user id | 123 | Restrito |
| token_hash | BINARY(32) | não | nenhum | não | — | uq_auth_sessions_token_hash | — | token hash | SHA-256, 32 bytes | Restrito |
| expires_at | DATETIME(6) | não | nenhum | não | — | — | ix_auth_sessions_user_id_expires_at | expires at | 2026-09-12 10:00:00.000000 | Restrito |
| revoked_at | DATETIME(6) | sim | NULL | não | — | — | — | revoked at | 2026-09-12 10:00:00.000000 | Restrito |
| ip_hash | BINARY(32) | sim | NULL | não | — | — | — | ip hash | SHA-256, 32 bytes | Restrito |
| device_id | BIGINT UNSIGNED | sim | NULL | não | devices.id | — | ix_auth_sessions_device_id | device id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `user_id` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `device_id` → `devices.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## roles

Papéis institucionais.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: 10-200 entradas de catalogo. Sensibilidade base: Interno.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Papéis institucionais; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_roles_code | — | Código estável do catálogo; não ENUM | CAT_EXEMPLO | Interno |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | Designação aprovada | Designacao de exemplo | Interno |
| is_active | TINYINT UNSIGNED | não | nenhum | não | — | — | — | Disponível para novas relações | 1 | Interno |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## permissions

Acção + tipo de dado e classificação máxima.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Acção + tipo de dado e classificação máxima; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_permissions_code | — | code | CAT_EXEMPLO | Restrito |
| action | VARCHAR(64) | não | nenhum | não | — | — | — | action | EXEMPLO | Restrito |
| data_type | VARCHAR(64) | não | nenhum | não | — | — | — | data type | EXEMPLO | Restrito |
| maximum_classification | VARCHAR(64) | não | nenhum | não | — | — | — | maximum classification | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## role_permissions

Permissões do papel.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Permissões do papel; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| role_id | BIGINT UNSIGNED | não | nenhum | não | roles.id | uq_role_permissions_role_id_permission_id | — | role id | 123 | Restrito |
| permission_id | BIGINT UNSIGNED | não | nenhum | não | permissions.id | uq_role_permissions_role_id_permission_id | ix_role_permissions_permission_id | permission id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `role_id` → `roles.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `permission_id` → `permissions.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## scopes

Unidade e departamento com descendência explícita; nada de NULL como acesso global.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Unidade e departamento com descendência explícita; nada de NULL como acesso global; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_scopes_unit_id_department_instance_id | unit id | 123 | Restrito |
| department_instance_id | BIGINT UNSIGNED | sim | NULL | não | department_instances.id | — | ix_scopes_unit_id_department_instance_id, ix_scopes_department_instance_id | department instance id | 123 | Restrito |
| include_descendants | TINYINT UNSIGNED | não | nenhum | não | — | — | — | include descendants | 1 | Restrito |
| scope_kind | VARCHAR(64) | não | nenhum | não | — | — | — | UNIT ou UNIT_DEPARTMENT | EXEMPLO | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `department_instance_id` → `department_instances.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## user_role_scopes

Concessão conjunta user+papel+scope por período, sem produto cartesiano.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Concessão conjunta user+papel+scope por período, sem produto cartesiano; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| user_id | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_user_role_scopes_user_id_status_starts_at | user id | 123 | Restrito |
| role_id | BIGINT UNSIGNED | não | nenhum | não | roles.id | — | ix_user_role_scopes_role_id | role id | 123 | Restrito |
| scope_id | BIGINT UNSIGNED | não | nenhum | não | scopes.id | — | ix_user_role_scopes_scope_id | scope id | 123 | Restrito |
| granted_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_user_role_scopes_granted_by | granted by | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_user_role_scopes_user_id_status_starts_at | status | DRAFT | Restrito |
| starts_at | DATETIME(6) | não | nenhum | não | — | — | ix_user_role_scopes_user_id_status_starts_at | Início do período conhecido | 2026-09-12 10:00:00.000000 | Restrito |
| ends_at | DATETIME(6) | sim | NULL | não | — | — | — | Fim exclusivo; NULL significa período aberto | 2026-09-12 10:00:00.000000 | Restrito |
| reason | TEXT | sim | NULL | não | — | — | — | Motivo institucional | Decisao documentada | Restrito |
| source_document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_user_role_scopes_source_document_id | Documento que fundamenta a relação | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `user_id` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `role_id` → `roles.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `scope_id` → `scopes.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `granted_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `source_document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## workflows

Definição de workflow versionada.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Definição de workflow versionada; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_workflows_code_version | — | code | CAT_EXEMPLO | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_workflows_code_version | — | version | 1 | Restrito |
| name | VARCHAR(191) | não | nenhum | não | — | — | — | name | Designacao de exemplo | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## workflow_steps

Etapas ordenadas e papel exigido.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Etapas ordenadas e papel exigido; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| workflow_id | BIGINT UNSIGNED | não | nenhum | não | workflows.id | uq_workflow_steps_workflow_id_sequence | — | workflow id | 123 | Restrito |
| sequence | INT UNSIGNED | não | nenhum | não | — | uq_workflow_steps_workflow_id_sequence | — | sequence | 1 | Restrito |
| role_id | BIGINT UNSIGNED | não | nenhum | não | roles.id | — | ix_workflow_steps_role_id | role id | 123 | Restrito |
| action | VARCHAR(64) | não | nenhum | não | — | — | — | action | EXEMPLO | Restrito |
| requires_separation | TINYINT UNSIGNED | não | nenhum | não | — | — | — | requires separation | 1 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `workflow_id` → `workflows.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `role_id` → `roles.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## workflow_instances

Instância rastreável; vinculação à entidade via FK dessa entidade.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Processo em URL de acompanhamento autorizado.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_workflow_instances_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Processo em URL de acompanhamento autorizado | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| workflow_id | BIGINT UNSIGNED | não | nenhum | não | workflows.id | — | ix_workflow_instances_workflow_id | workflow id | 123 | Restrito |
| unit_id | BIGINT UNSIGNED | não | nenhum | não | organizational_units.id | — | ix_workflow_instances_unit_id | unit id | 123 | Restrito |
| requested_by | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_workflow_instances_requested_by | requested by | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| submitted_at | DATETIME(6) | sim | NULL | não | — | — | — | submitted at | 2026-09-12 10:00:00.000000 | Restrito |
| completed_at | DATETIME(6) | sim | NULL | não | — | — | — | completed at | 2026-09-12 10:00:00.000000 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `workflow_id` → `workflows.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `requested_by` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## membership_workflows

Vínculo explícito entre admissão e workflow; evita subject_type/id sem FK.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Vínculo explícito entre admissão e workflow; evita subject_type/id sem FK; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| membership_id | BIGINT UNSIGNED | não | nenhum | não | memberships.id | — | ix_membership_workflows_membership_id | membership id | 123 | Restrito |
| workflow_instance_id | BIGINT UNSIGNED | não | nenhum | não | workflow_instances.id | uq_membership_workflows_workflow_instance_id | — | workflow instance id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `membership_id` → `memberships.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `workflow_instance_id` → `workflow_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## workflow_tasks

Tarefa numa etapa com responsável e scope.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Tarefa em URL administrativa.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_workflow_tasks_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Tarefa em URL administrativa | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| instance_id | BIGINT UNSIGNED | não | nenhum | não | workflow_instances.id | — | ix_workflow_tasks_instance_id_step_id | instance id | 123 | Restrito |
| step_id | BIGINT UNSIGNED | não | nenhum | não | workflow_steps.id | — | ix_workflow_tasks_instance_id_step_id, ix_workflow_tasks_step_id | step id | 123 | Restrito |
| assigned_to | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_workflow_tasks_assigned_to_status_due_at | assigned to | 123 | Restrito |
| scope_id | BIGINT UNSIGNED | não | nenhum | não | scopes.id | — | ix_workflow_tasks_scope_id | scope id | 123 | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_workflow_tasks_assigned_to_status_due_at | status | DRAFT | Restrito |
| due_at | DATETIME(6) | sim | NULL | não | — | — | ix_workflow_tasks_assigned_to_status_due_at | due at | 2026-09-12 10:00:00.000000 | Restrito |
| decided_at | DATETIME(6) | sim | NULL | não | — | — | — | decided at | 2026-09-12 10:00:00.000000 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `instance_id` → `workflow_instances.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `step_id` → `workflow_steps.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `assigned_to` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `scope_id` → `scopes.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## workflow_decisions

Decisões append-only, actor/motivo/documento.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Decisões append-only, actor/motivo/documento; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| task_id | BIGINT UNSIGNED | não | nenhum | não | workflow_tasks.id | — | ix_workflow_decisions_task_id | task id | 123 | Restrito |
| actor_id | BIGINT UNSIGNED | não | nenhum | não | users.id | — | ix_workflow_decisions_actor_id | actor id | 123 | Restrito |
| decision | VARCHAR(64) | não | nenhum | não | — | — | — | decision | EXEMPLO | Restrito |
| reason | TEXT | não | nenhum | não | — | — | — | reason | Decisao documentada | Restrito |
| decided_at | DATETIME(6) | não | nenhum | não | — | — | — | decided at | 2026-09-12 10:00:00.000000 | Restrito |
| document_id | BIGINT UNSIGNED | sim | NULL | não | legal_documents.id | — | ix_workflow_decisions_document_id | document id | 123 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `task_id` → `workflow_tasks.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `actor_id` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `document_id` → `legal_documents.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## idempotency_requests

Chave por actor/operação/cliente; replay verifica hash do pedido.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Chave por actor/operação/cliente; replay verifica hash do pedido; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| actor_id | BIGINT UNSIGNED | não | nenhum | não | users.id | uq_idempotency_requests_actor_id_operation_client_key | — | actor id | 123 | Restrito |
| operation | VARCHAR(64) | não | nenhum | não | — | uq_idempotency_requests_actor_id_operation_client_key | — | operation | EXEMPLO | Restrito |
| client_key | VARCHAR(64) | não | nenhum | não | — | uq_idempotency_requests_actor_id_operation_client_key | — | client key | EXEMPLO | Restrito |
| request_hash | BINARY(32) | não | nenhum | não | — | — | — | request hash | SHA-256, 32 bytes | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| result_public_id | CHAR(26) | sim | NULL | não | — | — | — | result public id | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| expires_at | DATETIME(6) | sim | NULL | não | — | — | — | expires at | 2026-09-12 10:00:00.000000 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `actor_id` → `users.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## outbox_events

Publicação após commit por Cron/database queue; sem daemon obrigatório.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Publicação após commit por Cron/database queue; sem daemon obrigatório; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| event_kind | VARCHAR(64) | não | nenhum | não | — | — | — | event kind | EXEMPLO | Restrito |
| aggregate_public_id | CHAR(26) | não | nenhum | não | — | — | — | aggregate public id | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| payload_metadata | JSON | não | nenhum | não | — | — | — | Referências mínimas sem secrets | {} | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | ix_outbox_events_status_available_at_id | status | DRAFT | Restrito |
| available_at | DATETIME(6) | não | nenhum | não | — | — | ix_outbox_events_status_available_at_id | available at | 2026-09-12 10:00:00.000000 | Restrito |
| attempts | INT UNSIGNED | não | nenhum | não | — | — | — | attempts | 1 | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## audit_logs

Auditoria append-only; referência genérica permitida apenas em log.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Auditoria append-only; referência genérica permitida apenas em log; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | NAO | AUTO_INCREMENT | SIM | — | — | ix_audit_logs_unit_id_occurred_at_id | Chave interna imutável | 123 | Interno |
| actor_id | BIGINT UNSIGNED | SIM | NULL | NAO | users | — | ix_audit_logs_actor_id_occurred_at | actor id | 123 | Restrito |
| actor_kind | VARCHAR(64) | NAO | nenhum | NAO | — | — | — | USER, SYSTEM, IMPORT | EXEMPLO | Restrito |
| action | VARCHAR(64) | NAO | nenhum | NAO | — | — | — | Acao auditavel, incluindo CHECKIN_DENIED; razao padronizada e metadados sem token/PII | EXEMPLO | Restrito |
| entity_type | VARCHAR(64) | NAO | nenhum | NAO | — | — | ix_audit_logs_entity_type_entity_id_occurred_at | entity type | EXEMPLO | Restrito |
| entity_id | BIGINT UNSIGNED | NAO | nenhum | NAO | — | — | ix_audit_logs_entity_type_entity_id_occurred_at | entity id | 1 | Restrito |
| unit_id | BIGINT UNSIGNED | NAO | nenhum | NAO | organizational_units | — | ix_audit_logs_unit_id_occurred_at_id | unit id | 123 | Restrito |
| department_instance_id | BIGINT UNSIGNED | SIM | NULL | NAO | department_instances | — | ix_audit_logs_department_instance_id | department instance id | 123 | Restrito |
| before_metadata | JSON | SIM | NULL | NAO | — | — | — | Campos permitidos e mascarados, nunca senha/token | {} | Restrito |
| after_metadata | JSON | SIM | NULL | NAO | — | — | — | after metadata | {} | Restrito |
| reason | TEXT | SIM | NULL | NAO | — | — | — | reason | Decisao documentada | Restrito |
| source | VARCHAR(64) | NAO | nenhum | NAO | — | — | — | source | EXEMPLO | Restrito |
| occurred_at | DATETIME(6) | NAO | nenhum | NAO | — | — | ix_audit_logs_entity_type_entity_id_occurred_at, ix_audit_logs_unit_id_occurred_at_id, ix_audit_logs_actor_id_occurred_at | occurred at | 2026-09-12 10:00:00.000000 | Restrito |
| session_id | BIGINT UNSIGNED | SIM | NULL | NAO | auth_sessions | — | ix_audit_logs_session_id | session id | 123 | Restrito |
| ip_hash | BINARY(32) | SIM | NULL | NAO | — | — | — | ip hash | SHA-256, 32 bytes | Restrito |
| correlation_id | CHAR(26) | NAO | nenhum | NAO | — | — | — | correlation id | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Restrito |
| created_at | DATETIME(6) | NAO | nenhum; relógio UTC do serviço | NAO | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | NAO | 0 | NAO | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `actor_id` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `unit_id` → `organizational_units.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `department_instance_id` → `department_instances.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `session_id` → `auth_sessions.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## system_settings

Configuração tipada versionada sem secrets de infraestrutura.

Domínio: Segurança e workflows. Owner lógico: Administração de segurança. Retenção: R-SEGURANCA. Volume esperado: Até 50 mil users; 2 milhões tarefas; 20 milhões audit logs. Sensibilidade base: Restrito.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Configuração tipada versionada sem secrets de infraestrutura; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| code | VARCHAR(64) | não | nenhum | não | — | uq_system_settings_code_version | — | code | CAT_EXEMPLO | Restrito |
| version | INT UNSIGNED | não | nenhum | não | — | uq_system_settings_code_version | — | version | 1 | Restrito |
| value_metadata | JSON | não | nenhum | não | — | — | — | value metadata | {} | Restrito |
| classification | VARCHAR(64) | não | nenhum | não | — | — | — | classification | EXEMPLO | Restrito |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Restrito |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |


## import_batches

Lote staging; nenhuma importação de produção nesta fase.

Domínio: Importação. Owner lógico: Equipa de migração autorizada. Retenção: R-IMPORTACAO. Volume esperado: Até 2 milhões registos staging por lote; retenção curta. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; `public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin`, NOT NULL, UNIQUE, imutavel, aplicacao; Lote em URL de acompanhamento de importacao.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| public_id | CHAR(26) CHARACTER SET ascii COLLATE ascii_bin | nao | nenhum; gerado na aplicacao | nao | — | uq_import_batches_public_id | — | ULID publico imutavel; gerado na aplicacao, nunca reutilizado; Lote em URL de acompanhamento de importacao | 01ARZ3NDEKTSV4RRFFQ69G5FAV | Altamente sensível |
| source_system | VARCHAR(64) | não | nenhum | não | — | — | — | source system | EXEMPLO | Altamente sensível |
| file_id | BIGINT UNSIGNED | não | nenhum | não | files.id | — | ix_import_batches_file_id | file id | 123 | Altamente sensível |
| source_hash | BINARY(32) | não | nenhum | não | — | — | ix_import_batches_source_hash_mapping_version | source hash | SHA-256, 32 bytes | Altamente sensível |
| mapping_version | VARCHAR(64) | não | nenhum | não | — | — | ix_import_batches_source_hash_mapping_version | mapping version | EXEMPLO | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| approved_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_import_batches_approved_by | approved by | 123 | Altamente sensível |
| approved_at | DATETIME(6) | sim | NULL | não | — | — | — | approved at | 2026-09-12 10:00:00.000000 | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `file_id` → `files.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `approved_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## import_records

Linha bruta cifrada, hash, destino e proveniência.

Domínio: Importação. Owner lógico: Equipa de migração autorizada. Retenção: R-IMPORTACAO. Volume esperado: Até 2 milhões registos staging por lote; retenção curta. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Linha bruta cifrada, hash, destino e proveniência; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| batch_id | BIGINT UNSIGNED | não | nenhum | não | import_batches.id | uq_import_records_batch_id_row_number | — | batch id | 123 | Altamente sensível |
| row_number | INT UNSIGNED | não | nenhum | não | — | uq_import_records_batch_id_row_number | — | row number | 1 | Altamente sensível |
| raw_ciphertext | VARBINARY(2048) | não | nenhum | não | — | — | — | raw ciphertext | EXEMPLO | Altamente sensível |
| key_version | SMALLINT UNSIGNED | não | nenhum | não | — | — | — | key version | 1 | Altamente sensível |
| row_hash | BINARY(32) | não | nenhum | não | — | — | — | row hash | SHA-256, 32 bytes | Altamente sensível |
| person_id | BIGINT UNSIGNED | sim | NULL | não | people.id | — | ix_import_records_person_id | person id | 123 | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `batch_id` → `import_batches.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `person_id` → `people.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## import_issues

Anomalias por campo sem PII em texto de erro.

Domínio: Importação. Owner lógico: Equipa de migração autorizada. Retenção: R-IMPORTACAO. Volume esperado: Até 2 milhões registos staging por lote; retenção curta. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Anomalias por campo sem PII em texto de erro; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| record_id | BIGINT UNSIGNED | não | nenhum | não | import_records.id | — | ix_import_issues_record_id | record id | 123 | Altamente sensível |
| field_name | VARCHAR(64) | não | nenhum | não | — | — | — | field name | EXEMPLO | Altamente sensível |
| issue_code | VARCHAR(64) | não | nenhum | não | — | — | — | issue code | EXEMPLO | Altamente sensível |
| severity | VARCHAR(64) | não | nenhum | não | — | — | — | severity | EXEMPLO | Altamente sensível |
| resolved_by | BIGINT UNSIGNED | sim | NULL | não | users.id | — | ix_import_issues_resolved_by | resolved by | 123 | Altamente sensível |
| resolution_reason | TEXT | sim | NULL | não | — | — | — | resolution reason | EXEMPLO | Altamente sensível |
| status | VARCHAR(64) | não | nenhum | não | — | — | — | status | DRAFT | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `record_id` → `import_records.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `resolved_by` → `users.id`: 0..1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.

## import_identity_maps

Mapa explícito origem→Pessoa idempotente; nenhuma fusão por telefone isolado.

Domínio: Importação. Owner lógico: Equipa de migração autorizada. Retenção: R-IMPORTACAO. Volume esperado: Até 2 milhões registos staging por lote; retenção curta. Sensibilidade base: Altamente sensível.

Identificadores (D-01): PK interna `id BIGINT UNSIGNED AUTO_INCREMENT`; sem public_id: Registo interno: Mapa explícito origem→Pessoa idempotente; nenhuma fusão por telefone isolado; consultado pelo recurso pai/servico, sem identidade externa autonoma planeada.

| Coluna | Tipo / tamanho | NULL | Default | PK | FK | UNIQUE | Índice | Descrição | Exemplo | Sensibilidade |
|---|---|---|---|---|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | não | AUTO_INCREMENT | sim | — | — | PRIMARY | Chave interna imutável | 123 | Interno |
| source_system | VARCHAR(64) | não | nenhum | não | — | uq_import_identity_maps_source_system_source_identifier | — | source system | EXEMPLO | Altamente sensível |
| source_identifier | VARCHAR(191) | não | nenhum | não | — | uq_import_identity_maps_source_system_source_identifier | — | source identifier | EXEMPLO | Altamente sensível |
| person_id | BIGINT UNSIGNED | não | nenhum | não | people.id | — | ix_import_identity_maps_person_id | person id | 123 | Altamente sensível |
| record_id | BIGINT UNSIGNED | não | nenhum | não | import_records.id | — | ix_import_identity_maps_record_id | record id | 123 | Altamente sensível |
| created_at | DATETIME(6) | não | nenhum; relógio UTC do serviço | não | — | — | — | Data de registo, distinta da data histórica | 2026-09-12 10:00:00.000000 | Interno |
| lock_version | INT UNSIGNED | não | 0 | não | — | — | — | Controlo optimista de edição; não permite editar fechos | 0 | Interno |

- FK `person_id` → `people.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.
- FK `record_id` → `import_records.id`: 1 alvo por linha; 0..N linhas por alvo (salvo UNIQUE declarado); ON DELETE RESTRICT; ON UPDATE RESTRICT. Preservar a identidade e o histórico; PK não muda. Arquivo lógico não elimina o alvo.


## P0.2-F: candidatos fisicos e configuracao

As tres regras de credential_types foram acrescentadas ao modelo logico; exemplos nao sao seeds nem validades aprovadas. Um tipo nao e activado ate completar a politica D-05. O candidato generated general_center_key e unit_type_code esta separado em model_catalog.physical_candidates e detalhado em 04_database_constraints.md; nao foi promovido a FK/coluna do ERD logico antes da qualificacao D-08/D-11. Nenhum schema de negocio foi alterado.
