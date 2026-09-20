# Academia: âmbito de polo/localização e scope granular por turma

**Status:** Accepted — contém duas decisões técnicas independentes, ambas fechadas em P0.3.5-A0.1; nenhuma delas implica aprovação de política institucional (D-09/D-06/D-08/D-11 continuam a seguir o seu próprio processo)
**Data de proposta:** 2026-09-18 (P0.3.5-A0)
**Data de fecho:** 2026-09-18 (P0.3.5-A0.1)

Este ADR regista **duas decisões independentes**. Cada uma tem o seu próprio estado, para nunca ficar ambíguo qual foi aceite e qual foi rejeitado (nenhuma decisão parcial é escondida atrás de um único veredicto de ficheiro).

## Adenda P0.3.5-A3 — atribuição de instrutores (A2-DEV-01)

**Status: Proposed, para ratificação dos owners.** Esta adenda regista a interpretação já implementada em A2 sem alterar código nem fechar uma decisão institucional por inferência.

- Redacção anterior: a matriz desta ADR associava `ACADEMY_TEACH` à criação/remoção de `class_instructors` e dizia que essa operação não exigia atribuição prévia.
- Interpretação aplicada e proposta: atribuir ou terminar a atribuição de instrutores exige `ACADEMY_MANAGE` (ou override explícito `ACADEMY_ADMIN`, quando permitido e auditado). `ACADEMY_TEACH` é uma permissão pedagógica; operações pedagógicas por turma continuam a exigir atribuição activa.
- Razão: impedir auto-atribuição por um docente e preservar separação entre administração da estrutura académica e actividade lectiva.
- Consequência: a Academy HTTP API publica `POST /classes/{class}/instructors` e `POST /instructor-assignments/{assignment}/end` com `ACADEMY_MANAGE`; não oferece uma via de auto-atribuição com `ACADEMY_TEACH`.

A autoridade para mudar o estado Accepted do corpo original não foi presumida nesta fase; por isso a adenda permanece Proposed e rastreada, sem bloquear A3.

---

## Decisão A — Localização/polo de uma turma

**Status: ACCEPTED, com modificação face à proposta original.**

### Contexto

`academic_units` é uma tabela plana (sem `parent_id`) ancorada a um único `organizational_units.id`; `classes` tem `academic_unit_id` mas nenhuma coluna de localização própria. A proposta original (P0.3.5-A0) sugeria duas colunas nullable em `classes`: `unit_id → organizational_units` e `location_id → physical_locations`.

### Decisão

- **`unit_id → organizational_units` em `classes`: REJECTED.** É redundante — sempre derivável transitivamente via `classes.academic_unit_id → academic_units.unit_id`. Adicioná-la criaria uma segunda fonte de verdade para a mesma pergunta ("que unidade organizacional responde por esta turma?"), sem necessidade comprovada.
- **`location_id → physical_locations` em `classes`: ACCEPTED**, `BIGINT UNSIGNED NULL`, `RESTRICT/RESTRICT`, com índice `ix_classes_location_id`. Representa o **local físico por defeito da turma**, não "o polo" — a distinção entre unidade académica responsável (`academic_unit_id`), unidade organizacional (derivável), local físico por defeito (`classes.location_id`) e local concreto de uma sessão específica (via `class_sessions.event_session_id → event_locations`, quando presente) é preservada.
- **Regra de precedência:** quando uma `class_session` tem `event_session_id` preenchido e esse evento tem `event_locations`, o local dessa sessão concreta **prevalece** sobre `classes.location_id` para essa sessão. Sem `event_session_id`, assume-se o local por defeito da turma.
- **Convenção operacional (sem alteração de schema):** um polo com identidade e reporte próprios (ex.: "IBT Huambo" distinto de "IBT Nacional") modela-se como uma linha adicional em `academic_units` (`academic_units.unit_id` aponta para o nível organizacional apropriado), nunca como uma coluna de localização no catálogo permanente do curso.

### Alternativas rejeitadas

Fundir "polo" com `organizational_units` directamente — contraria a separação institucional já estabelecida entre estrutura eclesiástica e estrutura académica, e o precedente do ADR-0010. Criar uma tabela `academic_poles` nova — sem necessidade comprovada; as duas colunas nullable avaliadas (uma aceite, uma rejeitada) resolvem o caso de uso concreto sem nova tabela. Obter localização exclusivamente via `event_locations` (forçar toda turma a ter uma `events` completa) — desproporcionado para turmas sem check-in/QR (ex.: uma aula comum de EBD), avaliado e rejeitado como via única.

### Consequências

`classes` ganha 1 coluna e 1 FK adicionais (`location_id`) face ao catálogo original; nenhuma outra tabela aprovada é alterada. Turma já concluída mantém o `location_id` histórico da altura; mudança de local futura da turma não reescreve turmas passadas (cada `classes` é uma linha própria, sem versionamento necessário para este campo).

---

## Decisão B — Scope granular por turma

**Status: ACCEPTED.**

### Contexto

`scopes` (Wave 2, já física) suporta apenas `scope_kind ∈ {UNIT, UNIT_DEPARTMENT}`. A missão exigia impedir que um professor com `ACADEMY_ASSESS` altere notas de uma turma que não lhe foi atribuída, sem confiar em `class_id`/`unit_id` enviado pelo cliente.

### Decisão

**Não se cria novo `scope_kind` académico.** `scopes`/`user_role_scopes` (Wave 2) permanecem inalterados. A autorização de Academia usa uma cadeia de duas camadas, ambas derivadas no servidor a partir de dados já persistidos:

```
actor → permission (ACADEMY_*) → institutional scope (user_role_scopes, ao nível do academic_unit/unit_id)
      → [se a acção for por turma] instructor assignment activo em class_instructors para essa class_id
      → class → acção permitida
```

`class_instructors` (já no catálogo aprovado: `class_id`, `instructor_id`, `status`, `starts_at`/`ends_at`, `source_document_id`, indexado por `(class_id, starts_at)` e por `instructor_id`) fornece a proveniência granular necessária, com a mesma forma já auditada de `department_appointments` (Wave 2).

**Matriz de autorização (fechada nesta decisão):**

| Permissão | Institutional scope | Class assignment (`class_instructors`) obrigatória? | Admin override (`ACADEMY_ADMIN`) | Auditoria |
|---|---|---|---|---|
| `ACADEMY_VIEW` | Sim | Não | — | Não |
| `ACADEMY_MANAGE` | Sim | Não | — | Sim |
| `ACADEMY_ENROLL` | Sim | Não | — | Sim |
| `ACADEMY_TEACH` | Sim | Não (é quem cria/remove `class_instructors`) | Sim, formal e auditado | Sim |
| `ACADEMY_ATTENDANCE` | Sim | **Sim** | Sim, formal e auditado | Sim |
| `ACADEMY_ASSESS` | Sim | **Sim** | Sim, formal e auditado | Sim |
| `ACADEMY_CERTIFY` | Sim (mais restrito) | Não (homologação institucional, deliberadamente distinta de leccionar) | Sim, formal e auditado | Sim |
| `ACADEMY_ADMIN` | Sim (amplo) | Não (é o próprio override) | — | Sim, sempre |

**Admin override:** só actua quando `user_role_scopes` conceder `ACADEMY_ADMIN` no `unit_id`/`academic_unit_id` relevante; cada uso é obrigatoriamente registado em `audit_logs` (`actor_id`, entidade afectada, `reason`). Nunca é um fallback silencioso.

### Alternativas rejeitadas

Estender `scopes.scope_kind` com um novo valor académico + coluna `academic_unit_id` — tecnicamente viável (haveria precedente de ALTER aditivo em `2026_09_13_000024_wave2_materialize_w1_f01_files_owner_department_id.php`), mas rejeitado por resolver, com mais superfície de mudança a uma tabela já aprovada, exactamente o mesmo problema que `class_instructors` já resolve com uma consulta indexada directa.

### Consequências

Nenhuma alteração a `scopes`/`user_role_scopes` (Wave 2). Nenhuma tabela nova. A implementação de serviço de Academia deve consultar `class_instructors` (e `user_role_scopes` para o nível institucional) em toda a acção sensível por turma, nunca aceitar `class_id`/`unit_id` do payload como autoridade.

---

## Referências

Baseline: `mepa_crm_v1.1.1.md` §14. Levantamento original: `docs/reviews/P0.3.5_A0_academy_design.md` (Secções 5, 8.2, 21). Fecho da decisão: `docs/reviews/P0.3.5_A0_1_academy_decision_gate.md` (Secções 8–10). Catálogo: `docs/database/model_catalog.json` (domínio Academia). Manifest: `docs/database/physical/wave5_manifest.json` (WAVE5-DECISION-01, WAVE5-DECISION-02, ambas ACCEPTED). Precedente de ALTER aditivo (não usado, mas considerado): `apps/api/database/migrations/2026_09_13_000024_wave2_materialize_w1_f01_files_owner_department_id.php`. Este ADR fecha as duas decisões técnicas de arquitectura; não aprova nem infere nenhuma política institucional (D-09 e restantes D-0x seguem o seu processo próprio, documentado em P0.3.5-A0.1).
