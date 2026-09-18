"""P0.3.4-M1.2.1 — single-run orchestrator: CREATE schema -> run real PHPUnit suite
(untouched; this only wraps it) -> deterministic per-run cleanup via cleanup.py.

This replaces the ad hoc `sql(f"DROP DATABASE ...")` calls used by every prior
qualification collector (scripts/wave4-m12/run_wamp.py, scripts/audit-wave4-m12r/
qualify.py) with the verified state machine in cleanup.py. It does not modify any
of those prior scripts or their evidence -- this is new, additive infrastructure
scoped to the M1.2.1 harness lifecycle only, per the task's strict scope (no
EvangelismService/DomainClock/track/step/progress/CheckinService/ChildrenService/
migrations/schema changes).
"""
import json
import os
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from cleanup import ENV_PREFIX, new_schema, guard_schema, cleanup_run, _mysql  # noqa: E402
from verify import barrier_dirs  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "docs/database/physical/wave4_m121_qualification"
OUT.mkdir(parents=True, exist_ok=True)
PHP = r"C:\wamp64\bin\php\php8.1.33\php.exe"

FUNCTIONAL_TEST_TIMEOUT_SECONDS = 300  # separate from cleanup's own budget (section 10)


def run_one(label: str, suite: str, wave: str, filter_text: str = None, extra_env: dict = None) -> dict:
    schema = new_schema(wave)
    guard_schema(schema)
    run_id = schema.rsplit("_", 1)[-1]
    prefix = ENV_PREFIX[wave]
    record = {"label": label, "run_id": run_id, "schema": schema, "suite": suite, "filter": filter_text,
               "started_utc": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}

    create = _mysql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", timeout=30)
    if create.returncode != 0:
        record.update({"domain_exit": None, "domain_result": "SCHEMA_CREATE_FAILED", "cleanup_result": "NOT_CREATED"})
        return record

    env = os.environ.copy()
    env.update({
        f"{prefix}_DSN": f"mysql:host=127.0.0.1;port=3306;dbname={schema}",
        f"{prefix}_USER": "root", f"{prefix}_PASSWORD": "", f"{prefix}_ALLOW_SYNTHETIC": "1",
        "DB_CAPABILITIES_DSN": f"mysql:host=127.0.0.1;port=3306;dbname={schema}",
        "DB_CAPABILITIES_USER": "root", "DB_CAPABILITIES_PASSWORD": "",
        "PHP_BIN": PHP, "XDEBUG_MODE": "off",
    })
    if extra_env:
        env.update(extra_env)

    xml = OUT / f"{label}_{run_id}.xml"
    cmd = [PHP, "vendor/phpunit/phpunit/phpunit", f"tests/Database/{suite}.php", "--log-junit", str(xml)]
    if filter_text:
        cmd += ["--filter", filter_text]

    # H2 fix: snapshot barrier dirs tightly around this process's own lifetime, so
    # cleanup_run() can verify the ones that appeared during THIS run's window are
    # actually gone afterward, instead of blindly claiming FILES_REMOVED (see
    # cleanup.py's cleanup_run() docstring / docs/reviews/
    # P0.3.4_M1_2_2_cleanup_verification_remediation.md). Barrier directory names are
    # crypto-random per WaveFourWorkerHarness instance, so a before/after diff scoped
    # to this process's own start/exit is the only correlation available without
    # touching PHP test-support code (out of the M1.2.2 scope).
    barriers_before = set(barrier_dirs())
    t0 = time.monotonic()
    proc = subprocess.Popen(cmd, cwd=ROOT / "apps/api", env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        stdout, stderr = proc.communicate(timeout=FUNCTIONAL_TEST_TIMEOUT_SECONDS)
        exit_code = proc.returncode
    except subprocess.TimeoutExpired:
        # Functional-test fail-safe only: a genuinely hung PHPUnit process. Distinct
        # from the cleanup budget entirely (section 10) -- this taskkill targets our
        # own direct child PID, never anything discovered by name/heuristic.
        subprocess.run(["taskkill", "/PID", str(proc.pid), "/T", "/F"], capture_output=True, text=True)
        stdout, stderr = proc.communicate(timeout=15)
        exit_code = 124
    record["domain_seconds"] = round(time.monotonic() - t0, 2)
    record["domain_exit"] = exit_code
    record["domain_result"] = "PASS" if exit_code == 0 else "FAIL"
    (OUT / f"{label}_{run_id}.log").write_text((stdout or "") + (stderr or ""), encoding="utf-8")
    record["log"] = str((OUT / f"{label}_{run_id}.log").relative_to(ROOT))
    record["junit"] = str(xml.relative_to(ROOT))

    candidate_barriers = sorted(set(barrier_dirs()) - barriers_before)
    record["candidate_barriers"] = [str(p) for p in candidate_barriers]
    telemetry = cleanup_run(run_id, schema, exit_code, record["domain_result"], out_dir=OUT,
                             candidate_barriers=candidate_barriers)
    record["cleanup"] = telemetry.__dict__
    record["cleanup_result"] = telemetry.cleanup_result
    record["overall_pass"] = (exit_code == 0) and (telemetry.cleanup_result == "PASS")
    print(json.dumps({k: record[k] for k in
                       ("label", "run_id", "domain_result", "domain_seconds", "cleanup_result", "overall_pass")}),
          flush=True)
    return record


if __name__ == "__main__":
    # Smoke self-check: one isolated run through the new harness end to end.
    r = run_one("selftest", "WaveThreeCheckinConcurrencyTest", "wave3", "test_same_person_different_sessions")
    (OUT / "selftest.json").write_text(json.dumps(r, indent=2, default=str) + "\n", encoding="utf-8")
    sys.exit(0 if r["overall_pass"] else 1)
