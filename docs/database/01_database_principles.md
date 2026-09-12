# Princípios do banco mestre

Estado: proposta P0.2 para auditoria independente. Referência: [baseline 1.1.1](../../mepa_crm_v1.1.1.md), ADRs 0001–0005 aceites e decisão explícita Centro/Centro Geral nesta tarefa. ADR 0009 Accepted no fecho D-01; os demais ADRs novos conservam o seu estado. Não há autorização para implementar o modelo nesta tarefa.

## Identidade e separação de domínios

Pessoa é a raiz. Aluno externo do IBT, pai Manuel não membro, mãe Isabel não membro, convidado de Congresso, visitante e contacto evangelístico usam people. Matrícula, parentesco ou contribuição não exige membership. Uma admissão posterior acrescenta memberships à mesma Pessoa. Obreiro é uma Pessoa com relações ministeriais. User humano liga-se à Pessoa; conta de serviço é excepção técnica explícita.

memberships.person_id e member_numbers.membership_id são únicos durante toda a vida, mais fortes que um número activo. Saída ou transferência não libera número. Legado mantém texto bruto, normalizado, fonte e anomalias. Não fundir automaticamente por nome/telefone/documento não verificado. Família e agregado são relações distintas; parentesco não autoriza recolha de menor.

Órgão permanente não é unidade territorial nem reunião. governance_sessions liga órgão ao evento concreto. Convocação, confirmação, entrada, credenciamento e presença têm estados diferentes. Lista seleccionada é congelada/versionada para preservar convocados apesar de promoções posteriores. Departamento tem definição, aplicabilidade, instância local, lugar de cargo e nomeação. NON_CONSTITUTED distingue ausência institucional; VACANT significa SEM NOMEAÇÃO, sem Pessoa fictícia; INTERIM exige nomeação interina vigente.

Unidade não é edifício. Imóvel, local físico, templo, instalação e ocupação são identidades separadas. Mudança fecha vínculo de local e abre outro sem renumerar unidade ou membros. Nomes department_categories/departments/department_instances/department_posts/department_appointments foram conservados.

## Tipos e identificadores

ADR 0009 Accepted: id BIGINT UNSIGNED AUTO_INCREMENT interno e FKs BIGINT UNSIGNED para target.id. Nas entidades classificadas, public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin, NOT NULL, UNIQUE, imutável, gerado na aplicação e nunca reutilizado. D-01 RESOLVED por decisão técnica explícita P0.2-D01. O dicionário e o catálogo justificam 54 entidades com identidade externa e 145 sem public_id; não é uma coluna herdada de todas as tabelas. ULID não é autorização, revela tempo aproximado e não garante ordem de commit. Número MEPA permanece separado e não é PK/FK estrutural.

Laravel futuro: gerador partilhado Str::ulid(), chave Eloquent inteira/incrementing, route binding por public_id e policy/scope após resolução. Trait/service não implementados nesta fase. Criação offline usa chave de cliente/idempotência e resolve a entidade no servidor, mantendo a PK central.

InnoDB, utf8mb4, collation textual proposta utf8mb4_unicode_ci por disponibilidade comum; não herdar default nem exportar utf8mb4_0900_ai_ci para MariaDB. Códigos/identificadores usam comparação binária. Login normalizado antes de UNIQUE; nome não é único. Campos privados usam cifra autenticada e key_version. Índices cegos usam HMAC keyed, não SHA simples de BI/telefone. Rotação é por lote, sem divulgar secrets em log.

Dinheiro DECIMAL(19,4): 15 dígitos inteiros e quatro casas, máximo 999999999999999.9999. Quatro casas conservam cálculo intermédio; liquidação AOA segue escala institucional aprovada, normalmente duas. Nunca FLOAT ou soma de moedas distintas. Totais usam acumulador decimal mais largo, proposta DECIMAL(24,4), e verificação de overflow. Números legados/telefone são texto; size_bytes BIGINT. Notas usam DECIMAL(9,4). Proporção completion_ratio entre 0 e 1; pesos percentuais entre 0 e 100 segundo política académica ainda por aprovar.

Latitude DECIMAL(9,6), longitude DECIMAL(10,6); ambas presentes ou ambas NULL; intervalos [-90,90]/[-180,180]. Seis casas não afirmam precisão real do GPS. Shared hosting usa bounding box e Haversine no serviço para locais APPROVED_PUBLIC, tratando antimeridiano e limitando candidatos. B-tree não é spatial; medir selectividade. Na VPS pode acrescentar POINT/SRID 4326/índice espacial pelo adapter, sem substituir identidade nem impor extensão agora.

## Tempo, exclusão e operação

Instantes DATETIME(6) UTC, conexão UTC; datas civis DATE sem meia-noite UTC fictícia. DATETIME evita conversões automáticas/limites de TIMESTAMP. Interface Africa/Luanda; AA/MM do número segue período institucional aprovado e guarda ano completo/instante. created_at é quando se registou, não início histórico. Data desconhecida não é inventada; precisão explícita e staging até regularização.

Catálogos configuráveis usam lookup/FK. Estados técnicos fechados usam código validado por CHECK/serviço, sem ENUM indiscriminado ou catálogo universal de estado. JSON somente evidência/metadados verdadeiramente variáveis, política versionada e auditoria mascarada; nunca substituir People, FKs ou ledger. Dimensões estatísticas não são EAV da Pessoa.

ON DELETE/UPDATE RESTRICT em todas as FKs propostas. PK imutável; catálogo desactivado, não apagado. Arquivo específico people/users.archived_at, estado de unidade e tombstone de files; não deleted_at indiscriminado. Ledger POSTED, nomeações publicadas e fechos são imutáveis; corrigir por estorno/versão, motivo e auditoria.

lock_version dá compare-and-swap de rascunho, não garante agregados. Autorizar backend, bloquear âncoras preexistentes em ordem, validar, gravar fontes+auditoria+outbox num commit. Não divulgar número/QR definitivo antes do commit. Cron/database queue processa outbox; retry integral limitado e idempotência. Sem Redis/daemon/Docker obrigatório.

Referências verificadas: [MySQL CHECK 8.0.16](https://dev.mysql.com/blog-archive/mysql-8-0-16-introducing-check-constraint/), [InnoDB locks](https://dev.mysql.com/doc/refman/8.0/en/innodb-locks-set.html), [FK MySQL](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html), [FK MariaDB](https://mariadb.com/docs/server/architecture/server-constraints/foreign-key-constraints) e [collations MariaDB](https://mariadb.com/docs/server/reference/data-types/string-data-types/character-sets/setting-character-sets-and-collations). Hosting/versão reais ainda precisam de prova isolada.
