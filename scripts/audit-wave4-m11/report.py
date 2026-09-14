from pathlib import Path
import json,time,xml.etree.ElementTree as ET,hashlib,subprocess
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit'
while not (out/'isolation_qualification.json').exists():time.sleep(2)
runs=json.loads((out/'runs.json').read_text());qualification=json.loads((out/'isolation_qualification.json').read_text());extras=qualification['additional_runs'];qualified=set(qualification['qualified_isolated']);allruns=runs+extras
iso=[r for r in allruns if r['label'] in qualified];loaded=[r for r in runs if r['label'].startswith('loaded_')];parallel=[r for r in runs if r['label'].startswith('parallel_')];candidates=[r for r in runs if r['label'].startswith('isolated_')]
def score(rows):return f"{sum(r['exit']==0 for r in rows)}/{len(rows)} PASS"
def junit(path):
 t=ET.parse(path).getroot();s=t if t.tag=='testsuite' else t.find('testsuite');return {k:s.get(k,'0') for k in ['tests','assertions','failures','errors','skipped']}
suites=[]
for r in runs:
 if r['label'].startswith(('isolated_','parallel_','loaded_','load_child_')) or r['suite']=='IndependentM11Test':continue
 suites.append((r,junit(out/(r['label']+'.xml'))))
source=json.loads((out/'source_preservation.json').read_text());graph=json.loads((out/'graph_queries.json').read_text());strict=json.loads((out/'strict_parity.json').read_text());env=json.loads((out/'environment.json').read_text())
# Record final server settings and physical migration count, no credentials.
q="SELECT VERSION(),@@innodb_flush_log_at_trx_commit,@@sync_binlog,@@transaction_isolation,@@foreign_key_checks; SELECT COUNT(*) FROM mepa_wave4_test_m11_wavefourphysicaltest.migrations WHERE migration LIKE '%wave4%'; SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_deadlocks','Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_row_lock_current_waits');"
r=subprocess.run(['docker','exec','mepa-wave4-m11-independent-mysql','mysql','-uroot','--batch','-e',q],capture_output=True,text=True);(out/'final_server_and_locks.txt').write_text(r.stdout+r.stderr,encoding='utf8')
summary={'gate':'REJECTED','W4R-01':'RESOLVED','W4R-02':'RESOLVED','W4R-03':'RESOLVED','W4R-M1R-01':'PARTIALLY_RESOLVED','TEST-TIMING-01':'OPEN','new_findings':[{'id':'W4R-M11R-01','severity':'HIGH','status':'OPEN','title':'DISCIPLESHIP_CONFIGURE expires behind audit FK wait; final wrapper checks only OUTREACH_WRITE'},{'id':'W4R-M11R-02','severity':'HIGH','status':'OPEN','title':'progress commits after session expires during audit FK wait following decisive check'},{'id':'W4R-M11R-03','severity':'MEDIUM','status':'OPEN','title':'Original collect has unbounded blocking read and missing failure-path cleanup'}],'distinct_active_issues':{'CRITICAL':0,'HIGH':2,'MEDIUM':2},'note':'W4R-M1R-01 is the aggregate remediation finding represented by the two HIGH child findings, not an additional distinct issue. TEST-TIMING-01 is the other MEDIUM issue.','isolated':iso,'loaded':loaded,'parallel':parallel,'candidate_runs':candidates,'qualification':qualification,'source_preservation':source,'strict_parity':strict,'graph':graph}
(out/'audit_summary.json').write_text(json.dumps(summary,indent=2),encoding='utf8')
text=f'''# P0.3.4-M1.1-R — Auditoria independente final da Wave 4

Gate: **REJECTED**. Wave 5 não iniciada. Nenhum código de aplicação, teste herdado ou migration foi corrigido nesta auditoria. Relatórios anteriores preservados; o gate recebe apenas adenda.

## Ambiente, âmbito e limitações de proveniência

Novo MySQL **8.4.11**, contentor `{env['container']}`, id `{env['id']}`, porta exclusiva `127.0.0.1:33114`, volume anónimo novo. Não foram reutilizados contentor, DB, barreiras ou temporários do executor. A imagem local mysql:8.4 foi reutilizada como distribuição, sem reutilizar dados. Contas sintéticas com senhas aleatórias não gravadas. PHP 8.0.30 / PHPUnit 9.6.36. Esta é uma passagem independente de ambiente, ferramentas e observações; a sessão conhece o histórico anterior e não constitui uma pessoa auditora distinta.

Preflight real antes do primeiro DDL: **READ_ONLY_PREFLIGHT_PASS** (`preflight.json`). UTC, InnoDB, REPEATABLE READ, CHECK/FK qualificados pela suite física. Para reduzir espera de fsync do Docker durante criação de schemas, o servidor sintético foi afinado para `innodb_flush_log_at_trx_commit=2`, `sync_binlog=0`; não se alteraram isolamento, locks, relógio, schema ou regras. Não é uma qualificação de crash-durability/hosting de produção (D-08 continua aberta). Parâmetros e contadores finais: `final_server_and_locks.txt`.

Foram lidos os cinco relatórios pedidos, baseline e ADR 0013. `git_diff.txt` contém o diff Git real dos três ficheiros tracked alterados. **Não existe commit/snapshot Git M1.1 isolado**: WaveFour e os seus testes/migrations estão untracked sobre HEAD b336c4e. Portanto não se afirma ter reconstruído um diff exacto M1→M1.1; foi inspeccionada integralmente a implementação corrente e confrontada com as declarações dos relatórios e contratos aprovados. Nenhuma migration temporal M1.1 adicional: lista das 17 Wave4 corresponde ao manifest, static/physical parity e hashes de preservação.

## W4R-M1R-01 e inventário de writers

Estado **PARTIALLY_RESOLVED**. O wrapper corrigiu OUTREACH_WRITE para os seus oito writers; `progress()` corrigiu esperas explícitas anteriores ao insert, mas ainda tem uma espera SQL relevante depois do recheck. CONFIGURE em track/step não é repetida pelo wrapper final. Ver os dois HIGH reproduzidos abaixo.

Todos usam a mesma `Illuminate\\Database\\Connection`, transacção `transaction(...,5)` e rollback integral em excepção; QueryException é convertido em OUTREACH_STORAGE_CONFLICT. O parâmetro 5 é política de retry de deadlock interna da aplicação já existente, **não retry das execuções da auditoria**. DML e audit_logs usam InnoDB e a mesma conexão.

| Writer | Locks de domínio | Provisional | Fresh clock / decisiva | Writes e rollback | Resultado |
|---|---|---|---|---|---|
| campaign | events SHARE opcional | run antes de work | run depois de work, authorize(null), depois dos locks de autorização | outreach_campaigns + audit dentro de run; rollback se final falha | OUTREACH revalidado |
| contact | campaign SHARE; people FOR UPDATE; person/status SHARE; assigned opcional | run antes | run depois de work, null | contact + audit já executados antes da final, integralmente revertidos na prova | PASS de segurança |
| followup | contact/campaign, performer/status SHARE | run antes | run depois de work, null | followup + audit; mesma transacção | OUTREACH revalidado |
| decision | participation join, person/status, contact/campaign SHARE | run antes | run depois de work, null | decision + audit; mesma transacção | OUTREACH revalidado |
| track | locks de autorização; locks implícitos de insert/FK/audit | run OUTREACH antes; CONFIGURE própria | CONFIGURE null antes de insert; run final apenas OUTREACH | track + audit; wrapper não detecta expiração de CONFIGURE | **HIGH W4R-M11R-01** |
| step | track SHARE; locks implícitos de insert/FK/audit | run OUTREACH antes | CONFIGURE null após track SHARE; run final apenas OUTREACH | step + audit; CONFIGURE pode expirar numa espera posterior | mesma lacuna de track, extensão por inspecção |
| enroll | participation/person/status; mentor opcional; track SHARE | run antes | run depois de work, null | enrollment + audit/proveniência atómicos | OUTREACH revalidado |
| integrate | participation/person/status; membership SHARE opcional | run antes | run depois de work, null | integration + audit; mesma transacção | OUTREACH revalidado |
| progress | enrollment FOR UPDATE; provenance audit SHARE; step SHARE; participation/person/status SHARE; FK de audit | authorize null depois de enrollment/scope | segundo authorize null depois de step/participation **antes** de lookup/insert/audit | progress + audit; nenhuma final depois da espera FK | **HIGH W4R-M11R-02** |
| history | contact/campaign SHARE | OUTREACH_READ | leitura, N/A para writer | nenhuma escrita durável | N/A |

`EvangelismService::now()` delega exclusivamente em `DomainClock::now($this->db)`. DomainClock executa `SELECT UTC_TIMESTAMP(6)` nessa conexão e constrói DateTimeImmutable UTC; `format('Y-m-d H:i:s.u')` conserva seis casas. authorize(null) amostra depois de todos os seus próprios locks. O clock provisional serve fail-fast; a autorização final de OUTREACH no wrapper decide o commit. Isso não basta quando uma permissão adicional não é revalidada ou quando o writer possui locks posteriores à sua própria final.

## Reproduções reais, writes parciais e clock do caller

Ferramentas próprias: `scripts/audit-wave4-m11/worker.php`, IndependentM11ValidatedTest, ConfigureLateLockTest, AdditionalM11ValidatedTest. Processos PHP reais, conexões independentes e barreiras aleatórias, sem utilizar os workers temporais do executor para os findings novos.

- **contact expired**: worker inicia com sessão válida; outra conexão retém people.id; innodb_trx comprova LOCK WAIT; sessão expira por passagem real do tempo; libertar lock → ACTOR_NOT_AUTHORIZED. Listener confirma **INSERT de contact e INSERT de audit realmente executados** antes do recheck final. SQL depois confirma delta **0 contacts, 0 audit**, transaction level 0. `validated_contact_expired.json`.
- **step expired**: cenário real sob track SHARE bloqueado, lease válida inicialmente; após expiração → ACTOR_NOT_AUTHORIZED, delta **0 steps / 0 audit**. `validated_step_expired.json`, StepLongLease.xml/log. Há ainda o caso step passado no lote V2 (`step_v2_final_sql.json`).
- **progress late step lock**: validação inicial passa; espera no step subsequente; expiração → DENIED com zero progress e zero audit. `validated_progress_expired.json`. Isto fecha a lacuna explícita de M1, mas não a espera no audit FK descrita no HIGH.
- **pre e post válidos**: contact efectivamente bloqueado, sessão válida após libertação → PASS, uma contact e uma audit. `validated_contact_valid.json`.
- **todos os nove writers já expirados**: ACTOR_NOT_AUTHORIZED, zero deltas de domínio/audit. `expired_all_writers.json`.
- **microseconds**: expires_at = UTC_TIMESTAMP(6)−1 microsegundo → DENIED; claramente futuro → PASS. UTC e seis casas testados; datetime(6) confirmado no inspector físico. `validated_clock.json`.
- **caller clock**: assinaturas públicas não oferecem now/clock/timestamp decisivo; datas de negócio 1900/2999 não contornam sessão expirada. Em sessão válida, campanha futura é escrita como data de negócio. DomainAccess aceita now internamente, mas os callers decisivos normais inspeccionados fornecem null; nenhuma rota/API Evangelism publicada fornece um relógio de caller. Não é alegada ausência de qualquer relógio de negócio no sistema inteiro.

Pesquisa integral de EvangelismService/DomainAccess/DomainClock/Scope e respectivos callbacks: nenhuma chamada email/SMS/queue/webhook/filesystem/notification/event externo. Os side effects são exclusivamente DML via mesma conexão e ULID/random em memória. Não foi acrescentado dispatcher à aplicação; o listener de SQL existe apenas no worker da auditoria. **Nenhum efeito externo irreversível pré-auth encontrado no âmbito inspeccionado.**

Erros iniciais das ferramentas próprias estão preservados: BOM/parse error na geração, timeout de preparação e precondições de observação mais estreitas que a lease; um lote concorrente de probes usou o mesmo schema e invalidou uma asserção de contagem; houve erro de concatenação do ficheiro de evidência. Estes ensaios **não foram convertidos em PASS**. A ferramenta final grava tempo DB de início, exige LOCK WAIT real e grava cada caso separadamente. A variante StepLongLease amplia só a lease de preparação para 60s, mantendo expiração real e DENIED como propriedade. Logs/JUnit iniciais permanecem na pasta; esta distinção não remove qualquer falha das repetições do harness auditado.

## Progress scope e audit-log provenance

Cross-unit com contacto local da mesma Pessoa → ACTOR_NOT_AUTHORIZED; scope é resolvido da inscrição, sem fallback para unit_id do caller. Proveniência ausente/ambígua → ENROLLMENT_SCOPE_UNRESOLVED; entidade unrelated com mesmo id é ignorada; same-scope válido → PASS. `validated_scope.json`, AdditionalM11Validated.xml, WaveFourDiscipleshipScopeTest.xml. As inserções/deleções adversariais de audit nesta prova são SQL sintético da auditoria, não endpoints de aplicação.

**TEMPORARY_TECHNICAL_WORKAROUND** mantido, tracking explícito em `wave4_application_rules_tracking.json`, W4M1-02; substituição futura por owner_unit_id/backfill depois de D-11. Não se exige redesign aqui. W4R-02 funcionalmente RESOLVED; o HIGH temporal em progress é independente da proveniência.

## TEST-TIMING-01 e isolamento do harness

Estado **OPEN**, por falha real conservada, embora o deadline de 5s tenha sido substituído. Old flake 5/10 (50%) é evidência histórica do audit M1-R, não uma repetição do código antigo nesta passagem.

A barreira mantém a ideia de concluir a sessão independente enquanto o claim bloqueado continua retido. O segundo actor conserva Pessoa/token e separa as concessões; elimina da propriedade original a partilha de actor. A metadata `shared_actor_and_token=true` continua incorrecta no teste actual. A pré-criação de claims PROCESSING/hash correcto é legítima para isolar locks de sessão/claim; **não prova o caminho de criação inicial de claims**. Esse caminho continua parcialmente coberto pelos providers de fresh claims, mas o cenário cross-session/shared-actor/fresh-claim foi retirado deste teste e merece cobertura separada. Não é correcto concluir, só por essa pré-criação, que uma condição normal de produção não existe.

`sys_get_temp_dir()/mepa_wave3_barrier_<random_bytes(8)>` fornece 64 bits de namespace por lançamento; done_0/done_1 são locais nesse directório. Nomes globais não usados. Duas execuções simultâneas em schemas próprios: **{score(parallel)}**; namespaces observados em innodb_observations.json/lock_wait_details.json; não houve sinal cruzado encontrado. A limpeza usa apenas glob dentro do directório criado pela própria execução e unlink/rmdir, mas não está em finally: falhas podem deixar ficheiros/processos residuais. Não foi feita limpeza genérica de temporários do executor.

Worker crash handling: probe do worker original devolve domain_error, exit 2 e done presente; o collector original por reflection rejeita done+exit 3 com ExpectationFailedException. **Não há falso PASS por done isolado.** PID/running do bloqueado é verificado; stdout JSON e resultados CHECKED_IN de ambos são exigidos depois. Contudo **collect() usa stream_get_contents bloqueante sem deadline/process supervision**, provado por worker controlado vivo depois de done: timeout externo de 6s encontra collector ainda preso. Os deadlines 25/60/20s não limitam essa fase. O runner da auditoria impõe timeout externo, mas isso não corrige o harness original.

Repetições sem retries: lote inicial de 30 chamadas --filter = **{score(candidates)}**. Algumas coincidiram com desenvolvimento de probes próprios; a qualificação não as declara isoladas nem apaga falhas. Foram completadas chamadas adicionais para obter exactamente 30 sem outra suite/probe: **{score(iso)}**. Sob suite infantil concorrente real (providers 2/10/30/50): **{score(loaded)}**. Cada chamada tem schema virgem, label, exit code, duração, log e JUnit. Lote dual-run adicional: {score(parallel)}. Ver runs.json e isolation_qualification.json. **Uma falha em qualquer lote continua uma falha**, ainda que o lote isolado qualificado venha a passar.

## InnoDB: o lock de intervalo não foi eliminado por pré-criação

Em `isolated_5`, innodb_trx observou repetidamente dois LOCK WAIT: SELECT FOR UPDATE do blocked_claim e **INSERT IGNORE do independent_session já pré-criado**, durante o claim deliberadamente retido. O segundo actor e duas rows preexistentes estão comprovados por SQL. `isolated_5_lock_samples.json`, isolated_5.log/xml. O writer de scan tem retry interno após lock timeout; isto não é retry de PHPUnit da auditoria e a chamada conservou FAIL.

A captura confirma contenção SQL real e refuta a declaração de que pre-criação converte TODA a aquisição subsequente em locks exclusivamente de registo sem espera de intervalo. `INSERT IGNORE` ainda executa validação de duplicação/locks InnoDB; pré-criar não remove essa instrução. A classificação de artefacto refere-se à **duração artificial do claim retido pelo teste**, não à impossibilidade de contenção em produção. Não existe limite comprovado de milissegundos para uma transacção de produção; não se aceita essa afirmação do executor como prova. Os modos/índices/blockers capturados posteriormente constam de lock_wait_details.json; distinguir observação concreta de inferência sobre o mecanismo. Nenhuma race de autorização de check-in foi demonstrada por este timeout.

## Regressões e validadores

| Suite realmente executada | Testes | Assertions | Fail | Error | Skip |
'''
for r,j in suites:text+=f"| {r['suite']} | {j['tests']} | {j['assertions']} | {j['failures']} | {j['errors']} | {j['skipped']} |\n"
text+='''
W4R-01: generic scan protegido → CHILD_SAFETY_FLOW_REQUIRED, zero checkin/attendance/custody indevidos; Children autorizado PASS; consent revogado e ausência CHILDREN DENIED. Providers 10/30/50: uma logical checkin, uma attendance, uma custody visit, nenhum órfão. W4R-03: checkout unauthorized member DENIED; autorizado non-member PASS; autorização revogada DENIED; double checkout converge; revoke/checkout nas duas ordens lineariza. Wave3 adultos/guest/invitation/revoked credential/session isolation/checkin concurrency PASS na suite completa. Pipeline de Evangelismo conserva Pessoa, followups, decisão sem membership, discipulado/integração e admissão sintética preservando person_id; nenhum workflow de admissão futuro foi certificado.

Document validator: 199 tabelas / 1752 colunas / 439 FKs, **0 errors**. Approved contracts: **118 ficheiros, drift 0**. Physical validator/strict parity: **123 tabelas, 1068 colunas, 255 FKs, 81 CHECKs, 115 UNIQUEs, 32 public_ids, zero CASCADE; STRICT_PARITY_PASS; unexpected_drift 0**. Static Wave4 parity: 17 tabelas e nenhuma migration não registada. Suite física PASS para fresh/upgrade/empty rollback/remigrate e rollback com dados duráveis recusado. Nenhuma migration M1.1 temporal nova declarada/encontrada.

Graphify: **13 queries**, 3439 nodes / 5714 edges, **0 dangling**, hash preservado, sem destructive shrink/force/rebuild. Consultas expandidas exclusivamente a tokens do vocabulário real; reflect/LESSONS lidos e resultados brutos na pasta. O grafo é mapa de fontes, não prova de autorização. D-02–D-12 continuam BLOCKED/abertas; approved contracts e source_preservation comprovam preservação. Nenhuma decisão institucional encerrada. Relatórios de executor/auditorias anteriores preservados byte a byte; evidências geradas pelas regressões copiadas e originais restaurados.

## Findings finais

| Finding | Severidade | Estado | Conclusão |
|---|---|---|---|
| W4R-01 | HIGH original | RESOLVED | Boundary genérico/infantil regressado com sucesso |
| W4R-02 | MEDIUM original | RESOLVED | Scope fail-closed; workaround temporário e tracking mantidos |
| W4R-03 | MEDIUM original | RESOLVED | Checkout temporal regressado com sucesso |
| W4R-M1R-01 | agregado original | PARTIALLY_RESOLVED | OUTREACH wrapper corrigido, dois HIGH residuais abaixo |
| TEST-TIMING-01 | MEDIUM | OPEN | Falha real após pré-criação; não demonstrado determinismo global |
| W4R-M11R-01 | HIGH | OPEN | CONFIGURE não revalidada após lock posterior à sua própria verificação |
| W4R-M11R-02 | HIGH | OPEN | progress sem autorização final após waits de insert/audit |
| W4R-M11R-03 | MEDIUM | OPEN | collect sem timeout e cleanup dos caminhos de falha incompleto |

### W4R-M11R-01 — HIGH: CONFIGURE stale depois do lock FK

Locais: EvangelismService.php:158–159 (track), :170–171 (step), :40 (final verifica só OUTREACH); DomainAccess.php:89 (audit INSERT/FK unit). Reprodução concreta **track**: actor possui OUTREACH durável e CONFIGURE em concessão separada; unit.id retida por outra conexão; track insere e bloqueia no INSERT de audit_logs; CONFIGURE expira; libertar unit → **PASS**, delta **1 track, 1 success audit**, após expiração. `configure_late_lock.json`: expires 15:31:14.402755 UTC, conclusão 15:31:14.773425 UTC. step possui a mesma sequência por inspecção; não se declara uma reprodução adicional dessa variante pós-audit. Expected: ACTOR_NOT_AUTHORIZED e zero writes. Impacto: escrita de configuração após expiração da permissão obrigatória, sob espera SQL real. HIGH pelo critério explícito do pedido. Recomendação sem aplicar: revalidar também CONFIGURE com fresh clock depois de todas as writes/locks relevantes, dentro da mesma transacção.

### W4R-M11R-02 — HIGH: progress stale depois do audit FK

Locais: EvangelismService.php:205–213; DomainAccess.php:89. Reprodução: sessão válida no início 15:32:35.387300; expires 15:32:49.833964; INSERT de audit em LOCK WAIT sob unit.id; libertação pós-expiração → **PASS**, delta **1 progress, 1 audit**, conclusão 15:32:50.008035. `progress_late_lock.json`. A espera posterior à última decisiva invalida a declaração de que todos os writers revalidam depois de todos os locks. Scope correcto não impede o bypass temporal. Recomendação sem aplicar: autorização final fresh depois de insert/audit ou eliminar todos os waits posteriores à decisiva com protocolo comprovado; rollback integral se final falhar.

### W4R-M11R-03 — MEDIUM: liveness e limpeza do collector

Locais: WaveThreeCheckinConcurrencyTest.php:19–52 / :174–202. done pode preceder fim do processo; collect lê stdout/stderr em modo bloqueante e não supervisiona running/deadline. Probe por reflection do método original demonstra bloqueio persistente mesmo após done, exigindo terminação externa da árvore criada pelo probe. Crash que termina é correctamente recusado: este finding é hang/cleanup, **não falso PASS**. Readiness timeout e falhas de asserção podem deixar workers/directórios porque cleanup não usa finally. Recomendação sem aplicar: recolha não bloqueante com timeout de toda a fase, exit code preservado, terminação supervisionada e limpeza scoped em finally.

## Gate

**REJECTED**: dois HIGH reproduzidos e dois problemas MEDIUM distintos (TEST-TIMING-01 e liveness) abertos. W4R-M1R-01 é o agregado dos dois HIGH, não um problema adicional para contagem. Paridade/regressões PASS não compensam as escritas stale confirmadas. Nenhuma correcção aplicada e Wave 5 não iniciada.

Artefactos: `docs/database/physical/wave4_m11_audit/`, com environment/preflight, snapshots Git/fontes, todos logs/JUnit, SQL adversarial, 30 isolated qualificados, 10 loaded, parallel-run, InnoDB e Graphify. `audit_summary.json` permite leitura automática. Fonte/ferramentas próprias: `scripts/audit-wave4-m11/`.

P0.3.4 — CONTINUA NÃO APROVADA PARA WAVE 5
'''
report=root/'docs/reviews/P0.3.4_M1_1_temporal_remediation_audit.md';report.write_text(text,encoding='utf8')
gate=root/'docs/reviews/P0.3.4_wave4_gate.md'
with gate.open('a',encoding='utf8') as f:f.write(f'''\n\n---\n\n## Adenda P0.3.4-M1.1-R — auditoria independente final (2026-09-14)\n\n**Gate: REJECTED.** Ver [auditoria temporal final](P0.3.4_M1_1_temporal_remediation_audit.md). MySQL novo 8.4.11 / 33114; preflight, regressões infantis/checkout/Wave3/Evangelismo, validadores/paridade e Graphify PASS. W4R-01/02/03 RESOLVED; proveniência mantém TEMPORARY_TECHNICAL_WORKAROUND.\n\nW4R-M1R-01 PARTIALLY_RESOLVED: novos **W4R-M11R-01 HIGH** (CONFIGURE expira durante audit FK wait; wrapper final verifica só OUTREACH) e **W4R-M11R-02 HIGH** (progress committa após sessão expirar em audit FK wait posterior ao último recheck). Ambos reproduzidos com writes e success audit persistidos. **TEST-TIMING-01 OPEN**: falha conservada apesar de duas claims pré-criadas. **W4R-M11R-03 MEDIUM OPEN**: collector original sem timeout/cleanup supervisionado.\n\nRepetições: 30 isoladas qualificadas = {score(iso)}; 10 com suite infantil concorrente = {score(loaded)}; dual-run = {score(parallel)}. Chamadas iniciais que coincidiram com probes próprios permanecem registadas ({score(candidates)}), sem retry de falhas nem descarte do resultado global. Strict parity PASS, unexpected_drift 0, 13 Graphify queries, zero dangling; D-02–D-12 intactas.\n\nNenhum código/migration corrigido; relatórios anteriores preservados; Wave 5 não iniciada.\n\nP0.3.4 — CONTINUA NÃO APROVADA PARA WAVE 5\n''')
print('REPORT_WRITTEN',report,flush=True)
