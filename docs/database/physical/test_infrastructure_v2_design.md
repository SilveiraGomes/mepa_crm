# Test Infrastructure V2 — design

Companion to [ADR-0014](../../adr/0014_test_evidence_infrastructure_gate_policy.md) and
[P0_TI_1_test_infrastructure_architecture_reset.md](../../reviews/P0_TI_1_test_infrastructure_architecture_reset.md).
Full design; only a bounded pilot (6 critical suites) is implemented in P0-TI.1 —
see that review doc's pilot section for what actually ran.

## Root cause this design removes

Every `TEST_INFRA` finding to date (W4R-M12R-01, M122R-01, M122R-02, M123R-01,
M123R-02) traces back to the same architectural choice: each test run mints
its own schema and issues `CREATE DATABASE`/`DROP DATABASE` against a MySQL
server shared with every other concurrent run. `DROP DATABASE` on a
~127-table/255-FK schema goes through InnoDB's atomic DDL commit phase, which
shares the server's redo-log/group-commit pipeline with every other
connection — under concurrent load this phase can legitimately take tens of
seconds, and the cleanup harness that verifies/rescues around that reality is
where all five findings above live. V2 removes the per-run `CREATE`/`DROP`
entirely: schemas are created once, reused for the whole test session, and
reset by data-only `TRUNCATE`.

## Architecture

```
tools/test-infrastructure/
  mysql_instance.py   start/stop a dedicated mysqld, isolated from the app DB
  pool.py             create + migrate-once the fixed database pool
  run_pilot.py         run a mapped suite against a pool DB, record result
```

### Dedicated MySQL instance

- Same binaries as the app DB (`C:\wamp64\bin\mysql\mysql8.4.7\`), a
  **separate** running instance — never the `127.0.0.1:3306` / `laravel`
  server.
- `datadir`: `%TEMP%\mepa-test-mysql\<session_id>\data\`, initialized once
  per session via `mysqld --initialize-insecure` (root, empty password —
  same convention as the documented app-DB access).
- `port`: configurable, defaults to `3307` (never `3306`).
- Lifecycle: `mysql_instance.py init` → `start` (launches `mysqld`, polls
  `mysqladmin ping` until ready) → test session runs → `stop`
  (`mysqladmin shutdown`, wait for process exit) → best-effort datadir
  removal.
- **Section 15 rule, load-bearing**: if datadir deletion is slow/fails
  *after* `mysqld` is confirmed stopped, that is recorded as
  `INFRASTRUCTURE_RESULT = WARN`, never `PRODUCT_FAIL` — the same
  distinction ADR-0014 makes for every other infra finding.

### Database pool

Fixed, session-lived schemas, one per wave the pool serves, named to satisfy
the *existing, unmodified* guard regexes in `WaveFourCase::connect()` /
`WaveThreeCase::connect()` (`^mepa_wave{3,4}_test_[a-z0-9_]+$`) so **no
change to those guards is needed**:

```
mepa_wave4_test_pool_01
mepa_wave4_test_pool_02
mepa_wave3_test_pool_01
```

(The full target design is 4 Wave-4 + 2 Wave-3 pool databases, one per
concurrent worker slot; the P0-TI.1 pilot provisions the 3 shown above —
enough to prove no-cross-worker-sharing with 2 concurrent Wave-4 suites plus
1 Wave-3 suite, without paying for capacity the pilot's 6 fixed targets never
use concurrently.)

Each concurrent test worker gets exactly one exclusive pool database for its
run; the pool manager (`pool.py`) hands out and reclaims assignments so no
database is ever assigned to two workers at once.

### Migrate once

`pool.py` creates each empty pool database, then invokes a small CLI script
(`apps/api/tests/DatabaseV2/Support/migrate_pool_db.php`) that runs the
**exact same migration sequence** `WaveFourCase`/`WaveThreeCase` already run
in their `setUpBeforeClass()` (scaffold → wave1 → wave2 → wave2m1 → wave3 →
wave4, each read from the same `docs/database/physical/wave{1,2,3,4}_manifest.json`
manifests those classes already use) — same source of truth, so there is no
drift risk between what V1 and V2 consider "the schema." This runs **once**
per pool database for the life of the test session, never per test run.

### Reset strategy

No per-run `DROP DATABASE`. Between uses of a pool database:

```sql
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE <every table except `migrations`>;
SET FOREIGN_KEY_CHECKS = 1;
```

The table list is read from `information_schema.tables` for the pool schema
at reset time, so it never drifts from whatever the migration set actually
created. This is a data reset, not a schema reset — the ~127-table/255-FK/
81-CHECK schema is built exactly once per pool database per session.

For tests whose assertions are compatible with a single transaction
(no separate-process concurrency), a `BEGIN` / test body / `ROLLBACK` wrapper
is the cheaper reset and is preferred where applicable. The 6 P0-TI.1 pilot
targets are concurrency/authorization-boundary tests that spawn real worker
subprocesses against committed data (the same pattern the existing V1 suites
use) — `TRUNCATE` reset is what they need, so the pilot exercises that path;
the transactional path is documented here for suites ported later that don't
need multi-process locking.

### PHPUnit integration — additive only

`WaveFourCase.php` / `WaveThreeCase.php` are **not modified**. Two new
subclasses in `apps/api/tests/DatabaseV2/Support/` extend them and override
only `setUpBeforeClass()`:

- If the pool database's `information_schema.tables` is non-empty (already
  migrated by `pool.py`), skip the virgin-DB assertion and the migration
  replay entirely, `TRUNCATE`-reset business data, and continue.
- Otherwise (defensive fallback — the pilot's own flow never hits this, since
  `pool.py` always migrates before any test runs), fall through to running
  the same migration sequence directly, matching `migrate_pool_db.php`.

Every other inherited method (`connect()`, `fixture()`, `childFixture()`,
`row()`, `policy()`, `domainPolicy()`, …) is reused unchanged — the ported
pilot test methods are byte-identical copies of the already-audited
originals; only the database-lifecycle base class differs.

### Run result model

```json
{
  "suite": "WaveFourChildCheckinBoundaryTest::test_generic_service_direct_call_reproduces_original_bypass_scenario_and_is_now_denied",
  "product": "PASS",
  "evidence": "PASS",
  "infrastructure": "PASS",
  "workers": null,
  "duration_ms": 0,
  "pool_database": "mepa_wave4_test_pool_01",
  "notes": ""
}
```

Classification rule `run_pilot.py` applies to each PHPUnit invocation:

- `evidence = FAIL` if PHPUnit itself errors before running any assertion
  (fatal error, DB connection refused, migration/reset mismatch) — the run
  produced no trustworthy signal at all.
- `product = FAIL` if PHPUnit reports the suite's own domain assertions
  failed; `product = PASS` otherwise.
- `infrastructure = WARN` if the suite passed but pool reset/teardown after
  it had to retry or exceeded its own budget; `FAIL` only if that is shown to
  have affected the product/evidence result for *that* run; `PASS` otherwise.

## Gate logic (restated from ADR-0014)

```
release_wave = (product == PASS) and (evidence == PASS)
# infrastructure == WARN: tracked, never blocks
# infrastructure == FAIL: blocks only if shown to affect product/evidence
```

## Explicitly out of scope for P0-TI.1

- Porting all 21+ regression suites (only the 6 named critical suites are
  piloted).
- The 50-worker tier (2/10/30 run; 50 is future work, noted not dropped).
- Replacing the V1 harness (`scripts/wave4-m121/`) — it keeps qualifying
  Wave 4 as-is; V2 is additive, proven on a subset, not yet the default.
- `FIN-PAYROLL-01` — unchanged, future RH/Finance tracking only.
