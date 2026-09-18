"""P0.3.4-M1.2.1/M1.2.2/M1.2.3 -- deterministic per-run cleanup for disposable
Wave 3/4 test schemas.

Root cause (see docs/reviews/P0.3.4_M1_2_1_cleanup_remediation.md for full evidence):
DROP DATABASE on a ~127-table/255-FK/81-CHECK schema goes through InnoDB atomic DDL,
whose final "waiting for handler commit" phase shares the server's redo log / group
commit pipeline with every other connection. This module treats "cleanup" as a
verified state machine with its own SLA, its own connection/session hygiene pass,
and honest bounded retries -- never converting a real timeout into a fake PASS.

M1.2.2 hardening (docs/reviews/P0.3.4_M1_2_2_cleanup_verification_remediation.md):
the independent re-audit P0.3.4-M1.2.1-R reproduced four false-PASS classes (H1/H2/
M1/M2). Fixed by never reading a verification FAILURE as ABSENT (`VerificationError`
is the explicit UNKNOWN outcome), a single shared cleanup SLA, and real barrier
diffing. schema_exists()/referencing_sessions() keep their existing boolean/list
return contracts (several out-of-scope audit probe scripts in
scripts/audit-wave4-m121r/ and scripts/audit-wave4-m122r/ call them in boolean
context -- changing those would silently break that truthiness logic).

M1.2.3 hardening (docs/reviews/P0.3.4_M1_2_3_cleanup_ownership_remediation.md): the
independent re-audit P0.3.4-M1.2.2-R found two residual MEDIUM findings in the M1.2.2
fix itself:
  M122R-01 - a raw OSError (not just a non-zero exit or subprocess.TimeoutExpired)
             at three _mysql()-adjacent call sites -- DROP issuance, session KILL,
             and diagnostics_snapshot() (which runs on every real qualification run,
             not just a test corner case) -- was never caught, so it propagated
             uncaught out of cleanup_run() and crashed the whole harness instead of
             reporting CLEANUP_FAIL; for the first two sites this left the schema
             orphaned with zero rescue attempt, because the crash happened before
             the exception handler that triggers rescue_cleanup() was ever reached.
  M122R-02 - barrier "ownership" was inferred purely from before/after directory-
             listing timing (run_suite.py snapshotting tempfile.gettempdir() before
             and after each PHPUnit process), never from identity. Confirmed
             empirically (not just theoretical) in 6/40 real parallel-battery runs
             across two independent qualification passes: a directory legitimately
             appeared as a "candidate" for a run that did not create it. Every
             observed case self-resolved within one poll of the primary path before
             ever reaching rescue -- but rescue_cleanup()'s unconditional
             shutil.rmtree() on a surviving candidate had no ownership check, so a
             genuinely slower sibling's still-active barrier could in principle be
             deleted out from under it.

The M1.2.3 fix has two independent pieces:

(1) Single failure boundary (section 3-6 of the remediation brief): every command
    this module runs (subprocess-based MySQL calls) goes through ONE centralized
    wrapper, `mysql_exec()`, which normalizes subprocess.TimeoutExpired, OSError,
    and a non-zero client exit into `CleanupInfrastructureError` -- a new, explicit
    "the infrastructure itself failed" exception, distinct from `VerificationError`
    ("a resource's state could not be positively determined", still raised by the
    higher-level schema_exists()/referencing_sessions()/barriers_present() functions,
    which now internally catch CleanupInfrastructureError from mysql_exec() and
    re-raise it as VerificationError to preserve their existing external contract).
    cleanup_run()'s top-level boundary now catches both exception types identically
    (mark CLEANUP_FAIL, run bounded rescue), and a THIRD, broader handler catches any
    genuinely unexpected exception, attempts a defensive best-effort rescue so no
    resource is silently abandoned, and then re-raises -- an unmodeled programming
    error must surface loudly, never be swallowed into a routine CLEANUP_FAIL.

(2) Ownership by identity, not by timing (section 7-15): every run now gets its own
    root directory, created and known BEFORE the domain process even starts:
    `<tempdir>/wave4-m121/<run_id>/`, containing a `.owner.json` marker
    (run_id + database_name + created_at). This is a resource this module fully
    controls the creation of, unlike the PHP-side WaveFourWorkerHarness's own
    randomly-named barrier directory (touching WaveFourWorkerHarness.php remains out
    of scope -- see the M1.2.2 doc's H2 section for why that correlation gap cannot
    be closed without it). `verify_ownership()`/`safe_remove_run_root()` are the ONLY
    functions in this module permitted to shutil.rmtree anything, and they refuse
    unless the path resolves EXACTLY to the expected per-run root (never a prefix
    match -- immune to traversal and symlink-escape) AND its owner marker matches the
    caller's own run_id/schema. FILES_REMOVED is now authoritative over this owned
    root only (section 15); the legacy before/after barrier_dirs() diff is kept as
    pure diagnostic telemetry (section 14) and never gates FILES_REMOVED or PASS, and
    rescue_cleanup() no longer calls shutil.rmtree on any timing-inferred candidate
    at all -- it only ever removes its own verified-owned run root.

Every function in this module refuses to operate on anything but a schema matching
SCHEMA_RE (mepa_wave3_test_m121_<hex> / mepa_wave4_test_m121_<hex>), and separately,
explicitly, hard-refuses the literal name 'laravel' even if some future caller loosens
the regex. Schema names are never accepted from outside the process (always minted
here via secrets.token_hex()), per the no-arbitrary-external-name requirement.
"""
import json
import re
import secrets
import shutil
import subprocess
import tempfile
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

MYSQL = r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe"

# Every PHP test bootstrap's own required dbname prefix (Tests\Database\Support\*Case.php
# and the individual WaveOne/WaveTwo*Test.php self-contained bootstraps). This harness
# never invents a prefix the PHP side wouldn't already accept -- it mints names using
# exactly these, suffixed with _m121_<hex> so M1.2.x runs are trivially distinguishable
# from any other harness's evidence.
ENV_PREFIX = {
    "wave1": "WAVE1", "wave2": "WAVE2", "wave2f": "WAVE2F", "wave2m1": "WAVE2M1",
    "m1audit": "M1AUDIT", "m1safety": "M1SAFETY", "wave3": "WAVE3", "wave4": "WAVE4",
}
SCHEMA_RE = re.compile(r"^mepa_(wave1|wave2|wave2f|wave2m1|m1audit|m1safety|wave3|wave4)_test_m121_([0-9a-f]{24})$")
RUN_ID_RE = re.compile(r"^[0-9a-f]{24}$")
FORBIDDEN_SCHEMAS = {"laravel", "mysql", "information_schema", "performance_schema", "sys"}

# Two distinct budgets (section 10 of M1.2.1): the functional test has its own
# generous timeout elsewhere; this is ONLY the cleanup budget. Per M1.2.2 section 9,
# this is the ONE budget covering the entire cleanup lifecycle (drain, drop+poll,
# ownership verification, final check) -- computed once, at cleanup_started_at, and
# never reset per phase.
CLEANUP_TIMEOUT_SECONDS = 150
DROP_ATTEMPT_POLL_SECONDS = 2.0
RESCUE_TIMEOUT_SECONDS = 90

# Kept for any external caller that still imports this name; no longer used to bound a
# separate early sub-phase (M1.2.2 unifies drain+drop+verify under one deadline).
CONNECTION_DRAIN_TIMEOUT_SECONDS = 20

# M1.2.3: the base directory under which every run gets its own identity-owned root
# (section 7). Never itself a valid deletion target -- see safe_remove_run_root().
RUN_ROOT_BASE = Path(tempfile.gettempdir()) / "wave4-m121"
OWNER_MARKER_NAME = ".owner.json"
HARNESS_VERSION = "M1.2.3"


class CleanupInfrastructureError(RuntimeError):
    """The underlying command/filesystem layer itself could not complete: a
    subprocess could not be launched or timed out (OSError / subprocess.
    TimeoutExpired), or a MySQL client invocation exited non-zero. Raised ONLY by
    the centralized low-level boundaries in this module (mysql_exec(), the run-root
    filesystem helpers) -- never scattered ad hoc per call site (M1.2.3 section 4).
    """


class VerificationError(RuntimeError):
    """Raised whenever this module cannot positively determine a resource's state
    (infrastructure failure while checking, or an ambiguous/ownership-mismatched
    result). This is the explicit UNKNOWN outcome required by the M1.2.2 remediation
    (section 2/3): PRESENT and ABSENT are only ever returned when the underlying
    check actually succeeded. Every raise site in this module is a place where the
    old code used to let a failed check silently read as "resource absent" --
    callers MUST NOT catch this and treat it as ABSENT; the only correct response is
    to fail the run. Higher-level verification functions (schema_exists(),
    referencing_sessions(), barriers_present()) catch CleanupInfrastructureError from
    the lower-level command boundary and re-raise it as VerificationError, so their
    external (boolean/list-returning) contract is unchanged from M1.2.2.
    """


class ResourceState:
    """Explicit tri-state result recorded on RunTelemetry for observability (section 2
    of M1.2.2). Control flow uses the exception types above rather than this enum's
    members directly, so that a caller can never forget to check for UNKNOWN and
    accidentally treat a bare boolean/list result as authoritative -- but every
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


def run_id_of(schema: str) -> str:
    """The canonical run identity embedded in a schema name (section 10): the
    24-hex suffix minted by new_schema(). Centralizes what used to be an ad hoc
    `schema.rsplit("_", 1)[-1]` duplicated across callers.
    """
    guard_schema(schema)
    return SCHEMA_RE.match(schema).group(2)


def validate_database_ownership(schema: str, expected_run_id: str) -> None:
    """Hard guard (section 10): the schema this call is about to act on must belong
    to exactly the run_id the caller believes it is operating as. Guards against a
    run_id/schema pair being mixed up (e.g. by a caller bug) and one run's cleanup
    logic being pointed at a different run's database.
    """
    guard_schema(schema)
    if not RUN_ID_RE.match(expected_run_id):
        raise RuntimeError(f"ABORT HARD: invalid run_id {expected_run_id!r}")
    actual = run_id_of(schema)
    if actual != expected_run_id:
        raise RuntimeError(
            f"ABORT HARD: schema {schema!r} belongs to run {actual!r}, not the expected run {expected_run_id!r}"
        )


def _mysql(statement: str, timeout: float = 30) -> subprocess.CompletedProcess:
    """Raw subprocess boundary. Kept exactly as-is (return type, exceptions) for
    backward compatibility: multiple out-of-scope audit scripts
    (scripts/audit-wave4-m121r/, scripts/audit-wave4-m122r/) call this directly and
    expect a CompletedProcess back. In-module code should use mysql_exec() instead
    (section 4) -- this function is the one and only place subprocess.run() is
    invoked for a MySQL command, which mysql_exec() wraps.
    """
    return subprocess.run(
        [MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306", "--batch", "--silent", "--execute=" + statement],
        capture_output=True, text=True, timeout=timeout,
    )


def mysql_exec(statement: str, timeout: float = 30, tolerate_timeout: bool = False) -> Optional[str]:
    """The single centralized boundary (M1.2.3 section 4) through which every
    in-module MySQL command is executed. Normalizes subprocess.TimeoutExpired,
    OSError, and a non-zero client exit into CleanupInfrastructureError -- the ONLY
    exception type this function can raise. Returns stdout on success.

    `tolerate_timeout` exists for exactly one legitimate case (the DROP DATABASE
    issuance in drop_database_verified()): M1.2.1 proved live that the MySQL server
    keeps executing DROP after the issuing client times out, so treating that
    specific timeout as fatal would reintroduce the original false-FAIL this whole
    harness exists to prevent. When set, a timeout returns None instead of raising,
    and the caller is responsible for independently verifying the real outcome
    (drop_database_verified() always does, via schema_exists()). This is the one
    documented, explicit deviation the module allows -- never a silent, scattered
    per-call-site difference (section 4's "não espalhar tratamento diferente").
    """
    try:
        r = _mysql(statement, timeout=timeout)
    except subprocess.TimeoutExpired as exc:
        if tolerate_timeout:
            return None
        raise CleanupInfrastructureError(f"mysql_exec: timed out after {timeout}s: {exc}") from exc
    except OSError as exc:
        raise CleanupInfrastructureError(f"mysql_exec: could not invoke mysql client: {exc}") from exc
    if r.returncode != 0:
        raise CleanupInfrastructureError(f"mysql_exec: client exited rc={r.returncode}: {r.stderr.strip()}")
    return r.stdout


def schema_exists(schema: str) -> bool:
    """PRESENT -> True, ABSENT -> False, UNKNOWN -> raises VerificationError.

    H1 fix (M1.2.2): a query that fails is NEVER interpreted as "schema not found".
    M1.2.3: routed through the centralized mysql_exec() boundary; any
    CleanupInfrastructureError it raises is re-wrapped as VerificationError so this
    function's external raise-type contract (relied on by existing tests and
    out-of-scope audit scripts) is unchanged.
    """
    guard_schema(schema)
    try:
        stdout = mysql_exec(f"SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = '{schema}'", timeout=15)
    except CleanupInfrastructureError as exc:
        raise VerificationError(f"schema_exists({schema}): {exc}") from exc
    return schema in stdout


def referencing_sessions(schema: str) -> list[dict]:
    """Every thread that could plausibly still be touching this schema.

    Deliberately broader than `processlist.db = schema`: the live reproduction in
    the M1.2.1 remediation showed a metadata-lock row against the schema's own
    tables from a thread that did NOT show up under db=schema filtering. So this
    unions two independent signals: direct db context, and
    performance_schema.metadata_locks joined back to processlist for GRANTED locks
    on this schema's objects.

    M2 fix (M1.2.2): a failed query raises VerificationError instead of silently
    returning [] (which used to read identically to "zero sessions, safe to
    proceed"). M1.2.3: routed through mysql_exec(), re-wrapped the same way as
    schema_exists().
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
        stdout = mysql_exec(q, timeout=15)
    except CleanupInfrastructureError as exc:
        raise VerificationError(f"referencing_sessions({schema}): {exc}") from exc
    rows = []
    for line in stdout.splitlines():
        if not line.strip():
            continue
        parts = line.split("\t")
        if len(parts) >= 6 and parts[0].isdigit():
            rows.append({"id": int(parts[0]), "user": parts[1], "host": parts[2],
                          "command": parts[3], "time": parts[4], "state": parts[5]})
    return rows


def barriers_present(paths: list[Path]) -> list[Path]:
    """The subset of `paths` that still exist. Diagnostic only as of M1.2.3 (section
    14) -- kept for observability/telemetry, but its result no longer gates
    FILES_REMOVED or PASS (see cleanup_run()'s docstring). A filesystem stat error
    is UNKNOWN, never ABSENT -- it raises rather than being swallowed.
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


# --------------------------------------------------------------------------------
# M1.2.3 ownership: per-run root directory + owner marker (sections 7-9).
# --------------------------------------------------------------------------------

def run_root_path(run_id: str) -> Path:
    """The one, deterministic, expected root path for a given run_id. Every
    ownership check re-derives this from run_id rather than trusting a caller-
    supplied path, so a traversal/mismatch attempt has nothing to exploit.
    """
    if not RUN_ID_RE.match(run_id):
        raise RuntimeError(f"ABORT HARD: invalid run_id {run_id!r}")
    return RUN_ROOT_BASE / run_id


def create_run_root(run_id: str, schema: str) -> Path:
    """Create this run's own root + owner marker BEFORE the domain process starts
    (section 7 -- "a run conhece o caminho antes de iniciar", never discovers it
    later via timing). Idempotent-safe: if the directory already exists (should
    never happen given run_id's 96 bits of randomness, but guarded regardless),
    the marker is (re)written rather than erroring on a stale leftover.
    """
    validate_database_ownership(schema, run_id)
    root = run_root_path(run_id)
    try:
        root.mkdir(parents=True, exist_ok=True)
        marker = {
            "run_id": run_id, "database_name": schema,
            "created_at": _now(), "harness_version": HARNESS_VERSION,
        }
        (root / OWNER_MARKER_NAME).write_text(json.dumps(marker), encoding="utf-8")
    except OSError as exc:
        raise CleanupInfrastructureError(f"create_run_root({run_id}): {exc}") from exc
    return root


def _read_owner_marker(root: Path) -> Optional[dict]:
    """None means "no marker present" (a legitimate, expected state for a root this
    module never created, or one already cleaned up) -- never treated as an error.
    A read/parse failure on a marker that DOES exist is a genuine infrastructure
    problem and raises.
    """
    marker_path = root / OWNER_MARKER_NAME
    try:
        if not marker_path.exists():
            return None
        raw = marker_path.read_text(encoding="utf-8")
    except OSError as exc:
        raise CleanupInfrastructureError(f"_read_owner_marker({root}): {exc}") from exc
    try:
        return json.loads(raw)
    except json.JSONDecodeError as exc:
        raise CleanupInfrastructureError(f"_read_owner_marker({root}): corrupt marker: {exc}") from exc


def verify_ownership(root: Path, run_id: str, schema: str) -> bool:
    """The sole authority (section 8/9) for whether `root` may be treated as this
    run's own, deletable resource. Returns True only if ALL of:
      - `root`, canonicalized, resolves to EXACTLY the expected per-run path (never
        a prefix/ancestor match -- this alone rejects RUN_ROOT_BASE itself, the
        system temp root, the repository root, a drive root, any traversal
        sequence, and any symlink that resolves elsewhere);
      - a owner marker exists inside it;
      - the marker's run_id and database_name exactly match the caller's own.
    A missing marker or a path/identity mismatch is a normal "not owned" result
    (returns False), not an error. A genuine OS-level failure while resolving/
    reading raises CleanupInfrastructureError (propagates -- never silently read as
    "not owned" or "owned", section 2's tri-state principle applied to ownership).
    """
    validate_database_ownership(schema, run_id)
    expected = run_root_path(run_id)
    try:
        resolved = root.resolve(strict=False)
        expected_resolved = expected.resolve(strict=False)
    except OSError as exc:
        raise CleanupInfrastructureError(f"verify_ownership: path resolution failed: {exc}") from exc
    if resolved != expected_resolved:
        return False
    marker = _read_owner_marker(root)
    if marker is None:
        return False
    return marker.get("run_id") == run_id and marker.get("database_name") == schema


def safe_remove_run_root(root: Path, run_id: str, schema: str) -> bool:
    """The ONLY function in this module permitted to shutil.rmtree a run root.
    Refuses (raises RuntimeError, a hard guard failure, not a soft False) unless
    verify_ownership() passes. Returns True once the root is confirmed absent
    (either it never existed, or removal succeeded and was verified).
    """
    if not root.exists():
        return True
    if not verify_ownership(root, run_id, schema):
        raise RuntimeError(f"ABORT HARD: refusing to remove {root} -- ownership verification failed")
    try:
        shutil.rmtree(root)
    except OSError as exc:
        raise CleanupInfrastructureError(f"safe_remove_run_root({root}): {exc}") from exc
    return not root.exists()


def diagnostics_snapshot(schema: str) -> dict:
    """Best-effort observability only -- never used to gate PASS/FAIL, so a failure
    on any individual query is tolerated and annotated rather than raised. M1.2.3
    fix (M122R-01): every query here used to share one unguarded _mysql() call
    each; a subprocess-level OSError/timeout on any of them used to propagate
    uncaught and crash the entire harness (this function runs on every real
    qualification run, since run_suite.py always passes out_dir). Each query is now
    independently guarded so a single infrastructure hiccup here degrades this
    snapshot, never the whole run.
    """
    guard_schema(schema)
    out = {}
    for key, statement in (
        ("processlist", f"SELECT id, user, host, db, command, time, state, LEFT(info,200) info "
                         f"FROM information_schema.processlist WHERE db = '{schema}'"),
        ("metadata_locks", f"SELECT OBJECT_TYPE, OBJECT_NAME, LOCK_TYPE, LOCK_DURATION, LOCK_STATUS, OWNER_THREAD_ID "
                            f"FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA = '{schema}'"),
        ("innodb_trx", f"SELECT t.trx_id, t.trx_state, t.trx_started, LEFT(t.trx_query,150) trx_query "
                       f"FROM information_schema.innodb_trx t "
                       f"JOIN information_schema.processlist p ON p.id = t.trx_mysql_thread_id WHERE p.db = '{schema}'"),
        ("data_lock_waits", "SELECT requesting_thread_id, blocking_thread_id FROM performance_schema.data_lock_waits"),
    ):
        try:
            out[key] = mysql_exec(statement, timeout=15)
        except CleanupInfrastructureError as exc:
            out[key] = ""
            out[f"{key}_error"] = str(exc)
    return out


def kill_orphan_sessions(schema: str, sessions: list[dict]) -> list[dict]:
    """Kill sessions this run itself owns, identified solely by exact schema-name
    uniqueness (never anything not already scoped to `schema`). Never touches a
    session whose only evidence is a generic host/user match -- only rows returned
    by referencing_sessions(schema), which are schema-scoped by construction.

    M1.2.3 fix (M122R-01): a subprocess-level OSError/timeout on the KILL command
    itself now raises CleanupInfrastructureError instead of propagating an
    unguarded raw exception. A KILL that runs but reports failure because the
    thread already disconnected on its own (an ordinary, harmless race -- the
    thread ID is simply gone by the time KILL reaches it) is deliberately NOT
    treated as fatal here, same as before M1.2.3: only genuine infrastructure
    failures (couldn't even talk to the client) are.
    """
    guard_schema(schema)
    killed = []
    for s in sessions:
        try:
            r = _mysql(f"KILL {int(s['id'])}", timeout=10)
        except subprocess.TimeoutExpired as exc:
            raise CleanupInfrastructureError(f"kill_orphan_sessions: KILL {s['id']} timed out: {exc}") from exc
        except OSError as exc:
            raise CleanupInfrastructureError(f"kill_orphan_sessions: could not invoke mysql client for KILL {s['id']}: {exc}") from exc
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
    # M1.2.3: the run's own identity-owned root, if one was provided (section 7-9).
    run_root: Optional[str] = None
    # M1.2.3 section 14: the legacy timing-based candidate diff, kept for
    # observability only -- never consulted for FILES_REMOVED/PASS.
    candidate_barriers_present_diagnostic: list = field(default_factory=list)


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
    client only destroys our ability to observe the real outcome -- see
    mysql_exec()'s `tolerate_timeout` docstring. Instead this lets each DROP attempt
    run up to the *remaining* cleanup budget, and separately verifies absence via
    information_schema.schemata -- the authoritative signal.

    `deadline` defaults to a fresh CLEANUP_TIMEOUT_SECONDS-wide budget when omitted,
    so this remains independently callable/testable; cleanup_run() always passes the
    one shared deadline it computed at cleanup_started_at (M1.2.2 section 9).

    H1 fix: schema_exists() raises VerificationError instead of ever returning a
    false "absent", and database_dropped_at is stamped ONLY on a confirmed-absent
    result. M1.2.3 fix (M122R-01): the DROP issuance itself now raises
    CleanupInfrastructureError on a genuine OSError (client could not even be
    invoked), not just tolerating a timeout as before -- an infra failure here can
    no longer silently be treated as "still present, keep polling".
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
        mysql_exec(f"DROP DATABASE IF EXISTS `{schema}`", timeout=remaining, tolerate_timeout=True)
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


def rescue_cleanup(schema: str, telemetry: RunTelemetry, candidate_barriers: Optional[list] = None,
                    run_root: Optional[Path] = None) -> bool:
    """Bounded best-effort sweep after a genuine CLEANUP_FAIL. Never flips the run's
    recorded cleanup_result back to PASS -- see section 9/11/14 of the M1.2.1/M1.2.2
    remediation briefs: this exists so the environment ends up clean, not to launder
    a failed run.

    M1.2.3 fix (M122R-02): rescue no longer calls shutil.rmtree on anything from
    `candidate_barriers` (the timing-inferred, ownership-blind PHP barrier
    candidates) -- that capability is removed entirely, closing the cross-run
    deletion risk. Rescue receives EXACTLY the same ownership guard as the primary
    path (section 11: "rescue NÃO recebe permissão mais ampla"): it may only ever
    remove `run_root` via safe_remove_run_root(), which independently re-verifies
    ownership every time, the same as anywhere else in this module.

    Rescue is explicitly allowed to tolerate its own verification/infrastructure
    failures (it is a last-resort sweep, not a gate): a query/stat error here just
    means "assume not yet confirmed clean, keep trying within the rescue budget",
    and RESCUE_VERIFIED is only ever returned once a real, successful check confirms
    every owned resource is gone.
    """
    guard_schema(schema)
    telemetry.rescue_used = True
    run_id = telemetry.run_id
    deadline = time.monotonic() + RESCUE_TIMEOUT_SECONDS

    def _schema_gone() -> bool:
        try:
            return not schema_exists(schema)
        except (VerificationError, CleanupInfrastructureError):
            return False

    def _root_gone() -> bool:
        if run_root is None:
            return True
        try:
            if not run_root.exists():
                return True
            return safe_remove_run_root(run_root, run_id, schema)
        except (CleanupInfrastructureError, RuntimeError):
            return False

    while time.monotonic() < deadline:
        try:
            sessions = referencing_sessions(schema)
        except (VerificationError, CleanupInfrastructureError):
            # Defense in depth (M122R-01): referencing_sessions() is documented to
            # only ever raise VerificationError, but rescue is the last line of
            # defense against a resource being silently abandoned -- it must not
            # let ANY observability failure here escape uncaught, even a class it
            # doesn't strictly expect.
            sessions = []  # best-effort: do not let an observability failure block the sweep
        if sessions:
            try:
                kill_orphan_sessions(schema, sessions)
            except CleanupInfrastructureError:
                pass  # best-effort: try again next iteration
        try:
            mysql_exec(f"DROP DATABASE IF EXISTS `{schema}`", timeout=max(5.0, deadline - time.monotonic()),
                       tolerate_timeout=True)
        except CleanupInfrastructureError:
            pass  # best-effort: try again next iteration
        if _schema_gone() and _root_gone():
            return True
        time.sleep(DROP_ATTEMPT_POLL_SECONDS)
    return _schema_gone() and _root_gone()


def _run_primary_cleanup(schema: str, telemetry: RunTelemetry, cleanup_deadline: float,
                          out_dir: Optional[Path], candidate_barriers: list, run_root: Optional[Path]) -> bool:
    """The full primary-path sequence (section 3 of M1.2.1, section 12/13 of M1.2.2,
    section 15/16 of M1.2.3). Returns True only if database, connections, AND the
    owned run root (if any) are all confirmed absent by a single, fresh, final check.
    Any CleanupInfrastructureError/VerificationError raised anywhere in this
    sequence propagates to the caller (cleanup_run()), which is the only place that
    decides what to do about it.
    """
    drain_connections(schema, telemetry, cleanup_deadline)
    telemetry.state = "CONNECTIONS_DRAINED" if telemetry.connections_zero_at else "CONNECTIONS_REMAIN"

    dropped = drop_database_verified(schema, telemetry, out_dir=out_dir, deadline=cleanup_deadline)
    telemetry.state = "DATABASE_DROPPED" if dropped else "CLEANUP_FAILED"

    # Single final gate (section 12/13 of M1.2.2): fresh, all-resource, right now --
    # never trusted from earlier per-phase timestamps, which only exist for
    # observability.
    sessions_now = referencing_sessions(schema)
    schema_gone = not schema_exists(schema)

    # M1.2.3 section 15: FILES_REMOVED authority is the identity-owned run root,
    # verified by ownership -- NOT the timing-derived candidate_barriers diff, which
    # remains diagnostic-only (section 14) and never gates PASS/FAIL. Sequence is
    # literal: known root -> ownership validated -> removal executed -> absence
    # verified. safe_remove_run_root() re-verifies ownership itself before removing
    # (never trusts a prior check), and re-confirms absence via .exists() afterward.
    root_gone = True
    if run_root is not None:
        root_gone = safe_remove_run_root(run_root, telemetry.run_id, schema)

    # Fully diagnostic (section 14): even a verification error while collecting
    # this legacy signal must not affect PASS/FAIL, any more than its outcome does.
    try:
        present_barriers = barriers_present(candidate_barriers)
        telemetry.candidate_barriers_present_diagnostic = [str(p) for p in present_barriers]
    except VerificationError as exc:
        telemetry.candidate_barriers_present_diagnostic = [f"<diagnostic unavailable: {exc}>"]

    telemetry.final_database_state = ResourceState.ABSENT if schema_gone else ResourceState.PRESENT
    telemetry.final_connections_state = ResourceState.ABSENT if not sessions_now else ResourceState.PRESENT
    telemetry.final_barriers_state = ResourceState.ABSENT if root_gone else ResourceState.PRESENT

    if schema_gone and telemetry.database_dropped_at is None:
        telemetry.database_dropped_at = _now()
    if not sessions_now and telemetry.connections_zero_at is None:
        telemetry.connections_zero_at = _now()
    if root_gone and telemetry.files_removed_at is None:
        telemetry.files_removed_at = _now()

    return schema_gone and (not sessions_now) and root_gone


def cleanup_run(run_id: str, schema: str, domain_exit: int, domain_result: str,
                 out_dir: Optional[Path] = None, candidate_barriers: Optional[list] = None,
                 run_root: Optional[Path] = None) -> RunTelemetry:
    """The full per-run finalization order, as a verified state machine.

    DOMAIN TEST FINISHED (caller already reaped the process before calling this)
      -> drain sessions, drop+verify database, verify run-root absence -- all under
         ONE shared cleanup_deadline (M1.2.2 section 9; no phase gets its own reset
         budget)
      -> a single FINAL, fresh, all-resource re-check is the only thing allowed to
         grant CLEANUP_VERIFIED (section 12/13 of M1.2.2): database ABSENT,
         connections ABSENT, owned run root ABSENT, simultaneously, with zero
         UNKNOWNs, and the shared deadline not yet exceeded
      -> CLEANUP_VERIFIED -> DONE, or CLEANUP_FAILED -> RESCUE_CLEANUP -> RESCUE_VERIFIED

    M1.2.3 failure boundary (section 3-6): the primary sequence above runs inside a
    single try/except with three outcomes, matching the remediation brief's literal
    contract --
      except CleanupInfrastructureError / except VerificationError: both are known,
        modeled failure classes; both mark CLEANUP_FAIL, are recorded in
        telemetry.verification_error, and trigger a bounded rescue attempt exactly
        like any other CLEANUP_FAIL.
      except Exception: a genuinely UNEXPECTED programming error. Never swallowed
        into a routine CLEANUP_FAIL result -- a defensive, best-effort rescue is
        still attempted first (so no resource is silently abandoned even when the
        bug is in this module itself), then the original exception is re-raised so
        it surfaces loudly, as a bug report demands, not a quiet FAIL entry.
    cleanup_run() itself therefore never raises CleanupInfrastructureError or
    VerificationError to ITS OWN caller -- those are always fully absorbed into a
    structured RunTelemetry result. Only a genuinely unmodeled exception escapes.

    `run_root` (M1.2.3 section 7-9): this run's own identity-owned directory, if the
    caller created one via create_run_root() before starting the domain process.
    `candidate_barriers` (H2, M1.2.2): paths observed to appear during this run's
    own process window via the legacy before/after diff -- kept for diagnostics only
    as of M1.2.3 (section 14), never consulted for FILES_REMOVED/PASS.
    """
    guard_schema(schema)
    if run_root is not None:
        validate_database_ownership(schema, run_id)
    t0 = time.monotonic()
    telemetry = RunTelemetry(run_id=run_id, schema=schema, domain_exit=domain_exit, domain_result=domain_result)
    telemetry.domain_finished_at = _now()
    telemetry.state = "DOMAIN_DONE"
    telemetry.cleanup_started_at = _now()
    telemetry.candidate_barriers = [str(p) for p in (candidate_barriers or [])]
    telemetry.run_root = str(run_root) if run_root is not None else None
    cleanup_deadline = time.monotonic() + CLEANUP_TIMEOUT_SECONDS
    candidate_barriers = list(candidate_barriers or [])

    all_absent = False
    verification_error = None
    try:
        all_absent = _run_primary_cleanup(schema, telemetry, cleanup_deadline, out_dir, candidate_barriers, run_root)
    except CleanupInfrastructureError as exc:
        verification_error = str(exc)
    except VerificationError as exc:
        verification_error = str(exc)
    except Exception:
        # Genuinely unexpected programming error (section 5): attempt a defensive,
        # fault-tolerant rescue so no resource is silently abandoned, then re-raise
        # -- this must surface as a bug, never be swallowed into a routine
        # CLEANUP_FAIL.
        try:
            rescue_cleanup(schema, telemetry, candidate_barriers=candidate_barriers, run_root=run_root)
        except Exception:
            pass
        telemetry.cleanup_duration_ms = int((time.monotonic() - t0) * 1000)
        raise

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
        rescued = rescue_cleanup(schema, telemetry, candidate_barriers=candidate_barriers, run_root=run_root)
        telemetry.state = "RESCUE_VERIFIED" if rescued else "RESCUE_FAILED"
        # cleanup_result stays CLEANUP_FAIL regardless of rescue outcome (section 9/11/14).

    telemetry.cleanup_duration_ms = int((time.monotonic() - t0) * 1000)
    return telemetry
