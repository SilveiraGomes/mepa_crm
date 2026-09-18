"""Diagnostic follow-up: why did BOTH concurrent runs in s10_parallel_barrier_isolation
report CLEANUP_FAIL with no fault injected? Capture full telemetry this time."""
import concurrent.futures
import json
import shutil
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "wave4-m121"))
import cleanup  # noqa: E402

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m122r_audit"


def sql(statement, timeout=30):
    r = cleanup._mysql(statement, timeout=timeout)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout


def real_teardown(schema):
    if cleanup.schema_exists(schema):
        sql(f"DROP DATABASE IF EXISTS `{schema}`", timeout=30)


def main():
    schema_a = cleanup.new_schema("wave4")
    schema_b = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema_a}`")
    sql(f"CREATE DATABASE `{schema_b}`")
    barrier_a = Path(tempfile.gettempdir()) / f"mepa_wave4_m12_diag_a_{schema_a.rsplit('_', 1)[-1]}"
    barrier_b = Path(tempfile.gettempdir()) / f"mepa_wave4_m12_diag_b_{schema_b.rsplit('_', 1)[-1]}"
    barrier_a.mkdir()
    barrier_b.mkdir()
    try:
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            fut_a = pool.submit(cleanup.cleanup_run, "diag_a", schema_a, 0, "PASS", None, [barrier_a])
            fut_b = pool.submit(cleanup.cleanup_run, "diag_b", schema_b, 0, "PASS", None, [barrier_b])
            t_a = fut_a.result()
            t_b = fut_b.result()
        for label, t in (("A", t_a), ("B", t_b)):
            print(f"=== {label} ===")
            print(json.dumps({
                "cleanup_result": t.cleanup_result, "state": t.state,
                "verification_error": t.verification_error, "sla_exceeded": t.sla_exceeded,
                "final_database_state": t.final_database_state,
                "final_connections_state": t.final_connections_state,
                "final_barriers_state": t.final_barriers_state,
                "candidate_barriers": t.candidate_barriers,
                "blocking_sessions_seen": t.blocking_sessions_seen,
                "cleanup_duration_ms": t.cleanup_duration_ms,
                "drop_attempts": t.drop_attempts,
                "database_dropped_at": t.database_dropped_at,
                "connections_zero_at": t.connections_zero_at,
                "files_removed_at": t.files_removed_at,
            }, indent=2, default=str))
    finally:
        real_teardown(schema_a)
        real_teardown(schema_b)
        for b in (barrier_a, barrier_b):
            if b.exists():
                shutil.rmtree(b, ignore_errors=True)


if __name__ == "__main__":
    main()
