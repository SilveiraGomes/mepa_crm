"""P0.3.4-M1.2.3 section 20 -- 10 runs with intentional infrastructure failure.

Cycles through the three previously-unguarded call sites (M122R-01: DROP issuance,
session KILL, diagnostics_snapshot) plus a genuine non-zero mysql exit, injecting a
REAL OSError (bad binary path) or a real client failure for each run. Every run
must: never crash (always return a structured RunTelemetry), report
cleanup_result == CLEANUP_FAIL, attempt rescue, and leave 0 orphan resources behind
once rescue (or manual teardown) completes.
"""
import json
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import cleanup as cleanup_module  # noqa: E402
from cleanup import _mysql, cleanup_run, new_schema, run_id_of, schema_exists  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "docs/database/physical/wave4_m123_qualification"
OUT.mkdir(parents=True, exist_ok=True)


def _teardown_schema(schema):
    if schema_exists(schema):
        _mysql(f"DROP DATABASE IF EXISTS `{schema}`", timeout=30)


def _teardown_root(root):
    if root.exists():
        import shutil
        shutil.rmtree(root, ignore_errors=True)


def _new_run():
    schema = new_schema("wave4")
    run_id = run_id_of(schema)
    _mysql(f"CREATE DATABASE `{schema}`")
    root = cleanup_module.create_run_root(run_id, schema)
    return schema, run_id, root


def run_with_bad_binary(n: int) -> dict:
    schema, run_id, root = _new_run()
    real_path = cleanup_module.MYSQL
    real_rescue = cleanup_module.RESCUE_TIMEOUT_SECONDS
    cleanup_module.MYSQL = real_path + f".audit_bad_binary_{n}"
    cleanup_module.RESCUE_TIMEOUT_SECONDS = 5
    result = {"run": n, "fault": "bad_mysql_binary"}
    try:
        t = cleanup_run(run_id, schema, 0, "PASS", run_root=root)
        result["crashed"] = False
        result["cleanup_result"] = t.cleanup_result
        result["rescue_used"] = t.rescue_used
    except Exception as exc:
        result["crashed"] = True
        result["exception"] = f"{type(exc).__name__}: {exc}"
    finally:
        cleanup_module.MYSQL = real_path
        cleanup_module.RESCUE_TIMEOUT_SECONDS = real_rescue
        _teardown_schema(schema)
        _teardown_root(root)
    result["zero_orphan"] = not schema_exists(schema) and not root.exists()
    return result


def run_with_held_session_and_kill_fault(n: int) -> dict:
    schema, run_id, root = _new_run()
    holder = subprocess.Popen(
        [cleanup_module.MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306",
         "--execute=SELECT SLEEP(4)", f"--database={schema}"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    )
    time.sleep(0.3)
    real_mysql_fn = cleanup_module._mysql
    real_rescue = cleanup_module.RESCUE_TIMEOUT_SECONDS
    cleanup_module.RESCUE_TIMEOUT_SECONDS = 10

    def injected(statement, timeout=30):
        if statement.startswith("KILL "):
            raise OSError(f"simulated KILL failure (run {n})")
        return real_mysql_fn(statement, timeout=timeout)

    cleanup_module._mysql = injected
    result = {"run": n, "fault": "kill_oserror"}
    try:
        t = cleanup_run(run_id, schema, 0, "PASS", run_root=root)
        result["crashed"] = False
        result["cleanup_result"] = t.cleanup_result
        result["rescue_used"] = t.rescue_used
    except Exception as exc:
        result["crashed"] = True
        result["exception"] = f"{type(exc).__name__}: {exc}"
    finally:
        cleanup_module._mysql = real_mysql_fn
        cleanup_module.RESCUE_TIMEOUT_SECONDS = real_rescue
        try:
            holder.wait(timeout=10)
        except subprocess.TimeoutExpired:
            holder.kill()
        _teardown_schema(schema)
        _teardown_root(root)
    result["zero_orphan"] = not schema_exists(schema) and not root.exists()
    return result


def run_with_non_zero_exit(n: int) -> dict:
    """A genuine mysql client non-zero exit (malformed statement), not a
    subprocess-level OSError."""
    schema, run_id, root = _new_run()
    real_mysql_fn = cleanup_module._mysql
    real_rescue = cleanup_module.RESCUE_TIMEOUT_SECONDS
    cleanup_module.RESCUE_TIMEOUT_SECONDS = 5

    def injected(statement, timeout=30):
        if statement.startswith("SELECT SCHEMA_NAME"):
            return real_mysql_fn("THIS IS NOT VALID SQL;;;", timeout=timeout)
        return real_mysql_fn(statement, timeout=timeout)

    cleanup_module._mysql = injected
    result = {"run": n, "fault": "mysql_non_zero_exit"}
    try:
        t = cleanup_run(run_id, schema, 0, "PASS", run_root=root)
        result["crashed"] = False
        result["cleanup_result"] = t.cleanup_result
        result["rescue_used"] = t.rescue_used
    except Exception as exc:
        result["crashed"] = True
        result["exception"] = f"{type(exc).__name__}: {exc}"
    finally:
        cleanup_module._mysql = real_mysql_fn
        cleanup_module.RESCUE_TIMEOUT_SECONDS = real_rescue
        _teardown_schema(schema)
        _teardown_root(root)
    result["zero_orphan"] = not schema_exists(schema) and not root.exists()
    return result


def main():
    rows = []
    for i in range(1, 5):
        rows.append(run_with_bad_binary(i))
    for i in range(1, 4):
        rows.append(run_with_held_session_and_kill_fault(i))
    for i in range(1, 4):
        rows.append(run_with_non_zero_exit(i))

    for r in rows:
        print(json.dumps(r), flush=True)

    (OUT / "failure_qualification.json").write_text(json.dumps(rows, indent=2) + "\n", encoding="utf-8")
    total = len(rows)
    never_crashed = sum(not r["crashed"] for r in rows)
    all_failed = sum((not r["crashed"]) and r.get("cleanup_result") == "CLEANUP_FAIL" for r in rows)
    all_rescued = sum((not r["crashed"]) and r.get("rescue_used") is True for r in rows)
    all_clean = sum(r["zero_orphan"] for r in rows)
    summary = {
        "total_runs": total, "never_crashed": never_crashed, "all_reported_fail": all_failed,
        "all_rescue_attempted": all_rescued, "all_zero_orphan": all_clean,
    }
    print(json.dumps(summary, indent=2))
    ok = never_crashed == total and all_failed == total and all_rescued == total and all_clean == total
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
