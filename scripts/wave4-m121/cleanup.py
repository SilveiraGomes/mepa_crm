"""P0.3.4-M1.2.1/M1.2.2 -- deterministic per-run cleanup for disposable Wave 3/4 test schemas.

Root cause (see docs/reviews/P0.3.4_M1_2_1_cleanup_remediation.md for full evidence):
DROP DATABASE on a ~127-table/255-FK/81-CHECK schema goes through InnoDB atomic DDL,
whose final "waiting for handler commit" phase shares the server's redo log / group
commit pipeline with every other connection. When a concurrent sibling run is
committing many small worker transactions at the same time, that phase can
legitimately take up to ~100s even though the target schema itself has zero sessions
with db=<schema> -- this was reproduced live (thread state "waiting for handler
commit", 40s+, zero processlist rows for the schema). Critically: killing the
*client* that issued DROP DATABASE does NOT stop the server-side operation, which
keeps running unattended to completion. A harness that times out its client and
walks away without polling for actual absence will misreport a slow-but-successful
cleanup as FAILED. This module fixes that by treating "cleanup" as a verified state
machine (see CLEANUP_STATES) with its own SLA, its own connection/session hygiene
pass, and honest bounded retries -- never converting a real timeout into a fake PASS.

M1.2.2 hardening (see docs/reviews/P0.3.4_M1_2_2_cleanup_verification_remediation.md):
the independent re-audit P0.3.4-M1.2.1-R reproduced four false-PASS classes empirically
(invariant_probes.json) before being interrupted by usage limits:
  H1 - a failed verification query (SQL error, timeout, disconnect) was silently read
       as "schema absent" because `schema in stdout` is False on empty/garbage stdout.
  H2 - FILES_REMOVED / CLEANUP_VERIFIED were stamped without ever checking whether any
       barrier directory actually still existed.
  M1 - the 150s budget only ever bounded the DROP/poll sub-phase; total cleanup wall
       time (drain + drop + barrier checks) could exceed it while still reporting PASS.
  M2 - connections_zero_at was stamped unconditionally once the connection-drain
       sub-deadline expired, even with sessions still present after the forced kill.

The fix follows one rule everywhere a resource's existence is checked: a verification
FAILURE (SQL error, subprocess timeout, unexpected filesystem error) must never be
read as ABSENT. `VerificationError` is the explicit UNKNOWN outcome (see ResourceState)
-- it is raised, never swallowed into a truthy/falsy default, and it always collapses
the whole run to CLEANUP_FAIL. schema_exists()/referencing_sessions() keep their
existing boolean/list return contracts (several out-of-scope audit probe scripts in
scripts/audit-wave4-m121r/ call them in boolean context, e.g. `if cleanup.schema_exists(
schema):` -- changing those to return a non-boolean tri-state value would silently
break that truthiness logic without touching a single line there, which is worse than
today). Raising on failure is the "explicit result, or equivalent" the remediation
brief asks for: it is impossible to accidentally treat as falsy, and it forces every
caller in this module to either handle UNKNOWN explicitly or let it propagate to
CLEANUP_FAIL.

Every function in this module refuses to operate on anything but a schema matching
SCHEMA_RE (mepa_wave3_test_m121_<hex> / mepa_wave4_test_m121_<hex>), and separately,
explicitly, hard-refuses the literal name 'laravel' even if some future caller loosens
the regex. Schema names are never accepted from outside the process (always minted
here via secrets.token_hex()), per the no-arbitrary-external-name requirement.
"""
import re
import secrets
import shutil
import subprocess
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

MYSQL = r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe"

# Every PHP test bootstrap's own required dbname prefix (Tests\Database\Support\*Case.php
# and the individual WaveOne/WaveTwo*Test.php self-contained bootstraps). This harness
# never invents a prefix the PHP side wouldn't already accept -- it mints names using
# exactly these, suffixed with _m121_<hex> so M1.2.1/M1.2.2 runs are trivially
# distinguishable from any other harness's evidence.
ENV_PREFIX = {
    "wave1": "WAVE1", "wave2": "WAVE2", "wave2f": "WAVE2F", "wave2m1": "WAVE2M1",
    "m1audit": "M1AUDIT", "m1safety": "M1SAFETY", "wave3": "WAVE3", "wave4": "WAVE4",
}
SCHEMA_RE = re.compile(r"^mepa_(wave1|wave2|wave2f|wave2m1|m1audit|m1safety|wave3|wave4)_test_m121_[0-9a-f]{24}$")
FORBIDDEN_SCHEMAS = {"laravel", "mysql", "information_schema", "performance_schema", "sys"}

# Two distinct budgets (section 10): the functional test has its own generous timeout
# elsewhere; this is ONLY the cleanup budget, sized from observed evidence:
#   - idle DROP (zero concurrent load): p100 < 0.5s over 30 samples.
#   - light concurrent load (single filtered test, 2 parallel runs): 3.6s-6.5s over 10 samples.
#   - heavy concurrent load (full unfiltered class, 2 parallel runs): observed in-flight at
#     40s+ (still running when sampled), confirmed complete moments later; prior independent
#     audit observed total wall time up to ~135s using a naive 45s-timeout-then-declare-FAIL
#     design that never actually waited for completion.
# CLEANUP_TIMEOUT_SECONDS is set well above every observed real completion so that a run is
# only ever marked CLEANUP_FAIL for a genuine stall, not for legitimate slow-but-forward-
# progressing InnoDB commit work. Per M1.2.2 section 9, this is now the ONE budget covering
# the entire cleanup lifecycle (drain, drop+poll, barrier verification, final check) -- it
# is computed once, at cleanup_started_at, and never reset per phase.
CLEANUP_TIMEOUT_SECONDS = 150
DROP_ATTEMPT_POLL_SECONDS = 2.0
RESCUE_TIMEOUT_SECONDS = 90

# Kept for any external caller that still imports this name; no longer used to bound a
# separate early sub-phase (M1.2.2 unifies drain+drop+verify under one deadline -- see
# module docstring M1 fix). Retained only as a floor for a single drain iteration.
CONNECTION_DRAIN_TIMEOUT_SECONDS = 20


class VerificationError(RuntimeError):
    """Raised whenever this module cannot positively determine a resource's state
    (SQL error, subprocess timeout/disconnect, unexpected filesystem stat error).

    This is the explicit UNKNOWN outcome required by the M1.2.2 remediation (section
    2/3): PRESENT and ABSENT are only ever returned when the underlying check actually
    succeeded. Every raise site in this module is a place where the old code used to
    let a failed check silently read as "resource absent" -- callers MUST NOT catch
    this and treat it as ABSENT; the only correct response is to fail the run.
    """


class ResourceState:
    """Explicit tri-state result recorded on RunTelemetry for observability (section 2).
    Control flow uses VerificationError (see class docstring above) rather than this
    enum's members directly, so that a caller can never forget to check for UNKNOWN
    and accidentally treat a bare boolean/list result as authoritative -- but every
    telemetry field below starts life as UNKNOWN and is only ever overwritten with
    PRESENT/ABSENT after a verification call that actually completed successfully.
    """
    PRESENT = "PRESENT"
    ABSENT = "ABSENT"
    UNKNOWN = "UNKNOWN"


def new_schema(wave: str) -> str:
    assert wave in ENV_PREFIX, f"unknown wave {wave!r}"
    schema = f"mepa_{wave}_test_m121_{secrets.token_hex(12)}"
    assert SCHEMA_RE.match(schema)
    return schema


def guard_schema(schema: str) -> None:
    """Hard, unconditional refusal for anything not our own minted synthetic name."""
    if schema in FORBIDDEN_SCHEMAS or schema.strip().lower() == "laravel":
        raise RuntimeError(f"ABORT HARD: refusing to operate on protected schema {schema!r}")
    if not SCHEMA_RE.match(schema):
        raise RuntimeError(f"ABORT HARD: schema {schema!r} does not match the required synthetic prefix")


def _mysql(statement: str, timeout: float = 30) -> subprocess.CompletedProcess:
    return subprocess.run(
        [MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306", "--batch", "--silent", "--execute=" + statement],
        capture_output=True, text=True, timeout=timeout,
    )


def schema_exists(schema: str) -> bool:
    """PRESENT -> True, ABSENT -> False, UNKNOWN -> raises VerificationError.

    H1 fix: a query that fails (non-zero return, client timeout, client launch
    failure) is NEVER interpreted as "schema not found". Only a query that actually
    completed successfully is allowed to answer this question either way.
    """
    guard_schema(schema)
    try:
        r = _mysql(f"SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = '{schema}'", timeout=15)
    except subprocess.TimeoutExpired as exc:
        raise VerificationError(f"schema_exists({schema}): verification query timed out: {exc}") from exc
    except OSError as exc:
        raise VerificationError(f"schema_exists({schema}): could not invoke mysql client: {exc}") from exc
    if r.returncode != 0:
        raise VerificationError(f"schema_exists({schema}): verification query failed rc={r.returncode}: {r.stderr.strip()}")
    return schema in r.stdout


def referencing_sessions(schema: str) -> list[dict]:
    """Every thread that could plausibly still be touching this schema.

    Deliberately broader than `processlist.db = schema`: the live reproduction in this
    remediation showed a metadata-lock row against the schema's own tables from a thread
    that did NOT show up under db=schema filtering. So this unions two independent
    signals: direct db context, and performance_schema.metadata_locks joined back to
    processlist for GRANTED locks on this schema's objects.

    M2 fix: a failed query raises VerificationError instead of silently returning []
    (which used to read identically to "zero sessions, safe to proceed").
    """
    guard_schema(schema)
    q = (
        f"SELECT DISTINCT p.id, p.user, p.host, p.command, p.time, p.state "
        f"FROM information_schema.processlist p WHERE p.db = '{schema}' "
        f"UNION "
        f"SELECT DISTINCT p.id, p.user, p.host, p.command, p.time, p.state "
        f"FROM performance_schema.metadata_locks m "
        f"JOIN performance_schema.threads t ON t.thread_id = m.OWNER_THREAD_ID "
        f"JOIN information_schema.processlist p ON p.id = t.processlist_id "
        f"WHERE m.OBJECT_SCHEMA = '{schema}' AND m.LOCK_STATUS = 'GRANTED'"
    )
    try:
        r = _mysql(q, timeout=15)
    except subprocess.TimeoutExpired as exc:
        raise VerificationError(f"referencing_sessions({schema}): verification query timed out: {exc}") from exc
    except OSError as exc:
        raise VerificationError(f"referencing_sessions({schema}): could not invoke mysql client: {exc}") from exc
    if r.returncode != 0:
        raise VerificationError(f"referencing_sessions({schema}): verification query failed rc={r.returncode}: {r.stderr.strip()}")
    rows = []
    for line in r.stdout.splitlines():
        if not line.strip():
            continue
        parts = line.split("\t")
        if len(parts) >= 6 and parts[0].isdigit():
            rows.append({"id": int(parts[0]), "user": parts[1], "host": parts[2],
                          "command": parts[3], "time": parts[4], "state": parts[5]})
    return rows


def barriers_present(paths: list[Path]) -> list[Path]:
    """The subset of `paths` that still exist. H2 fix: this is the function that must
    actually be called before anything is allowed to claim FILES_REMOVED -- an empty
    candidate list (nothing new appeared during this run's process window) trivially
    satisfies it, but a candidate that is still on disk does not. A filesystem stat
    error is UNKNOWN, never ABSENT -- it raises rather than being swallowed.
    """
    present = []
    for p in paths:
        try:
            exists = p.exists()
        except OSError as exc:
            raise VerificationError(f"barriers_present: existence check failed for {p}: {exc}") from exc
        if exists:
            present.append(p)
    return present


def diagnostics_snapshot(schema: str) -> dict:
    """Best-effort observability only -- never used to gate PASS/FAIL, so failures here
    are tolerated and simply produce an empty/partial snapshot for the evidence log.
    """
    guard_schema(schema)
    out = {}
    out["processlist"] = _mysql(
        f"SELECT id, user, host, db, command, time, state, LEFT(info,200) info "
        f"FROM information_schema.processlist WHERE db = '{schema}'", timeout=15).stdout
    out["metadata_locks"] = _mysql(
        f"SELECT OBJECT_TYPE, OBJECT_NAME, LOCK_TYPE, LOCK_DURATION, LOCK_STATUS, OWNER_THREAD_ID "
        f"FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA = '{schema}'", timeout=15).stdout
    out["innodb_trx"] = _mysql(
        f"SELECT t.trx_id, t.trx_state, t.trx_started, LEFT(t.trx_query,150) trx_query "
        f"FROM information_schema.innodb_trx t "
        f"JOIN information_schema.processlist p ON p.id = t.trx_mysql_thread_id WHERE p.db = '{schema}'",
        timeout=15).stdout
    out["data_lock_waits"] = _mysql(
        "SELECT requesting_thread_id, blocking_thread_id FROM performance_schema.data_lock_waits", timeout=15
    ).stdout
    return out


def kill_orphan_sessions(schema: str, sessions: list[dict]) -> list[dict]:
    """Kill sessions this run itself owns, identified solely by exact schema-name
    uniqueness (never anything not already scoped to `schema`). Never touches a
    session whose only evidence is a generic host/user match -- only rows returned
    by referencing_sessions(schema), which are schema-scoped by construction.
    """
    guard_schema(schema)
    killed = []
    for s in sessions:
        r = _mysql(f"KILL {int(s['id'])}", timeout=10)
        killed.append({**s, "kill_returncode": r.returncode, "kill_stderr": r.stderr.strip()})
    return killed


@dataclass
class RunTelemetry:
    run_id: str
    schema: str
    state: str = "RUNNING"
    domain_result: Optional[str] = None
    domain_exit: Optional[int] = None
    domain_finished_at: Optional[str] = None
    cleanup_started_at: Optional[str] = None
    connections_zero_at: Optional[str] = None
    drop_started_at: Optional[str] = None
    database_dropped_at: Optional[str] = None
    files_removed_at: Optional[str] = None
    cleanup_verified_at: Optional[str] = None
    cleanup_duration_ms: Optional[int] = None
    drop_attempts: int = 0
    blocking_sessions_seen: list = field(default_factory=list)
    metadata_locks_seen: int = 0
    cleanup_result: Optional[str] = None
    rescue_used: bool = False
    diagnostics: list = field(default_factory=list)
    candidate_barriers: list = field(default_factory=list)
    verification_error: Optional[str] = None
    sla_exceeded: bool = False
    # Tri-state observability (section 2): starts UNKNOWN, only ever overwritten with
    # PRESENT/ABSENT by a verification call that actually completed. Never defaults
    # to ABSENT -- a run that errors before reaching a given check leaves it UNKNOWN.
    final_database_state: str = ResourceState.UNKNOWN
    final_connections_state: str = ResourceState.UNKNOWN
    final_barriers_state: str = ResourceState.UNKNOWN


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def drain_connections(schema: str, telemetry: RunTelemetry, deadline: float) -> None:
    """CLOSE ALL TEST DB CONNECTIONS / VERIFY NO SESSION USES RUN DATABASE.

    The PHP process that owned this schema has already been reaped by the caller
    (subprocess.communicate() returned) before this is ever called, so any session
    still referencing the schema is by definition an orphan of this same run --
    never laravel, never another run (schema names are globally unique per run).

    `deadline` is the single shared cleanup deadline (M1.2.2 section 9) -- this
    function no longer owns its own separate sub-budget. M2 fix: connections_zero_at
    is stamped ONLY the moment a successful query confirms zero sessions; if the
    shared deadline is hit first with sessions still present, it is left unset and
    this returns without claiming anything -- the caller's own final verification
    (never this function) is what ultimately decides CONNECTIONS_REMAIN vs FAIL.
    """
    guard_schema(schema)
    while True:
        sessions = referencing_sessions(schema)  # VerificationError propagates: never read as "zero"
        if not sessions:
            if telemetry.connections_zero_at is None:
                telemetry.connections_zero_at = _now()
            return
        telemetry.blocking_sessions_seen.append({"at": _now(), "sessions": sessions})
        kill_orphan_sessions(schema, sessions)
        if time.monotonic() >= deadline:
            return  # Deadline reached with sessions still present: do NOT fabricate zero.
        time.sleep(0.3)


def drop_database_verified(schema: str, telemetry: RunTelemetry, out_dir: Optional[Path] = None,
                            deadline: Optional[float] = None) -> bool:
    """DROP DATABASE + VERIFY DATABASE ABSENT, honoring the shared cleanup deadline.

    Does not kill its own DROP-issuing client on a short per-attempt timeout: we
    proved the server keeps executing DROP regardless of the client, so killing the
    client only destroys our ability to observe the real outcome. Instead this lets
    each DROP attempt run up to the *remaining* cleanup budget, and separately
    verifies absence via information_schema.schemata -- the authoritative signal.

    `deadline` defaults to a fresh CLEANUP_TIMEOUT_SECONDS-wide budget when omitted,
    so this remains independently callable/testable (as test_cleanup.py and the
    out-of-scope audit probes already do); cleanup_run() always passes the one
    shared deadline it computed at cleanup_started_at (M1.2.2 section 9).

    H1 fix: schema_exists() now raises VerificationError instead of ever returning a
    false "absent", and database_dropped_at is stamped ONLY on a confirmed-absent
    result -- never merely because the retry loop's deadline was reached (section 11).
    """
    guard_schema(schema)
    if deadline is None:
        deadline = time.monotonic() + CLEANUP_TIMEOUT_SECONDS
    telemetry.drop_started_at = telemetry.drop_started_at or _now()
    attempt = 0
    while time.monotonic() < deadline:
        attempt += 1
        telemetry.drop_attempts = attempt
        remaining = max(5.0, deadline - time.monotonic())
        try:
            _mysql(f"DROP DATABASE IF EXISTS `{schema}`", timeout=remaining)
        except subprocess.TimeoutExpired:
            # Our *client* gave up at the budget edge; the server may still be
            # working. Fall through to the absence check below rather than assuming
            # failure -- and rather than retrying, which could race a still-running
            # first attempt.
            pass
        if not schema_exists(schema):  # may raise VerificationError; must propagate, never swallowed here
            telemetry.database_dropped_at = _now()
            return True
        if out_dir is not None:
            snap = diagnostics_snapshot(schema)
            telemetry.diagnostics.append({"at": _now(), "attempt": attempt, **snap})
            telemetry.metadata_locks_seen += snap["metadata_locks"].count("\n")
        time.sleep(DROP_ATTEMPT_POLL_SECONDS)
    # Deadline reached inside this function's own loop: one last honest check. Do NOT
    # stamp database_dropped_at unless it is actually true this time (section 11).
    if not schema_exists(schema):
        telemetry.database_dropped_at = _now()
        return True
    return False


def rescue_cleanup(schema: str, telemetry: RunTelemetry, candidate_barriers: Optional[list] = None) -> bool:
    """Bounded best-effort sweep after a genuine CLEANUP_FAIL. Never flips the run's
    recorded cleanup_result back to PASS -- see section 9/11/14 of the remediation
    briefs: this exists so the environment ends up clean, not to launder a failed run.

    Rescue is explicitly allowed to tolerate its own verification failures (it is a
    last-resort sweep, not a gate): a query/stat error here just means "assume not yet
    confirmed clean, keep trying within the rescue budget", and RESCUE_VERIFIED is
    only ever returned once a real, successful check confirms every candidate is gone.
    """
    guard_schema(schema)
    telemetry.rescue_used = True
    candidate_barriers = list(candidate_barriers or [])
    deadline = time.monotonic() + RESCUE_TIMEOUT_SECONDS

    def _schema_gone() -> bool:
        try:
            return not schema_exists(schema)
        except VerificationError:
            return False

    def _barriers_gone() -> bool:
        for p in candidate_barriers:
            try:
                if p.exists():
                    return False
            except OSError:
                return False
        return True

    while time.monotonic() < deadline:
        try:
            sessions = referencing_sessions(schema)
        except VerificationError:
            sessions = []  # best-effort: do not let an observability failure block the sweep
        if sessions:
            kill_orphan_sessions(schema, sessions)
        for p in candidate_barriers:
            try:
                if p.exists():
                    shutil.rmtree(p, ignore_errors=True)
            except OSError:
                pass
        try:
            _mysql(f"DROP DATABASE IF EXISTS `{schema}`", timeout=max(5.0, deadline - time.monotonic()))
        except subprocess.TimeoutExpired:
            pass
        if _schema_gone() and _barriers_gone():
            return True
        time.sleep(DROP_ATTEMPT_POLL_SECONDS)
    return _schema_gone() and _barriers_gone()


def cleanup_run(run_id: str, schema: str, domain_exit: int, domain_result: str,
                 out_dir: Optional[Path] = None, candidate_barriers: Optional[list] = None) -> RunTelemetry:
    """The full per-run finalization order from section 3, as a verified state machine.

    DOMAIN TEST FINISHED (caller already reaped the process before calling this)
      -> drain sessions, drop+verify database, verify barrier absence -- all under
         ONE shared cleanup_deadline (M1.2.2 section 9; no phase gets its own reset
         budget)
      -> a single FINAL, fresh, all-resource re-check is the only thing allowed to
         grant CLEANUP_VERIFIED (section 12/13): database ABSENT, connections ABSENT,
         barriers ABSENT, simultaneously, with zero UNKNOWNs, and the shared deadline
         not yet exceeded -- never inferred from stale per-phase timestamps alone
      -> CLEANUP_VERIFIED -> DONE, or CLEANUP_FAILED -> RESCUE_CLEANUP -> RESCUE_VERIFIED

    `candidate_barriers` (H2 fix): paths the caller observed appear during this run's
    own process window (see run_suite.run_one()'s before/after barrier_dirs() diff)
    that must be verified absent before FILES_REMOVED/CLEANUP_VERIFIED can be claimed.
    Defaults to an empty list for callers (including every existing unit-test scenario
    and the out-of-scope audit probes) that don't pass one -- which correctly means
    "nothing to verify" rather than "assume clean", since an empty candidate list is
    only ever produced by an honest before/after diff, not by skipping the check.
    """
    guard_schema(schema)
    t0 = time.monotonic()
    telemetry = RunTelemetry(run_id=run_id, schema=schema, domain_exit=domain_exit, domain_result=domain_result)
    telemetry.domain_finished_at = _now()
    telemetry.state = "DOMAIN_DONE"
    telemetry.cleanup_started_at = _now()
    telemetry.candidate_barriers = [str(p) for p in (candidate_barriers or [])]
    cleanup_deadline = time.monotonic() + CLEANUP_TIMEOUT_SECONDS
    candidate_barriers = list(candidate_barriers or [])

    all_absent = False
    verification_error = None
    try:
        drain_connections(schema, telemetry, cleanup_deadline)
        telemetry.state = "CONNECTIONS_DRAINED" if telemetry.connections_zero_at else "CONNECTIONS_REMAIN"

        dropped = drop_database_verified(schema, telemetry, out_dir=out_dir, deadline=cleanup_deadline)
        telemetry.state = "DATABASE_DROPPED" if dropped else "CLEANUP_FAILED"

        # Single final gate (section 12/13): fresh, all-resource, right now -- never
        # trusted from earlier per-phase timestamps, which only exist for observability.
        sessions_now = referencing_sessions(schema)
        schema_gone = not schema_exists(schema)
        present_barriers = barriers_present(candidate_barriers)

        telemetry.final_database_state = ResourceState.ABSENT if schema_gone else ResourceState.PRESENT
        telemetry.final_connections_state = ResourceState.ABSENT if not sessions_now else ResourceState.PRESENT
        telemetry.final_barriers_state = ResourceState.ABSENT if not present_barriers else ResourceState.PRESENT

        if schema_gone and telemetry.database_dropped_at is None:
            telemetry.database_dropped_at = _now()
        if not sessions_now and telemetry.connections_zero_at is None:
            telemetry.connections_zero_at = _now()
        if not present_barriers and telemetry.files_removed_at is None:
            telemetry.files_removed_at = _now()

        all_absent = schema_gone and (not sessions_now) and (not present_barriers)
    except VerificationError as exc:
        verification_error = str(exc)
        telemetry.state = "CLEANUP_FAILED"

    within_sla = time.monotonic() <= cleanup_deadline
    telemetry.sla_exceeded = not within_sla
    telemetry.verification_error = verification_error

    if all_absent and within_sla and verification_error is None:
        telemetry.cleanup_verified_at = _now()
        telemetry.state = "CLEANUP_VERIFIED"
        telemetry.cleanup_result = "PASS"
        telemetry.state = "DONE"
    else:
        telemetry.state = "CLEANUP_FAILED"
        telemetry.cleanup_result = "CLEANUP_FAIL"
        rescued = rescue_cleanup(schema, telemetry, candidate_barriers=candidate_barriers)
        telemetry.state = "RESCUE_VERIFIED" if rescued else "RESCUE_FAILED"
        # cleanup_result stays CLEANUP_FAIL regardless of rescue outcome (section 9/11/14).

    telemetry.cleanup_duration_ms = int((time.monotonic() - t0) * 1000)
    return telemetry
