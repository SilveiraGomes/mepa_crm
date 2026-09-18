"""Independent, disposable fault probes for the M1.2.1 cleanup contract."""
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

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m121r_audit"
OUT.mkdir(parents=True, exist_ok=True)


def sql(statement):
    result = cleanup._mysql(statement)
    if result.returncode:
        raise RuntimeError(result.stderr)
    return result.stdout


def main():
    report = {"guards": {}, "probes": {}}
    for name in ("laravel", "mysql", "information_schema", "performance_schema", "sys", "arbitrary"):
        try:
            cleanup.cleanup_run("audit", name, 0, "PASS")
        except RuntimeError as error:
            report["guards"][name] = str(error)
        else:
            report["guards"][name] = "UNEXPECTEDLY ALLOWED"

    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_mysql = cleanup._mysql
    try:
        def injected_mysql(statement, timeout=30):
            if statement.startswith("DROP DATABASE"):
                return subprocess.CompletedProcess([], 1, "", "injected DROP failure")
            if statement.startswith("SELECT SCHEMA_NAME"):
                return subprocess.CompletedProcess([], 1, "", "injected schema query failure")
            return real_mysql(statement, timeout=timeout)

        cleanup._mysql = injected_mysql
        telemetry = cleanup.cleanup_run("audit_false_absence", schema, 0, "PASS")
        cleanup._mysql = real_mysql
        report["probes"]["false_absence"] = {
            "cleanup_result": telemetry.cleanup_result,
            "state": telemetry.state,
            "schema_still_exists": cleanup.schema_exists(schema),
            "drop_attempts": telemetry.drop_attempts,
        }
    finally:
        cleanup._mysql = real_mysql
        sql(f"DROP DATABASE IF EXISTS `{schema}`")

    schema = cleanup.new_schema("wave4")
    run_id = schema.rsplit("_", 1)[-1]
    barrier = Path(tempfile.gettempdir()) / f"mepa_wave4_m12_{run_id}"
    sql(f"CREATE DATABASE `{schema}`")
    barrier.mkdir()
    (barrier / f"{run_id}_1_ready").write_text("ready", encoding="utf-8")
    try:
        telemetry = cleanup.cleanup_run(run_id, schema, 0, "PASS")
        report["probes"]["barrier_survives"] = {
            "cleanup_result": telemetry.cleanup_result,
            "state": telemetry.state,
            "files_removed_at": telemetry.files_removed_at,
            "barrier_still_exists": barrier.exists(),
            "schema_still_exists": cleanup.schema_exists(schema),
        }
    finally:
        if cleanup.schema_exists(schema):
            sql(f"DROP DATABASE IF EXISTS `{schema}`")
        shutil.rmtree(barrier)

    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    real_budget = cleanup.CLEANUP_TIMEOUT_SECONDS
    real_mysql = cleanup._mysql
    try:
        cleanup.CLEANUP_TIMEOUT_SECONDS = 0.01

        def delayed_drop(statement, timeout=30):
            if statement.startswith("DROP DATABASE"):
                time.sleep(0.06)
            return real_mysql(statement, timeout=timeout)

        cleanup._mysql = delayed_drop
        telemetry = cleanup.cleanup_run("audit_sla", schema, 0, "PASS")
        report["probes"]["sla_overrun"] = {
            "cleanup_result": telemetry.cleanup_result,
            "configured_budget_ms": 10,
            "actual_cleanup_duration_ms": telemetry.cleanup_duration_ms,
            "schema_still_exists": real_mysql(
                f"SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = '{schema}'"
            ).stdout.strip() == schema,
        }
    finally:
        cleanup._mysql = real_mysql
        cleanup.CLEANUP_TIMEOUT_SECONDS = real_budget
        if cleanup.schema_exists(schema):
            sql(f"DROP DATABASE IF EXISTS `{schema}`")

    schema = cleanup.new_schema("wave4")
    real_refs = cleanup.referencing_sessions
    real_kill = cleanup.kill_orphan_sessions
    real_drain_budget = cleanup.CONNECTION_DRAIN_TIMEOUT_SECONDS
    try:
        cleanup.CONNECTION_DRAIN_TIMEOUT_SECONDS = 0.01
        cleanup.referencing_sessions = lambda _schema: [{"id": 123456, "user": "synthetic"}]
        cleanup.kill_orphan_sessions = lambda _schema, sessions: []
        telemetry = cleanup.RunTelemetry(run_id="audit_drain", schema=schema)
        cleanup.drain_connections(schema, telemetry)
        report["probes"]["false_drain"] = {
            "connections_zero_at_recorded": telemetry.connections_zero_at is not None,
            "sessions_after_kill_recorded": bool(telemetry.blocking_sessions_seen[-1].get("sessions_after_kill")),
        }
    finally:
        cleanup.referencing_sessions = real_refs
        cleanup.kill_orphan_sessions = real_kill
        cleanup.CONNECTION_DRAIN_TIMEOUT_SECONDS = real_drain_budget

    path = OUT / "invariant_probes.json"
    path.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
