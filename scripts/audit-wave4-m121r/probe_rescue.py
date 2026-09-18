"""Force primary cleanup failure while allowing a real rescue DROP."""
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "wave4-m121"))
import cleanup  # noqa: E402

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m121r_audit"
OUT.mkdir(parents=True, exist_ok=True)


def main():
    schema = cleanup.new_schema("wave4")
    created = cleanup._mysql(f"CREATE DATABASE `{schema}`")
    if created.returncode:
        raise RuntimeError(created.stderr)
    original = cleanup.drop_database_verified
    try:
        cleanup.drop_database_verified = lambda *args, **kwargs: False
        telemetry = cleanup.cleanup_run("forced_primary_fail", schema, 0, "PASS", OUT)
        cleanup.drop_database_verified = original
        result = {"domain_result": telemetry.domain_result, "cleanup_result": telemetry.cleanup_result,
                  "rescue_used": telemetry.rescue_used, "final_state": telemetry.state,
                  "schema_absent": not cleanup.schema_exists(schema)}
        (OUT / "rescue_probe.json").write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        print(json.dumps(result))
    finally:
        cleanup.drop_database_verified = original
        if cleanup.schema_exists(schema):
            cleanup._mysql(f"DROP DATABASE IF EXISTS `{schema}`")


if __name__ == "__main__":
    main()
