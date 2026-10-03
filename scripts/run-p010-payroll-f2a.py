"""P0.10-F2A RH / compensation foundation campaign runner (Test Infrastructure V2).

Sequence (every step recorded in docs/reviews/evidence/P0.10-F2A/f2a-run.json):
  1. tools/test-infrastructure/mysql_instance.py init-start --port auto          -> STARTED_VERIFIED
  2. tools/test-infrastructure/pool.py create-and-migrate                          -> fresh pools (Wave 3/4/5 + P0.5..P0.10 F2A)
  3. schema parity on the FRESH pool: P0.10 35/35 (catalog <-> migrations <-> INFORMATION_SCHEMA, 0 seeded rules), P0.9..P0.6
  4. PHPUnit PayrollFoundationF2ATest: H01-H24, HC1-HC2 (real processes, lock-wait barrier), S01-S09
  5. validator: payroll F2A contracts P2A01-P2A16 + M1-M16 (CONTROL, on-disk mutation, SHA-256 restore)
  6. Finance regressions F1A / F1B / F1C / F1D (+ reporting evidence) and Territorial / Files / People
  7. validators: Finance F1D / F1C / F1B / F1A (with their mutations), HTTP, UI, Auth, Application, database docs, Wave 5 schema
  8. web: vitest, eslint, production/PWA build
  9. FinanceE2EFixtureTest seeds F1B / F1C / F1D / F2A browser data through the API
 10. Laravel (php -S) + vite preview; Playwright F2A suite + F1D / F1C / F1B suites as regressions, 4 viewports
 11. servers stopped; log + evidence scanned for the fixture passwords / key ring / bank number; manifest, key ring and
     apps/web/test-results deleted; mysql_instance.py stop -> STOPPED_VERIFIED
No development database is touched and no secret is written inside the repository.
Flags: --only-e2e, --grep <pattern>, --project <name>.
"""
import argparse
import base64
import shutil
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
API = REPO / "apps" / "api"
WEB = REPO / "apps" / "web"
PHP = os.environ.get("MEPA_PHP_BIN", r"C:\wamp64\bin\php\php8.1.33\php.exe")
MYSQL = os.environ.get("MYSQL_BIN", r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe")
EVIDENCE = REPO / "docs" / "reviews" / "evidence" / "P0.10-F2A"
RING = Path(tempfile.gettempdir()) / f"mepa-p010-f2a-ring-{os.getpid()}.json"
NPX = "npx.cmd" if os.name == "nt" else "npx"
NPM = "npm.cmd" if os.name == "nt" else "npm"
MANIFEST = REPO / ".tmp" / "p010-e2e-fixtures.json"
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


def phpunit(name, test, env, timeout=3600):
    result = run([PHP, "vendor/phpunit/phpunit/phpunit", "--log-junit", str(EVIDENCE / f"{name}-junit.xml"), f"tests/DatabaseV2/{test}.php"], API, env, timeout=timeout, log=f"{name}.log")
    return {"rc": result["rc"], "summary": summary_line(result["stdout_tail"]), "seconds": result["seconds"]}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--only-e2e", action="store_true")
    parser.add_argument("--grep", default=None)
    parser.add_argument("--project", default=None)
    args = parser.parse_args()

    EVIDENCE.mkdir(parents=True, exist_ok=True)
    report = {"runner": "scripts/run-p010-payroll-f2a.py", "steps": [], "started_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "head": subprocess.run(["git", "rev-parse", "HEAD"], cwd=REPO, capture_output=True, text=True).stdout.strip()}
    kek = base64.b64encode(os.urandom(32)).decode()
    RING.write_text(json.dumps({"active_version": 1, "keys": {"1": {"kek": kek}}}), encoding="utf-8")
    servers = []
    port = None
    secrets = []
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
        pool = run([sys.executable, "tools/test-infrastructure/pool.py", "create-and-migrate", "--port", str(port)], REPO, timeout=1200, log="pool-create-and-migrate.log")
        report["steps"].append({"step": "pool.create-and-migrate", **pool})
        report["pools"] = last_json(pool["stdout_tail"])["migrated"]
        (EVIDENCE / "test-infrastructure-started.json").write_text(json.dumps({**info, "pools": report["pools"]}, indent=2) + "\n", encoding="utf-8", newline="\n")
        short = session[:8]
        wave5 = f"mepa_wave5_test_{short}_pool_01"
        dsn = f"mysql:host=127.0.0.1;port={port};dbname={wave5}"
        env = {**os.environ, "XDEBUG_MODE": "off", "WAVE5_DSN": dsn, "WAVE5_USER": "root", "WAVE5_PASSWORD": "", "WAVE5_ALLOW_SYNTHETIC": "1", "PHP_BIN": PHP, "MEPA_PHP_BIN": PHP,
               "P010_DSN": dsn, "P010_USER": "root", "P010_PASSWORD": "", "P09_DSN": dsn, "P09_USER": "root", "P09_PASSWORD": "", "P08_DSN": dsn, "P08_USER": "root", "P08_PASSWORD": "",
               "P07_DSN": dsn, "P07_USER": "root", "P07_PASSWORD": "", "P06_DSN": dsn, "P06_USER": "root", "P06_PASSWORD": "", "P010_EVIDENCE_DIR": str(EVIDENCE),
               "P010_E2E_KEYRING_PATH": str(RING)}

        if not args.only_e2e:
            report["schema_parity"] = {}
            for name, script in [("p010", "validate-p010-finance-schema.cjs"), ("p09", "validate-p09-membership-schema.cjs"), ("p08", "validate-p08-files-schema.cjs"),
                                 ("p07", "validate-p07-physical-schema.cjs"), ("p06", "validate-p06-territorial-schema.cjs")]:
                out = EVIDENCE / f"{name}-schema-parity.json"
                result = run(["node", f"scripts/{script}", "--output", str(out)], REPO, env, log=f"{name}-schema-parity.log")
                report["schema_parity"][name] = {"rc": result["rc"], "status": json.loads(out.read_text(encoding="utf-8")).get("status") if out.exists() else None}

            report["f2a_phpunit"] = phpunit("f2a-payroll-foundation", "PayrollFoundationF2ATest", env)

            result = run(["node", "scripts/validate-payroll-f2a-contracts.cjs", "--output", str(EVIDENCE / "payroll-f2a-contract-validation.json")], REPO, env, timeout=5400, log="validator-payroll-f2a.log")
            report["f2a_mutations"] = {"rc": result["rc"], "tail": result["stdout_tail"][-400:].strip()}

            report["regressions"] = {}
            for name, test in [("finance_f1a", "FinanceCoreFoundationTest"), ("finance_f1b", "FinanceTransfersTest"), ("finance_f1c", "FinanceCoreF1CTest"), ("finance_f1d", "FinanceReportingF1DTest"),
                               ("finance_f1d_evidence", "FinanceReportingEvidenceTest"), ("territorial", "TerritorialVerticalTest"), ("files", "FilesVerticalTest"),
                               ("people_context", "PeopleContextAuthorityTest"), ("people_person", "PeoplePersonTest")]:
                report["regressions"][name] = phpunit(f"{name}-regression", test, env)

            validators = [
                ("finance_f1d_contracts", ["node", "scripts/validate-finance-reporting-contracts.cjs", "--output", str(EVIDENCE / "finance-reporting-contract-validation.json")]),
                ("finance_f1c_contracts", ["node", "scripts/validate-finance-core-f1c-contracts.cjs", "--output", str(EVIDENCE / "finance-f1c-contract-validation.json")]),
                ("finance_transfer_contracts", ["node", "scripts/validate-finance-transfer-contracts.cjs", "--output", str(EVIDENCE / "finance-transfer-contract-validation.json")]),
                ("finance_core_contracts", ["node", "scripts/validate-finance-contracts.cjs", "--output", str(EVIDENCE / "finance-core-contract-validation.json")]),
                ("http_contracts", ["node", "scripts/validate-wave5-http-contracts.cjs"]),
                ("ui_contracts", ["node", "scripts/validate-wave5-ui-contracts.cjs"]),
                ("auth_contracts", ["node", "scripts/validate-auth-contracts.cjs"]),
                ("application_contracts", ["node", "scripts/validate-wave5-application-contracts.cjs"]),
                ("database_docs", ["node", "scripts/validate-database-docs.cjs"]),
                ("wave5_schema_static", ["node", "scripts/validate-wave5-schema.cjs"]),
            ]
            report["validators"] = {}
            for name, cmd in validators:
                result = run(cmd, REPO, env, timeout=5400, log=f"validator-{name}.log")
                report["validators"][name] = {"rc": result["rc"], "tail": result["stdout_tail"][-300:].strip()}

            report["web"] = {}
            for name, cmd in [("tests", [NPM, "run", "test"]), ("lint", [NPM, "run", "lint"])]:
                result = run(cmd, WEB, os.environ.copy(), timeout=900, log=f"web-{name}.log")
                report["web"][name] = {"rc": result["rc"]}

        fixture = run([PHP, "vendor/phpunit/phpunit/phpunit", "tests/DatabaseV2/FinanceE2EFixtureTest.php"], API, env, log="e2e-fixture.log")
        report["steps"].append({"step": "finance_fixture", **fixture})
        if fixture["rc"] != 0:
            raise RuntimeError("finance fixture failed")
        manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
        secrets = [manifest["password"], manifest["f1d"]["readers"]["cons"]["password"], manifest["f1d"]["readers"]["own"]["password"],
                   manifest["f2a"]["readers"]["hr"]["password"], manifest["f2a"]["readers"]["viewer"]["password"]]

        build = run([NPM, "run", "build"], WEB, {**os.environ, "VITE_API_URL": "http://127.0.0.1:18080/api/v1"}, timeout=900, log="web-build.log")
        report["web_build"] = {"rc": build["rc"], "pwa": (WEB / "dist" / "sw.js").exists()}
        if build["rc"] != 0:
            raise RuntimeError("build failed")

        server_env = {**os.environ, "APP_ENV": "e2e", "APP_DEBUG": "false", "DB_CONNECTION": "mysql", "DB_HOST": "127.0.0.1", "DB_PORT": str(port),
                      "DB_DATABASE": wave5, "DB_USERNAME": "root", "DB_PASSWORD": "", "AUTH_ACTIVE_USER_STATUSES": "SYNTHETIC_READY",
                      "AUTH_ACTIVE_GRANT_STATUSES": "SYNTHETIC_READY", "ACADEMY_E2E_TEST_POLICY": "true", "CACHE_DRIVER": "file", "MEPA_PHP_BIN": PHP,
                      "FILES_KEYRING_PATH": str(RING), "P010_UI_EVIDENCE_DIR": str(EVIDENCE / "f1b-regression"), "P010_F1C_EVIDENCE_DIR": str(EVIDENCE / "f1c-regression"),
                      "P010_F1D_EVIDENCE_DIR": str(EVIDENCE / "f1d-regression"), "P010_F2A_EVIDENCE_DIR": str(EVIDENCE)}
        (EVIDENCE / "f1b-regression").mkdir(exist_ok=True)
        (EVIDENCE / "f1c-regression").mkdir(exist_ok=True)
        (EVIDENCE / "f1d-regression").mkdir(exist_ok=True)
        router = API / "vendor" / "laravel" / "framework" / "src" / "Illuminate" / "Foundation" / "resources" / "server.php"
        servers.append(subprocess.Popen([PHP, "-S", "127.0.0.1:18080", str(router)], cwd=API / "public", env=server_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        servers.append(subprocess.Popen([NPX, "vite", "preview", "--host", "127.0.0.1", "--port", "14173", "--strictPort"], cwd=WEB, env=os.environ.copy(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        report["servers_ready"] = {"api": wait_http("http://127.0.0.1:18080/api/v1/health"), "web": wait_http("http://127.0.0.1:14173/entrar")}

        # node + the CLI directly: npx.cmd would hand a --grep containing "|" to cmd.exe.
        cmd = ["node", str(WEB / "node_modules" / "@playwright" / "test" / "cli.js"), "test", "--config", "playwright.payroll-f2a.config.ts"]
        if args.grep:
            cmd += ["--grep", args.grep]
        if args.project:
            cmd += ["--project", args.project]
        playwright = run(cmd, WEB, server_env, timeout=5400, log="playwright.log")
        report["steps"].append({"step": "playwright_finance", **playwright})
        results_path = EVIDENCE / "playwright-results.json"
        report["playwright_finance"] = json.loads(results_path.read_text(encoding="utf-8")).get("stats", {}) if results_path.exists() else {"error": "no results", "rc": playwright["rc"]}
    finally:
        for proc in servers:
            if os.name == "nt":
                subprocess.run(["taskkill", "/PID", str(proc.pid), "/T", "/F"], capture_output=True)
            else:
                proc.terminate()
        report["servers_stopped"] = all(proc.poll() is not None for proc in servers)
        if log_path.exists():
            with open(log_path, "rb") as handle:
                handle.seek(log_offset)
                tail = handle.read()
            needles = [("fixture_password", s.encode()) for s in secrets] + [("key_ring_kek", kek.encode()), ("bank_account_number", b"00405555666677771234")]
            report["log_scan"] = {"bytes_scanned": len(tail), "leaks": sorted({label for label, needle in needles if needle and needle in tail})}
        else:
            report["log_scan"] = {"bytes_scanned": 0, "leaks": []}
        evidence_text = b"".join(p.read_bytes() for p in EVIDENCE.rglob("*") if p.is_file() and p.suffix in (".json", ".log", ".csv", ".xml"))
        report["evidence_scan"] = {"leaks": sorted({"secret" for s in secrets + [kek] if s and s.encode() in evidence_text})}
        if MANIFEST.exists():
            MANIFEST.unlink()
        # Playwright's failure traces carry page state (the readers' session tokens): never left in the tree.
        shutil.rmtree(WEB / "test-results", ignore_errors=True)
        report["playwright_output_removed"] = not (WEB / "test-results").exists()
        if RING.exists():
            RING.unlink()
        report["temporary_key_ring_removed"] = not RING.exists()
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
        (EVIDENCE / "f2a-run.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps({k: report.get(k) for k in ("head", "infrastructure_start", "schema_parity", "f2a_phpunit", "f2a_mutations", "regressions", "validators", "web", "web_build", "servers_ready", "playwright_finance",
                                                  "servers_stopped", "log_scan", "evidence_scan", "fixture_manifest_removed", "playwright_output_removed", "temporary_key_ring_removed", "tracked_rewrites_restored", "infrastructure_stop")}, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
