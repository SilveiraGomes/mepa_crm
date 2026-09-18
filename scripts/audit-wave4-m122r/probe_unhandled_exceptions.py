"""Independent P0.3.4-M1.2.2-R probe: does an OSError (not just the already-handled
subprocess.TimeoutExpired) at any of the three _mysql() call sites that lack their
own try/except -- the DROP-issuance line inside drop_database_verified(), the KILL
line inside kill_orphan_sessions(), and diagnostics_snapshot() (called whenever
out_dir is passed, which every real qualification run does via run_suite.py) --
propagate as a raw, uncaught exception out of cleanup_run(), instead of being turned
into a VerificationError -> CLEANUP_FAIL like every other verification failure in
this module?

This is read-only against the executor's code (no modification). Disposable,
never touches production.
"""
import json
import sys
import traceback
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "wave4-m121"))
import cleanup  # noqa: E402

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m122r_audit"
OUT.mkdir(parents=True, exist_ok=True)


def sql(statement, timeout=30):
    r = cleanup._mysql(statement, timeout=timeout)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout


def probe_drop_issuance_oserror():
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_mysql = cleanup._mysql

    def injected(statement, timeout=30):
        if statement.startswith("DROP DATABASE"):
            raise OSError("simulated: mysql.exe unavailable during DROP issuance")
        return real_mysql(statement, timeout=timeout)

    cleanup._mysql = injected
    result = {"scenario": "drop_issuance_oserror"}
    try:
        telemetry = cleanup.cleanup_run("audit122_drop_oserror", schema, 0, "PASS")
        result["crashed"] = False
        result["cleanup_result"] = telemetry.cleanup_result
        result["state"] = telemetry.state
        result["rescue_used"] = telemetry.rescue_used
    except Exception as exc:
        result["crashed"] = True
        result["exception_type"] = type(exc).__name__
        result["exception_str"] = str(exc)
        result["traceback"] = traceback.format_exc()
    finally:
        cleanup._mysql = real_mysql
        # Manual teardown regardless of crash -- prove whether the schema was
        # actually left behind because rescue never ran.
        exists_before_manual_teardown = cleanup.schema_exists(schema)
        result["schema_orphaned_before_manual_teardown"] = exists_before_manual_teardown
        if exists_before_manual_teardown:
            sql(f"DROP DATABASE IF EXISTS `{schema}`")
        result["schema_absent_after_manual_teardown"] = not cleanup.schema_exists(schema)
    return result


def probe_kill_oserror():
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_refs = cleanup.referencing_sessions
    real_mysql = cleanup._mysql

    # Force a synthetic "session present" so kill_orphan_sessions() is actually
    # invoked, then make the KILL statement itself explode.
    cleanup.referencing_sessions = lambda _s: [{"id": 999999999, "user": "synthetic",
                                                 "host": "-", "command": "Sleep", "time": "0", "state": ""}]

    def injected(statement, timeout=30):
        if statement.startswith("KILL "):
            raise OSError("simulated: mysql.exe unavailable during KILL")
        return real_mysql(statement, timeout=timeout)

    cleanup._mysql = injected
    result = {"scenario": "kill_oserror"}
    real_budget = cleanup.CLEANUP_TIMEOUT_SECONDS
    cleanup.CLEANUP_TIMEOUT_SECONDS = 2
    try:
        telemetry = cleanup.cleanup_run("audit122_kill_oserror", schema, 0, "PASS")
        result["crashed"] = False
        result["cleanup_result"] = telemetry.cleanup_result
        result["state"] = telemetry.state
        result["rescue_used"] = telemetry.rescue_used
    except Exception as exc:
        result["crashed"] = True
        result["exception_type"] = type(exc).__name__
        result["exception_str"] = str(exc)
        result["traceback"] = traceback.format_exc()
    finally:
        cleanup._mysql = real_mysql
        cleanup.referencing_sessions = real_refs
        cleanup.CLEANUP_TIMEOUT_SECONDS = real_budget
        exists_before_manual_teardown = cleanup.schema_exists(schema)
        result["schema_orphaned_before_manual_teardown"] = exists_before_manual_teardown
        if exists_before_manual_teardown:
            sql(f"DROP DATABASE IF EXISTS `{schema}`")
        result["schema_absent_after_manual_teardown"] = not cleanup.schema_exists(schema)
    return result


def probe_diagnostics_snapshot_oserror():
    """out_dir is passed on EVERY real qualification run (run_suite.py always
    passes out_dir=OUT), so diagnostics_snapshot() runs for real whenever a DROP
    attempt doesn't immediately succeed -- this is not just a unit-test corner case.
    """
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_mysql = cleanup._mysql
    real_schema_exists = cleanup.schema_exists
    call_count = {"n": 0}

    def fake_schema_exists(s):
        # Force at least one non-terminal loop iteration in drop_database_verified
        # so diagnostics_snapshot() actually gets invoked (out_dir is not None).
        call_count["n"] += 1
        if call_count["n"] == 1:
            return True
        return real_schema_exists(s)

    def injected(statement, timeout=30):
        if statement.startswith("SELECT id, user, host, db, command"):
            raise OSError("simulated: mysql.exe unavailable during diagnostics_snapshot")
        return real_mysql(statement, timeout=timeout)

    cleanup.schema_exists = fake_schema_exists
    cleanup._mysql = injected
    result = {"scenario": "diagnostics_snapshot_oserror"}
    try:
        telemetry = cleanup.cleanup_run("audit122_diag_oserror", schema, 0, "PASS", out_dir=OUT)
        result["crashed"] = False
        result["cleanup_result"] = telemetry.cleanup_result
        result["state"] = telemetry.state
        result["rescue_used"] = telemetry.rescue_used
    except Exception as exc:
        result["crashed"] = True
        result["exception_type"] = type(exc).__name__
        result["exception_str"] = str(exc)
        result["traceback"] = traceback.format_exc()
    finally:
        cleanup._mysql = real_mysql
        cleanup.schema_exists = real_schema_exists
        exists_before_manual_teardown = cleanup.schema_exists(schema)
        result["schema_orphaned_before_manual_teardown"] = exists_before_manual_teardown
        if exists_before_manual_teardown:
            sql(f"DROP DATABASE IF EXISTS `{schema}`")
        result["schema_absent_after_manual_teardown"] = not cleanup.schema_exists(schema)
    return result


def main():
    report = {
        "drop_issuance": probe_drop_issuance_oserror(),
        "kill": probe_kill_oserror(),
        "diagnostics_snapshot": probe_diagnostics_snapshot_oserror(),
    }
    path = OUT / "unhandled_exceptions_probe.json"
    path.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({k: {kk: vv for kk, vv in v.items() if kk != "traceback"} for k, v in report.items()}, indent=2))


if __name__ == "__main__":
    main()
