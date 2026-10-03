"""P0.10-F2B §1/§2: baseline smoke + P0.9 parity stability proof (Test Infrastructure V2, TEST_INFRA only).

Sequence (recorded in docs/reviews/evidence/P0.10-F2B/baseline-smoke.json):
  1. tools/test-infrastructure/mysql_instance.py init-start --port auto -> STARTED_VERIFIED (a NEW instance)
  2. tools/test-infrastructure/pool.py create-and-migrate               -> fresh pools
  3. P0.9 parity (validate-p09-membership-schema.cjs) N times (default 3) on the fresh pool: every run must PASS with the
     same non-zero compared_indexes (the F2A-I01 symptom was compared_indexes = 0)
  4. smoke: P0.10 parity, PHPUnit FinanceReportingF1DTest and PayrollFoundationF2ATest
  5. mysql_instance.py stop -> STOPPED_VERIFIED (datadir removed)
No development database is touched; no migration and no Membership product file is changed.
"""
import argparse
import json
import os
import subprocess
import sys
import time
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
API = REPO / "apps" / "api"
PHP = os.environ.get("MEPA_PHP_BIN", r"C:\wamp64\bin\php\php8.1.33\php.exe")
EVIDENCE = REPO / "docs" / "reviews" / "evidence" / "P0.10-F2B"


def run(cmd, cwd, env=None, timeout=1800):
    started = time.time()
    proc = subprocess.run(cmd, cwd=cwd, env=env, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=timeout)
    return {"cmd": " ".join(str(c) for c in cmd), "rc": proc.returncode, "seconds": round(time.time() - started, 1), "stdout": proc.stdout, "stderr_tail": proc.stderr[-1500:]}


def last_json(text):
    start = text.rfind("\n{")
    start = 0 if start < 0 else start
    return json.loads(text[text.find("{", start):])


def summary_line(text):
    lines = [line for line in text.splitlines() if line.startswith(("OK (", "Tests:", "FAILURES", "ERRORS", "OK, but"))]
    return lines[-1] if lines else ""


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--runs", type=int, default=3)
    parser.add_argument("--no-smoke", action="store_true")
    args = parser.parse_args()
    EVIDENCE.mkdir(parents=True, exist_ok=True)
    report = {"runner": "scripts/run-p09-parity-stability.py", "head": subprocess.run(["git", "rev-parse", "HEAD"], cwd=REPO, capture_output=True, text=True).stdout.strip(),
              "started_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "p09_runs": []}
    port = None
    try:
        start = run([sys.executable, "tools/test-infrastructure/mysql_instance.py", "init-start", "--port", "auto"], REPO, timeout=600)
        info = last_json(start["stdout"])
        report["infrastructure_start"] = info.get("status")
        if info.get("status") != "STARTED_VERIFIED":
            raise RuntimeError("instance not verified")
        port, session = int(info["port"]), info["session_id"]
        report["instance"] = {"port": port, "session": session[:12]}
        pool = run([sys.executable, "tools/test-infrastructure/pool.py", "create-and-migrate", "--port", str(port)], REPO, timeout=1200)
        report["pools_migrated"] = pool["rc"] == 0
        dsn = f"mysql:host=127.0.0.1;port={port};dbname=mepa_wave5_test_{session[:8]}_pool_01"
        env = {**os.environ, "XDEBUG_MODE": "off", "WAVE5_DSN": dsn, "WAVE5_USER": "root", "WAVE5_PASSWORD": "", "WAVE5_ALLOW_SYNTHETIC": "1", "PHP_BIN": PHP, "MEPA_PHP_BIN": PHP,
               "P010_DSN": dsn, "P010_USER": "root", "P010_PASSWORD": "", "P09_DSN": dsn, "P09_USER": "root", "P09_PASSWORD": ""}
        for i in range(args.runs):
            result = run(["node", "scripts/validate-p09-membership-schema.cjs"], REPO, env)
            parsed = last_json(result["stdout"])
            details = parsed.get("details", {})
            report["p09_runs"].append({"run": i + 1, "rc": result["rc"], "status": parsed.get("status"), "compared_columns": details.get("compared_columns"),
                                       "compared_indexes": details.get("compared_indexes"), "compared_foreign_keys": details.get("compared_foreign_keys"),
                                       "mismatches": len(details.get("missing_or_mismatched", [])) + len(details.get("unexpected", [])), "stable_read_retries": details.get("stable_read_retries")})
        indexes = {r["compared_indexes"] for r in report["p09_runs"]}
        report["p09_stable"] = all(r["status"] == "PASS" for r in report["p09_runs"]) and len(indexes) == 1 and 0 not in indexes and None not in indexes
        if not args.no_smoke:
            result = run(["node", "scripts/validate-p010-finance-schema.cjs"], REPO, env)
            parsed = last_json(result["stdout"])
            report["p010_parity"] = {"rc": result["rc"], "status": parsed.get("status")}
            report["smoke"] = {}
            for name, test in [("f1d", "FinanceReportingF1DTest"), ("f2a", "PayrollFoundationF2ATest")]:
                result = run([PHP, "vendor/phpunit/phpunit/phpunit", f"tests/DatabaseV2/{test}.php"], API, env, timeout=3600)
                report["smoke"][name] = {"rc": result["rc"], "summary": summary_line(result["stdout"][-3000:])}
    finally:
        if port is not None:
            stop = run([sys.executable, "tools/test-infrastructure/mysql_instance.py", "stop", "--port", str(port)], REPO, timeout=600)
            try:
                report["infrastructure_stop"] = last_json(stop["stdout"]).get("status")
            except Exception:
                report["infrastructure_stop"] = "UNPARSED"
        report["finished_at"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
        (EVIDENCE / "baseline-smoke.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps(report, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
