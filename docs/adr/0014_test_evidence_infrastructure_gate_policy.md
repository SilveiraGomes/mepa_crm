# ADR 0014 — Test evidence, infrastructure isolation and Wave gate policy

Estado: decisão de governança de processo P0-TI.1, sujeita a auditoria. Não altera código de produção, migrations ou schema.

## Contexto

Cinco rondas de remediação P0.3.4 (M1.2 → M1.2.3-R) resolveram, sucessivamente, findings de produto (W4R-01/02/03, W4R-M11R-01/02), findings de evidência (H1/H2/M1/M2, M122R-01/02) e agora um finding na própria mecânica de rescue do harness de cleanup (M123R-01/02). Cada ronda tratou todo finding aberto como bloqueador de Wave igualmente severo, o que produz um ciclo sem critério de término: um defeito no harness que limpa schemas descartáveis **depois** de uma corrida já correctamente marcada `CLEANUP_FAIL` bloqueia a mesma Wave que um bypass de autorização em produção. Esta ADR formaliza a distinção que faltava.

## Decisão

Todo resultado de teste/auditoria neste projecto passa a ser classificado em três dimensões independentes, nunca fundidas numa única severidade:

- **PRODUCT_RESULT** — o comportamento do domínio (autorização, custódia infantil, consistência de dados, concorrência, segurança) está correcto?
- **EVIDENCE_RESULT** — a prova de que PRODUCT_RESULT é verdadeiro é, ela própria, digna de confiança (não pode ser falsificada, ocultar uma falha real, ou ser ambígua)?
- **INFRASTRUCTURE_RESULT** — o arnês/ambiente descartável usado para produzir a evidência funcionou sem afectar as duas dimensões acima?

Estados possíveis para cada dimensão: `PASS`, `FAIL`, `WARN`, `NOT_RUN`.

### Regra de gate

```
PRODUCT_RESULT == PASS AND EVIDENCE_RESULT == PASS
```
é necessário e suficiente para libertar uma Wave. `INFRASTRUCTURE_RESULT == WARN` fica como dívida técnica rastreada, nunca bloqueia. `INFRASTRUCTURE_RESULT == FAIL` só bloqueia se uma análise concreta mostrar que compromete PRODUCT_RESULT ou EVIDENCE_RESULT — nunca por defeito.

### Bloqueadores de PRODUCT (qualquer CRITICAL/HIGH/MEDIUM aberto bloqueia a Wave)

- bypass de autorização;
- autorização obsoleta capaz de committar;
- bypass de segurança infantil;
- bypass de âmbito (scope);
- corrupção ou perda de dados;
- schema/FK/invariante inválido;
- falha de correcção de concorrência;
- regressão de segurança;
- comportamento do produto que contradiz requisitos canónicos.

### Bloqueadores de EVIDENCE (qualquer CRITICAL/HIGH/MEDIUM aberto bloqueia a Wave)

- falso `PASS`;
- teste que não executa de facto o caminho que afirma verificar;
- harness capaz de ocultar uma falha de domínio;
- `FAIL` convertível em `PASS`;
- uma corrida capaz de alterar a evidência/resultado de outra corrida;
- resultado não reproduzível por verificação independente razoável;
- evidência crítica ambígua ou não fidedigna.

### Dívida de infraestrutura de teste (TEST_INFRA_DEBT) — não bloqueia por defeito

Um finding de infraestrutura **não** bloqueia a Wave quando é demonstrado (nunca assumido) que não pode:

a) alterar o comportamento do produto;
b) produzir um falso `PASS`;
c) ocultar uma falha real de produto;
d) danificar recursos/resultados de outra corrida;
e) invalidar evidência crítica.

Exemplos: um recurso de cleanup temporário sobrevive a uma corrida já correctamente marcada `FAIL`; cleanup diagnóstico é lento; rescue não consegue remover artefactos de teste depois do resultado já estar `FAIL`; ineficiência de log/cleanup não crítica.

Severidade e "bloqueia a Wave" são campos separados. Exemplo:

```json
{"severity": "MEDIUM", "category": "TEST_INFRA_DEBT", "wave_blocker": "NO"}
```

Um finding de infraestrutura classificado desta forma permanece **OPEN** como dívida técnica — esta classificação nunca implica `RESOLVED`.

### Modelo de resultado de corrida

```json
{
  "product": "PASS",
  "evidence": "PASS",
  "infrastructure": "WARN"
}
```

### Isolamento de infraestrutura de teste

A causa raiz de grande parte da dívida de infraestrutura acumulada (M12R-01, M122R-01/02, M123R-01/02) é a mesma: `CREATE`/`DROP DATABASE` por corrida contra um servidor MySQL partilhado sob carga concorrente. A "Test Infrastructure V2" (ver `docs/database/physical/test_infrastructure_v2_design.md`) elimina essa causa raiz com uma instância MySQL dedicada, uma pool fixa de schemas persistentes durante a sessão, e reset por `TRUNCATE` em vez de `DROP`/`CREATE` por corrida. Isto não substitui a classificação acima — mesmo com V2 totalmente adoptado, um finding de infraestrutura continua avaliado pelos mesmos cinco critérios (a–e).

## Consequências

- Nenhuma Wave é aprovada com um PRODUCT ou EVIDENCE blocker aberto, independentemente de quantas rondas de remediação já ocorreram.
- Dívida de infraestrutura de teste permanece visível (matriz de findings, secção de dívida técnica no gate), mas deixa de forçar rondas de remediação adicionais antes de uma Wave poder avançar.
- Reclassificar um finding existente para TEST_INFRA_DEBT exige repetir os critérios a–e contra a evidência real (código e reprodução), nunca por inspecção da severidade original apenas.
- Esta ADR não reabre nem reclassifica retroactivamente findings já `RESOLVED`; aplica-se à classificação de findings correntes e futuros. Ver a adenda `P0-TI.1` em `docs/reviews/P0.3.4_wave4_gate.md` para a reclassificação do estado corrente do P0.3.4.
