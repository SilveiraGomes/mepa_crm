# FundaÃ§Ã£o fÃ­sica â€” Wave 1

AutorizaÃ§Ã£o P0.3.1, limitada Ã  onda 1. Fontes de autoridade: ADRs, model_catalog.json, 02_data_dictionary.md e 04_database_constraints.md. [Plano completo](../12_physical_migration_plan.md). Nenhum dado real ou secret nestes artefactos.

## ConvenÃ§Ãµes

InnoDB; utf8mb4/utf8mb4_unicode_ci explÃ­citos. code usa utf8mb4_bin segundo a polÃ­tica binÃ¡ria de cÃ³digos em 01_database_principles. PK id BIGINT UNSIGNED AUTO_INCREMENT; FKs BIGINT UNSIGNED para id, RESTRICT/RESTRICT. public_id sÃ³ nas oito entidades classificadas: CHAR(26), ascii/ascii_bin, NOT NULL, UNIQUE e sem default SQL. Instantes DATETIME(6) UTC, datas civis DATE. Nenhum updated_at/deleted_at herdado; files conserva seus campos de tombstone documentados. Cifra e HMAC sÃ£o bytes, telefone nÃ£o Ã© nÃºmero SQL.

DDL MySQL explÃ­cito dentro das migrations Laravel evita substituiÃ§Ã£o de VARBINARY/BINARY por BLOB e preserva precisÃ£o/nome de CHECKs. up cria uma tabela com suas constraints; down remove somente essa tabela. Ordem topolÃ³gica de criaÃ§Ã£o e ordem inversa de remoÃ§Ã£o validadas pela captura de execuÃ§Ã£o dos mÃ©todos. Commits implÃ­citos de DDL nÃ£o permitem rollback global automÃ¡tico de falha de deploy.

## ValidaÃ§Ãµes sem banco

```powershell
node scripts/validate-database-docs.cjs
node scripts/validate-wave1-schema.cjs --static
node scripts/validate-wave1-schema.cjs --static --allow-deferred
node --test tests/database/wave1-validator.test.cjs
```

O modo estÃ¡tico executa os mÃ©todos Laravel up/down contra um recorder e analisa o DDL emitido. NÃ£o qualifica MySQL, CHECK enforcement, migrate ou rollback. O modo estrito retorna exit 1 para W1-F01; --allow-deferred valida somente o schema parcial planeado, sempre incluindo strict_errors. NÃ£o usar essa opÃ§Ã£o para declarar o Gate aprovado.

## Testes fÃ­sicos isolados

Disponibilizar uma base **vazia** local MySQL >=8.0.16, nome `mepa_wave1_test_*`, com acesso explÃ­cito. NÃ£o reutilizar banco de negÃ³cio. Configurar WAVE1_DSN, WAVE1_USER, WAVE1_PASSWORD por ambiente e WAVE1_ALLOW_SYNTHETIC=1. NÃ£o colocar secrets em comandos versionados ou ficheiros deste directÃ³rio.

```powershell
$env:DB_CAPABILITIES_DSN=$env:WAVE1_DSN
$env:DB_CAPABILITIES_USER=$env:WAVE1_USER
$env:DB_CAPABILITIES_PASSWORD=$env:WAVE1_PASSWORD
php scripts/check-database-capabilities.php
$env:WAVE1_KEEP_SCHEMA="1" # conservar schema sint?tico para introspec??o ap?s a suite
Push-Location apps/api
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml tests/Database/WaveOnePhysicalTest.php
Pop-Location
node scripts/validate-wave1-schema.cjs --allow-deferred
node scripts/validate-wave1-schema.cjs
```

O teste executa novamente o preflight existente antes de qualquer DDL, usa Capsule e o Migrator Laravel reais, nÃ£o carrega .env, exige SHOW TABLES vazio e instala o scaffolding original em batch separado. Migra 31 tabelas, rollback de Wave 1 preserva scaffolding, remigra e testa fixtures sintÃ©ticos em transacÃ§Ãµes. A limpeza final remove somente Wave 1; deixa scaffolding/repositÃ³rio de migrations. ApÃ³s falha de DDL conservar a base para diagnÃ³stico; uma repetiÃ§Ã£o exige outra base vazia autorizada. NÃ£o desactivar foreign_key_checks/CHECK nem usar SQLite.

O inspector lÃª information_schema, SHOW CREATE TABLE e metadados de versÃ£o; nÃ£o lÃª linhas de negÃ³cio. O validador compara tipos, NULL/defaults, charset/collation, PK, FKs/acÃ§Ãµes/alvos, UNIQUE, Ã­ndices e CHECKs/enforcement. AST de CHECK conserva precedÃªncia lÃ³gica. `--snapshot docs/database/physical/wave1_schema.sql` sÃ³ escreve snapshot real apÃ³s paridade fÃ­sica estrita; enquanto faltar W1-F01 esse snapshot Ã© recusado. wave1_planned_schema.sql Ã© DDL previsto, nÃ£o snapshot executado. wave1_schema_report.md regista a introspec??o real MySQL ap?s execu??o. wave1_partial_schema.sql ? o snapshot real do schema parcial, com W1-F01 expl?cita; n?o representa paridade estrita aprovada.

## PendÃªncias explÃ­citas

W1-F01: FK files.owner_department_id pendente atÃ© department_instances existir na onda 2. O schema parcial nÃ£o Ã© implantÃ¡vel. NÃ£o hÃ¡ serviÃ§o autorizado de escrita nesta fase; nÃ£o produzir vÃ­nculos departamentais. A adaptaÃ§Ã£o de users do scaffolding para o modelo aprovado Ã© trabalho futuro com inventÃ¡rio/backfill.

Self-parent, ciclos, tipo de pai/filho, mÃ­nimo municipal, mÃ¡ximo Centro Geral e sobreposiÃ§Ã£o de intervalos: `NOT YET EXECUTABLE â€” APPLICATION LAYER WAVE`. Os testes territoriais positivos provam apenas persistÃªncia dos pares permitidos; nÃ£o testam falsa rejeiÃ§Ã£o de estados que a FK simples aceita. NÃ£o aplicar o candidato generated+UNIQUE nem FK composta de physical_candidates.

D-02..D-12 nÃ£o alteradas. `SCHEMA CAN BE CREATED / SEED IS BLOCKED`: catÃ¡logos institucionais e Ã¡rvores reais continuam sem seed. NATIONAL_TREE e materializaÃ§Ã£o de unit_parent_rules terÃ£o de ser provisionados antes dos serviÃ§os de mutaÃ§Ã£o conforme as decisÃµes aprovadas; esta fase cria apenas a estrutura.

## Qualifica??o de CHECK e FKs

A documenta??o MySQL pro?be CHECK sobre AUTO_INCREMENT. No c?digo oficial 8.0, a restri??o de ac??es referenciais cobre SET NULL/SET DEFAULT/UPDATE CASCADE; RESTRICT usado nesta onda n?o cai nessas condi??es. O ensaio real do DDL confirmou a cria??o e enforcement das constraints. Fontes: [manual CHECK](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html), [sql_table.cc oficial](https://github.com/mysql/mysql-server/blob/8.0/sql/sql_table.cc#L6153).
