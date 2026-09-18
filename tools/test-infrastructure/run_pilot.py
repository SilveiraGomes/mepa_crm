"""P0-TI.1 Test Infrastructure V2 pilot -- runs the 6 critical suites named
in the P0-TI.1 spec against the pooled/reset databases and records a
{product, evidence, infrastructure} result per suite (ADR-0014 run-result
model). Mirrors the exact PHPUnit invocation convention already used by
scripts/wave4-m121/run_suite.py (same PHP binary, same
`vendor/phpunit/phpunit/phpunit <file> --log-junit <xml>` command, same
cwd=apps/api) -- the only thing V2 changes is what database the run points
at (a pre-migrated pool schema, never a freshly CREATEd/DROPped one).
"""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import uuid
import xml.etree.ElementTree as ET
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from mysql_instance import MysqlInstance, DEFAULT_PORT  # noqa: E402
import pool as pool_mod  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
PHP = r"C:\wamp64\bin\php\php8.1.33\php.exe"
OUT = ROOT / "docs/database/physical/test_infrastructure_v2_pilot"
OUT.mkdir(parents=True, exist_ok=True)

TARGETS = [
    {"label": "wave3_checkin_concurrency", "suite": "PooledWaveThreeCheckinConcurrencyTest", "wave": "wave3",
     "filter": r"::test_same_person_session_token_converges( with data set .*)?$", "pool_db": "mepa_wave3_test_pool_01",
     "note": "workers 2/10/30, all three data sets in one PHPUnit invocation"},
    {"label": "track_commit_authorization", "suite": "PooledCommitAuthorizationTest", "wave": "wave4",
     "filter": r"::test_track_configure_expires_during_success_audit_fk_wait$", "pool_db": "mepa_wave4_test_pool_01"},
    {"label": "step_commit_authorization", "suite": "PooledCommitAuthorizationTest", "wave": "wave4",
     "filter": r"::test_step_configure_expires_during_success_audit_fk_wait$", "pool_db": "mepa_wave4_test_pool_01"},
    {"label": "progress_commit_boundary", "suite": "PooledProgressCommitBoundaryTest", "wave": "wave4",
     "filter": r"::test_progress_session_expires_during_success_audit_fk_wait_and_rolls_back$", "pool_db": "mepa_wave4_test_pool_01"},
    {"label": "child_safety_generic_path_rejection", "suite": "PooledChildCheckinBoundaryTest", "wave": "wave4",
     "filter": r"::test_generic_service_direct_call_reproduces_original_bypass_scenario_and_is_now_denied$", "pool_db": "mepa_wave4_test_pool_02"},
    {"label": "checkout_temporal_expiry", "suite": "PooledTemporalAuthorizationTest", "wave": "wave4",
     "filter": r"::test_authorization_expiring_during_lock_wait_is_denied_with_post_lock_clock$", "pool_db": "mepa_wave4_test_pool_01"},
]

FUNCTIONAL_TEST_TIMEOUT_SECONDS = 300


def run_one(target: dict, port: int) -> dict:
    prefix = target["wave"].upper()
    env = os.environ.copy()
    env.update({
        f"{prefix}_DSN": f"mysql:host=127.0.0.1;port={port};dbname={target['pool_db']}",
        f"{prefix}_USER": "root", f"{prefix}_PASSWORD": "", f"{prefix}_ALLOW_SYNTHETIC": "1",
        "PHP_BIN": PHP, "XDEBUG_MODE": "off",
    })
    xml_path = OUT / f"{target['label']}.xml"
    cmd = [PHP, "vendor/phpunit/phpunit/phpunit", f"tests/DatabaseV2/{target['suite']}.php",
           "--filter", target["filter"], "--log-junit", str(xml_path)]
    t0 = time.monotonic()
    proc = subprocess.run(cmd, cwd=ROOT / "apps/api", env=env, capture_output=True, text=True,
                           timeout=FUNCTIONAL_TEST_TIMEOUT_SECONDS)
    duration_ms = int((time.monotonic() - t0) * 1000)

    result = {
        "suite": f"{target['suite']}::{target['filter']}", "label": target["label"],
        "pool_database": target["pool_db"], "workers": target.get("note"),
        "duration_ms": duration_ms, "exit_code": proc.returncode,
        "product": "NOT_RUN", "evidence": "NOT_RUN", "infrastructure": "PASS", "notes": "",
    }
    if not xml_path.exists():
        result.update({"evidence": "FAIL", "product": "NOT_RUN",
                        "notes": f"PHPUnit produced no JUnit XML (rc={proc.returncode}); stderr: {proc.stderr[-2000:]}"})
        return result
    try:
        root = ET.parse(xml_path).getroot()
    except ET.ParseError as exc:
        result.update({"evidence": "FAIL", "notes": f"JUnit XML unparseable: {exc}"})
        return result

    tests = failures = errors = 0
    for ts in root.iter("testsuite"):
        tests += int(ts.get("tests", 0))
        failures += int(ts.get("failures", 0))
        errors += int(ts.get("errors", 0))
    result["tests"] = tests
    result["failures"] = failures
    result["errors"] = errors
    result["evidence"] = "PASS"
    result["product"] = "PASS" if (tests > 0 and failures == 0 and errors == 0 and proc.returncode == 0) else "FAIL"
    if result["product"] == "FAIL":
        result["notes"] = f"{failures} failures, {errors} errors of {tests} tests (rc={proc.returncode})"
    return result


def main() -> None:
    session_id = uuid.uuid4().hex[:12]
    datadir = Path(tempfile.gettempdir()) / "mepa-test-mysql" / session_id / "data"
    instance = MysqlInstance(datadir, DEFAULT_PORT)
    summary = {"session_id": session_id, "port": DEFAULT_PORT, "results": []}

    try:
        instance.initialize()
        instance.start()
        pool_created = pool_mod.create_pool(instance.port)
        pool_migrated = pool_mod.migrate_once(instance.port)
        summary["pool_created"] = pool_created
        summary["pool_migrated"] = {k: v.get("tables") for k, v in pool_migrated.items()}

        # Section 13 proof: two suites run concurrently against distinct pool
        # databases of the same wave (pool_01 / pool_02) -- no cross-worker
        # DB sharing, no collision.
        concurrent_pair = [t for t in TARGETS if t["label"] in ("track_commit_authorization", "child_safety_generic_path_rejection")]
        sequential = [t for t in TARGETS if t not in concurrent_pair]

        import concurrent.futures
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool_exec:
            futures = {pool_exec.submit(run_one, t, instance.port): t for t in concurrent_pair}
            for fut in concurrent.futures.as_completed(futures):
                summary["results"].append(fut.result())

        for t in sequential:
            summary["results"].append(run_one(t, instance.port))

        summary["results"].sort(key=lambda r: r["label"])
        for r in summary["results"]:
            (OUT / f"{r['label']}.json").write_text(json.dumps(r, indent=2), encoding="utf-8")

    finally:
        stop_result = instance.stop()
        summary["mysql_instance_stop"] = stop_result

    product_pass = all(r["product"] == "PASS" for r in summary["results"])
    evidence_pass = all(r["evidence"] == "PASS" for r in summary["results"])
    summary["pilot_product_result"] = "PASS" if product_pass else "FAIL"
    summary["pilot_evidence_result"] = "PASS" if evidence_pass else "FAIL"
    summary["pilot_infrastructure_result"] = stop_result.get("infrastructure_result", "PASS")
    (OUT / "summary.json").write_text(json.dumps(summary, indent=2), encoding="utf-8")
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    main()
