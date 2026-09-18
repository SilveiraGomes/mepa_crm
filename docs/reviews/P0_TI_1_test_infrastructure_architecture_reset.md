# P0-TI.1 — Test Evidence & Gate Architecture Reset

Papel: Principal Software Architect / Test Infrastructure Architect. Esta tarefa não é uma remediação nem uma auditoria de um finding específico — é uma reformulação da política de gate em si, seguindo [ADR-0014](../adr/0014_test_evidence_infrastructure_gate_policy.md). Zero alterações a código de produção, migrations ou schema. Não reabre nem reclassifica retroactivamente findings já fechados; aplica a nova política à classificação corrente.

## Metodologia de reclassificação

Cada finding foi revisto contra a evidência real já produzida (código-fonte de `scripts/wave4-m121/cleanup.py`, texto integral de `docs/reviews/P0.3.4_wave4_gate.md` e dos relatórios de auditoria referenciados por cada adenda), nunca por suposição a partir da severidade original. Um finding só é classificado `TEST_INFRA_DEBT`/`TEST_INFRA_INFO` quando as cinco perguntas negativas da secção "Dívida de infraestrutura de teste" da ADR-0014 têm resposta NÃO, demonstrada, não assumida. Nenhum finding historicamente `RESOLVED` é reaberto aqui sem evidência nova.

## Matriz de findings Wave 4

| Finding | Severidade | Estado actual | Categoria | product_blocker | evidence_blocker | wave_blocker | Razão |
|---|---|---|---|---|---|---|---|
| W4R-01 | HIGH | RESOLVED | PRODUCT | NO | NO | NO | Check-in genérico (`CheckinService`) contornava a fronteira de segurança infantil — criança com consentimento revogado e operador sem `CHILDREN` obtinha `CHECKED_IN`/`event_attendance` sem custódia. Corrigido com gateway child-aware; reprodução original agora recusada (0 escritas); reconfirmado independentemente em P0.3.4-M1-R. |
| W4R-02 | MEDIUM | RESOLVED | PRODUCT | NO | NO | NO | Inscrição de discipulado sem `owner_unit_id` permitia a um operador de outra unidade concluir uma etapa (`progress`) sem vínculo à inscrição de origem. Corrigido e reconfirmado em P0.3.4-M1-R. |
| W4R-03 | MEDIUM | RESOLVED | PRODUCT | NO | NO | NO | Relógio decisivo de checkout amostrado antes de uma espera de lock, nunca revalidado depois — autorização podia expirar durante a espera e ainda assim validar. Corrigido (revalidação pós-lock) e reconfirmado. |
| W4R-M11R-01 | HIGH | RESOLVED | PRODUCT | NO | NO | NO | `EvangelismService::track()`: concessão CONFIGURE expirava durante espera de lock de FK de audit; o wrapper final só revalidava OUTREACH. Corrigido com revalidação fresh pós-todas-as-waits; reconfirmado RESOLVED em P0.3.4-M1.2-R. |
| W4R-M11R-02 | HIGH | RESOLVED | PRODUCT | NO | NO | NO | `progress()` committava após a sessão expirar numa espera de FK de audit posterior à última revalidação. Mesma classe de correcção que M11R-01; reconfirmado RESOLVED. |
| W4R-M11R-03 | MEDIUM | RESOLVED | TEST_INFRA | NO | NO | NO | Collector original de `WaveThreeCheckinConcurrencyTest` (não produto) podia bloquear sem timeout/cleanup supervisionado após `done`; o próprio relatório original confirma "hang/cleanup, nunca falso PASS". Corrigido, reconfirmado RESOLVED. |
| TEST-TIMING-01 | MEDIUM | RESOLVED | TEST_INFRA | NO | NO | NO | Teste de janela fixa de 5s (dependente de relógio de parede) falhava ~50% mesmo isolado. Causa raiz real: contenção de lock de intervalo do InnoDB sobre unique key composta quase vazia — falha de desenho do arnês, nunca uma corrida de produção. Corrigido via barreira determinística; reconfirmado RESOLVED. |
| W4R-M12R-01 | MEDIUM | RESOLVED | TEST_INFRA | NO | NO | NO | `DROP DATABASE` sobre schema de ~127 tabelas excedia o SLA de 45s do cleanup em corridas concorrentes (schema sempre acabava por desaparecer; zero impacto de produto). Corrigido pelo novo harness `scripts/wave4-m121/` (máquina de estados verificada por ausência real); RESOLVED. |
| H1 | HIGH | RESOLVED | EVIDENCE | NO | NO | NO | `schema_exists()` podia ler uma consulta falhada como "ausente" — classe de falso `PASS`. Corrigido (`VerificationError` explícita); reconfirmado RESOLVED independentemente em P0.3.4-M1.2.3-R. |
| H2 | HIGH | RESOLVED | EVIDENCE | NO | NO | NO | `FILES_REMOVED` podia ser concedido sem verificar ausência real de directórios de barrier — classe de falso `PASS`. Corrigido; reconfirmado RESOLVED. |
| M1 | MEDIUM | RESOLVED | EVIDENCE | NO | NO | NO | SLA de cleanup reiniciado por fase em vez de um único orçamento partilhado, podia mascarar um timeout real numa fase lenta. Corrigido (orçamento único computado em `cleanup_started_at`); reconfirmado RESOLVED. |
| M2 | MEDIUM | RESOLVED | EVIDENCE | NO | NO | NO | `connections_zero_at` podia ser gravado ao expirar o prazo em vez de após confirmação real de zero sessões — estado falso. Corrigido; reconfirmado RESOLVED. |
| M122R-01 | MEDIUM | RESOLVED | EVIDENCE | NO | NO | NO | `OSError` não capturado em 3 pontos de `_mysql()` (emissão DROP, KILL, `diagnostics_snapshot()`) crashava `cleanup_run()` sem resultado estruturado e, em 2/3 casos, sem tentativa de rescue. Corrigido com boundary único `mysql_exec()`; reconfirmado RESOLVED independentemente em P0.3.4-M1.2.3-R com falhas reais (binário inexistente, timeout genuíno, exit≠0 genuíno). |
| M122R-02 | MEDIUM | RESOLVED | EVIDENCE | NO | NO | NO | Posse de barrier inferida por timing, não identidade — `rescue_cleanup()` podia remover (`shutil.rmtree`) um candidato sobrevivente de outra corrida sem verificar posse (confirmado não-vazio em 6/40 corridas reais, sempre auto-resolvido). Corrigido com raiz própria por corrida + `verify_ownership()`; reconfirmado RESOLVED independentemente com 60/60 pares de ownership e ataques de integração dedicados, sempre `DENIED`. |
| M123R-01 | MEDIUM | **OPEN** | TEST_INFRA_DEBT | NO | NO | NO | Ver secção dedicada abaixo. |
| M123R-02 | LOW | **OPEN** | TEST_INFRA_INFO | NO | NO | NO | Ver secção dedicada abaixo. |

**Contagem actual: 0 PRODUCT blockers abertos. 0 EVIDENCE blockers abertos. 2 TEST_INFRA findings abertos (1 MEDIUM debt, 1 LOW informational), nenhum wave-blocking.**

## M123R-01 — reclassificação

Código relevante: `scripts/wave4-m121/cleanup.py`, `rescue_cleanup()` (linha ~600) e os seus dois pontos de invocação em `cleanup_run()` (linha ~721).

`rescue_cleanup()`'s própria função interna `_root_gone()` (linha ~632) apanha apenas `(CleanupInfrastructureError, RuntimeError)`. Chama `safe_remove_run_root()` → `verify_ownership()` → `_read_owner_marker()`, que faz `marker_path.read_text(encoding="utf-8")` guardado apenas por `except OSError` (linha ~371). Um `.owner.json` corrompido com bytes não-UTF8 levanta `UnicodeDecodeError` — um `ValueError`, não um `OSError` — que não é apanhado em nenhum ponto desta cadeia e propaga-se para fora de `rescue_cleanup()` sem ser interceptado.

Isto afecta os dois pontos onde `cleanup_run()` invoca `rescue_cleanup()`:

- No handler `except Exception` genérico (linha ~784): o crash de `rescue_cleanup()` é apanhado por um `except Exception: pass` local, mas a excepção **original** (a que causou o `except Exception` ser alcançado) continua a propagar-se via `raise` na linha seguinte — o harness termina em crash visível, nunca em `PASS`.
- No caminho de rotina após `CLEANUP_FAIL` (linha ~803): esta chamada não tem qualquer try/except à sua volta — o crash de `rescue_cleanup()` propaga-se directamente para fora de `cleanup_run()`.

Em ambos os casos, aplicando os cinco critérios da ADR-0014:

a) **Altera comportamento de produto?** Não — `rescue_cleanup()` só actua depois do teste de domínio já ter terminado e o seu resultado já ter sido registado; a rescue nunca toca em código ou dados de produto.
b) **Produz falso PASS?** Não — em ambos os caminhos a excepção propaga-se visivelmente; `cleanup_result` já está gravado como `CLEANUP_FAIL` antes de rescue ser chamado (caminho de rotina) ou o harness inteiro aborta (caminho genérico). Nunca um `PASS` fabricado.
c) **Oculta uma falha real de produto?** Não — o resultado de domínio (`domain_result`/`domain_exit`) é gravado em `RunTelemetry` antes de qualquer lógica de cleanup correr; um crash no rescue não apaga nem reescreve esse campo.
d) **Danifica recursos de outra corrida?** Não — toda a função neste módulo é guardada por `guard_schema()`/`validate_database_ownership()`; a rescue só opera sobre a schema e a raiz da sua própria corrida.
e) **Invalida evidência crítica?** Não — a evidência do resultado de domínio e do `CLEANUP_FAIL` já está gravada; o único efeito é que o recurso órfão (schema + run_root da própria corrida) não é removido nesta passagem best-effort.

Confirmado por reprodução limpa na auditoria M1.2.3-R (623e921): schema E run_root ambos genuinamente presentes imediatamente após o crash, antes de qualquer teardown manual.

```json
{
  "finding": "M123R-01",
  "severity": "MEDIUM",
  "category": "TEST_INFRA_DEBT",
  "product_blocker": "NO",
  "evidence_blocker": "NO",
  "wave_blocker": "NO",
  "status": "OPEN"
}
```

A corrida já está correctamente marcada `FAIL` antes de rescue começar; o defeito afecta apenas a limpeza best-effort posterior à falha. **Não é marcado RESOLVED** — permanece dívida técnica aberta; uma remediação M1.2.4 deve fechá-lo alargando o `except` de `_root_gone()`/`_schema_gone()` (ou de todo o corpo de `rescue_cleanup()`) para tolerar uma classe de excepção verdadeiramente não modelada, sem nunca converter isso num falso `PASS`.

## M123R-02 — reclassificação

Um bug de chamador (raiz de outra corrida passada por engano a `rescue_cleanup()`) faz `safe_remove_run_root()` levantar `RuntimeError("ABORT HARD…")` a partir do caminho primário (linha ~697, sem guarda aí). Isso é apanhado pelo handler genérico de `cleanup_run()`, que dispara a chamada defensiva a `rescue_cleanup()`; dentro da rescue, `_root_gone()` apanha esse mesmo `RuntimeError` a cada iteração de poll e devolve `False`, fazendo o ciclo girar pelo orçamento completo de `RESCUE_TIMEOUT_SECONDS` (90s) antes de `rescue_cleanup()` devolver `False` (sem excepção) e o `raise` original de `cleanup_run()` disparar correctamente — coincide com a medição da auditoria (~91.4s). Nunca remove o recurso errado, nunca fabrica um resultado — apenas ineficiência.

```json
{
  "finding": "M123R-02",
  "severity": "LOW",
  "category": "TEST_INFRA_INFO",
  "product_blocker": "NO",
  "evidence_blocker": "NO",
  "wave_blocker": "NO",
  "status": "OPEN"
}
```

## Test Infrastructure V2 — piloto

Ver [test_infrastructure_v2_design.md](../database/physical/test_infrastructure_v2_design.md) para a arquitectura completa. Resultados do piloto (secção 18 do pedido) documentados abaixo — corrida real, não simulada: `tools/test-infrastructure/run_pilot.py`, evidência completa (JUnit XML + JSON por suite + `summary.json`) em `docs/database/physical/test_infrastructure_v2_pilot/`.

Instância dedicada: MySQL 8.4.7, `datadir` efémero sob `%TEMP%\mepa-test-mysql\<session_id>\`, porta 3307 (nunca 3306/`laravel`). Pool criada e migrada uma única vez: `mepa_wave4_test_pool_01`/`_02` (127 tabelas cada) e `mepa_wave3_test_pool_01` (110 tabelas). `track_commit_authorization` e `child_safety_generic_path_rejection` correram **concorrentemente** contra `_pool_01`/`_pool_02` (prova da secção 13 — sem partilha de base entre workers); as restantes 4 corridas reutilizaram sequencialmente uma pool já migrada, reset por `TRUNCATE` (nunca `DROP DATABASE`) automaticamente dentro de `PooledWaveFourCase`/`PooledWaveThreeCase::setUpBeforeClass()`.

| Suite piloto | Pool DB | Resultado | product | evidence | infrastructure | Testes |
|---|---|---|---|---|---|---|
| Wave3 check-in concurrency (2/10/30 workers, um invocação) | `mepa_wave3_test_pool_01` | PASS | PASS | PASS | PASS | 6/6 |
| track commit authorization | `mepa_wave4_test_pool_01` | PASS | PASS | PASS | PASS | 1/1 |
| step commit authorization | `mepa_wave4_test_pool_01` | PASS | PASS | PASS | PASS | 1/1 |
| progress commit boundary | `mepa_wave4_test_pool_01` | PASS | PASS | PASS | PASS | 1/1 |
| child safety generic-path rejection (reprodução literal W4R-01) | `mepa_wave4_test_pool_02` | PASS | PASS | PASS | PASS | 1/1 |
| checkout temporal expiry | `mepa_wave4_test_pool_01` | PASS | PASS | PASS | PASS | 1/1 |

Paragem da instância: `mysqld_stopped=true`, `datadir_removed=true`, `infrastructure_result=PASS` (nenhum WARN necessário nesta corrida). Zero processos `mysqld.exe` órfãos confirmados por `tasklist` imediatamente após. Zero DSN alguma vez apontou para `127.0.0.1:3306`/`laravel`.

**6/6 suites críticas reproduzem o mesmo resultado `PASS` já estabelecido pela evidência V1**, com 0 partilha de base entre workers concorrentes, 0 `DROP DATABASE` por corrida, 0 falso `PASS` (todo resultado tem JUnit XML válido com contagem de asserções reais), 0 ambiguidade de evidência. Critério de aceitação da secção 18: cumprido.

Escopo explicitamente não coberto por este piloto (secção 11/17 — "não portar tudo"): as restantes 15+ suites de regressão, o nível de 50 workers (2/10/30 corridos; 50 fica como trabalho futuro), e a promoção de V2 a harness por defeito — `scripts/wave4-m121/` continua a qualificar a Wave 4 normalmente.

## Recomendação final

Com a matriz de findings acima (0 PRODUCT blockers abertos, 0 EVIDENCE blockers abertos) e o piloto V2 a reproduzir 6/6 resultados críticos com sucesso real, os três critérios da secção 19 do pedido estão cumpridos:

- PRODUCT blockers = 0 ✓
- EVIDENCE blockers = 0 ✓
- Suites críticas V2 reproduzem com sucesso ✓

**WAVE 4 — PRODUCT/EVIDENCE GATE SATISFIED**

**P0.3.4 — APPROVED FOR WAVE 5 WITH NON-BLOCKING TEST-INFRA DEBT**

Dívida de infraestrutura de teste não bloqueante e rastreada: M123R-01 (MEDIUM, `rescue_cleanup()` não uniformemente defensivo nos seus próprios passos internos — ver secção dedicada acima) e M123R-02 (LOW, informativo). Uma remediação M1.2.4 deve fechar M123R-01 quando conveniente; não é gate para Wave 5. Wave 5 **não** é iniciada por este documento — esta é uma reavaliação de gate, não uma autorização de implementação (secção 20/23 do pedido).
