"""P0.10-F1A Finance Core foundation runner (Test Infrastructure V2).

Sequence (every step recorded in docs/reviews/evidence/P0.10-F1A/f1a-run.json):
  1. tools/test-infrastructure/mysql_instance.py init-start --port auto          -> STARTED_VERIFIED
  2. tools/test-infrastructure/pool.py create-and-migrate                          -> fresh Wave 3/4/5 pools (+ P0.5..P0.10 deltas)
  3. schema parity: P0.10 (catalog <-> migrations <-> INFORMATION_SCHEMA, fresh pool, role check on) and the P0.9, P0.8,
     P0.7, P0.6 parities (regression: the catalog reconciliation must not disturb them)
  4. PHPUnit: FinanceCoreFoundationTest (S01-S07, J01-J16, C1/C3 with real processes)
  5. Wave 2 regressions on their own empty databases (the catalog hash of wave2_manifest was refreshed by F1A)
  6. regressions: Membership, People, Territorial, Files, Physical, Academy (short)
  7. validators: Finance contracts (F01-F12 + M1-M12 mutation probes, byte-for-byte restore), Membership/Files/Physical
     (structural), Territorial, HTTP, UI, Auth, Application, People/Families, Wave 5 schema (static), database docs
  8. mysql_instance.py stop -> STOPPED_VERIFIED
No development (3306/laravel) database is touched and no secret is written inside the repository.
"""
import json
import os
import subprocess
import sys
import time
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
API = REPO / "apps" / "api"
PHP = os.environ.get("MEPA_PHP_BIN", r"C:\wamp64\bin\php\php8.1.33\php.exe")
MYSQL = os.environ.get("MYSQL_BIN", r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe")
EVIDENCE = REPO / "docs" / "reviews" / "evidence" / "P0.10-F1A"
TRACKED_REWRITES = ["docs/database/physical/wave5_application_contracts_validation.json", "docs/database/physical/wave2_physical_validation.json", "docs/database/physical/wave2_physical_inspection.json"]


def run(cmd, cwd, env=None, timeout=1800, log=None):
    started = time.time()
    proc = subprocess.run(cmd, cwd=cwd, env=env, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=timeout)
    if log:
        (EVIDENCE / log).write_text(proc.stdout + ("\n--- stderr ---\n" + proc.stderr if proc.stderr.strip() else ""), encoding="utf-8", newline="\n")
    return {"cmd": " ".join(str(c) for c in cmd), "rc": proc.returncode, "seconds": round(time.time() - started, 1), "stdout_tail": proc.stdout[-3000:], "stderr_tail": proc.stderr[-1500:]}


def last_json(text):
    start = text.rfind("\n{")
    start = 0 if start < 0 else start
    return json.loads(text[text.find("{", start):])


def summary_line(text):
    lines = [line for line in text.splitlines() if line.startswith(("OK (", "Tests:", "FAILURES", "ERRORS", "OK, but"))]
    return lines[-1] if lines else ""


def mysql(port, sql):
    return subprocess.run([MYSQL, "-uroot", "-h127.0.0.1", f"-P{port}", "-e", sql], capture_output=True, text=True)


def main():
    EVIDENCE.mkdir(parents=True, exist_ok=True)
    report = {"runner": "scripts/run-p010-finance-f1a.py", "steps": [], "started_at": time.strftime("%Y-%m-%dT%H:%M:%S%z")}
    port = None
    try:
        start = run([sys.executable, "tools/test-infrastructure/mysql_instance.py", "init-start", "--port", "auto"], REPO, timeout=600)
        report["steps"].append({"step": "mysql_instance.init-start", **start})
        info = last_json(start["stdout_tail"])
        report["infrastructure_start"] = info.get("status")
        if info.get("status") != "STARTED_VERIFIED":
            raise RuntimeError("instance not verified")
        port, session = int(info["port"]), info["session_id"]
        pool = run([sys.executable, "tools/test-infrastructure/pool.py", "create-and-migrate", "--port", str(port)], REPO, timeout=1200, log="pool-create-and-migrate.log")
        report["steps"].append({"step": "pool.create-and-migrate", **pool})
        report["pools"] = last_json(pool["stdout_tail"])["migrated"]
        (EVIDENCE / "test-infrastructure-started.json").write_text(json.dumps({**info, "pools": report["pools"]}, indent=2) + "\n", encoding="utf-8", newline="\n")
        short = session[:8]
        wave5 = f"mepa_wave5_test_{short}_pool_01"
        dsn = f"mysql:host=127.0.0.1;port={port};dbname={wave5}"
        env = {**os.environ, "XDEBUG_MODE": "off", "WAVE5_DSN": dsn, "WAVE5_USER": "root", "WAVE5_PASSWORD": "", "WAVE5_ALLOW_SYNTHETIC": "1", "PHP_BIN": PHP, "MEPA_PHP_BIN": PHP,
               "P010_DSN": dsn, "P010_USER": "root", "P010_PASSWORD": "", "P09_DSN": dsn, "P09_USER": "root", "P09_PASSWORD": "", "P08_DSN": dsn, "P08_USER": "root", "P08_PASSWORD": "",
               "P07_DSN": dsn, "P07_USER": "root", "P07_PASSWORD": "", "P06_DSN": dsn, "P06_USER": "root", "P06_PASSWORD": "", "P010_EVIDENCE_DIR": str(EVIDENCE), "P09_EVIDENCE_DIR": str(EVIDENCE / "p09-regression")}
        (EVIDENCE / "p09-regression").mkdir(exist_ok=True)

        # Parity on the FRESH pool first (default mode: 0 roles may carry FINANCE / MEMBERSHIP permissions).
        report["schema_parity"] = {}
        for name, script, extra in [("p010", "validate-p010-finance-schema.cjs", []), ("p09", "validate-p09-membership-schema.cjs", []), ("p08", "validate-p08-files-schema.cjs", []),
                                    ("p07", "validate-p07-physical-schema.cjs", []), ("p06", "validate-p06-territorial-schema.cjs", [])]:
            out = EVIDENCE / f"{name}-schema-parity.json"
            result = run(["node", f"scripts/{script}", "--output", str(out), *extra], REPO, env, log=f"{name}-schema-parity.log")
            report["schema_parity"][name] = {"rc": result["rc"], "status": json.loads(out.read_text(encoding="utf-8")).get("status") if out.exists() else None}

        finance = run([PHP, "vendor/phpunit/phpunit/phpunit", "--log-junit", str(EVIDENCE / "finance-foundation-junit.xml"), "tests/DatabaseV2/FinanceCoreFoundationTest.php"], API, env, timeout=3600, log="finance-foundation.log")
        report["finance_phpunit"] = {"rc": finance["rc"], "summary": summary_line(finance["stdout_tail"]), "seconds": finance["seconds"]}

        report["wave2_regressions"] = {}
        for db, prefix, test in [(f"mepa_wave2_test_{short}_gen", "WAVE2", "WaveTwoConcurrencyTest"), (f"mepa_wave2_test_{short}_phy", "WAVE2", "WaveTwoPhysicalTest"),
                                 (f"mepa_wave2f_test_{short}", "WAVE2F", "WaveTwoIndependentReconciliationTest"), (f"mepa_wave2m1_test_{short}", "WAVE2M1", "WaveTwoTransferConcurrencyTest"),
                                 (f"mepa_m1audit_test_{short}", "M1AUDIT", "WaveTwoM1IndependentAuditTest")]:
            mysql(port, f"DROP DATABASE IF EXISTS {db}; CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
            wenv = {**env, f"{prefix}_DSN": f"mysql:host=127.0.0.1;port={port};dbname={db}", f"{prefix}_USER": "root", f"{prefix}_PASSWORD": "", f"{prefix}_ALLOW_SYNTHETIC": "1"}
            result = run([PHP, "vendor/phpunit/phpunit/phpunit", "--log-junit", str(EVIDENCE / f"{test}-regression.xml"), f"tests/Database/{test}.php"], API, wenv, timeout=2400, log=f"{test}-regression.log")
            report["wave2_regressions"][test] = {"rc": result["rc"], "summary": summary_line(result["stdout_tail"])}
            mysql(port, f"DROP DATABASE IF EXISTS {db}")

        report["regressions"] = {}
        for name, test in [("membership", "MembershipVerticalTest"), ("people_context", "PeopleContextAuthorityTest"), ("people_person", "PeoplePersonTest"), ("territorial", "TerritorialVerticalTest"),
                           ("files", "FilesVerticalTest"), ("physical", "PhysicalVerticalTest"), ("academy_remediation", "AcademyRemediationTest"), ("academy_http", "AcademyHttpAuthenticationTest")]:
            result = run([PHP, "vendor/phpunit/phpunit/phpunit", "--log-junit", str(EVIDENCE / f"{name}-regression.xml"), f"tests/DatabaseV2/{test}.php"], API, env, timeout=3600, log=f"{name}-regression.log")
            report["regressions"][name] = {"rc": result["rc"], "summary": summary_line(result["stdout_tail"])}

        validators = [
            ("finance_contracts", ["node", "scripts/validate-finance-contracts.cjs", "--output", str(EVIDENCE / "finance-contract-validation.json")]),
            ("membership_contracts", ["node", "scripts/validate-membership-contracts.cjs", "--structural-only", "--output", str(EVIDENCE / "membership-contract-validation.json")]),
            ("files_contracts", ["node", "scripts/validate-files-contracts.cjs", "--structural-only", "--output", str(EVIDENCE / "files-contract-validation.json")]),
            ("physical_contracts", ["node", "scripts/validate-physical-contracts.cjs", "--structural-only", "--output", str(EVIDENCE / "physical-contract-validation.json")]),
            ("territorial_contracts", ["node", "scripts/validate-territorial-contracts.cjs", "--output", str(EVIDENCE / "territorial-contract-validation.json")]),
            ("http_contracts", ["node", "scripts/validate-wave5-http-contracts.cjs"]),
            ("ui_contracts", ["node", "scripts/validate-wave5-ui-contracts.cjs"]),
            ("auth_contracts", ["node", "scripts/validate-auth-contracts.cjs"]),
            ("application_contracts", ["node", "scripts/validate-wave5-application-contracts.cjs"]),
            ("people_families_contracts", ["node", "scripts/validate-people-families-contracts.cjs"]),
            ("wave5_schema_static", ["node", "scripts/validate-wave5-schema.cjs"]),
            ("database_docs", ["node", "scripts/validate-database-docs.cjs"]),
        ]
        report["validators"] = {}
        for name, cmd in validators:
            result = run(cmd, REPO, env, timeout=5400, log=f"validator-{name}.log")
            report["validators"][name] = {"rc": result["rc"], "tail": result["stdout_tail"][-300:].strip()}
    finally:
        restored = subprocess.run(["git", "checkout", "--", *TRACKED_REWRITES], cwd=REPO, capture_output=True, text=True)
        report["tracked_rewrites_restored"] = restored.returncode == 0
        if port is not None:
            stop = run([sys.executable, "tools/test-infrastructure/mysql_instance.py", "stop", "--port", str(port)], REPO, timeout=600)
            report["steps"].append({"step": "mysql_instance.stop", **stop})
            try:
                stopped = last_json(stop["stdout_tail"])
                report["infrastructure_stop"] = stopped.get("status")
                (EVIDENCE / "test-infrastructure-stopped.json").write_text(json.dumps(stopped, indent=2) + "\n", encoding="utf-8", newline="\n")
            except Exception:
                report["infrastructure_stop"] = "UNPARSED"
        for step in report["steps"]:
            step["stdout_tail"] = step["stdout_tail"][-1200:]
        report["finished_at"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
        (EVIDENCE / "f1a-run.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps({k: report.get(k) for k in ("infrastructure_start", "schema_parity", "finance_phpunit", "wave2_regressions", "regressions", "validators", "tracked_rewrites_restored", "infrastructure_stop")}, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
