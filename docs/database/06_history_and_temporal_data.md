# Histórico temporal

Estado: proposta. Períodos classe/função/cargo/departamento/agregado/residência/Congregação usam intervalo semiaberto [starts_at,ends_at). NULL fim é aberto, nunca vaga. created_at indica registo, não data histórica. Início desconhecido permanece em staging até regularização documentada D-10; não substituir por data de importação.

Cargo é lugar persistente+nomeação+ocupação. PLANNED futura não preenche vaga actual. Consultar intervalo além do status. occupancy_status é projecção actualizada no mesmo commit dos períodos; Cron trata início/fim planeado com auditoria, mas consulta autoritativa deriva dos períodos para não depender da execução imediata do Cron.

Dois intervalos conflitam se A.start < B.end e B.start < A.end, com fim aberto infinito. Lock da âncora existente: Pessoa para classe/congregação principal, lugar para ocupação exclusiva, unidade para local principal, child_profile para custódia. Consultar inclusive futuro e fechar/abrir num commit. MySQL não tem exclusion constraint temporal portável; UNIQUE com NULL não basta. Cargos/funções acumuláveis exigem catálogo de incompatibilidades D-07, sem proibir todas as simultaneidades.

Transferência: bloquear membership/unidades, validar aceite, fechar origem T e abrir destino T, documento/auditoria. Número vitalício não muda. Mudança de pai bloqueia singleton da estrutura e actualiza parent_id+unit_parent_periods atomicamente; criar Centro Geral reparenta todos os Centros do Município, remover devolve todos ao Município. DRAFT pode ser construído vazio; ACTIVE exige Centro. Fechos históricos consultam pais temporais, nunca árvore actual. Datas desconhecidas e revisões retroactivas D-10.

Não se pretende bitemporalidade universal: tempo efectivo+auditoria não dá automaticamente consultas completas 'o que sabíamos em T'. Exige revisão histórica explícita quando necessário. Correcção retroactiva preserva versão anterior/cancelamento, actor/motivo/documento e não altera snapshot fechado; novo fecho autorizado.

Currículo/course_versions/grades/certificates/transcripts/templates/document_versions/budgets/snapshots são versionados e congelados após publicação. Referências continuam para a versão emitida. Snapshot guarda cutoff, definição e árvore usada. Ledger POSTED imutável: erro gera lançamento inverso reversal_of_id e correcção em período autorizado. Não reabrir período nem cancelar transferência enviada sem estornar contabilização de trânsito.

## P0.2-F — verificação de cache

occupancy_status é cache de interface. Relatórios oficiais e auditoria de vaga derivam ministerial_assignments / department_appointments no instante histórico, com intervalos [starts_at,ends_at), incluindo interinidade/substituição aplicável e estado do lugar. Cron reconcilia o cache diariamente e após imports; divergência gera incidente e não reescreve nomeações publicadas. Ver protocolo em 04_database_constraints.md.