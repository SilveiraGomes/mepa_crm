# Migração e qualificação

Estado: plano documental. Nenhuma ligação à BD real, importação, seed massivo ou migration de negócio nesta fase.

Inspecção: Laravel/React, model User e migrations users/password_resets/failed_jobs/personal_access_tokens. Sem SQL legado/CRM anterior/gerador antigo/schema de negócio/dados de exemplo nas áreas versionáveis inspeccionadas. Formato antigo na baseline não prova código disponível. Rubricas são catálogo textual; Arrendamento por classificar. Fontes de origem serão necessárias na fase de migração, sem procurar dados privados fora do âmbito.

Git inicial: v1.0.1 já eliminada e v1.1.0 untracked, alterações prévias. v1.1.0 preservada byte a byte; v1.1.1 corrige hierarquia/referências. Não apagar v1.1.0 nem fazer commit automático para contornar estado anterior. No commit autorizado incluir ambas versões para preservar histórico; v1.0.1 continua no histórico já existente.

## Pipeline futuro

Upload privado/quarentena → lote/checksum → staging cifrado → normalização mapping_version → deduplicação candidata/revisão humana → import_identity_maps → validação campos/FK/tipos/ciclos/períodos/financeiro → preview/totais → aprovação auditada → lotes transaccionais idempotentes → reconciliação por domínio → relatório de aceite → purga segundo política.

Raw não é sobrescrito pelo normalizado. Produção fora do Git/Graphify. UNIQUE sistema+id origem permite replay. Detectar BI/número legado repetido, telefone partilhado, nome+nascimento próximo, Congregação/cargo desconhecidos e pais em texto. Telefone/nome são indícios; não fundir automaticamente. Família vira Pessoas relacionadas. Fusão bloqueia ambas Pessoas em ordem, verifica conflitos números/documentos/períodos e reatribui referências com auditoria mantendo merged_into_id; não criar outra membership para contornar UNIQUE.

Legado Cargo+Província+Município+Sequência(4)+Nível(2)+Ano(2) fica texto bruto/proveniência, nunca regenerado. Duplicados bloqueiam aceite até resolução, não staging. Política v2 legados/data D-02; não inventar AA/MM. V2 já confirmado valida formato/mês/ano/sequência sob mesmo singleton gerador; ajustar máximo validado sem emissão concorrente. Nunca MAX+1 concorrente.

Financeiro antigo exige classificação receita/despesa/activo/passivo/transferência/principal de empréstimo/em espécie. Não transformar cada linha Excel em nova receita. Saldos iniciais por unidade/conta/fundo/moeda reconciliam com documentos/extractos/balancete assinado. Não inventar partida de equilíbrio sem contabilista.

## Depois da auditoria

Lookup/estrutura/âncoras → Pessoa/família → storage/documentos → users/scopes/workflows → membership/credenciais → ministério/departamentos/eventos/crianças/evangelismo → academia → ledger → comunicação/snapshots/import/outbox/auditoria. Ciclos de criação opcionais files.created_by/users, documents/files e department/files requerem criar tabelas antes de adicionar FKs, sem foreign_key_checks global como técnica de validação. DDL MySQL pode ter commit implícito; deploy inteiro não é transacção de dados reversível.

Não recriar users ocupada: adaptar campos/backfill deliberado após inventário de ambientes. Separar expand schema/backfill em lotes/validate/contract. Rollback ledger publicado é estorno, não down destrutivo. Restore/janela/locks ensaiados antes do piloto.

Qualificação sintética isolada: MySQL >=8.0.16 e MariaDB real; InnoDB/utf8mb4/strict SQL modes; FK/UNIQUE/CHECK rejeitam inválidos; locks/retries/idempotência; collation/HMAC/UTC; limite índices/JSON/packet/quota/inodes/Cron/privilégios/backup. Contratos API/scopes antes de implementar. Ensaiar externo→membro, duas emissões simultâneas, replay pós-commit, transferência/consolidado, restore e reimport idempotente. Estes testes de DB não foram executados nesta fase documental.

## Evidencia posterior P0.2-F

A declaracao anterior de ausencia de testes refere-se a entrega documental P0.2 original. P0.2-F executou 16 ensaios sinteticos num MariaDB 10.4.32 temporario; resultados em ../reviews/P0.2F_test_results.json. Isso nao qualifica producao. Baseline/diagnostico e motor exacto bloqueados D-08: ver 10_database_engine_compatibility.md. Historicamente D-01..D-12 estavam marcadas como bloqueio global. A re-auditoria reviu essa classificacao: D-01 e RESOLVED em P0.2-D01, D-02..D-12 conservam bloqueios de seeds/activacao/implantacao e alteracoes fisicas especificas. P0.3 nao inicia nesta tarefa; Gate final necessario.
