# Constraints e integridade

Estado: proposta. PK/FK/UNIQUE/índices enumerados no dicionário. FK simples prova existência, não estado/tipo/contexto. Scheduled validation detecta corrupção, mas não é garantia primária. Triggers não são propostas. Runtime/deploy devem ter privilégios distintos; se o hosting não permitir, rever implantação/risco.

| Regra | DB | Serviço/transacção | Verificação complementar |
|---|---|---|---|
| Pessoa sem dois números/perfis | UNIQUE memberships.person_id e member_numbers.membership_id | Bloquear membership aprovada; número vitalício | Mapa legado |
| Número nacional único | UNIQUE number e sequence_value | Singleton nacional FOR UPDATE | Contador >= máximo confirmado |
| Legado preservado | FK/RESTRICT | raw_number append-only; correcção por evidência | Duplicados por fonte |
| Unidade não é pai de si | APP_ENFORCED: PK AUTO_INCREMENT impede este CHECK em MySQL | Rejeitar também no serviço | Percurso |
| Sem ciclo | FK não impede ciclo | Singleton NATIONAL_TREE preexistente serializa mutações; percorrer pais | Percurso completo |
| Município max 1 Centro Geral | Candidato generated CASE + UNIQUE; qualificacao obrigatoria | Lock árvore; contar unidades não encerradas do município | Cardinalidade |
| Município ACTIVE min 1 Centro | Não é CHECK de linha | Activação/remanejamento/encerramento validam mínimo num commit | Municípios ACTIVE |
| Centro pai conforme estrutura | FK e unit_parent_rules | Com Centro Geral activo todos os Centros são filhos dele; sem, do Município | Coerência territorial |
| Congregação filha de Centro | FK | Tipo CENTRO/pai vigente/caminho territorial | Árvore |
| Cargo esperado VACANT | Lugar persistente e occupancy_status obrigatório | Vaga sem nomeação vigente; INTERIM exige interino; sem Pessoa fictícia | Derivar de períodos |
| Períodos incompatíveis | CHECK fim > início | Lock Pessoa/post; verificar sobreposição inclusive futura | Consulta temporal |
| Nomeação histórica preservada | RESTRICT | Proibir hard-delete/edição publicada; nova versão | Auditoria |
| Check-in único Pessoa+sessão | UNIQUE session_id,person_id | Validar inscrição/evento e chave idempotente | Replay |
| Credencial revogada não entra | UNIQUE token_hash/FKs | Lock credencial e conferir revoked/status/expiry antes de inserir | Corrida revogação |
| Aluno externo sem membership | enrollment FK people | Elegibilidade não cria membro | Caso externo |
| Convidado externo sem número | Number só membership | Emissão MEPA exige aprovação; temporária sem member_number_id | Tipos |
| Menor só recolhido por autorizado | FKs | Lock child_profile; validar responsável/finalidade/vigência/revogação/actor | Recolhas abertas |
| Débito = crédito | CHECK de linha não soma agregados | Lock cabeçalho/período; somar DECIMAL; publicar atomicamente | Balancete |
| Transferência não é receita | UNIQUE etapa/entry de transfer_postings | Principal só activos/interunidades; não contribuição | Consolidado/trânsito |
| Alocação não excede dívida | CHECK amount >0 | Lock settlement/dívida; moeda/titular/soma | Saldo derivado |
| Fecho não muda | UNIQUE unidade/período/versão; RESTRICT | Congelar fonte/definição/dimensões; correcção por sucessor | Checksum |
| Ficheiro eliminado não quebra histórico | RESTRICT; row tombstone | Purga física só após política/legal hold; referência preservada | Restore/storage |
| Scope não cruza concessões | Papel+scope na mesma linha | Avaliar permissão/concessão conjunta, unidade+departamento+acção/dado | Testes horizontais |

## Garantias de contexto

event_checkins.person_id = registration.person_id; session.event_id = registration.event_id; invitee/list do mesmo evento; credential da mesma Pessoa; event_credential da mesma inscrição. Bloquear credencial e inscrição/lista relevantes antes de validar; revogação/cancelamento usam as mesmas âncoras. Attendance exige inscrição/sessão correspondentes. Académica: enrollment/class_session mesma turma, assessment/lesson/module mesma course_version, attempt dentro dos limites sob lock enrollment/assessment, transcript/certificate mesma Pessoa. Responsável/contacto/autorização correspondem à criança; checkout repetido bloqueia a mesma visita. Scope.department_instance.unit_id = scope.unit_id. Linha financeira respeita moeda/unidade/plano da account e do cabeçalho. Dimension_value pertence ao dimension_type da ligação.

O catálogo tem FKs simples; estas igualdades são validação transaccional obrigatória. Na fase física avaliar FKs compostas com UNIQUE explícito do alvo, tipos compatíveis e custo de índice. Não alegar que FK simples garante contexto.

## CHECK, estados e NULL

CHECKs propostos: geo emparelhada/intervalos; debit/credit >=0 e exactamente um positivo; amounts de transferência/liquidação >0; ends_at > starts_at; issued_month 1..12; sequence_value 1..999999; version/slot/attempt_number >=1; nascimento consistente com birth_precision; proporção 0..1 e peso 0..100; notas >=0, pass_score <= max_score; origem != destino; parentesco não reflexivo. CHECK não consulta outras tabelas/soma journal_lines. MySQL mínimo proposto 8.0.16; MariaDB qualificado por versão real, inclusive enforcement; serviço duplica validação de linha.

XORs: financial_parties exactamente uma Pessoa/agregado/unidade/departamento ou external_name conforme tipo; invitation_criteria um alvo tipado; settlement_allocations recebível OU pagável; resource FILE exige file_id e proíbe provider, VIDEO exige provider/id externo; contribution IDENTIFIED exige titular, ANONYMOUS/AGGREGATED não; IN_KIND exige descrição e avaliação aprovada, MONETARY exige amount. NULL fim significa aberto; NULL revogação significa não revogado; NULL geo significa não geocodificado; NULL número temporário intencional por tipo. Scope nacional referencia Direcção Geral, sem NULL global.

Estados propostos: unidade DRAFT/ACTIVE/CLOSED; períodos PLANNED/ACTIVE/ENDED/CANCELLED; instância NON_CONSTITUTED/ACTIVE/INACTIVE; ocupação VACANT/FILLED/INTERIM/INACTIVE; credential ISSUED/REVOKED/EXPIRED; checkin VALID/VOIDED; attendance PRESENT/ABSENT/EXCUSED; ledger DRAFT/POSTED; orçamento DRAFT/SUBMITTED/APPROVED/EXECUTING/CLOSED/CANCELLED. Lists/event/registration, académico e workflow/import precisam de catálogo exacto por tabela e transições D-11 antes de migrations. Não aceitar status livre nem reutilizar catálogo universal.

UNIQUE (anchor_id,ends_at) não impede múltiplos NULL. MySQL não oferece UNIQUE parcial WHERE active portável. Alternativa generated CASE + UNIQUE exige ensaio de versão; proposta inicial é lock de âncora preexistente + serviço. Não bloquear um conjunto vazio esperando impedir corrida. Regras de cardinalidade/overlap são garantia do serviço, não DB constraint imaginária.

## Número Único: algoritmo documental

Autorizar aprovação → reclamar idempotência UNIQUE actor/operation/client_key e comparar hash do pedido → bloquear membership aprovada → devolver número existente se houver → SELECT FOR UPDATE do singleton MEPA_NATIONAL dentro da transacção → last_value+1, rejeitar >999999 → AA/MM do período institucional aprovado, ano completo guardado → inserir member_numbers/actualizar contador/auditoria/outbox → commit → divulgar/emitir passe.

Rollback reverte número e contador; candidato não confirmado pode ser reutilizado porque nunca foi divulgado. AUTO_INCREMENT técnico pode ter lacunas e não é contador MEPA. Retry integral limitado, proposta 3 tentativas com backoff/jitter para deadlock/timeout; revalidar estado/autorizações. Resposta perdida após commit: replay devolve número existente. Colisão UNIQUE exige diagnóstico/mapa/contador; não inventar outro mês ou usar MAX+1. Legado bruto não incrementa contador. Import de v2 confirmado usa mesmo lock e valida máximo. Nunca reset por mês/ano, truncar ou renumerar. Alertar antes de 999999; expansão e regra do próximo século AA em D-03.

Ordem de locks documentada por operação: gerador idempotência→membership→contador; estrutura singleton→unidades ordenadas; financeiro periodo→transferencia/divida→contas ordenadas→cabecalho/chave idempotente (todos os writers; prototipo P0.2-F). Todos os writers seguem mesma ordem. [MySQL CHECK](https://dev.mysql.com/blog-archive/mysql-8-0-16-introducing-check-constraint/), [InnoDB locks](https://dev.mysql.com/doc/refman/8.0/en/innodb-locks-set.html) e [MariaDB constraints](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint).

## Ledger: proposta contabilística e exemplo adversarial

Plano de contas é contabilístico; accounts são caixas/bancos operacionais ligados a contas postáveis. Fund é afectação/restrição, category é rubrica de gestão. Saldo deriva exclusivamente de journal_lines de cabeçalhos POSTED. Não somar contributions + settlements + ledger como três receitas. Contributions e obligations são subledgers/evidência; realizado vem do ledger uma única vez. Reconhecimento de obrigação/recebível não é pagamento; liquidação debita caixa e credita recebível quando receita já reconhecida. Regime de caixa versus acréscimo D-04 determina a política antes de publicar.

Publicação bloqueia cabeçalho e período OPEN, garante >=2 linhas, moeda única, contas activas/postáveis, amounts de escala permitida, exactamente um debit/credit positivo, soma debit=credit e equilíbrio por unidade e fundo quando exigido. Não deixar fundos restritos financiarem outros sem transferência de fundo aprovada. Todo editor de linhas bloqueia o cabeçalho, impedindo inserção concorrente durante soma/publicação. POSTED proíbe UPDATE/DELETE de cabeçalho/linhas; estorno inverso ligado a original, sem reutilizar chave idempotente. Valor com moeda diferente não se publica no mesmo journal; FX requer desenho aprovado D-04.

Exemplo sintético: saldo caixa origem A =1000 Kz, destino B =0; enviar 300 Kz no mesmo fundo. Proposta portátil com trânsito e contas interunidades (a nomenclatura contabilística final depende do contabilista):

| Etapa | Unidade | Débito | Crédito | Valor Kz |
|---|---|---|---|---|
| SEND | A | Activo dinheiro em trânsito | Caixa A | 300 |
| RECEIVE | B | Caixa B | Passivo interunidade devido a A | 300 |
| RECEIVE | A | Activo interunidade a receber de B | Activo dinheiro em trânsito | 300 |

SEND tem duas linhas equilibradas em A; RECEIVE tem quatro linhas, duas equilibradas em cada unidade. transfer_postings liga cada etapa ao mesmo internal_transfer; fundo/moeda/valor coincidem. Recepção só uma vez por UNIQUE transfer_id,posting_stage e lock transfer. Após envio, consolidado contém caixa 700 + trânsito 300; após recepção, caixa A 700 + caixa B 300, trânsito zero. Eliminar em consolidação o activo/passivo interunidades emparelhados da mesma transferência: 300 a receber A contra 300 a pagar B, resultado de receitas/despesas zero. Não eliminar trânsito ainda não recebido nem classificar crédito no banco B como receita. Usar ledger nacional único e referência transfer_id para localizar pares, nunca somar relatórios locais sem eliminações. Saldo interunidades pode manter evidência de alocação de património; política de encerramento/eliminações D-04. Taxa bancária é despesa externa real num lançamento separado, não altera o principal recebido sem decisão documentada.

Conciliação valida correspondências parciais de bank_statement_lines/journal_lines da mesma account, sinal/moeda e soma matched_amount <= montante de cada lado sob locks; não marcar extracto conciliado por soma global que esconda linhas repetidas. Reconciliation fechada é versão imutável. Orçamento aprovado preserva versão; execução usa contas/rubricas do ledger e compromissos em payables separadamente para não duplicar realizado. Cancelar SEND já confirmado requer REVERSE_SEND; após RECEIVE exigir inversões coerentes de ambas etapas, conta/fundo/período e motivo. Verificação agendada procura trânsito envelhecido, pares incompletos, diferenças de extracto e ledger desbalanceado, mas publicação transaccional é a primeira garantia.

## Invariantes adicionais de revisão

Todos os booleanos TINYINT aceitam apenas 0/1. Catálogos não recebem texto arbitrário. Árvore civil, hierarquia do plano de contas, instalações e pré-requisitos académicos também rejeitam ciclos sob âncoras de domínio; a regra não é exclusiva da árvore MEPA. merged_into aponta para sobrevivente sem ciclo, source != target e sem conflito de membership/número; não se faz fusão automática. supersedes de documento/snapshot e reversal_of de lançamento referem antecessor coerente, nunca entidade de outro titular/contexto, com versão/instante ordenado.

Datas de sessões/eventos respeitam início/fim e containment conforme política do evento/turma. Credencial permanente com member_number_id precisa corresponder à membership da mesma Pessoa e aprovação. A escolha de verificar validade no instante de sincronização offline versus ocorrência é D-05; número, aprovação, reconciliação e mudança de estrutura permanecem online, sem validar revogação antiga pelo cliente.

Estado CLOSED de unidade impede novos vínculos incompatíveis, mas não apaga linhas de histórico/ledger. Local principal vigente é no máximo um por unidade, sob lock dessa unidade; existência obrigatória de local principal não foi presumida. Slot de cargo representa capacidade explícita, não inventa ocupante. Uma contribuição em espécie só entra no ledger após valorização/documento aprovados; ainda não contabilizada permanece subledger sem duplicar receita realizada.

## P0.2-F: enforcement da árvore (F02 / D-12)

A regra institucional está aceite no ADR 0006; catálogo geográfico, códigos e raízes activas continuam BLOCKED em D-12. DRAFT já existe e permite construção incompleta. ACTIVE exige pelo menos um Centro ACTIVE no município, contando Centros directos ou do seu Centro Geral. CLOSED conserva histórico. Não se cria INCOMPLETE. Um Centro Geral DRAFT reserva a sua posição; não permite tornar o município ACTIVE com Centros dependentes de um Centro Geral não ACTIVE.

A fonte canónica dos pares de tipos é [unit_parent_rules.json](unit_parent_rules.json). unit_parent_rules na BD será materialização desse manifesto, resolvendo códigos para IDs após aprovação de D-12; o serviço carrega o mesmo catálogo, sem cópias de listas em controladores. O manifesto expressa a hierarquia já aprovada, não seeds territoriais. Direcção Geral é raiz; qualquer outro tipo exige pai. Tipos desactivados impedem novos vínculos. Com Centro Geral ACTIVE, todos os Centros ACTIVE municipais dependem dele; sem ele, dependem directamente da Direcção Municipal. Congregação depende de Centro. O catálogo permite ambos os pais de Centro, mas não prova sozinho essa condição municipal.

| Invariante | Camadas escolhidas | Limite / protocolo |
|---|---|---|
| Existência de pai/tipo | FOREIGN KEY + APPLICATION SERVICE | FK simples existente prova existência; serviço prova tipo, estado e território |
| Máximo um Centro Geral não CLOSED por Direcção Municipal | UNIQUE INDEX candidato + TRANSACTION + APPLICATION SERVICE + VALIDATION JOB | Coluna generated determinística detalhada abaixo; fallback obrigatório sob singleton enquanto candidato não qualificado |
| Mínimo um Centro ACTIVE no município ACTIVE | TRANSACTION + APPLICATION SERVICE + VALIDATION JOB | Validar activação e toda retirada/reparentamento/fecho; FK e CHECK de linha não contam filhos |
| Pai/filho permitido | FOREIGN KEY de tipo + APPLICATION SERVICE + VALIDATION JOB | Consultar o manifesto; FKs compostas de pares são alternativa física D-11, não garantia existente |
| Sem self-parent/ciclo | TRANSACTION + APPLICATION SERVICE + VALIDATION JOB | Singleton NATIONAL_TREE preexistente, depois unidades por id; percorrer ascendentes com conjunto visitado |
| Raiz/códigos/território oficial | APPLICATION SERVICE + DEPLOYMENT_BLOCKER | D-12; não inferir quantidade de raízes nacionais |

**Candidato portátil para máximo um Centro Geral.** Acrescentar unit_type_code normal VARCHAR(64) NOT NULL, correspondente ao lookup, e UNIQUE explícito organizational_unit_types(id,code). FK composta (unit_type_id,unit_type_code) referencia esse par; tipos e collation devem coincidir, RESTRICT em ambas as acções. O runtime não altera códigos, lookup ou manifesto. Acrescentar general_center_key BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN unit_type_code='GENERAL_CENTER' AND status<>'CLOSED' THEN parent_id ELSE NULL END) STORED e UNIQUE(general_center_key). A chave guarda o ID da Direcção Municipal, nunca municipality_id da geografia civil. Múltiplos NULL permitem outros tipos; um pai igual colide para Centro Geral. O serviço exige parent_id e pai municipal, evitando contornar a regra com NULL ou outro pai. O candidato não é partial index e não participa de FK; a FK composta usa apenas colunas normais.

Benefício: o índice impede concorrência e duplicação mesmo se a contagem da aplicação falhar. Custo: coluna de código duplicada, FK composta/índice adicional e coluna+índice generated, ensaio de versão e actualização controlada do lookup. Não gerar a classificação por subquery ou IDs arbitrários do catálogo. O protótipo T12 prova o índice em MariaDB 10.4.32, mas não prova a FK composta nem qualifica MySQL/hosting. A proposta está em model_catalog.physical_candidates e não foi silenciosamente promovida ao ERD lógico. Antes da fase física: qualificar D-08, aprovar D-11, adicionar o candidato ao modelo completo e testar também tentativas de falsificação do par id/código. Se indisponível, a garantia primária é o singleton+serviço; nunca anunciar DB_ENFORCED sem a constraint aplicada.

Mutações da árvore: begin → bloquear a linha NATIONAL_TREE existente em organizational_structure_lock → bloquear IDs afectados ordenados → validar tipos e caminho ascendente → simular estado final → validar mínimo/condição municipal → alterar pai actual, períodos e auditoria juntos → commit. Criação/fecho de Centro Geral reparenta todos os Centros no mesmo commit. Todos os writers, incluindo import e backfill, seguem esse protocolo. Corrida entre dois reparentamentos não usa locks apenas dos filhos, pois ambos poderiam criar um ciclo. Rollback conserva árvore e períodos.

Cron diário e após import/reorganização: percorrer todas as unidades; detectar self-parent, ciclo, pai ausente/inactivo, par inválido, divergência de município civil, Centros dependentes de pai errado, duplicação de Centro Geral e mínimo ACTIVE. Registrar incidente de integridade com IDs/código/momento, suspender a operação afectada e exigir correcção auditada. Não converter corrupção em indicador de qualidade nem reparentar automaticamente. Runtime sem DDL e sem escrita humana directa; jobs e import passam pelo serviço. O job detecta bypass, não substitui prevenção transaccional.

## P0.2-F: restante reconciliação

**F04 credenciais.** credential_types possui requires_member_number, default_validity_days e requires_formal_approval no catálogo e dicionário. Booleanos 0/1; validade positiva se presente. NULL representa política não definida, não autorização tácita de validade infinita. Não activar tipo sem configuração aprovada D-05. Passe permanente continua a exigir membership/número/aprovação, e o convidado não recebe número. Não se altera idempotência QR.

**F05 ocupação.** occupancy_status é cache para interface. Fonte autoritativa: ministerial_assignments / department_appointments não canceladas cujo starts_at <= instante e ends_at NULL ou > instante, junto com estado do lugar e substituição/interinidade. Relatório oficial, auditoria de vaga e autorização consultam períodos; não filtram pelo cache. Actualizar no commit de nomeação e reconciliar diariamente e após import; registar divergências como incidentes. O cache nunca concede acesso. Funções/política de sobreposição continuam D-07/D-11.

**F06 revogação.** Arquivar user ou retirar a última concessão activa: bloquear users.id → concessões e auth_sessions por id → arquivar/remover scopes → revoked_at em todas as sessões ainda válidas → auditoria → commit. Emissão/refresh também bloqueiam users.id e rejeitam actor arquivado ou sem concessão. Cada request verifica user activo, sessão não expirada/revogada e concessão actual no servidor. Operações sensíveis repetem essa validação dentro da transacção sob a mesma âncora de user antes do efeito; request já autorizado antes do commit não pode concluir escrita após revogação sem revalidação. T14 verifica a atomicidade básica; corrida de refresh/efeito deve integrar o ensaio de segurança D-08 antes de produção.

**F07 criança.** Antes de abrir child_custody_visits, bloquear child_profiles.id preexistente; consultar child_custody_visits por child_person_id=child_profiles.person_id e session_id, com checked_out_at IS NULL; rejeitar OPEN_VISIT_EXISTS. Checkout bloqueia a mesma criança e visita e valida responsável/autorização ainda vigente. Mesmo protocolo em todos os writers; SELECT de conjunto vazio não é a âncora. T13 prova a rejeição sequencial; ensaiar concorrência no motor exacto antes do módulo. Nenhuma mudança no modelo de crianças aprovado.

**F08 entrada recusada.** Usar audit_logs.action=CHECKIN_DENIED, entity_type=event_sessions e entity_id=sessão; actor_id=operador efectivo. reason contém código padronizado: CREDENTIAL_REVOKED, CREDENTIAL_EXPIRED, REGISTRATION_CANCELLED, NOT_ELIGIBLE, CONTEXT_MISMATCH, DEVICE_NOT_AUTHORIZED. after_metadata só correlation_id, IDs estritamente necessários e código; sem token, imagem ou documento pessoal. Persistir após rollback da tentativa de check-in numa transacção de auditoria separada; indisponibilidade de auditoria impede resposta de sucesso e gera incidente operacional. Consultar pelo índice existente (entity_type,entity_id,occurred_at), sem tabela ou índice redundante. Não gravar check-in VALID para recusa; replay de tentativa preserva correlação.

**F09 manual.** Pré-registar um devices por unidade para atendimento manual, com código/convenção operacional MANUAL:<unit.public_id> no registo administrado (manifesto de dispositivos, não um novo campo fictício). devices.public_id é ULID e não esse texto. Operador usa o device.id real e a identidade de user efectiva, actor_kind USER; revogação e scope são verificados normalmente. device_id de event_checkins permanece obrigatório. Ausência de dispositivo autorizado exige registo antes da operação; atendimento em papel não vira entrada na BD sem reconciliação autorizada. Manual não dispensa chaves idempotentes, inscrição ou credencial.

**F10 deduplicação.** person_documents(document_type_id,issuer_country,number_blind_index) continua índice não UNIQUE. Número partilhado/erro/tipo/país requer evidência e revisão. Não fundir pessoas ou números por heurística. Política de conflito e acesso D-06/D-11 permanece BLOCKED antes da importação de documentos em produção.

**F11 chaves.** key_version resolve identificador num registo de configuração externo ao banco, sob Operações/Segurança; implementação proposta: variável de ambiente/KMS apontando para cofre com versões e estados ACTIVE/DECRYPT_ONLY/RETIRED. Nunca guardar material secreto em catálogo, logs, Graphify ou repositório. Rotação: disponibilizar versão nova, novos writes nessa versão, leitura com versões anteriores, backfill por lotes auditados com checkpoint, recomputar blind indexes com procedimento de dedup, testar restore, retirar anterior só após backups/holds e recuperação aprovados. Não reutilizar versão. Responsável nomeado, prazo de rotação, cofre/hosting e destruição permanecem D-06/D-08 BLOCKED; uma key_version desconhecida falha fechada. Sem nova tabela de segredos.
## D-01 — estratégia aceite de identificadores

ADR 0009 Accepted, D-01 RESOLVED por instrução explícita P0.2-D01. PK id BIGINT UNSIGNED AUTO_INCREMENT e FKs BIGINT UNSIGNED para id. public_id selectivo: CHAR(26) CHARACTER SET ascii COLLATE ascii_bin, NOT NULL, UNIQUE, aplicação, imutável e não reutilizável. 54 entidades justificadas no dicionário; 145 tabelas internas sem public_id. Não usar public_id nas FKs normais. id técnico, public_id do CRM e member_number eclesiástico permanecem separados.

Contrato comum: ULID canónico uppercase, 26 ASCII, primeiro carácter 0..7, regex ^[0-7][0-9A-HJKMNP-TV-Z]{25}$; rejeitar espaços/caracteres inválidos, sem default SQL. UNIQUE já fornece índice; validação de forma na aplicação e CHECK opcional apenas após qualificação exacta D-08/D-11. Erro de colisão nesse UNIQUE permite retry limitado de geração, nunca contornar outras constraints. Confirmar idempotência antes de gerar resposta nova; public_id é publicado apenas após commit e replay devolve o mesmo identificador. Updates não alteram id/public_id; arquivo conserva a reserva. Purga futura sem reserva não satisfaz a regra de não reutilização.

ULID não concede acesso: resolver public_id, verificar autenticação/policy/scope e exposição mínima. QR continua com token separado/hashing conforme desenho aprovado. Laravel futuro: serviço comum Str::ulid(), chave primária inteira incrementing, binding por public_id e policy; não usar automaticamente HasUlids para converter id numa PK textual. D-02..D-12 continuam abertas conforme re-auditoria; nenhuma migration nesta tarefa.