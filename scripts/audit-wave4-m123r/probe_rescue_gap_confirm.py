"""Clean, timed, definitive reproduction of the two gaps found by
probe_integration_attacks.py:

1. rescue_cleanup() crashes on its own FIRST step (the session check) when
   referencing_sessions() raises anything other than VerificationError/
   CleanupInfrastructureError -- meaning rescue never even attempts the DROP
   DATABASE call, despite that call not depending on the broken step at all.
   Confirmed here WITHOUT relying on the test's own external teardown to mask
   the outcome: checks resource state immediately after cleanup_run() raises,
   before any manual cleanup.

2. An ownership mismatch between run_root and run_id/schema (a caller bug,
   not an infra failure) makes the defensive rescue attempt retry uselessly
   for the FULL RESCUE_TIMEOUT_SECONDS budget before re-raising, because
   rescue's own ownership-check failure is caught and treated identically to
   "maybe transient, keep polling" rather than "structurally undecidable,
   stop immediately". Timed precisely here.
"""
import json
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "wave4-m121"))
import cleanup  # noqa: E402

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m123r_audit"
OUT.mkdir(parents=True, exist_ok=True)


def sql(statement, timeout=30):
    r = cleanup._mysql(statement, timeout=timeout)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout


def teardown_schema(schema):
    if cleanup.schema_exists(schema):
        sql(f"DROP DATABASE IF EXISTS `{schema}`")


def teardown_root(root):
    if root.exists():
        import shutil
        shutil.rmtree(root, ignore_errors=True)


class NovelBug(Exception):
    pass


def gap1_rescue_crashes_on_first_step_no_cleanup_attempted():
    schema = cleanup.new_schema("wave4")
    run_id = cleanup.run_id_of(schema)
    sql(f"CREATE DATABASE `{schema}`")
    root = cleanup.create_run_root(run_id, schema)
    real_refs = cleanup.referencing_sessions

    def exploding(_schema):
        raise NovelBug("unmodeled bug in referencing_sessions")

    cleanup.referencing_sessions = exploding
    result = {}
    try:
        try:
            cleanup.cleanup_run(run_id, schema, 0, "PASS", run_root=root)
            result["raised"] = False
        except NovelBug:
            result["raised"] = True
        # Restore the fault BEFORE checking state, since schema_exists() does
        # not depend on referencing_sessions at all -- this checks state
        # immediately after cleanup_run() raised, before any manual teardown,
        # i.e. exactly what a live run_suite.py flow would be left with.
        cleanup.referencing_sessions = real_refs
        result["schema_still_present_immediately_after"] = cleanup.schema_exists(schema)
        result["root_still_present_immediately_after"] = root.exists()
    finally:
        cleanup.referencing_sessions = real_refs
        teardown_schema(schema)
        teardown_root(root)
    return result


def gap2_ownership_mismatch_futile_retry_timing():
    schema_a = cleanup.new_schema("wave4")
    run_id_a = cleanup.run_id_of(schema_a)
    schema_b = cleanup.new_schema("wave4")
    run_id_b = cleanup.run_id_of(schema_b)
    sql(f"CREATE DATABASE `{schema_a}`")
    sql(f"CREATE DATABASE `{schema_b}`")
    root_a = cleanup.create_run_root(run_id_a, schema_a)
    root_b = cleanup.create_run_root(run_id_b, schema_b)
    result = {}
    t0 = time.monotonic()
    try:
        try:
            cleanup.cleanup_run(run_id_a, schema_a, 0, "PASS", run_root=root_b)
            result["raised"] = False
        except RuntimeError:
            result["raised"] = True
        result["elapsed_seconds"] = round(time.monotonic() - t0, 1)
        result["rescue_timeout_budget"] = cleanup.RESCUE_TIMEOUT_SECONDS
        result["b_root_untouched"] = root_b.exists()
        result["b_database_untouched"] = cleanup.schema_exists(schema_b)
    finally:
        teardown_schema(schema_a)
        teardown_schema(schema_b)
        teardown_root(root_a)
        teardown_root(root_b)
    return result


def main():
    report = {
        "gap1_rescue_crashes_on_first_step": gap1_rescue_crashes_on_first_step_no_cleanup_attempted(),
        "gap2_ownership_mismatch_futile_retry_timing": gap2_ownership_mismatch_futile_retry_timing(),
    }
    print(json.dumps(report, indent=2))
    (OUT / "rescue_gap_confirm_probe.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
