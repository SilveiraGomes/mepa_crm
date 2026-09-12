# Relatório P0.2 para auditoria

## Resumo executivo

Arquitectura documental proposta com 199 tabelas, 1705 colunas e 439 relações FK. Pessoa é raiz; membership, aluno, responsável, convidado e user reutilizam a identidade. Estrutura territorial, governança, imóveis e departamentos separados. Ledger nacional permite partidas dobradas e eliminações interunidades. v1.1.1 corrige Centro Geral opcional e preserva v1.1.0. Esta entrega não é aprovação do modelo.

## Artefactos criados e revistos

Criados docs/database/01_database_principles.md a 09_open_database_decisions.md, este relatório, model_catalog.json, docs/diagrams/mepa_erd_master.mmd e 14 diagramas por domínio. Criados ADRs 0006–0011, revistos ADRs 0001–0005 sem mudar Accepted. Criada mepa_crm_v1.1.1.md; v1.1.0 conservada. Revistos README, CONTRIBUTING, CHANGELOG, database README, development-rules e referências das quatro skills mepa-*. Graphify reconstruído, resultado final abaixo; criado scripts/validate-database-docs.cjs para validação documental reproduzível. Não foram alterados controllers, páginas, models de negócio, migrations ou banco real.

Estado Git prévio incluía eliminação v1.0.1 e v1.1.0 sem rastreamento. Não foi efectuado commit nem apagada v1.1.0. SHA-256 da versão preservada: 832B9DCB54C8E952EFFEB1419EFC15110B5ED30F230AF0B617186ADF86480439. Inclusão das duas versões no commit autorizado continua a preservar o histórico sem substituir trabalho anterior.

## Decisões principais

Pessoa única transversal; membership e número vitalícios; sequência nacional independente de AA/MM; catálogos normalizados; vaga como lugar persistente VACANT e interino explícito; períodos semiabertos; versões publicadas imutáveis; local/imóvel separado da unidade; metadados privados de storage e tombstone; backend RBAC concede papel+scope conjuntamente; outbox/Cron e idempotência para shared hosting. BIGINT interno+ULID público é proposta divergente da recomendação técnica anterior, ADR 0009/D-01.

## Constraints principais

UNIQUE memberships.person_id, member_numbers.membership_id/number/sequence_value, event_checkins(session_id,person_id) e etapas de transfer_postings. FKs RESTRICT em delete/update; CHECK de linha complementado por serviço. Singleton nacional serializa geração; singleton da árvore serializa ciclos/cardinalidades/reparenting; âncoras preexistentes impedem sobreposição. Cabeçalho/período bloqueados antes de somar e publicar ledger, com writers de linha obrigados ao mesmo lock. Igualdade de contexto evento/turma/unidade/fundo/moeda é explícita validação transaccional onde FK simples não basta.

## Revisão adversarial executada

| Risco examinado | Resultado / correcção no desenho |
|---|---|
| Duplicar aluno/pai/convidado ao admitir membro | Mesma Pessoa; memberships.person_id único; mapa import e fusão auditada |
| Perder cargo/classe/Congregação/pai de unidade | Períodos, documentos, versões e auditoria; árvores históricas não usam parent_id actual |
| FK permitir estado impossível | Igualdade evento/inscrição/credencial/turma/unidade explicitada no serviço; FKs compostas a avaliar D-11 |
| NULL sem significado | Vaga explícita, scope nacional sem NULL global, geo emparelhada, fim aberto/documento desconhecido documentados |
| Corrida em conjunto vazio | Bloquear singleton/Pessoa/lugar existente, não consulta vazia |
| Índice faltar ou duplicar | FK com suporte, índices por consulta e prefixo esquerdo; não status isolado nem JSON/ciphertext |
| Cascade destruir prova | Todas as FKs RESTRICT; arquivo específico, tombstone e estornos |
| Transferência duplicar receita | Exemplo SEND/RECEIVE equilibra unidades; consolidado elimina par interunidades, trânsito não recebido mantido |
| Menor exposto/recolha inválida | Classificação reforçada; autorização independente do parentesco; locks/check-out/consentimento |
| User atravessar scope | Papel e scope na mesma concessão, permissão/acção/dado/department validados no backend |
| Shared hosting limitar | Cron/outbox/database queue; quota BD/media/log estimada e qualificação pendente |
| VPS exigir reescrita | PK/domain/FKs mantidos; storage/spatial/cache/workers por adapters |
| Tipo reason temporal incorrecto | Corrigido para TEXT no catálogo e dicionário; validação mecânica repetida |

## Decisões abertas e riscos críticos

D-01 a D-12 em [decisões abertas](09_open_database_decisions.md): PK, v2 legados/data, seis dígitos/próximo século, contabilidade/rubricas/FX, credenciais/QR, retenção/legal hold, catálogos/incompatibilidades/idades, hosting/privilégios/revogação scope, critérios académicos, datas históricas/bitemporalidade, estados/FKs compostas e catálogo territorial.

Não implementar sem fechar decisões que condicionam o domínio respectivo. Riscos críticos: serviço transaccional é responsável por constraints de agregado; acesso SQL externo pode contorná-las; logs milhões podem exceder plano; histórico de início desconhecido ainda por fechar; 999999 é limite de formato, não de PK; privacidade/retention dependem de aprovação; desenho contabilístico precisa revisão por contabilista designado. Esta revisão interna não substitui a auditoria independente.

## MySQL/MariaDB e shared hosting

Target proposto MySQL >=8.0.16 com InnoDB/utf8mb4/strict mode/UTC e provas de enforcement. Compatibilidade MariaDB deve usar versão real do provedor. Collation textual portátil proposta unicode_ci, identificadores binários, DECIMAL para dinheiro, DATETIME para instantes, FK real. Sem extensão geospatial/Redis/daemon obrigatório. CHECK não garante agregados; índices parciais não presumidos. DDL/deploy tem commits implícitos e requer expand/backfill/validate/contract/restore. [MySQL CHECK](https://dev.mysql.com/blog-archive/mysql-8-0-16-introducing-check-constraint/) e [MariaDB FK](https://mariadb.com/docs/server/architecture/server-constraints/foreign-key-constraints) sustentam a qualificação técnica.

## Graphify

Consulta inicial: graphify query 'Pessoa árvore Centro Geral ledger histórico impacto P0.2' --budget 1800. Funcionou, mas devolveu 13 nós de infraestrutura/migrations, sem cobertura útil de domínio. Expansão contra vocabulário real identificou User/Database/Schema/Migrations/users/tokens; domínio ainda ausente do grafo inicial code-only (324 nós). Não se usou esse resultado como regra de negócio.

Detecção antes da extracção: 141 ficheiros, aproximadamente 200422 palavras, 63 fontes semânticas sem cache, nenhum skipped_sensitive. Extracção semântica em três chunks, por agentes conforme exigência da skill Graphify, paralela ao AST; AST sem o script transitório: 349 nós/353 relações. Custo semântico em tokens não é mensurável nesta interface; campos zero significam indisponível, não custo nulo. Resultado: 1573 nós, 2838 arestas e 155 comunidades, grafo dirigido; 81 fontes guardadas em cache. Outputs graphify-out/graph.json, graph.html e GRAPH_REPORT.md; evidência integral em p02_extraction.json, diagnóstico em p02_health.json e resumo em p02_build_summary.json. Foram preservadas e verificadas no grafo exportado todas as 439 FKs por caminhos tabela → coluna FK → alvo. A extracção semântica tem zero endpoints pendentes e zero selfloops. O diagnóstico completo ainda reporta 21 endpoints ausentes de imports AST, 1 selfloop AST e 3 relações AST susceptíveis de fusão em 2 grupos; limitações da infraestrutura existente, declaradas sem as esconder. Não há alegação de saúde perfeita do grafo completo. Consulta final com vocabulário confirmado people/organizational/units/journal/entries/member/numbers encontrou 8 nós do domínio; a heurística field limitou a navegação, pelo que graphify explain member_numbers foi também executado e devolveu 11 conexões. Benchmark oficial executado; redução é estimativa sobre perguntas genéricas, não medição do tempo desta tarefa.

## Validações documentais

Parser real Mermaid 11 aceitou os 15 ficheiros .mmd. Catálogo: 199 entidades, 1705 colunas, 439 FKs e exactamente 439 relações no Master. Verificados campos obrigatórios, alvos FK, política RESTRICT, cobertura por secção do dicionário, tipos reason TEXT e ausência de FLOAT/DOUBLE. Links locais revalidados após criação deste relatório, sem erros. node scripts/validate-database-docs.cjs passou. Verificados nomes de índices <=64 caracteres; três nomes longos foram encurtados. Verificação dos 439 caminhos FK no grafo exportado passou sem erros. Referências v1.0.1/v1.1.0 remanescentes são de histórico, não canónicas. Revisão narrativa com Humanizer, sem alterar SQL/identificadores/regras estatutárias. Nenhum teste de banco, integração funcional ou concorrência foi executado: são recomendações para a fase física isolada, não resultados alegados.

## Próxima fase recomendada

Auditoria independente do modelo e revisão contabilística; resolver D-01/D-04/D-06/D-08/D-10/D-11 conforme escopo, fechar catálogos e aprovar ADRs técnicos. Só então contratos/API/scopes detalhados e migrations por domínio em banco sintético isolado, com ensaios de concorrência, reimportação e restauro antes do piloto.

P0.2 — ARQUITECTURA DO BANCO DE DADOS PRONTA PARA AUDITORIA
