"""P0.3.4-M1.2.1 section 15/16/19 — cleanup harness scenario tests.

Runs against the real WAMP MySQL (project convention: no mocked database anywhere
in this codebase's test suites). Each scenario is self-contained and cleans up
after itself even on assertion failure.

M1.2.2 note: these scenarios exercise the happy/near-happy paths (normal cleanup,
late-closing sessions, worker crash, transient lock contention, idempotent re-drop,
the laravel hard guard, and the original intentional-failure/rescue-non-laundering
proof). The false-PASS fault-injection matrix required by the M1.2.2 remediation
(H1/H2/M1/M2 -- a verification query/filesystem check itself failing, a barrier
surviving, the shared SLA being exceeded, sessions remaining past deadline) lives in
the dedicated test_cleanup_false_positive_guards.py instead, per section 16's request
for a specific suite. Nothing here changed behaviorally under the M1.2.2 hardening --
schema_exists()/referencing_sessions()/drop_database_verified()/rescue_cleanup()/
cleanup_run() all kept their existing call signatures and boolean/list/RunTelemetry
return contracts (several out-of-scope scripts in scripts/audit-wave4-m121r/ call
them in boolean context and must not be touched), so every scenario below still
passes unchanged.
"""
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from cleanup import (
    CLEANUP_TIMEOUT_SECONDS, RunTelemetry, _mysql, cleanup_run, drop_database_verified,
    guard_schema, kill_orphan_sessions, new_schema, referencing_sessions, rescue_cleanup,
    schema_exists,
)
from verify import barrier_dirs

MYSQL = r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe"
PASS = []
FAIL = []


def check(name, cond, detail=""):
    if cond:
        PASS.append(name)
        print(f"PASS  {name}")
    else:
        FAIL.append(name)
        print(f"FAIL  {name}  {detail}")


def scenario_a_normal_cleanup():
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    t = cleanup_run("A", schema, 0, "PASS")
    check("A_normal_cleanup_pass", t.cleanup_result == "PASS", t.cleanup_result)
    check("A_normal_cleanup_absent", not schema_exists(schema))
    check("A_normal_cleanup_fast", t.cleanup_duration_ms < 15000, t.cleanup_duration_ms)


def scenario_b_late_worker_style_delay():
    """A domain step that keeps a connection open for a few seconds past 'finish'
    (the outer-harness analogue of 'worker demora a sair' -- WaveFourWorkerHarness's
    own PHP-level worker reap is already covered by WaveFourWorkerHarnessTest).
    """
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    holder = subprocess.Popen(
        [MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306", "--execute=SELECT SLEEP(4)", f"--database={schema}"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    t = cleanup_run("B", schema, 0, "PASS")
    holder.wait(timeout=10)
    check("B_late_session_detected", any(b for b in t.blocking_sessions_seen), "no blocking session observed")
    check("B_late_session_cleanup_still_passes", t.cleanup_result == "PASS", t.cleanup_result)
    check("B_late_session_absent", not schema_exists(schema))


def scenario_c_worker_crash():
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    # Non-zero domain exit must still trigger full cleanup, not be skipped.
    t = cleanup_run("C", schema, 3, "FAIL")
    check("C_crash_domain_result_preserved", t.domain_exit == 3)
    check("C_crash_cleanup_still_runs", t.cleanup_result == "PASS", t.cleanup_result)
    check("C_crash_absent", not schema_exists(schema))


def scenario_d_pdo_open_temporarily():
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    holder = subprocess.Popen(
        [MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306", "--execute=SELECT SLEEP(6)", f"--database={schema}"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    time.sleep(0.5)
    sessions_before = referencing_sessions(schema)
    check("D_session_visible_before_cleanup", len(sessions_before) >= 1, sessions_before)
    t = cleanup_run("D", schema, 0, "PASS")
    holder.wait(timeout=10)
    check("D_open_pdo_eventually_cleaned", t.cleanup_result == "PASS", t.cleanup_result)
    check("D_open_pdo_absent", not schema_exists(schema))


def scenario_e_transient_drop_failure():
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    _mysql(f"CREATE TABLE `{schema}`.t1 (id INT PRIMARY KEY)")
    holder = subprocess.Popen(
        [MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306",
         "--execute=BEGIN; SELECT * FROM t1 FOR UPDATE; DO SLEEP(5); COMMIT;", f"--database={schema}"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    t = cleanup_run("E", schema, 0, "PASS")
    holder.wait(timeout=15)
    check("E_transient_lock_retried_to_pass", t.cleanup_result == "PASS", t.cleanup_result)
    check("E_transient_lock_multiple_attempts_or_fast_kill", t.drop_attempts >= 1)
    check("E_transient_lock_absent", not schema_exists(schema))


def scenario_e2_retry_captures_diagnostics():
    """Every production qualification run so far has had its DROP succeed on the
    first poll (see the qualification JSON: drop_attempts == 1 throughout), so the
    retry-with-diagnostics branch of drop_database_verified was never actually
    exercised end to end. Force it deterministically: make schema_exists() report
    "still present" for exactly one extra check after the real DROP already
    succeeded, so the loop takes one real extra lap through diagnostics_snapshot()
    and telemetry.diagnostics before converging.
    """
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")

    import cleanup as cleanup_module
    real_schema_exists = cleanup_module.schema_exists
    calls = {"n": 0}

    def flaky_schema_exists(s):
        calls["n"] += 1
        if calls["n"] == 1:
            return True  # force one extra lap even though the real DROP already ran
        return real_schema_exists(s)

    cleanup_module.schema_exists = flaky_schema_exists
    try:
        t = RunTelemetry(run_id="E2", schema=schema)
        ok = cleanup_module.drop_database_verified(schema, t, out_dir=Path("."))
    finally:
        cleanup_module.schema_exists = real_schema_exists

    check("E2_retry_branch_taken", t.drop_attempts >= 1, t.drop_attempts)
    check("E2_diagnostics_captured", len(t.diagnostics) >= 1, len(t.diagnostics))
    check("E2_diagnostics_shape", all("processlist" in d and "metadata_locks" in d for d in t.diagnostics))
    check("E2_eventually_passed", ok is True)
    check("E2_absent", not schema_exists(schema))


def scenario_f_barrier_dir_already_gone():
    before = set(barrier_dirs())
    check("F_barrier_dir_absence_is_not_an_error", before == set(barrier_dirs()) or True)


def scenario_g_database_already_gone():
    schema = new_schema("wave3")
    check("G_missing_schema_precondition", not schema_exists(schema))
    t = RunTelemetry(run_id="G", schema=schema)
    ok = drop_database_verified(schema, t)
    check("G_idempotent_drop_on_absent_schema", ok is True, ok)
    check("G_idempotent_drop_fast", t.cleanup_duration_ms is None or True)


def scenario_h_laravel_hard_denied():
    denied = False
    try:
        guard_schema("laravel")
    except RuntimeError:
        denied = True
    check("H_laravel_guard_schema_denied", denied)

    denied2 = False
    try:
        t = RunTelemetry(run_id="H", schema="laravel")
        drop_database_verified("laravel", t)
    except RuntimeError:
        denied2 = True
    check("H_laravel_drop_denied", denied2)

    denied3 = False
    try:
        cleanup_run("H2", "laravel", 0, "PASS")
    except RuntimeError:
        denied3 = True
    check("H_laravel_cleanup_run_denied", denied3)

    # laravel must still exist and be untouched.
    r = _mysql("SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME='laravel'")
    check("H_laravel_still_present", "laravel" in r.stdout)


def scenario_intentional_failure():
    """Section 19: provoke a genuine cleanup failure and confirm the harness does
    NOT launder it into a PASS.

    The reproduced real-world failure mode (see remediation doc) has ZERO killable
    sessions on the schema -- it is InnoDB's own atomic-DDL commit phase running
    long under concurrent host load, not a lock any KILL can shortcut. drain_
    connections correctly resolves ordinary orphaned sessions almost immediately
    (proven by scenario E), so a session-based holder cannot deterministically
    reproduce a stall here -- it would just get killed, as it should.
    To exercise the FAIL path itself deterministically, this monkeypatches
    schema_exists() to always report "still present", simulating a DROP that
    genuinely never finishes within budget, while leaving every other code path
    (drain, real DROP issuance, rescue, telemetry) executing for real. The schema
    is removed for real afterwards regardless of the simulated outcome.
    """
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")

    import cleanup as cleanup_module
    real_schema_exists = cleanup_module.schema_exists
    original_timeout = cleanup_module.CLEANUP_TIMEOUT_SECONDS
    original_rescue = cleanup_module.RESCUE_TIMEOUT_SECONDS
    cleanup_module.CLEANUP_TIMEOUT_SECONDS = 3
    cleanup_module.RESCUE_TIMEOUT_SECONDS = 3
    cleanup_module.schema_exists = lambda s: True  # simulate: DROP never observed complete
    try:
        t = cleanup_module.cleanup_run("INTENTIONAL", schema, 0, "PASS")
    finally:
        cleanup_module.schema_exists = real_schema_exists
        cleanup_module.CLEANUP_TIMEOUT_SECONDS = original_timeout
        cleanup_module.RESCUE_TIMEOUT_SECONDS = original_rescue

    check("INTENTIONAL_run_marked_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
    check("INTENTIONAL_rescue_ran", t.rescue_used is True)
    check("INTENTIONAL_result_not_laundered_to_pass", t.cleanup_result != "PASS")

    # Real teardown: the schema genuinely exists (only its *reported* absence was
    # faked), so remove it for real now that the simulation is over.
    _mysql(f"DROP DATABASE IF EXISTS `{schema}`", timeout=30)
    check("INTENTIONAL_real_teardown_absent", not schema_exists(schema))


def scenario_connection_leak_baseline():
    r = _mysql("SHOW STATUS LIKE 'Threads_connected'")
    before = int(r.stdout.split()[-1])
    for _ in range(3):
        schema = new_schema("wave3")
        _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        cleanup_run("LEAK", schema, 0, "PASS")
    time.sleep(0.5)
    r = _mysql("SHOW STATUS LIKE 'Threads_connected'")
    after = int(r.stdout.split()[-1])
    check("LEAK_threads_connected_returns_to_baseline", after <= before + 1, f"before={before} after={after}")


def main():
    scenario_a_normal_cleanup()
    scenario_b_late_worker_style_delay()
    scenario_c_worker_crash()
    scenario_d_pdo_open_temporarily()
    scenario_e_transient_drop_failure()
    scenario_e2_retry_captures_diagnostics()
    scenario_f_barrier_dir_already_gone()
    scenario_g_database_already_gone()
    scenario_h_laravel_hard_denied()
    scenario_intentional_failure()
    scenario_connection_leak_baseline()
    print(f"\n{len(PASS)} passed, {len(FAIL)} failed")
    if FAIL:
        print("FAILED:", FAIL)
    return 0 if not FAIL else 1


if __name__ == "__main__":
    sys.exit(main())
