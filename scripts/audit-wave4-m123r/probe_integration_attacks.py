"""Independent P0.3.4-M1.2.3-R probes, integration-level (through cleanup_run()
itself, not the isolated guard functions the executor's own O1/O2 tests exercise
directly) plus the unexpected-exception boundary with a genuinely novel exception
type never referenced anywhere in cleanup.py.
"""
import json
import sys
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


def s18_cross_run_database_attack_via_cleanup_run():
    """Section 18: try to make run A's cleanup_run() call operate on run B's
    database by passing B's schema alongside A's run_id -- through the full
    public entry point, not just the isolated guard.
    """
    schema_a = cleanup.new_schema("wave4")
    run_id_a = cleanup.run_id_of(schema_a)
    schema_b = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema_a}`")
    sql(f"CREATE DATABASE `{schema_b}`")
    root_a = cleanup.create_run_root(run_id_a, schema_a)
    result = {}
    try:
        try:
            # A's run_id, but B's schema/database as the target.
            cleanup.cleanup_run(run_id_a, schema_b, 0, "PASS", run_root=root_a)
            result["denied"] = False
        except RuntimeError as exc:
            result["denied"] = True
            result["message"] = str(exc)
        result["b_database_untouched"] = cleanup.schema_exists(schema_b)
    finally:
        teardown_schema(schema_a)
        teardown_schema(schema_b)
        teardown_root(root_a)
    return result


def s19_cross_run_file_attack_via_cleanup_run():
    """Section 19: try to make run A's cleanup_run() operate on run B's
    run_root by passing it explicitly as A's run_root argument."""
    schema_a = cleanup.new_schema("wave4")
    run_id_a = cleanup.run_id_of(schema_a)
    schema_b = cleanup.new_schema("wave4")
    run_id_b = cleanup.run_id_of(schema_b)
    sql(f"CREATE DATABASE `{schema_a}`")
    sql(f"CREATE DATABASE `{schema_b}`")
    root_a = cleanup.create_run_root(run_id_a, schema_a)
    root_b = cleanup.create_run_root(run_id_b, schema_b)
    result = {}
    try:
        # A's own identity, but B's run_root object passed in as the target.
        t = cleanup.cleanup_run(run_id_a, schema_a, 0, "PASS", run_root=root_b)
        result["cleanup_result"] = t.cleanup_result
        result["b_root_untouched"] = root_b.exists()
        result["b_database_untouched"] = cleanup.schema_exists(schema_b)
        # A's own database WAS legitimately dropped (that part of primary
        # cleanup doesn't depend on run_root at all) -- but A's OWN root
        # ownership check against root_b's path must fail (root_b's path !=
        # run_root_path(run_id_a)), so A's cleanup must NOT reach PASS.
        result["a_database_dropped_anyway"] = not cleanup.schema_exists(schema_a)
    finally:
        teardown_schema(schema_a)
        teardown_schema(schema_b)
        teardown_root(root_a)
        teardown_root(root_b)
    return result


class TotallyUnrelatedBug(Exception):
    """A type cleanup.py has never heard of -- not RuntimeError-derived even,
    to make sure it isn't accidentally caught by any of the module's except
    clauses (CleanupInfrastructureError/VerificationError both derive from
    RuntimeError; this deliberately does not)."""


def s9_unexpected_exception_boundary():
    schema = cleanup.new_schema("wave4")
    run_id = cleanup.run_id_of(schema)
    sql(f"CREATE DATABASE `{schema}`")
    root = cleanup.create_run_root(run_id, schema)
    real_refs = cleanup.referencing_sessions

    def exploding(_schema):
        raise TotallyUnrelatedBug("a genuine programming bug, not a modeled failure")

    cleanup.referencing_sessions = exploding
    result = {}
    try:
        try:
            cleanup.cleanup_run(run_id, schema, 0, "PASS", run_root=root)
            result["raised"] = False
        except TotallyUnrelatedBug:
            result["raised"] = True
        except Exception as exc:
            result["raised"] = "wrong_type"
            result["exception_type"] = type(exc).__name__
    finally:
        cleanup.referencing_sessions = real_refs
        # Confirm the defensive rescue actually ran and cleaned up despite the
        # bug: real referencing_sessions is restored now, so a real rescue
        # attempt (mirroring what cleanup_run's except Exception: block did
        # internally) should find the schema already gone if the defensive
        # rescue worked.
        result["schema_gone_after_bug"] = not cleanup.schema_exists(schema)
        result["root_gone_after_bug"] = not root.exists()
        teardown_schema(schema)
        teardown_root(root)
    return result


def s26_owner_marker_corrupted_binary_garbage():
    """Independent fault-injection variant beyond the executor's own O3/O4:
    a marker file that isn't even valid UTF-8/JSON at all (raw garbage bytes),
    not just a well-formed-but-wrong JSON payload."""
    schema = cleanup.new_schema("wave4")
    run_id = cleanup.run_id_of(schema)
    sql(f"CREATE DATABASE `{schema}`")
    root = cleanup.create_run_root(run_id, schema)
    result = {}
    try:
        (root / cleanup.OWNER_MARKER_NAME).write_bytes(b"\xff\xfe\x00\x01garbage-not-json\xdd")
        try:
            owned = cleanup.verify_ownership(root, run_id, schema)
            result["verify_ownership_result"] = owned
        except cleanup.CleanupInfrastructureError as exc:
            result["verify_ownership_raised_infra_error"] = True
            result["message"] = str(exc)
        denied = False
        try:
            cleanup.safe_remove_run_root(root, run_id, schema)
        except (RuntimeError, cleanup.CleanupInfrastructureError):
            denied = True
        result["removal_denied_or_errored"] = denied
        result["root_still_present"] = root.exists()
    finally:
        teardown_schema(schema)
        teardown_root(root)
    return result


def main():
    report = {}
    for name, fn in [
        ("s18_cross_run_database_attack_via_cleanup_run", s18_cross_run_database_attack_via_cleanup_run),
        ("s19_cross_run_file_attack_via_cleanup_run", s19_cross_run_file_attack_via_cleanup_run),
        ("s9_unexpected_exception_boundary", s9_unexpected_exception_boundary),
        ("s26_owner_marker_corrupted_binary_garbage", s26_owner_marker_corrupted_binary_garbage),
    ]:
        print(f"--- {name} ---", flush=True)
        try:
            report[name] = fn()
        except Exception as exc:
            report[name] = {"PROBE_CRASHED": True, "type": type(exc).__name__, "msg": str(exc)}
        print(json.dumps({name: report[name]}, indent=2), flush=True)
    (OUT / "integration_attacks_probe.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
