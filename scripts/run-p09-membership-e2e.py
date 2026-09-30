"""P0.9-I Membership end-to-end runner (Test Infrastructure V2).

Sequence (every step recorded in docs/reviews/evidence/P0.9/e2e-run.json):
  1. tools/test-infrastructure/mysql_instance.py init-start --port auto          -> STARTED_VERIFIED
  2. tools/test-infrastructure/pool.py create-and-migrate                          -> Wave 3/4/5 pools (+ P0.5..P0.9 deltas)
  3. schema parity: P0.9 (bidirectional, open-period guard, 17 controlled rows), P0.8, P0.7, P0.6 (regression)
  4. PHPUnit: MembershipVerticalTest (S01/S02, M01-M20, F-06/IDOR, commit-time recheck, C1-C5 with real processes)
  5. Wave 2 regressions on their own empty databases in the same instance (generator, reconciliation, transfer guard,
     M1 audit, Wave 2 physical)
  6. regressions: People, Territorial, Files, Physical, Academy (short)
  7. validators: Membership (S01-S20 + N1-N15 + N4b mutation probes), Files, Physical, Territorial, HTTP, UI, Auth,
     Application, People/Families, database docs
  8. web: vitest, eslint, production/PWA build (VITE_API_URL -> 127.0.0.1:18080)
  9. MembershipE2EFixtureTest seeds the browser fixture through the API (4 independent data sets)
  10. Laravel on PHP's built-in server (APP_ENV=e2e, pool DSN) + vite preview; Playwright Membership suite on 4 viewports
  11. servers stopped; log scanned for the fixture password; fixture manifest deleted; mysql_instance.py stop ->
      STOPPED_VERIFIED
No development (3306/laravel) database is touched and no secret is written inside the repository.
"""
import argparse
import json
import os
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
API = REPO / "apps" / "api"
WEB = REPO / "apps" / "web"
PHP = os.environ.get("MEPA_PHP_BIN", r"C:\wamp64\bin\php\php8.1.33\php.exe")
MYSQL = os.environ.get("MYSQL_BIN", r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe")
EVIDENCE = REPO / "docs" / "reviews" / "evidence" / "P0.9"
NPX = "npx.cmd" if os.name == "nt" else "npx"
NPM = "npm.cmd" if os.name == "nt" else "npm"
MANIFEST = REPO / ".tmp" / "p09-e2e-fixtures.json"
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


def wait_http(url, seconds=60):
    deadline = time.time() + seconds
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(url, timeout=3) as response:
                if response.status < 500:
                    return True
        except Exception:
            time.sleep(0.5)
    return False


def summary_line(text):
    lines = [line for line in text.splitlines() if line.startswith(("OK (", "Tests:", "FAILURES", "ERRORS", "OK, but"))]
    return lines[-1] if lines else ""


def mysql(port, sql):
    return subprocess.run([MYSQL, "-uroot", "-h127.0.0.1", f"-P{port}", "-e", sql], capture_output=True, text=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--skip-mutations", action="store_true")
    parser.add_argument("--skip-regressions", action="store_true")
    parser.add_argument("--only-e2e", action="store_true")
    args = parser.parse_args()

    EVIDENCE.mkdir(parents=True, exist_ok=True)
    report = {"runner": "scripts/run-p09-membership-e2e.py", "steps": [], "started_at": time.strftime("%Y-%m-%dT%H:%M:%S%z")}
    servers = []
    port = None
    password = None
    log_path = API / "storage" / "logs" / "laravel.log"
    log_offset = log_path.stat().st_size if log_path.exists() else 0
    try:
        start = run([sys.executable, "tools/test-infrastructure/mysql_instance.py", "init-start", "--port", "auto"], REPO, timeout=600)
        report["steps"].append({"step": "mysql_instance.init-start", **start})
        info = last_json(start["stdout_tail"])
        report["infrastructure_start"] = info.get("status")
        if info.get("status") != "STARTED_VERIFIED":
            raise RuntimeError("instance not verified")
        port, session = int(info["port"]), info["session_id"]
        pool = run([sys.executable, "tools/test-infrastructure/pool.py", "create-and-migrate", "--port", str(port)], REPO, timeout=1200)
        report["steps"].append({"step": "pool.create-and-migrate", **pool})
        report["pools"] = last_json(pool["stdout_tail"])["migrated"]
        (EVIDENCE / "test-infrastructure-started.json").write_text(json.dumps({**info, "pools": report["pools"]}, indent=2) + "\n", encoding="utf-8", newline="\n")
        short = session[:8]
        wave5 = f"mepa_wave5_test_{short}_pool_01"
        dsn = f"mysql:host=127.0.0.1;port={port};dbname={wave5}"
        test_env = {**os.environ, "XDEBUG_MODE": "off", "WAVE5_DSN": dsn, "WAVE5_USER": "root", "WAVE5_PASSWORD": "", "WAVE5_ALLOW_SYNTHETIC": "1",
                    "P09_DSN": dsn, "P09_USER": "root", "P09_PASSWORD": "", "P08_DSN": dsn, "P08_USER": "root", "P08_PASSWORD": "",
                    "P07_DSN": dsn, "P07_USER": "root", "P07_PASSWORD": "", "P06_DSN": dsn, "P06_USER": "root", "P06_PASSWORD": "",
                    "PHP_BIN": PHP, "P09_EVIDENCE_DIR": str(EVIDENCE), "MEPA_PHP_BIN": PHP}

        if not args.only_e2e:
            for name, script in [("schema_parity", "validate-p09-membership-schema.cjs"), ("p08_schema_parity", "validate-p08-files-schema.cjs"), ("p07_schema_parity", "validate-p07-physical-schema.cjs"), ("p06_schema_parity", "validate-p06-territorial-schema.cjs")]:
                out = EVIDENCE / f"{name.replace('_', '-')}.json"
                result = run(["node", f"scripts/{script}", "--output", str(out)], REPO, test_env, log=f"{name}.log")
                report[name] = {"rc": result["rc"], "status": json.loads(out.read_text(encoding="utf-8")).get("status") if out.exists() else None}

            membership = run([PHP, "vendor/phpunit/phpunit/phpunit", "--log-junit", str(EVIDENCE / "membership-junit.xml"), "tests/DatabaseV2/MembershipVerticalTest.php"], API, test_env, timeout=3600, log="membership.log")
            report["membership_phpunit"] = {"rc": membership["rc"], "summary": summary_line(membership["stdout_tail"]), "seconds": membership["seconds"]}

            report["wave2_regressions"] = {}
            for db, prefix, test in [(f"mepa_wave2_test_{short}_gen", "WAVE2", "WaveTwoConcurrencyTest"), (f"mepa_wave2_test_{short}_phy", "WAVE2", "WaveTwoPhysicalTest"),
                                     (f"mepa_wave2f_test_{short}", "WAVE2F", "WaveTwoIndependentReconciliationTest"), (f"mepa_wave2m1_test_{short}", "WAVE2M1", "WaveTwoTransferConcurrencyTest"),
                                     (f"mepa_m1audit_test_{short}", "M1AUDIT", "WaveTwoM1IndependentAuditTest")]:
                mysql(port, f"DROP DATABASE IF EXISTS {db}; CREATE DATABASE {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
                env = {**test_env, f"{prefix}_DSN": f"mysql:host=127.0.0.1;port={port};dbname={db}", f"{prefix}_USER": "root", f"{prefix}_PASSWORD": "", f"{prefix}_ALLOW_SYNTHETIC": "1"}
                result = run([PHP, "vendor/phpunit/phpunit/phpunit", "--log-junit", str(EVIDENCE / f"{test}-regression.xml"), f"tests/Database/{test}.php"], API, env, timeout=2400, log=f"{test}-regression.log")
                report["wave2_regressions"][test] = {"rc": result["rc"], "summary": summary_line(result["stdout_tail"])}
                mysql(port, f"DROP DATABASE IF EXISTS {db}")

            if not args.skip_regressions:
                report["regressions"] = {}
                for name, test in [("people_context", "PeopleContextAuthorityTest"), ("people_person", "PeoplePersonTest"), ("territorial", "TerritorialVerticalTest"),
                                   ("files", "FilesVerticalTest"), ("physical", "PhysicalVerticalTest"), ("academy_remediation", "AcademyRemediationTest"), ("academy_http", "AcademyHttpAuthenticationTest")]:
                    result = run([PHP, "vendor/phpunit/phpunit/phpunit", "--log-junit", str(EVIDENCE / f"{name}-regression.xml"), f"tests/DatabaseV2/{test}.php"], API, test_env, timeout=2400, log=f"{name}-regression.log")
                    report["regressions"][name] = {"rc": result["rc"], "summary": summary_line(result["stdout_tail"])}

            validators = [
                ("membership_contracts", ["node", "scripts/validate-membership-contracts.cjs", "--output", str(EVIDENCE / "contract-validation.json")] + (["--structural-only"] if args.skip_mutations else [])),
                ("files_contracts", ["node", "scripts/validate-files-contracts.cjs", "--structural-only", "--output", str(EVIDENCE / "files-contract-validation.json")]),
                ("physical_contracts", ["node", "scripts/validate-physical-contracts.cjs", "--structural-only", "--output", str(EVIDENCE / "physical-contract-validation.json")]),
                ("territorial_contracts", ["node", "scripts/validate-territorial-contracts.cjs", "--output", str(EVIDENCE / "territorial-contract-validation.json")]),
                ("http_contracts", ["node", "scripts/validate-wave5-http-contracts.cjs"]),
                ("ui_contracts", ["node", "scripts/validate-wave5-ui-contracts.cjs"]),
                ("auth_contracts", ["node", "scripts/validate-auth-contracts.cjs"]),
                ("application_contracts", ["node", "scripts/validate-wave5-application-contracts.cjs"]),
                ("people_families_contracts", ["node", "scripts/validate-people-families-contracts.cjs"]),
                ("database_docs", ["node", "scripts/validate-database-docs.cjs"]),
            ]
            report["validators"] = {}
            for name, cmd in validators:
                result = run(cmd, REPO, test_env, timeout=5400, log=f"validator-{name}.log")
                report["validators"][name] = {"rc": result["rc"], "tail": result["stdout_tail"][-300:].strip()}

            report["web"] = {}
            for name, cmd in [("tests", [NPM, "run", "test"]), ("lint", [NPM, "run", "lint"])]:
                result = run(cmd, WEB, os.environ.copy(), timeout=900, log=f"web-{name}.log")
                report["web"][name] = {"rc": result["rc"]}

        fixture = run([PHP, "vendor/phpunit/phpunit/phpunit", "tests/DatabaseV2/MembershipE2EFixtureTest.php"], API, test_env, log="e2e-fixture.log")
        report["steps"].append({"step": "membership_fixture", **fixture})
        if fixture["rc"] != 0:
            raise RuntimeError("membership fixture failed")
        password = json.loads(MANIFEST.read_text(encoding="utf-8"))["password"]

        build = run([NPM, "run", "build"], WEB, {**os.environ, "VITE_API_URL": "http://127.0.0.1:18080/api/v1"}, timeout=900, log="web-build.log")
        report["web_build"] = {"rc": build["rc"], "pwa": (WEB / "dist" / "sw.js").exists()}
        if build["rc"] != 0:
            raise RuntimeError("build failed")

        server_env = {**os.environ, "APP_ENV": "e2e", "APP_DEBUG": "false", "DB_CONNECTION": "mysql", "DB_HOST": "127.0.0.1", "DB_PORT": str(port),
                      "DB_DATABASE": wave5, "DB_USERNAME": "root", "DB_PASSWORD": "", "AUTH_ACTIVE_USER_STATUSES": "SYNTHETIC_READY",
                      "AUTH_ACTIVE_GRANT_STATUSES": "SYNTHETIC_READY", "ACADEMY_E2E_TEST_POLICY": "true", "CACHE_DRIVER": "file", "MEPA_PHP_BIN": PHP}
        router = API / "vendor" / "laravel" / "framework" / "src" / "Illuminate" / "Foundation" / "resources" / "server.php"
        servers.append(subprocess.Popen([PHP, "-S", "127.0.0.1:18080", str(router)], cwd=API / "public", env=server_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        servers.append(subprocess.Popen([NPX, "vite", "preview", "--host", "127.0.0.1", "--port", "14173", "--strictPort"], cwd=WEB, env=os.environ.copy(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        report["servers_ready"] = {"api": wait_http("http://127.0.0.1:18080/api/v1/health"), "web": wait_http("http://127.0.0.1:14173/entrar")}

        playwright = run([NPX, "playwright", "test", "--config", "playwright.membership.config.ts"], WEB, server_env, timeout=2400, log="playwright.log")
        report["steps"].append({"step": "playwright_membership", **playwright})
        results = json.loads((EVIDENCE / "playwright-results.json").read_text(encoding="utf-8"))
        report["playwright_membership"] = results.get("stats", {})
    finally:
        for proc in servers:
            if os.name == "nt":
                subprocess.run(["taskkill", "/PID", str(proc.pid), "/T", "/F"], capture_output=True)
            else:
                proc.terminate()
        if log_path.exists():
            with open(log_path, "rb") as handle:
                handle.seek(log_offset)
                tail = handle.read()
            leaks = [label for label, needle in [("fixture_password", (password or "\x00").encode())] if needle in tail]
            report["log_scan"] = {"bytes_scanned": len(tail), "leaks": leaks}
        if MANIFEST.exists():
            MANIFEST.unlink()
        report["fixture_manifest_removed"] = not MANIFEST.exists()
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
        (EVIDENCE / "e2e-run.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps({k: report.get(k) for k in ("infrastructure_start", "schema_parity", "p08_schema_parity", "p07_schema_parity", "p06_schema_parity", "membership_phpunit", "wave2_regressions", "regressions", "validators", "web", "web_build", "servers_ready", "playwright_membership", "log_scan", "fixture_manifest_removed", "tracked_rewrites_restored", "infrastructure_stop")}, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
