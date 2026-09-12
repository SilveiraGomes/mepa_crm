# Compatibilidade do motor — P0.2-F

Estado: TECHNICAL_DECISION proposta para re-auditoria. D-08 continua BLOCKED: não há versão/enforcement do hosting nem baseline institucionalmente homologado. Não actualizar o XAMPP automaticamente. Diagnóstico de leitura e ensaio isolado são etapas distintas.

| Motor | Baseline técnico | Condição de produção |
|---|---|---|
| MySQL 8.x | >= 8.0.16 para enforcement CHECK | Patch e linha ainda mantidos pelo fornecedor, InnoDB, configuração qualificada e ensaio no motor exacto; preferir linha LTS mantida |
| MariaDB | >= 10.11.2, linha 10.11 ou sucessora mantida e qualificada | Patch mantido, InnoDB, check_constraint_checks=1 e ensaio equivalente; não basta dizer “compatível com MySQL” |
| MySQL < 8.0.16 / MariaDB < 10.11.2 | DEPLOYMENT_BLOCKER | Sem downgrade automático nem substituição silenciosa de constraints |
| Outros compatíveis/proxies | Não qualificados | DEPLOYMENT_BLOCKER até verificação explícita |

MySQL aplica CHECK a partir de 8.0.16; expressões não consultam outras tabelas e não podem referir AUTO_INCREMENT. Por isso self-parent com id técnico é APP_ENFORCED. [Manual MySQL CHECK](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html).

MariaDB suporta CHECK desde 10.2.1, mas permite desactivação por check_constraint_checks. O mínimo de produção aqui é mais recente que o mínimo funcional e continua proposta: exige linha mantida e homologação do hosting. [MariaDB constraints](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint), [manutenção MariaDB](https://mariadb.org/about/).

Configuração exigida: tabelas InnoDB transaccionais, FK activas, utf8mb4; texto portátil preferencial utf8mb4_unicode_ci, identificadores técnicos com collation binária compatível em ambos os lados da FK. Não exportar utf8mb4_0900_ai_ci do MySQL para MariaDB sem conversão planeada. strict SQL mode em todas as conexões, UTC no serviço, DECIMAL com escala explícita, PDO sem emulação e sem tolerar INSERT IGNORE nos fluxos críticos. O serviço não deve desactivar FK/CHECK. Runtime sem DDL, deploy separado; bloqueio se privilégios/cron/restore não forem viáveis no hosting.

| Invariante | Classe | Enforcement e alternativa |
|---|---|---|
| PK, número público, número MEPA único, check-in pessoa/sessão, etapa de transferência | DB_AND_APP | PK/UNIQUE + serviço e replay determinístico; nenhuma alternativa autoriza duplicação |
| Existência e preservação dos alvos | DB_AND_APP | FK RESTRICT + validação de estado/contexto; sem CASCADE |
| Valores de linha, booleanos, intervalos, escala/XOR local e campos obrigatórios | DB_AND_APP | NOT NULL/CHECK quando expressão legal + mesma validação no serviço; motor sem CHECK é bloqueado |
| parent_id != id; ciclos | APP_ENFORCED | Singleton de árvore, percurso e job; CHECK sobre AUTO_INCREMENT não é opção portátil |
| Mínimo municipal, pai por tipo/estado/município | APP_ENFORCED | Serviço transaccional + manifesto + job; FK simples não prova essa regra |
| Máximo um Centro Geral | DB_AND_APP candidato | Generated STORED + UNIQUE e código ligado por FK composta, após qualificação; enquanto proposta: serviço sob singleton, sem alegar constraint aplicada |
| Débitos=créditos por entry/unidade/fundo | APP_ENFORCED | Soma de DECIMAL sob locks; CHECK de linha não soma agregado; nenhum write de linhas ignora lock de cabeçalho |
| Saldo de dívida/caixa e consolidação sem receita interna | APP_ENFORCED | Ledger POSTED, alocações sob âncora e eliminações por transferência |
| Autorização/revogação/menor/validade | APP_ENFORCED | Verificação servidor e locks de âncoras; cache/cliente não é fonte de permissão |
| Versão/enforcement/engine/charset/strict/qualificação exacta | DEPLOYMENT_BLOCKER | Falhar preflight ou Gate; não implantar com teste apenas noutro motor |

Para CHECK com NULL: UNKNOWN pode passar; NOT NULL e XOR explícito complementam a expressão. Campos em acções referenciais têm limitações por versão. Ensaiar cada expressão com valores válidos, inválidos e NULL no DDL isolado exacto antes de a classificar DB_ENFORCED. Não prometer CHECK universal a partir de uma lista conceptual.

O candidato generated não usa FK sobre a coluna gerada; o par id/código usa colunas normais. MySQL suporta índices em colunas generated com limitações; portabilidade não significa SQL idêntico sem ensaio. [Manual MySQL generated](https://dev.mysql.com/doc/refman/8.0/en/create-table-generated-columns.html).

## Diagnóstico sem alterar schema

[scripts/check-database-capabilities.php](../../scripts/check-database-capabilities.php) lê exclusivamente SELECT/SHOW/Information Schema. DSN explícito DB_CAPABILITIES_DSN e utilizador/password por ambiente; nunca carrega .env ou descobre credenciais. Executar com utilizador de leitura autorizado. Não publicar credenciais ou nomes de BD reais em artefactos públicos.

```powershell
$env:DB_CAPABILITIES_DSN='mysql:host=127.0.0.1;port=33079;dbname=p02f_architecture;charset=utf8mb4'
$env:DB_CAPABILITIES_USER='root'
& C:/xampp/php/php.exe scripts/check-database-capabilities.php
```

Exit 0: READ_ONLY_PREFLIGHT_PASS, não aprovação de deploy. Exit 1: DEPLOYMENT_BLOCKER. Exit 2: DSN ausente/erro de ligação/inspecção. VERSION_SUPPORTED_NOT_EMPIRICALLY_PROBED declara o limite do diagnóstico. Versão e flags permitem inferir capacidade; um script apenas de leitura não pode provar rejeição de INSERT sem um teste separado. Informação CHECK ENFORCED é inspeccionada em MySQL compatível; MariaDB verifica flag. Mostrar InnoDB com transacções/savepoints, FK, strict, charset/collation e tabelas inspeccionadas. Schema vazio não conta como schema qualificado.

Qualificação D-08: obter versão exacta, patch/linha mantida, grants e configuração do fornecedor; correr preflight; executar fixtures isoladas com CHECK inválido/FK inválida/UNIQUE/rollback/locks; testar todos os CHECK/candidatos físicos; anexar resultados, restore e responsável; homologar antes da implantação. Não executar DDL de teste na BD de negócio.

Evidência local em 2026-09-12: PHP 8.0.30; MariaDB 10.4.32 numa instância temporária porta 33079, sem tocar no serviço XAMPP. O protótipo demonstra rejeição real CHECK/FK/UNIQUE e transacções nesse motor. O preflight rejeita correctamente a versão abaixo de 10.11.2. Não houve ensaio em MySQL 8.x nem no hosting: esses passos permanecem BLOCKED D-08 e não se substituem por documentação do fornecedor. PHP local também não homologa o runtime Laravel futuro.