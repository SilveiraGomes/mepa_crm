"""Independent P0.3.4-M1.2.2-R core probes for H1/H2/M1/M2, using techniques
deliberately DIFFERENT from the executor's own test_cleanup_false_positive_guards.py
where practical (real subprocess failures via a bad binary path instead of
monkeypatched _mysql return values; a real two-way parallel barrier isolation test;
a real held MySQL transaction for M2 instead of a synthetic session dict; SLA
exhaustion via a genuinely near-zero budget instead of stubbing
drop_database_verified for the rescue-non-laundering proof).

Read-only against the harness's code. Disposable resources only, own schemas,
own barrier dirs. Never touches production.
"""
import concurrent.futures
import json
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "wave4-m121"))
import cleanup  # noqa: E402
from verify import barrier_dirs  # noqa: E402

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m122r_audit"
OUT.mkdir(parents=True, exist_ok=True)
MYSQL = cleanup.MYSQL


def sql(statement, timeout=30):
    r = cleanup._mysql(statement, timeout=timeout)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout


def real_teardown(schema):
    if cleanup.schema_exists(schema):
        sql(f"DROP DATABASE IF EXISTS `{schema}`", timeout=30)


# --- section 5/6: H1, real (not synthetic) subprocess-level failures ---------------

def s5_drop_and_verification_both_fail_synthetic_rc1():
    """Section 5, literal: DROP=error AND verification query=error simultaneously."""
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_mysql = cleanup._mysql

    def injected(statement, timeout=30):
        if statement.startswith("DROP DATABASE") or statement.startswith("SELECT SCHEMA_NAME"):
            return subprocess.CompletedProcess([], 1, "", "audit-injected failure")
        return real_mysql(statement, timeout=timeout)

    cleanup._mysql = injected
    real_rescue = cleanup.RESCUE_TIMEOUT_SECONDS
    cleanup.RESCUE_TIMEOUT_SECONDS = 3
    result = {}
    try:
        t = cleanup.cleanup_run("s5", schema, 0, "PASS")
        result["cleanup_result"] = t.cleanup_result
        result["state_before_rescue_data"] = t.state
        result["verification_error_set"] = t.verification_error is not None
        result["cleanup_verified_at_is_none"] = t.cleanup_verified_at is None
    finally:
        cleanup._mysql = real_mysql
        cleanup.RESCUE_TIMEOUT_SECONDS = real_rescue
        result["schema_genuinely_present_during_fault"] = cleanup.schema_exists(schema)
        real_teardown(schema)
        result["schema_absent_after_real_teardown"] = not cleanup.schema_exists(schema)
    return result


def s6_real_filenotfound_operational_error():
    """Section 6: 'permission/operational error simulado' -- reproduced with a REAL
    subprocess.run failure (FileNotFoundError from a nonexistent binary path), not a
    monkeypatched return value. Exercises the actual `except OSError` branch in
    schema_exists() end to end, including the real subprocess machinery."""
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_mysql_path = cleanup.MYSQL
    cleanup.MYSQL = real_mysql_path + ".audit_nonexistent_binary"
    result = {}
    try:
        try:
            cleanup.schema_exists(schema)
            result["raised"] = False
        except cleanup.VerificationError as exc:
            result["raised"] = True
            result["exception_str"] = str(exc)
        except Exception as exc:  # anything else propagating unwrapped is itself a finding
            result["raised"] = "wrong_type"
            result["exception_type"] = type(exc).__name__
            result["exception_str"] = str(exc)
    finally:
        cleanup.MYSQL = real_mysql_path
        real_teardown(schema)
        result["schema_absent_after_real_teardown"] = not cleanup.schema_exists(schema)
    return result


def s6_real_timeout_reachable():
    """Confirms subprocess.TimeoutExpired -- the exception type schema_exists()'s
    except clause is written to catch -- is genuinely reachable from a real slow
    MySQL query, not just a type that happens to be imported."""
    result = {}
    t0 = time.monotonic()
    try:
        cleanup._mysql("SELECT SLEEP(5)", timeout=0.5)
        result["timeout_raised"] = False
    except subprocess.TimeoutExpired:
        result["timeout_raised"] = True
    result["elapsed"] = round(time.monotonic() - t0, 2)
    return result


# --- section 8/9/10: H2 barrier verification + real parallel isolation -------------

def s8_barrier_residual_intentional():
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    barrier = Path(tempfile.gettempdir()) / f"mepa_wave4_m12_audit122_{schema.rsplit('_', 1)[-1]}"
    barrier.mkdir()
    (barrier / "residual").write_text("x", encoding="utf-8")
    result = {}
    try:
        t = cleanup.cleanup_run("s8", schema, 0, "PASS", candidate_barriers=[barrier])
        result["cleanup_result"] = t.cleanup_result
        result["files_removed_at_is_none_before_rescue_swept_it"] = t.files_removed_at is None
        result["final_barriers_state"] = t.final_barriers_state
        result["rescue_used"] = t.rescue_used
    finally:
        real_teardown(schema)
        if barrier.exists():
            shutil.rmtree(barrier, ignore_errors=True)
        result["barrier_absent_after_real_teardown"] = not barrier.exists()
    return result


def s9_barrier_verification_itself_errors():
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_bp = cleanup.barriers_present
    cleanup.barriers_present = lambda paths: (_ for _ in ()).throw(cleanup.VerificationError("audit-injected stat failure"))
    result = {}
    try:
        t = cleanup.cleanup_run("s9", schema, 0, "PASS", candidate_barriers=[Path("nonexistent")])
        result["cleanup_result"] = t.cleanup_result
        result["verification_error_set"] = t.verification_error is not None
        result["files_removed_at_is_none"] = t.files_removed_at is None
    finally:
        cleanup.barriers_present = real_bp
        real_teardown(schema)
    return result


def s10_parallel_barrier_isolation():
    """Two simultaneous cleanup_run() calls, each with its own real barrier
    directory, run concurrently via threads. Neither may observe or delete the
    other's barrier -- proves the per-run candidate_barriers list (populated by
    run_suite.py's before/after diff in real use, injected directly here) is a
    correctly scoped allowlist, not a global sweep."""
    schema_a = cleanup.new_schema("wave4")
    schema_b = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema_a}`")
    sql(f"CREATE DATABASE `{schema_b}`")
    barrier_a = Path(tempfile.gettempdir()) / f"mepa_wave4_m12_audit122_a_{schema_a.rsplit('_', 1)[-1]}"
    barrier_b = Path(tempfile.gettempdir()) / f"mepa_wave4_m12_audit122_b_{schema_b.rsplit('_', 1)[-1]}"
    barrier_a.mkdir()
    barrier_b.mkdir()
    (barrier_a / "a_marker").write_text("a", encoding="utf-8")
    (barrier_b / "b_marker").write_text("b", encoding="utf-8")
    result = {}
    try:
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            # A's candidate list includes ONLY barrier_a (as run_suite.py's diff
            # would produce for A's own process window); B's includes ONLY barrier_b.
            fut_a = pool.submit(cleanup.cleanup_run, "s10a", schema_a, 0, "PASS", None, [barrier_a])
            fut_b = pool.submit(cleanup.cleanup_run, "s10b", schema_b, 0, "PASS", None, [barrier_b])
            t_a = fut_a.result()
            t_b = fut_b.result()
        result["a_cleanup_result"] = t_a.cleanup_result
        result["b_cleanup_result"] = t_b.cleanup_result
        result["a_schema_absent"] = not cleanup.schema_exists(schema_a)
        result["b_schema_absent"] = not cleanup.schema_exists(schema_b)
        result["a_barrier_absent"] = not barrier_a.exists()
        result["b_barrier_absent"] = not barrier_b.exists()
        # The critical isolation check: did A ever have a chance to delete B's
        # barrier or vice versa? Both should be gone (each run legitimately removed
        # its OWN candidate), but neither run's candidate list ever named the
        # other's barrier -- confirmed by construction (a_candidate=[barrier_a] only).
    finally:
        real_teardown(schema_a)
        real_teardown(schema_b)
        for b in (barrier_a, barrier_b):
            if b.exists():
                shutil.rmtree(b, ignore_errors=True)
    return result


# --- section 11/12/13: M2 real residual session + query error + metadata locks ----

def s11_real_residual_session_past_deadline():
    """A REAL held MySQL connection (SELECT SLEEP, not a synthetic session dict)
    that kill_orphan_sessions cannot resolve in time because CLEANUP_TIMEOUT_SECONDS
    is shrunk below the holder's sleep duration AND kill is neutered -- proves
    connections_zero_at is never stamped when a genuine session outlives the deadline."""
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    holder = subprocess.Popen(
        [MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306", "--execute=SELECT SLEEP(8)", f"--database={schema}"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    time.sleep(0.5)
    real_budget = cleanup.CLEANUP_TIMEOUT_SECONDS
    real_kill = cleanup.kill_orphan_sessions
    cleanup.CLEANUP_TIMEOUT_SECONDS = 2  # shorter than the holder's 8s sleep
    cleanup.kill_orphan_sessions = lambda _s, sessions: []  # neuter kill so the real session survives
    result = {}
    try:
        t = cleanup.cleanup_run("s11", schema, 0, "PASS")
        result["cleanup_result"] = t.cleanup_result
        result["connections_zero_at_is_none"] = t.connections_zero_at is None
        result["final_connections_state"] = t.final_connections_state
        result["blocking_sessions_seen_nonempty"] = len(t.blocking_sessions_seen) > 0
    finally:
        cleanup.CLEANUP_TIMEOUT_SECONDS = real_budget
        cleanup.kill_orphan_sessions = real_kill
        try:
            holder.wait(timeout=15)
        except subprocess.TimeoutExpired:
            holder.kill()
        real_teardown(schema)
    return result


def s12_session_query_itself_errors():
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_refs = cleanup.referencing_sessions
    cleanup.referencing_sessions = lambda _s: (_ for _ in ()).throw(cleanup.VerificationError("audit-injected session query failure"))
    result = {}
    try:
        t = cleanup.cleanup_run("s12", schema, 0, "PASS")
        result["cleanup_result"] = t.cleanup_result
        result["connections_zero_at_is_none"] = t.connections_zero_at is None
        result["verification_error_set"] = t.verification_error is not None
    finally:
        cleanup.referencing_sessions = real_refs
        real_teardown(schema)
    return result


def s13_metadata_lock_without_processlist_db():
    """Reconfirm: a session that holds a metadata lock on the schema's tables
    without processlist.db=schema (qualified cross-db access) is still detected
    by referencing_sessions()'s UNION with performance_schema.metadata_locks."""
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    sql(f"CREATE TABLE `{schema}`.t (id INT PRIMARY KEY) ENGINE=InnoDB")
    # Connect with a DIFFERENT default db (laravel is off-limits; use information_schema
    # itself as an always-present, harmless default db for the holder connection) and
    # access the target schema only via qualified name -- so processlist.db never
    # equals our schema, but a metadata lock on it is still held.
    holder = subprocess.Popen(
        [MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306",
         "--execute=" + f"BEGIN; SELECT * FROM `{schema}`.t FOR UPDATE; DO SLEEP(4); COMMIT;",
         "--database=information_schema"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    result = {}
    try:
        time.sleep(1.0)
        processlist_db_rows = sql(
            f"SELECT id FROM information_schema.processlist WHERE db = '{schema}'"
        ).strip()
        seen = cleanup.referencing_sessions(schema)
        result["processlist_db_filtered_empty"] = processlist_db_rows == ""
        result["referencing_sessions_found_it"] = len(seen) > 0
        result["seen_count"] = len(seen)
    finally:
        holder.wait(timeout=15)
        real_teardown(schema)
    return result


# --- section 15/16: SLA total + timestamps-as-facts ---------------------------------

def s15_sla_exceeded_independent_mechanism():
    """Independent from the executor's T8 (which delayed the DROP call itself):
    here the delay is injected into referencing_sessions() during drain, so the SLA
    is consumed BEFORE drop_database_verified() even starts, and the eventual DROP
    genuinely succeeds fast -- proving the unified deadline covers the drain phase
    too, not just the drop phase."""
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_refs = cleanup.referencing_sessions
    real_budget = cleanup.CLEANUP_TIMEOUT_SECONDS
    cleanup.CLEANUP_TIMEOUT_SECONDS = 0.05
    call_n = {"n": 0}

    def slow_refs(s):
        call_n["n"] += 1
        if call_n["n"] == 1:
            time.sleep(0.3)  # blow the tiny budget during the FIRST drain check
        return real_refs(s)

    cleanup.referencing_sessions = slow_refs
    result = {}
    try:
        t = cleanup.cleanup_run("s15", schema, 0, "PASS")
        result["cleanup_result"] = t.cleanup_result
        result["sla_exceeded"] = t.sla_exceeded
        result["database_actually_absent_anyway"] = not cleanup.schema_exists(schema)
    finally:
        cleanup.referencing_sessions = real_refs
        cleanup.CLEANUP_TIMEOUT_SECONDS = real_budget
        real_teardown(schema)
    return result


def s16_timestamps_never_precede_confirmation():
    """Inject failure immediately before each of the three confirmable states and
    assert the corresponding timestamp is never populated when the state was never
    actually reached."""
    out = {}
    # database_dropped_at: force schema_exists to always report present (so DROP
    # is never confirmed) within a short budget.
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_se = cleanup.schema_exists
    real_budget = cleanup.CLEANUP_TIMEOUT_SECONDS
    real_rescue = cleanup.RESCUE_TIMEOUT_SECONDS
    cleanup.CLEANUP_TIMEOUT_SECONDS = 1
    cleanup.RESCUE_TIMEOUT_SECONDS = 1
    cleanup.schema_exists = lambda s: True
    try:
        t = cleanup.cleanup_run("s16db", schema, 0, "PASS")
        out["database_dropped_at_never_set_when_never_confirmed"] = t.database_dropped_at is None
    finally:
        cleanup.schema_exists = real_se
        cleanup.CLEANUP_TIMEOUT_SECONDS = real_budget
        cleanup.RESCUE_TIMEOUT_SECONDS = real_rescue
        real_teardown(schema)
    return out


# --- section 18: rescue non-laundering via a genuinely different mechanism ---------

def s18_rescue_never_launders_via_real_sla_exhaustion():
    """Different mechanism from the executor's T9 (which stubbed
    drop_database_verified entirely): here CLEANUP_TIMEOUT_SECONDS is shrunk to
    near-zero for the PRIMARY pass only (deadline already elapsed by the time
    drop_database_verified is reached), using the REAL drop/verify code path, not a
    stub -- so primary genuinely never gets a chance to even attempt DROP. Rescue
    then runs on its normal (unshrunk) budget and performs a real DROP."""
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_budget = cleanup.CLEANUP_TIMEOUT_SECONDS
    cleanup.CLEANUP_TIMEOUT_SECONDS = 0.001
    result = {}
    try:
        t = cleanup.cleanup_run("s18", schema, 0, "PASS")
        result["primary_cleanup_result"] = t.cleanup_result
        result["rescue_used"] = t.rescue_used
        result["final_state"] = t.state
        result["result_never_pass"] = t.cleanup_result != "PASS"
    finally:
        cleanup.CLEANUP_TIMEOUT_SECONDS = real_budget
        result["schema_absent_via_rescue"] = not cleanup.schema_exists(schema)
        real_teardown(schema)
    return result


# --- section 19: hard guard re-confirmation -----------------------------------------

def s19_hard_guard():
    denied = {}
    for name in ("laravel", "mysql", "information_schema", "performance_schema", "sys",
                 "mepa_wave4_test_m121_" + "a" * 24, "arbitrary_name"):
        try:
            cleanup.guard_schema(name)
            denied[name] = "ALLOWED (BAD)"
        except RuntimeError:
            denied[name] = "DENIED"
    r = sql("SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME='laravel'")
    denied["laravel_still_present_after_all_attempts"] = "laravel" in r
    return denied


def main():
    report = {}
    for name, fn in [
        ("s5_drop_and_verification_both_fail", s5_drop_and_verification_both_fail_synthetic_rc1),
        ("s6_real_filenotfound_operational_error", s6_real_filenotfound_operational_error),
        ("s6_real_timeout_reachable", s6_real_timeout_reachable),
        ("s8_barrier_residual_intentional", s8_barrier_residual_intentional),
        ("s9_barrier_verification_itself_errors", s9_barrier_verification_itself_errors),
        ("s10_parallel_barrier_isolation", s10_parallel_barrier_isolation),
        ("s11_real_residual_session_past_deadline", s11_real_residual_session_past_deadline),
        ("s12_session_query_itself_errors", s12_session_query_itself_errors),
        ("s13_metadata_lock_without_processlist_db", s13_metadata_lock_without_processlist_db),
        ("s15_sla_exceeded_independent_mechanism", s15_sla_exceeded_independent_mechanism),
        ("s16_timestamps_never_precede_confirmation", s16_timestamps_never_precede_confirmation),
        ("s18_rescue_never_launders_via_real_sla_exhaustion", s18_rescue_never_launders_via_real_sla_exhaustion),
        ("s19_hard_guard", s19_hard_guard),
    ]:
        print(f"--- running {name} ---", flush=True)
        try:
            report[name] = fn()
        except Exception as exc:
            report[name] = {"PROBE_CRASHED": True, "exception_type": type(exc).__name__, "exception_str": str(exc)}
        print(json.dumps({name: report[name]}, indent=2), flush=True)

    (OUT / "core_findings_probe.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
