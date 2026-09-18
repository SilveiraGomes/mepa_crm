"""P0.3.4-M1.2.2 sections 15/16/17 -- false-positive guard suite for the cleanup harness.

Objective (section 16): no observability/verification error can ever become PASS.
Covers, per the remediation brief, all four resource verifiers plus the shared
deadline: database (H1), barrier directory (H2), connection/session (M2), SLA scope
(M1), and the worker/process listing hardened alongside them. Every fault is injected
via monkeypatch on this module's own functions -- never on production code -- and
every scenario tears down whatever real resource it created for real, regardless of
the simulated outcome, so the suite leaves zero orphans behind it (section 21).

Runs against the real WAMP MySQL, per this codebase's no-mocked-database convention:
only the *verification instrument* is faked, never the database itself.

M1.2.3 update: T4/T5 originally exercised H2's guarantee via the legacy before/after
candidate_barriers diff. M1.2.3 deliberately moved FILES_REMOVED's authority to the
new identity-owned run_root mechanism (see cleanup.py's module docstring and
docs/reviews/P0.3.4_M1_2_3_cleanup_ownership_remediation.md) and made
candidate_barriers diagnostic-only, per its own section 14. T4/T5 were updated to
prove the guarantee via run_root instead; T4b/T5b are new and prove the deliberate
behavior change itself (a surviving/erroring legacy candidate must NOT fail a run
that has no run_root issue). The full ownership/failure-boundary model (M122R-01/
M122R-02) has its own dedicated suite,
test_cleanup_ownership_and_failure_boundary.py.
"""
import subprocess
import sys
import tempfile
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import cleanup as cleanup_module  # noqa: E402
import verify as verify_module  # noqa: E402
from cleanup import (  # noqa: E402
    VerificationError, _mysql, cleanup_run, guard_schema, new_schema, schema_exists,
)

PASS = []
FAIL = []


def check(name, cond, detail=""):
    if cond:
        PASS.append(name)
        print(f"PASS  {name}", flush=True)
    else:
        FAIL.append(name)
        print(f"FAIL  {name}  {detail}", flush=True)


def _teardown_schema(schema):
    if _mysql(f"SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME='{schema}'").stdout.strip():
        _mysql(f"DROP DATABASE IF EXISTS `{schema}`", timeout=30)


def _patch_mysql(fail_prefixes):
    """Return (patched_fn, restore) where statements starting with any of
    `fail_prefixes` fail (rc=1) and everything else goes to the real client."""
    real = cleanup_module._mysql

    def patched(statement, timeout=30):
        if any(statement.startswith(p) for p in fail_prefixes):
            return subprocess.CompletedProcess([], 1, "", "injected verification failure")
        return real(statement, timeout=timeout)

    return patched, real


def t1_database_exists_verification_error():
    """T1: DROP genuinely succeeds, but the absence-verification query itself is
    forced to fail. H1's exact defect class: a query error must never be read as
    'schema not found', even when the resource actually is gone underneath it.

    RESCUE_TIMEOUT_SECONDS is shrunk for the duration of the fault: with the same
    verification query still faked to fail, rescue's own observation is equally
    blind (it cannot tell success from failure either) and would otherwise spend its
    full budget unable to converge -- the same reason the existing scenario_intentional_
    failure() test in test_cleanup.py shrinks it. This does not weaken the assertion:
    we only assert on cleanup_result/verification_error/state, never on rescue timing.
    """
    schema = new_schema("wave4")
    _mysql(f"CREATE DATABASE `{schema}`")
    patched, real = _patch_mysql(["SELECT SCHEMA_NAME"])
    real_rescue_budget = cleanup_module.RESCUE_TIMEOUT_SECONDS
    cleanup_module.RESCUE_TIMEOUT_SECONDS = 3
    cleanup_module._mysql = patched
    try:
        t = cleanup_module.cleanup_run("T1", schema, 0, "PASS")
    finally:
        cleanup_module._mysql = real
        cleanup_module.RESCUE_TIMEOUT_SECONDS = real_rescue_budget
    check("T1_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
    check("T1_verification_error_recorded", t.verification_error is not None)
    check("T1_never_verified", t.state != "DONE")
    _teardown_schema(schema)
    check("T1_real_teardown_absent", not schema_exists(schema))


def t2_drop_error_and_verification_error():
    """T2: both DROP and the verification query fail -- the schema genuinely still
    exists throughout. Reproduces the exact original H1 probe (invariant_probes.json
    'false_absence', which returned cleanup_result=PASS with schema_still_exists=True
    before this remediation). RESCUE_TIMEOUT_SECONDS shrunk for the same reason as T1
    -- rescue's real DROP is also intercepted by this fault, so it cannot converge
    either; the outer test still performs a real, unpatched teardown afterward.
    """
    schema = new_schema("wave4")
    _mysql(f"CREATE DATABASE `{schema}`")
    patched, real = _patch_mysql(["DROP DATABASE", "SELECT SCHEMA_NAME"])
    real_rescue_budget = cleanup_module.RESCUE_TIMEOUT_SECONDS
    cleanup_module.RESCUE_TIMEOUT_SECONDS = 3
    cleanup_module._mysql = patched
    try:
        t = cleanup_module.cleanup_run("T2", schema, 0, "PASS")
    finally:
        cleanup_module._mysql = real
        cleanup_module.RESCUE_TIMEOUT_SECONDS = real_rescue_budget
    check("T2_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
    check("T2_schema_genuinely_still_present_during_fault", schema_exists(schema))
    _teardown_schema(schema)
    check("T2_real_teardown_absent", not schema_exists(schema))


def t3_database_absent_successful_verification():
    """T3: schema never created; every check succeeds and genuinely reports absence."""
    schema = new_schema("wave3")
    t = cleanup_run("T3", schema, 0, "PASS")
    check("T3_pass", t.cleanup_result == "PASS", t.cleanup_result)
    check("T3_state_done", t.state == "DONE", t.state)
    check("T3_no_rescue", t.rescue_used is False)


def t4_run_root_persists():
    """T4: the schema is genuinely dropped, but this run's own identity-owned root
    cannot be confirmed removed. FILES_REMOVED/CLEANUP_VERIFIED must not be reached
    -- the M1.2.3 equivalent of invariant_probes.json's original 'barrier_survives'
    finding, reproduced via the new ownership mechanism instead of the retired
    timing-based candidate_barriers diff (see t4b below).
    """
    schema = new_schema("wave4")
    _mysql(f"CREATE DATABASE `{schema}`")
    # M1.2.3: FILES_REMOVED authority moved from the timing-based candidate_barriers
    # diff to the identity-owned run_root (section 15) -- reproduce the FAIL via
    # THAT mechanism now. safe_remove_run_root() is forced to report "not removed"
    # (a stand-in for a transient real failure), proving the primary path still
    # correctly refuses to claim FILES_REMOVED without confirmation.
    run_id = cleanup_module.run_id_of(schema)
    run_root = cleanup_module.create_run_root(run_id, schema)
    real_safe_remove = cleanup_module.safe_remove_run_root
    cleanup_module.safe_remove_run_root = lambda *a, **k: False
    try:
        t = cleanup_run(run_id, schema, 0, "PASS", run_root=run_root)
        check("T4_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
        check("T4_files_removed_never_reached", t.files_removed_at is None)
        check("T4_database_still_dropped_for_real", not schema_exists(schema))
    finally:
        cleanup_module.safe_remove_run_root = real_safe_remove
        _teardown_schema(schema)
        if run_root.exists():
            import shutil
            shutil.rmtree(run_root, ignore_errors=True)
    check("T4_real_teardown_run_root_absent", not run_root.exists())


def t4b_legacy_barrier_diff_no_longer_gates():
    """M1.2.3 section 14: the legacy before/after candidate_barriers diff is
    diagnostic only now -- a barrier that persists must NOT fail the run on its
    own (this was T4's exact assertion under M1.2.2; M1.2.3 deliberately inverts
    it, since true ownership now lives in run_root instead).
    """
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}`")
    barrier = Path(tempfile.gettempdir()) / f"mepa_wave4_m12_{schema.rsplit('_', 1)[-1]}"
    barrier.mkdir()
    (barrier / "ready").write_text("ready", encoding="utf-8")
    try:
        t = cleanup_run("T4b", schema, 0, "PASS", candidate_barriers=[barrier])
        check("T4b_candidate_recorded_as_diagnostic", str(barrier) in t.candidate_barriers_present_diagnostic,
              t.candidate_barriers_present_diagnostic)
        check("T4b_pass_despite_surviving_candidate", t.cleanup_result == "PASS", t.cleanup_result)
    finally:
        _teardown_schema(schema)
        if barrier.exists():
            import shutil
            shutil.rmtree(barrier, ignore_errors=True)


def t5_run_root_removal_raises():
    """T5 (M1.2.3): a genuine infrastructure error while removing the run's own
    root (not merely 'still there') must also fail the run, never be swallowed.
    """
    schema = new_schema("wave4")
    _mysql(f"CREATE DATABASE `{schema}`")
    run_id = cleanup_module.run_id_of(schema)
    run_root = cleanup_module.create_run_root(run_id, schema)
    real_safe_remove = cleanup_module.safe_remove_run_root

    def exploding(*_a, **_k):
        raise cleanup_module.CleanupInfrastructureError("simulated filesystem failure removing run root")

    cleanup_module.safe_remove_run_root = exploding
    try:
        t = cleanup_run(run_id, schema, 0, "PASS", run_root=run_root)
        check("T5_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
        check("T5_verification_error_recorded", t.verification_error is not None)
    finally:
        cleanup_module.safe_remove_run_root = real_safe_remove
        _teardown_schema(schema)
        if run_root.exists():
            import shutil
            shutil.rmtree(run_root, ignore_errors=True)
    check("T5_real_teardown_absent", not schema_exists(schema))


def t5b_legacy_barrier_verification_error_no_longer_gates():
    """M1.2.3 section 14: even a verification ERROR on the legacy diagnostic must
    not fail the run -- distinct from t5's run_root-based failure, which still
    correctly fails.
    """
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}`")
    real_barriers_present = cleanup_module.barriers_present

    def exploding(_paths):
        raise VerificationError("simulated filesystem stat failure")

    cleanup_module.barriers_present = exploding
    try:
        t = cleanup_module.cleanup_run("T5b", schema, 0, "PASS", candidate_barriers=[Path("irrelevant")])
    finally:
        cleanup_module.barriers_present = real_barriers_present
    check("T5b_pass_despite_diagnostic_error", t.cleanup_result == "PASS", t.cleanup_result)
    _teardown_schema(schema)


def t6_sessions_remain_after_deadline():
    """T6: a session referencing the schema never clears (kill is a no-op in this
    simulation) and the shared cleanup deadline is shrunk so the test is fast.
    Reproduces invariant_probes.json's 'false_drain' (previously connections_zero_at
    stamped anyway, and sessions_after_kill non-empty at the same time).
    """
    schema = new_schema("wave4")
    _mysql(f"CREATE DATABASE `{schema}`")
    real_budget = cleanup_module.CLEANUP_TIMEOUT_SECONDS
    real_refs = cleanup_module.referencing_sessions
    real_kill = cleanup_module.kill_orphan_sessions
    cleanup_module.CLEANUP_TIMEOUT_SECONDS = 1
    cleanup_module.referencing_sessions = lambda _schema: [{"id": 999999999, "user": "synthetic",
                                                             "host": "-", "command": "Sleep", "time": "0", "state": ""}]
    cleanup_module.kill_orphan_sessions = lambda _schema, _sessions: []
    try:
        t = cleanup_module.cleanup_run("T6", schema, 0, "PASS")
    finally:
        cleanup_module.CLEANUP_TIMEOUT_SECONDS = real_budget
        cleanup_module.referencing_sessions = real_refs
        cleanup_module.kill_orphan_sessions = real_kill
    check("T6_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
    check("T6_connections_zero_never_stamped", t.connections_zero_at is None)
    check("T6_final_connections_state_present", t.final_connections_state == "PRESENT", t.final_connections_state)
    _teardown_schema(schema)
    check("T6_real_teardown_absent", not schema_exists(schema))


def t7_session_check_raises():
    """T7: the session-referencing query itself errors outright (not merely 'still
    present'). Must fail the run, never be treated as zero sessions.
    """
    schema = new_schema("wave4")
    _mysql(f"CREATE DATABASE `{schema}`")
    real_refs = cleanup_module.referencing_sessions

    def exploding(_schema):
        raise VerificationError("simulated session-check failure")

    cleanup_module.referencing_sessions = exploding
    try:
        t = cleanup_module.cleanup_run("T7", schema, 0, "PASS")
    finally:
        cleanup_module.referencing_sessions = real_refs
    check("T7_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
    check("T7_verification_error_recorded", t.verification_error is not None)
    _teardown_schema(schema)
    check("T7_real_teardown_absent", not schema_exists(schema))


def t8_total_cleanup_exceeds_sla_despite_drop_inside_sla():
    """T8: DROP itself completes quickly, but an injected delay pushes the total
    cleanup wall time past a deliberately tiny shared SLA. The database ends up
    genuinely absent -- must still be CLEANUP_FAIL (section 10: never converted to
    PASS just because the resource disappeared moments after the deadline).
    """
    schema = new_schema("wave4")
    _mysql(f"CREATE DATABASE `{schema}`")
    real_budget = cleanup_module.CLEANUP_TIMEOUT_SECONDS
    real_mysql = cleanup_module._mysql
    cleanup_module.CLEANUP_TIMEOUT_SECONDS = 0.05

    def delayed(statement, timeout=30):
        if statement.startswith("DROP DATABASE"):
            time.sleep(0.3)
        return real_mysql(statement, timeout=timeout)

    cleanup_module._mysql = delayed
    try:
        t = cleanup_module.cleanup_run("T8", schema, 0, "PASS")
    finally:
        cleanup_module._mysql = real_mysql
        cleanup_module.CLEANUP_TIMEOUT_SECONDS = real_budget
    check("T8_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
    check("T8_sla_exceeded_recorded", t.sla_exceeded is True)
    check("T8_database_actually_absent_anyway", not schema_exists(schema))
    _teardown_schema(schema)


def t9_rescue_succeeds_after_primary_fail():
    """T9: the primary drop-and-verify function is forced to report failure without
    ever touching the database (matching the out-of-scope independent audit's own
    probe_rescue.py technique), so the schema genuinely still exists when rescue
    takes over -- rescue then issues a REAL DROP with fully real verification (not
    patched, unlike a naive fault that would also blind rescue's own observation)
    and succeeds. The run's recorded result must remain CLEANUP_FAIL regardless
    (section 9/11/14), proving rescue success never launders a primary failure.
    """
    schema = new_schema("wave4")
    _mysql(f"CREATE DATABASE `{schema}`")
    real_drop_verified = cleanup_module.drop_database_verified
    cleanup_module.drop_database_verified = lambda *a, **k: False
    try:
        t = cleanup_module.cleanup_run("T9", schema, 0, "PASS")
    finally:
        cleanup_module.drop_database_verified = real_drop_verified
    check("T9_primary_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
    check("T9_rescue_used", t.rescue_used is True)
    check("T9_rescue_verified_state", t.state == "RESCUE_VERIFIED", t.state)
    check("T9_result_never_laundered_to_pass", t.cleanup_result != "PASS")
    check("T9_schema_genuinely_gone_via_rescue", not schema_exists(schema))


def t10_laravel_guard_hard_denied():
    denied = False
    try:
        guard_schema("laravel")
    except RuntimeError:
        denied = True
    check("T10_guard_schema_denied", denied)

    denied2 = False
    try:
        cleanup_run("T10b", "laravel", 0, "PASS")
    except RuntimeError:
        denied2 = True
    check("T10_cleanup_run_denied", denied2)

    r = _mysql("SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME='laravel'")
    check("T10_laravel_untouched", "laravel" in r.stdout)


def t11_invalid_schema_name_hard_denied():
    for bad in ("arbitrary", "mepa_wave4_test_m121_deadbeef", "mepa_wave4_test_m121_" + "a" * 24 + "x",
                "'; DROP DATABASE laravel; --", "mysql", "information_schema"):
        denied = False
        try:
            guard_schema(bad)
        except RuntimeError:
            denied = True
        check(f"T11_denied[{bad[:24]}]", denied, bad)


def t12_all_resources_genuinely_absent_inside_deadline():
    schema = new_schema("wave3")
    _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    t = cleanup_run("T12", schema, 0, "PASS")
    check("T12_pass", t.cleanup_result == "PASS", t.cleanup_result)
    check("T12_deadline_not_exceeded", t.sla_exceeded is False)
    check("T12_final_database_absent", t.final_database_state == "ABSENT", t.final_database_state)
    check("T12_final_connections_absent", t.final_connections_state == "ABSENT", t.final_connections_state)
    check("T12_final_barriers_absent", t.final_barriers_state == "ABSENT", t.final_barriers_state)
    check("T12_no_rescue", t.rescue_used is False)


def t13_worker_process_listing_raises_on_check_failure():
    """Section 16's 'worker verifier' coverage: verify.orphan_php_processes() must
    raise rather than silently reporting zero when the underlying tasklist check
    itself cannot be completed."""
    real_run = subprocess.run

    def exploding_run(cmd, *args, **kwargs):
        if cmd and cmd[0] == "tasklist":
            raise OSError("simulated tasklist failure")
        return real_run(cmd, *args, **kwargs)

    verify_module.subprocess.run = exploding_run
    raised = False
    try:
        verify_module.orphan_php_processes()
    except VerificationError:
        raised = True
    finally:
        verify_module.subprocess.run = real_run
    check("T13_orphan_php_processes_raises_on_failure", raised)
    check("T13_orphan_php_processes_normally_succeeds", isinstance(verify_module.orphan_php_processes(), list))


def t14_orphan_schemas_raises_on_query_failure():
    """Same class as T13, for the batch-level orphan-schema listing (section 20/21's
    '0 schemas' claim must not be trustable off a silently-failed query)."""
    patched, real = _patch_mysql(["SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME LIKE"])
    cleanup_module._mysql = patched
    raised = False
    try:
        verify_module.orphan_schemas("mepa_%_test_m121_%")
    except VerificationError:
        raised = True
    finally:
        cleanup_module._mysql = real
    check("T14_orphan_schemas_raises_on_failure", raised)


def main():
    t1_database_exists_verification_error()
    t2_drop_error_and_verification_error()
    t3_database_absent_successful_verification()
    t4_run_root_persists()
    t4b_legacy_barrier_diff_no_longer_gates()
    t5_run_root_removal_raises()
    t5b_legacy_barrier_verification_error_no_longer_gates()
    t6_sessions_remain_after_deadline()
    t7_session_check_raises()
    t8_total_cleanup_exceeds_sla_despite_drop_inside_sla()
    t9_rescue_succeeds_after_primary_fail()
    t10_laravel_guard_hard_denied()
    t11_invalid_schema_name_hard_denied()
    t12_all_resources_genuinely_absent_inside_deadline()
    t13_worker_process_listing_raises_on_check_failure()
    t14_orphan_schemas_raises_on_query_failure()
    print(f"\n{len(PASS)} passed, {len(FAIL)} failed")
    if FAIL:
        print("FAILED:", FAIL)
    return 0 if not FAIL else 1


if __name__ == "__main__":
    sys.exit(main())
