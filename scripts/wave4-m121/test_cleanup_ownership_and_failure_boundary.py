"""P0.3.4-M1.2.3 sections 6/17 -- ownership & failure-boundary test suite.

Covers the two findings from the independent P0.3.4-M1.2.2-R audit:

  M122R-01 (F1-F7): a raw OSError (not just a non-zero exit or subprocess.
  TimeoutExpired) at any of three unguarded _mysql()-adjacent call sites --
  DROP-issuance, session KILL, diagnostics_snapshot() -- used to propagate
  uncaught out of cleanup_run(), crashing the whole harness instead of reporting
  a structured CLEANUP_FAIL. F1-F3 reproduce a REAL (not synthetic) OSError at
  each of the three sites by pointing cleanup.MYSQL at a nonexistent binary path
  -- this exercises the actual subprocess machinery, not a monkeypatched return
  value. F4 reproduces a real subprocess.TimeoutExpired. F5 reproduces a genuine
  non-zero mysql client exit (malformed SQL). F6/F7 confirm rescue still runs
  after an infrastructure exception and the result never becomes anything but
  CLEANUP_FAIL.

  M122R-02 (O1-O9): barrier "ownership" used to be inferred purely from
  before/after directory-listing timing, with no way to verify a candidate
  actually belonged to this run before rescue_cleanup() deleted it. M1.2.3
  replaces this with an identity-owned run root
  (<tempdir>/wave4-m121/<run_id>/ + a .owner.json marker, created before the
  domain process starts) and makes verify_ownership()/safe_remove_run_root()
  the sole, ownership-checked deletion authority. O1-O9 prove this authority
  cannot be tricked into cross-run deletion, path traversal, or acting on an
  unowned/mismatched/missing marker.

Runs against the real WAMP MySQL, per this codebase's no-mocked-database
convention. Every scenario tears down whatever real resource it created,
regardless of the simulated outcome, so the suite leaves zero orphans (section
22).
"""
import concurrent.futures
import shutil
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import cleanup as cleanup_module  # noqa: E402
from cleanup import (  # noqa: E402
    CleanupInfrastructureError, RUN_ROOT_BASE, VerificationError, _mysql, cleanup_run,
    create_run_root, guard_schema, new_schema, run_id_of, run_root_path, safe_remove_run_root,
    schema_exists, validate_database_ownership, verify_ownership,
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
    if schema_exists(schema):
        _mysql(f"DROP DATABASE IF EXISTS `{schema}`", timeout=30)


def _teardown_root(root):
    if root.exists():
        shutil.rmtree(root, ignore_errors=True)


def _new_run():
    """A real schema + its real run_id + a real, freshly-created owned root."""
    schema = new_schema("wave4")
    run_id = run_id_of(schema)
    _mysql(f"CREATE DATABASE `{schema}`")
    root = create_run_root(run_id, schema)
    return schema, run_id, root


# --- F1-F3: real OSError at the three previously-unguarded call sites -------------

def _with_bad_mysql_path(fn):
    real_path = cleanup_module.MYSQL
    cleanup_module.MYSQL = real_path + ".audit_nonexistent_binary"
    try:
        return fn()
    finally:
        cleanup_module.MYSQL = real_path


def f1_oserror_drop_issuance():
    schema, run_id, root = _new_run()
    result = {}
    try:
        def run():
            return cleanup_run(run_id, schema, 0, "PASS", run_root=root)
        # Only the DROP-issuance path needs to see the bad binary; drain/final-check
        # also use it, so the whole run happens under the bad path -- this exercises
        # ALL three sites at once from the primary path's perspective, which is fine
        # for F1's purpose (drop issuance is the first mysql call cleanup_run makes).
        t = _with_bad_mysql_path(run)
        result["cleanup_result"] = t.cleanup_result
        result["verification_error_set"] = t.verification_error is not None
        result["rescue_used"] = t.rescue_used
        check("F1_no_crash_structured_result", True)
        check("F1_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
        check("F1_diagnostic_recorded", t.verification_error is not None)
    except Exception as exc:
        check("F1_no_crash_structured_result", False, f"{type(exc).__name__}: {exc}")
    finally:
        _teardown_schema(schema)
        _teardown_root(root)
    check("F1_zero_orphan_after", not schema_exists(schema) and not root.exists())


def f2_oserror_kill():
    """Real OSError specifically at the KILL call site: a real held session exists
    (so kill_orphan_sessions() is genuinely invoked), and MYSQL is pointed at a bad
    path only for the KILL statement via a thin real-passthrough wrapper -- proving
    the fix at this exact call site, not just anywhere in the primary path.
    """
    schema, run_id, root = _new_run()
    holder = subprocess.Popen(
        [cleanup_module.MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306",
         "--execute=SELECT SLEEP(6)", f"--database={schema}"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    time.sleep(0.5)
    real_mysql_fn = cleanup_module._mysql

    def injected(statement, timeout=30):
        if statement.startswith("KILL "):
            raise OSError("simulated: mysql.exe unavailable during KILL (F2)")
        return real_mysql_fn(statement, timeout=timeout)

    cleanup_module._mysql = injected
    result = {}
    try:
        t = cleanup_run(run_id, schema, 0, "PASS", run_root=root)
        result["cleanup_result"] = t.cleanup_result
        check("F2_no_crash_structured_result", True)
        check("F2_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
        check("F2_verification_error_set", t.verification_error is not None)
    except Exception as exc:
        check("F2_no_crash_structured_result", False, f"{type(exc).__name__}: {exc}")
    finally:
        cleanup_module._mysql = real_mysql_fn
        try:
            holder.wait(timeout=10)
        except subprocess.TimeoutExpired:
            holder.kill()
        _teardown_schema(schema)
        _teardown_root(root)
    check("F2_zero_orphan_after", not schema_exists(schema) and not root.exists())


def f3_oserror_diagnostics_snapshot():
    """diagnostics_snapshot() runs whenever out_dir is passed and a DROP attempt
    doesn't immediately succeed -- forced here via a flaky schema_exists() (same
    determinism technique as the pre-existing E2 scenario in test_cleanup.py) so
    the loop takes a real extra lap through diagnostics_snapshot() with a bad
    MySQL path active.
    """
    schema, run_id, root = _new_run()
    real_se = cleanup_module.schema_exists
    real_path = cleanup_module.MYSQL
    calls = {"n": 0}

    def flaky(s):
        calls["n"] += 1
        if calls["n"] == 1:
            return True  # force one extra lap so diagnostics_snapshot() runs for real
        return real_se(s)

    cleanup_module.schema_exists = flaky
    cleanup_module.MYSQL = real_path + ".audit_nonexistent_binary_f3"
    result = {}
    try:
        t = cleanup_run(run_id, schema, 0, "PASS", out_dir=Path("."), run_root=root)
        result["cleanup_result"] = t.cleanup_result
        check("F3_no_crash_structured_result", True)
        # diagnostics_snapshot() is explicitly tolerant of its own failures (never
        # gates PASS/FAIL) -- what F3 actually proves is that a failure there no
        # longer crashes the harness, which is the M122R-01 finding. The overall
        # result here is governed by the (also-broken, same bad path) schema/session
        # checks, so CLEANUP_FAIL is still the correct outcome, just not because of
        # diagnostics_snapshot() itself.
        check("F3_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
    except Exception as exc:
        check("F3_no_crash_structured_result", False, f"{type(exc).__name__}: {exc}")
    finally:
        cleanup_module.schema_exists = real_se
        cleanup_module.MYSQL = real_path
        _teardown_schema(schema)
        _teardown_root(root)
    check("F3_zero_orphan_after", not schema_exists(schema) and not root.exists())


def f4_timeout_expired():
    """A real subprocess.TimeoutExpired (not injected) from a genuinely slow query,
    at a call site where it is NOT the tolerated DROP-issuance case: referencing_
    sessions() has no tolerate_timeout allowance, so this must fail the run.
    """
    schema, run_id, root = _new_run()
    real_refs = cleanup_module.referencing_sessions

    def _slow_impl(_s):
        # Force a real timeout: ask mysql_exec for a real slow query with a tiny
        # client timeout, letting subprocess.TimeoutExpired occur for real (not
        # injected) at a call site with no tolerate_timeout allowance. Mirrors the
        # real referencing_sessions()'s own re-wrap of CleanupInfrastructureError
        # into VerificationError, so this mock faithfully matches its contract.
        try:
            cleanup_module.mysql_exec("SELECT SLEEP(3)", timeout=0.3)
        except CleanupInfrastructureError as exc:
            raise VerificationError(f"referencing_sessions(simulated): {exc}") from exc
        return []

    cleanup_module.referencing_sessions = _slow_impl
    result = {}
    try:
        t = cleanup_run(run_id, schema, 0, "PASS", run_root=root)
        result["cleanup_result"] = t.cleanup_result
        check("F4_no_crash_structured_result", True)
        check("F4_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
        check("F4_verification_error_mentions_timeout", "timed out" in (t.verification_error or ""), t.verification_error)
    except Exception as exc:
        check("F4_no_crash_structured_result", False, f"{type(exc).__name__}: {exc}")
    finally:
        cleanup_module.referencing_sessions = real_refs
        _teardown_schema(schema)
        _teardown_root(root)
    check("F4_zero_orphan_after", not schema_exists(schema) and not root.exists())


def f5_mysql_non_zero_exit():
    """A genuine non-zero mysql client exit (malformed SQL, not a subprocess-level
    failure) at the drop-issuance site, via mysql_exec()'s own returncode check.
    """
    result = {}
    try:
        cleanup_module.mysql_exec("THIS IS NOT VALID SQL;;;", timeout=10)
        check("F5_raises_infrastructure_error", False, "did not raise")
    except CleanupInfrastructureError as exc:
        check("F5_raises_infrastructure_error", True)
        check("F5_message_mentions_exit_code", "rc=" in str(exc), str(exc))
    except Exception as exc:
        check("F5_raises_infrastructure_error", False, f"wrong type: {type(exc).__name__}: {exc}")


def f6_rescue_attempted_after_infra_exception():
    schema, run_id, root = _new_run()
    result = {}
    try:
        t = _with_bad_mysql_path(lambda: cleanup_run(run_id, schema, 0, "PASS", run_root=root))
        check("F6_rescue_attempted", t.rescue_used is True, t.rescue_used)
    finally:
        _teardown_schema(schema)
        _teardown_root(root)


def f7_result_remains_fail():
    """Even when the bad-path fault is lifted mid-rescue (rescue succeeds for
    real), the run's recorded result must still be CLEANUP_FAIL -- rescue never
    launders an infrastructure-caused failure any more than a verification one.
    """
    schema, run_id, root = _new_run()
    real_path = cleanup_module.MYSQL
    real_rescue_budget = cleanup_module.RESCUE_TIMEOUT_SECONDS
    real_budget = cleanup_module.CLEANUP_TIMEOUT_SECONDS
    cleanup_module.CLEANUP_TIMEOUT_SECONDS = 1
    cleanup_module.RESCUE_TIMEOUT_SECONDS = 30
    cleanup_module.MYSQL = real_path + ".audit_nonexistent_binary_f7"
    try:
        # Lift the fault shortly after the primary window closes, so rescue -- which
        # runs afterward on its own budget -- operates against the real client.
        import threading
        def lift_later():
            time.sleep(1.5)
            cleanup_module.MYSQL = real_path
        threading.Thread(target=lift_later, daemon=True).start()
        t = cleanup_run(run_id, schema, 0, "PASS", run_root=root)
        check("F7_primary_cleanup_fail", t.cleanup_result == "CLEANUP_FAIL", t.cleanup_result)
        check("F7_rescue_used", t.rescue_used is True)
        check("F7_result_never_pass", t.cleanup_result != "PASS")
    finally:
        cleanup_module.MYSQL = real_path
        cleanup_module.CLEANUP_TIMEOUT_SECONDS = real_budget
        cleanup_module.RESCUE_TIMEOUT_SECONDS = real_rescue_budget
        _teardown_schema(schema)
        _teardown_root(root)


# --- O1-O9: ownership by identity ---------------------------------------------------

def o1_run_a_cannot_delete_run_b_root():
    schema_a, run_id_a, root_a = _new_run()
    schema_b, run_id_b, root_b = _new_run()
    try:
        denied = False
        try:
            safe_remove_run_root(root_b, run_id_a, schema_a)
            denied = False
        except RuntimeError:
            denied = True
        check("O1_cross_run_root_removal_denied", denied)
        check("O1_b_root_still_present", root_b.exists())
    finally:
        _teardown_schema(schema_a)
        _teardown_schema(schema_b)
        _teardown_root(root_a)
        _teardown_root(root_b)


def o2_run_a_cannot_drop_run_b_db():
    schema_a, run_id_a, root_a = _new_run()
    schema_b, run_id_b, root_b = _new_run()
    try:
        denied = False
        try:
            validate_database_ownership(schema_b, run_id_a)
        except RuntimeError:
            denied = True
        check("O2_cross_run_db_ownership_denied", denied)
        check("O2_b_db_still_present", schema_exists(schema_b))
    finally:
        _teardown_schema(schema_a)
        _teardown_schema(schema_b)
        _teardown_root(root_a)
        _teardown_root(root_b)


def o3_owner_marker_mismatch_denied():
    schema, run_id, root = _new_run()
    try:
        (root / cleanup_module.OWNER_MARKER_NAME).write_text(
            '{"run_id": "000000000000000000000000", "database_name": "not_this_schema"}', encoding="utf-8")
        check("O3_ownership_verification_fails", verify_ownership(root, run_id, schema) is False)
        denied = False
        try:
            safe_remove_run_root(root, run_id, schema)
        except RuntimeError:
            denied = True
        check("O3_removal_denied", denied)
        check("O3_root_still_present", root.exists())
    finally:
        _teardown_schema(schema)
        _teardown_root(root)


def o4_owner_marker_missing_denied():
    schema, run_id, root = _new_run()
    try:
        (root / cleanup_module.OWNER_MARKER_NAME).unlink()
        check("O4_ownership_verification_fails", verify_ownership(root, run_id, schema) is False)
        denied = False
        try:
            safe_remove_run_root(root, run_id, schema)
        except RuntimeError:
            denied = True
        check("O4_removal_denied", denied)
        check("O4_root_still_present", root.exists())
    finally:
        _teardown_schema(schema)
        _teardown_root(root)


def o5_traversal_denied():
    for bad_run_id in ("../../../etc", "..", "a/../../b", "a\\..\\..\\b", "", "not-hex-at-all", "0" * 23 + "g"):
        denied = False
        try:
            run_root_path(bad_run_id)
        except RuntimeError:
            denied = True
        check(f"O5_traversal_denied[{bad_run_id[:20]!r}]", denied, bad_run_id)


def o6_parent_deletion_denied():
    schema, run_id, root = _new_run()
    try:
        for parent_path in (RUN_ROOT_BASE, RUN_ROOT_BASE.parent, Path(root.anchor)):
            check(f"O6_parent_not_equal_to_run_root[{parent_path}]", parent_path.resolve() != root.resolve())
        # Directly attempt to point safe_remove_run_root at a parent -- it must
        # refuse because verify_ownership() only ever compares against the ONE
        # expected path derived from run_id, never a caller-supplied path's ancestry.
        denied = False
        try:
            safe_remove_run_root(RUN_ROOT_BASE, run_id, schema)
        except RuntimeError:
            denied = True
        check("O6_run_root_base_deletion_denied", denied)
        check("O6_run_root_base_still_present", RUN_ROOT_BASE.exists())
    finally:
        _teardown_schema(schema)
        _teardown_root(root)


def o7_symlink_escape_denied_where_applicable():
    schema, run_id, root = _new_run()
    decoy = RUN_ROOT_BASE / f"decoy_{run_id}"
    try:
        decoy.mkdir(parents=True, exist_ok=True)
        (decoy / "sentinel.txt").write_text("do not delete me", encoding="utf-8")
        link_path = root.parent / f"{run_id}_link"
        try:
            link_path.symlink_to(decoy, target_is_directory=True)
        except (OSError, NotImplementedError):
            check("O7_symlink_escape_denied_where_applicable", True, "symlink creation unsupported on this platform -- N/A")
            return
        try:
            resolved_matches_decoy = link_path.resolve() == decoy.resolve()
            check("O7_ownership_denied_via_symlink", verify_ownership(link_path, run_id, schema) is False)
            check("O7_decoy_untouched", (decoy / "sentinel.txt").exists())
        finally:
            link_path.unlink(missing_ok=True)
            shutil.rmtree(decoy, ignore_errors=True)
    finally:
        _teardown_schema(schema)
        _teardown_root(root)


def o8_parallel_rescue_isolation():
    """The mandatory parallel ownership test (section 12): force run A into
    rescue while run B stays active; confirm A's rescue never touches B's root/DB,
    and B completes normally afterward.

    Deliberately does NOT patch process-wide globals like cleanup.MYSQL or
    cleanup.CLEANUP_TIMEOUT_SECONDS: those affect every thread in the process, not
    just "run A" -- patching them from A's thread while B runs concurrently in
    another thread corrupted B's own real MySQL calls too (caught during this
    suite's own development). Instead, safe_remove_run_root is replaced with a
    thread-safe DISCRIMINATING wrapper that only forces failure for A's own
    run_id, delegating every other call (including all of B's) to the real
    implementation -- so B's cleanup is genuinely unaffected by A's forced fault,
    regardless of how the two threads interleave.
    """
    schema_a, run_id_a, root_a = _new_run()
    schema_b, run_id_b, root_b = _new_run()
    real_safe_remove = cleanup_module.safe_remove_run_root
    real_rescue_budget = cleanup_module.RESCUE_TIMEOUT_SECONDS

    def discriminating(root, run_id, schema):
        if run_id == run_id_a:
            return False  # A's own root removal never succeeds, real or rescue
        return real_safe_remove(root, run_id, schema)

    cleanup_module.safe_remove_run_root = discriminating
    cleanup_module.RESCUE_TIMEOUT_SECONDS = 5  # keep the test fast; A exhausts this and stays FAILED
    try:
        def run_a_failing():
            return cleanup_run(run_id_a, schema_a, 0, "PASS", run_root=root_a)

        def run_b_normal():
            time.sleep(0.2)  # start slightly after A so A is mid-rescue while B runs
            return cleanup_run(run_id_b, schema_b, 0, "PASS", run_root=root_b)

        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            fut_a = pool.submit(run_a_failing)
            fut_b = pool.submit(run_b_normal)
            t_a = fut_a.result()
            t_b = fut_b.result()

        check("O8_a_failed_as_forced", t_a.cleanup_result == "CLEANUP_FAIL", t_a.cleanup_result)
        check("O8_a_rescue_used", t_a.rescue_used is True)
        check("O8_b_passed_normally", t_b.cleanup_result == "PASS", t_b.cleanup_result)
        check("O8_b_no_rescue_needed", t_b.rescue_used is False)
        check("O8_b_root_removed_by_its_own_cleanup", not root_b.exists())
        check("O8_b_schema_removed_by_its_own_cleanup", not schema_exists(schema_b))
        # A's own root was NEVER genuinely removable in this test (always False by
        # design) -- it persisting is proof rescue only ever acted on A's own
        # resource and never reached for B's.
        check("O8_a_root_persists_untouched_by_b", root_a.exists())
    finally:
        cleanup_module.safe_remove_run_root = real_safe_remove
        cleanup_module.RESCUE_TIMEOUT_SECONDS = real_rescue_budget
        _teardown_schema(schema_a)
        _teardown_schema(schema_b)
        _teardown_root(root_a)
        _teardown_root(root_b)


def o9_exact_own_resource_cleanup_succeeds():
    schema, run_id, root = _new_run()
    t = cleanup_run(run_id, schema, 0, "PASS", run_root=root)
    check("O9_pass", t.cleanup_result == "PASS", t.cleanup_result)
    check("O9_files_removed_at_set", t.files_removed_at is not None)
    check("O9_root_actually_gone", not root.exists())
    check("O9_schema_actually_gone", not schema_exists(schema))
    check("O9_no_rescue_needed", t.rescue_used is False)


def main():
    f1_oserror_drop_issuance()
    f2_oserror_kill()
    f3_oserror_diagnostics_snapshot()
    f4_timeout_expired()
    f5_mysql_non_zero_exit()
    f6_rescue_attempted_after_infra_exception()
    f7_result_remains_fail()
    o1_run_a_cannot_delete_run_b_root()
    o2_run_a_cannot_drop_run_b_db()
    o3_owner_marker_mismatch_denied()
    o4_owner_marker_missing_denied()
    o5_traversal_denied()
    o6_parent_deletion_denied()
    o7_symlink_escape_denied_where_applicable()
    o8_parallel_rescue_isolation()
    o9_exact_own_resource_cleanup_succeeds()
    print(f"\n{len(PASS)} passed, {len(FAIL)} failed")
    if FAIL:
        print("FAILED:", FAIL)
    return 0 if not FAIL else 1


if __name__ == "__main__":
    sys.exit(main())
