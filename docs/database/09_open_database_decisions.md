# Decisões abertas

Proposta para auditoria. Bloqueios são da fase física correspondente, não impedem a entrega documental. Não preencher dúvidas com requisitos inventados.

| ID | Decisão/evidência | Owner | Bloqueia |
|---|---|---|---|
| D-01 — RESOLVED | BIGINT UNSIGNED AUTO_INCREMENT + ULID público selectivo CHAR(26) ascii/ascii_bin; ADR 0009 Accepted | Arquitectura; pedido P0.2-D01 | Nenhum por D-01; Gate final ainda necessário |
| D-02 | V2 para legados, emissão versus admissão e datas desconhecidas | Secretaria | Gerador/importação |
| D-03 | Expansão antes 999999, próximo século AA, tolerância lacunas | Secretaria/arquitectura | Operação futura número |
| D-04 | Regime contabilístico/plano/fundos/interunidades/AOA-FX/em espécie/Arrendamento | Contabilista/finanças | Financeiro/seeds |
| D-05 | Validade/reemissão/elegibilidade/reentrada/QR público/cores/templates | Secretaria/eventos/privacidade | Passe/API pública |
| D-06 | Fundamentos/prazos/legal hold/pedidos titular/purga/backups | Instituição/privacidade | Purga/export/implantação |
| D-07 | Departamentos/cargos/classes/funções/capacidade/incompatibilidade/faixas/progressão | Direcção/departamentos | Seeds/nomeação |
| D-08 | Hosting/versão/enforcement CHECK/privilégios/Cron/quota/inodes/restore; revogação concorrente scope | Operações/segurança | Implantação/constraints |
| D-09 | Critérios/notas/pesos/tentativas/rematrícula/certificados/transcripts | Academia | Migrations académicas |
| D-10 | Início histórico desconhecido/DATE ou instante/revisão retroactiva/bitemporalidade | Secretaria/arquitectura | Períodos/backfill |
| D-11 | Estados exactos por tabela/transições/segregação/FKs compostas de contexto | Owners/arquitectura | Constraints/serviços |
| D-12 | Catálogo territorial/códigos/raízes nacionais activas | Direcção administrativa | Seeds árvore |

ADRs 0001–0005 Accepted foram revistos por referência canónica/aprofundamento documental, sem fingir nova aprovação. ADR 0006 regista Centro/Centro Geral autorizado nesta tarefa. Novas alternativas técnicas são Proposed. Ausência de fonte legado fica declarada; não se exige Redis/spatial/chave LLM para concluir documentação.

## P0.2-F — bloqueios após reconciliação

A matriz completa está em [P0.2_open_decisions_matrix.md](../reviews/P0.2_open_decisions_matrix.md). O registo anterior P0.2-F é histórico; D-01 foi RESOLVED em P0.2-D01 e D-02..D-12 continuam abertas. A aceitação anterior dos pares da árvore e do Centro Geral opcional não fecha o catálogo territorial D-12. O diagnóstico de motor e o protótipo não fecham contratação/qualificação D-08 nem aprovação contabilística D-04. Nenhuma recomendação técnica equivale a acta institucional. A re-auditoria distingue escrita de DDL base de seeds/activação/implantação: D-02..D-12 não são bloqueios globais de desenho, conservando os bloqueios específicos. Esta tarefa não inicia P0.3; prepara Gate final.

D-05: colunas de configuração já propostas no modelo; falta política aprovada. D-06/D-11: deduplicação de documentos não UNIQUE e conflitos precisam de política. D-06/D-08: cofre, responsável nomeado e ciclo de chaves pendentes. D-11: candidato generated+UNIQUE e FK composta da árvore precisam ser promovidos ao modelo físico após qualificação. Não existem constraints físicas de negócio aplicadas nesta fase.
## D-01 — fecho técnico

ADR 0009 Accepted; ver ../reviews/P0.2_D01_resolution.md. D-02..D-12 não resolvidas; classificação de re-auditoria em ../reviews/P0.2_open_decisions_matrix.md. D-06 pode bloquear alteração estrutural de legal hold; D-10, bitemporalidade adicional; D-11, promoção de candidato/constraints apertadas. Nenhuma aprovação de produção ou sequenciamento P0.3 inferida.
