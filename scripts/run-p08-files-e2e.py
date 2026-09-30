"""P0.8-I Documents/Files end-to-end runner (Test Infrastructure V2).

Sequence (every step recorded in docs/reviews/evidence/P0.8/e2e-run.json):
  1. tools/test-infrastructure/mysql_instance.py init-start --port auto          -> STARTED_VERIFIED
  2. tools/test-infrastructure/pool.py create-and-migrate                          -> Wave 3/4/5 pools (+ P0.5..P0.8 deltas)
  3. schema parity: P0.8 (bidirectional, 0 DDL, 17 controlled rows), P0.7 and P0.6 (regression)
  4. PHPUnit: FilesVerticalTest (F01-F21 + guards) + regressions (Physical, Territorial, People, Academy)
  5. validators: Files (structural + M1-M14 mutation probes), Physical (structural), Territorial, HTTP, UI, Auth,
     Application, People/Families, database docs
  6. web: vitest, eslint, production/PWA build (VITE_API_URL -> 127.0.0.1:18080)
  7. FilesE2EFixtureTest seeds the browser fixture through the API into a PRIVATE storage root and a Files key ring,
     both in the OS temp directory (never in the repository)
  8. Laravel on PHP's built-in server (APP_ENV=e2e, pool DSN, same root/ring, upload limits raised) + vite preview
  9. Playwright Files suite on 4 viewports (F22 + versions + sensitive download + visual QA)
  10. servers stopped; log scanned for key material and fixture plaintext; storage root scanned for plaintext, then
      removed; key ring + manifest deleted; mysql_instance.py stop -> STOPPED_VERIFIED
No development (3306/laravel) database is touched and no secret is written inside the repository.
"""
import argparse
import base64
import json
import os
import secrets
import shutil
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
EVIDENCE = REPO / "docs" / "reviews" / "evidence" / "P0.8"
NPX = "npx.cmd" if os.name == "nt" else "npx"
NPM = "npm.cmd" if os.name == "nt" else "npm"
MANIFEST = REPO / ".tmp" / "p08-e2e-fixtures.json"


def run(cmd, cwd, env=None, timeout=1800, log=None):
    started = time.time()
    proc = subprocess.run(cmd, cwd=cwd, env=env, capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=timeout)
    if log:
        (EVIDENCE / log).write_text(proc.stdout + ("\n--- stderr ---\n" + proc.stderr if proc.stderr.strip() else ""), encoding="utf-8", newline="\n")
    return {"cmd": " ".join(str(c) for c in cmd if "keyring" not in str(c)), "rc": proc.returncode, "seconds": round(time.time() - started, 1), "stdout_tail": proc.stdout[-3000:], "stderr_tail": proc.stderr[-1500:]}


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
    lines = [line for line in text.splitlines() if line.startswith(("OK (", "Tests:", "FAILURES", "ERRORS"))]
    return lines[-1] if lines else ""


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--skip-mutations", action="store_true")
    parser.add_argument("--skip-regressions", action="store_true")
    parser.add_argument("--only-e2e", action="store_true")
    args = parser.parse_args()

    EVIDENCE.mkdir(parents=True, exist_ok=True)
    report = {"runner": "scripts/run-p08-files-e2e.py", "steps": [], "started_at": time.strftime("%Y-%m-%dT%H:%M:%S%z")}
    sandbox = Path(tempfile.mkdtemp(prefix="mepa-files-e2e-"))
    storage_root = sandbox / "files-private"
    storage_root.mkdir(mode=0o700)
    keyring = sandbox / "files-keyring.json"
    kek = secrets.token_bytes(32)
    keyring.write_text(json.dumps({"active_version": 1, "keys": {"1": {"kek": base64.b64encode(kek).decode()}}}), encoding="utf-8")
    people_ring = sandbox / "people-keyring.json"
    people_ring.write_text(json.dumps({"active_version": 1, "keys": {"1": {"encryption": base64.b64encode(secrets.token_bytes(32)).decode(), "blind_index": base64.b64encode(secrets.token_bytes(32)).decode()}}}), encoding="utf-8")
    report["isolation"] = {"storage_root_inside_repository": str(storage_root).lower().startswith(str(REPO).lower()),
                           "keyring_inside_repository": str(keyring).lower().startswith(str(REPO).lower()), "location": "OS temp directory"}
    servers = []
    port = None
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
                    "P08_DSN": dsn, "P08_USER": "root", "P08_PASSWORD": "", "P07_DSN": dsn, "P07_USER": "root", "P07_PASSWORD": "",
                    "P06_DSN": dsn, "P06_USER": "root", "P06_PASSWORD": "", "PHP_BIN": PHP, "P08_EVIDENCE_DIR": str(EVIDENCE), "MEPA_PHP_BIN": PHP}

        if not args.only_e2e:
            for name, script in [("schema_parity", "validate-p08-files-schema.cjs"), ("p07_schema_parity", "validate-p07-physical-schema.cjs"), ("p06_schema_parity", "validate-p06-territorial-schema.cjs")]:
                out = EVIDENCE / f"{name.replace('_', '-')}.json"
                result = run(["node", f"scripts/{script}", "--output", str(out)], REPO, test_env, log=f"{name}.log")
                report[name] = {"rc": result["rc"], "status": json.loads(out.read_text(encoding="utf-8")).get("status") if out.exists() else None}

            files = run([PHP, "vendor/phpunit/phpunit/phpunit", "--log-junit", str(EVIDENCE / "files-junit.xml"), "tests/DatabaseV2/FilesVerticalTest.php"], API, test_env, timeout=2400, log="files.log")
            report["files_phpunit"] = {"rc": files["rc"], "summary": summary_line(files["stdout_tail"]), "seconds": files["seconds"]}

            if not args.skip_regressions:
                report["regressions"] = {}
                for name, test in [("physical", "PhysicalVerticalTest"), ("territorial", "TerritorialVerticalTest"), ("people_context", "PeopleContextAuthorityTest"),
                                   ("people_person", "PeoplePersonTest"), ("academy_remediation", "AcademyRemediationTest"), ("academy_contract", "AcademyContractClosureTest"),
                                   ("academy_certification", "AcademyAssessmentCertificationTest"), ("academy_http", "AcademyHttpAuthenticationTest")]:
                    result = run([PHP, "vendor/phpunit/phpunit/phpunit", "--log-junit", str(EVIDENCE / f"{name}-regression.xml"), f"tests/DatabaseV2/{test}.php"], API, test_env, timeout=2400, log=f"{name}-regression.log")
                    report["regressions"][name] = {"rc": result["rc"], "summary": summary_line(result["stdout_tail"])}

            validators = [
                ("files_contracts", ["node", "scripts/validate-files-contracts.cjs", "--output", str(EVIDENCE / "contract-validation.json")] + (["--structural-only"] if args.skip_mutations else [])),
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

        fixture_env = {**test_env, "P08_E2E_STORAGE_ROOT": str(storage_root), "P08_E2E_KEYRING_PATH": str(keyring)}
        fixture = run([PHP, "vendor/phpunit/phpunit/phpunit", "tests/DatabaseV2/FilesE2EFixtureTest.php"], API, fixture_env, log="e2e-fixture.log")
        report["steps"].append({"step": "files_fixture", **fixture})
        if fixture["rc"] != 0:
            raise RuntimeError("files fixture failed")

        build = run([NPM, "run", "build"], WEB, {**os.environ, "VITE_API_URL": "http://127.0.0.1:18080/api/v1"}, timeout=900, log="web-build.log")
        report["web_build"] = {"rc": build["rc"], "pwa": (WEB / "dist" / "sw.js").exists()}
        if build["rc"] != 0:
            raise RuntimeError("build failed")

        server_env = {**os.environ, "APP_ENV": "e2e", "APP_DEBUG": "false", "DB_CONNECTION": "mysql", "DB_HOST": "127.0.0.1", "DB_PORT": str(port),
                      "DB_DATABASE": wave5, "DB_USERNAME": "root", "DB_PASSWORD": "", "AUTH_ACTIVE_USER_STATUSES": "SYNTHETIC_READY",
                      "AUTH_ACTIVE_GRANT_STATUSES": "SYNTHETIC_READY", "ACADEMY_E2E_TEST_POLICY": "true", "PEOPLE_KEYRING_PATH": str(people_ring),
                      "PHYSICAL_KEYRING_PATH": str(people_ring), "FILES_STORAGE_ROOT": str(storage_root), "FILES_KEYRING_PATH": str(keyring),
                      "CACHE_DRIVER": "file", "MEPA_PHP_BIN": PHP}
        router = API / "vendor" / "laravel" / "framework" / "src" / "Illuminate" / "Foundation" / "resources" / "server.php"
        servers.append(subprocess.Popen([PHP, "-d", "upload_max_filesize=25M", "-d", "post_max_size=30M", "-S", "127.0.0.1:18080", str(router)], cwd=API / "public", env=server_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        servers.append(subprocess.Popen([NPX, "vite", "preview", "--host", "127.0.0.1", "--port", "14173", "--strictPort"], cwd=WEB, env=os.environ.copy(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
        report["servers_ready"] = {"api": wait_http("http://127.0.0.1:18080/api/v1/health"), "web": wait_http("http://127.0.0.1:14173/entrar")}

        playwright = run([NPX, "playwright", "test", "--config", "playwright.files.config.ts"], WEB, server_env, timeout=2400, log="playwright.log")
        report["steps"].append({"step": "playwright_files", **playwright})
        results = json.loads((EVIDENCE / "playwright-results.json").read_text(encoding="utf-8"))
        report["playwright_files"] = results.get("stats", {})
    finally:
        for proc in servers:
            if os.name == "nt":
                subprocess.run(["taskkill", "/PID", str(proc.pid), "/T", "/F"], capture_output=True)
            else:
                proc.terminate()
        leaked = []
        if log_path.exists():
            with open(log_path, "rb") as handle:
                handle.seek(log_offset)
                tail = handle.read()
            for label, needle in [("kek_b64", base64.b64encode(kek)), ("kek_raw", kek), ("fixture_acta", b"E2E-ACTA"), ("fixture_identity", b"E2E-IDENTIDADE"),
                                  ("pdf_body", b"%PDF-"), ("storage_key", b"v1/2026/"), ("disk_root", str(storage_root).encode())]:
                if needle in tail:
                    leaked.append(label)
            report["log_scan"] = {"bytes_scanned": len(tail), "leaks": leaked}
        plaintext_objects = []
        objects = 0
        if storage_root.exists():
            for item in storage_root.rglob("*"):
                if item.is_file():
                    objects += 1
                    data = item.read_bytes()
                    if not data.startswith(b"MEPAF1") or b"%PDF" in data or b"E2E-" in data:
                        plaintext_objects.append(item.name[:8] + "...")
        report["storage_scan"] = {"objects": objects, "non_mepaf1_or_plaintext": plaintext_objects}
        shutil.rmtree(sandbox, ignore_errors=True)
        report["cleanup"] = {"private_root_removed": not storage_root.exists(), "keyring_removed": not keyring.exists(), "sandbox_removed": not sandbox.exists()}
        if MANIFEST.exists():
            MANIFEST.unlink()
        report["fixture_manifest_removed"] = not MANIFEST.exists()
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
    print(json.dumps({k: report.get(k) for k in ("infrastructure_start", "schema_parity", "p07_schema_parity", "p06_schema_parity", "files_phpunit", "regressions", "validators", "web", "web_build", "servers_ready", "playwright_files", "log_scan", "storage_scan", "cleanup", "fixture_manifest_removed", "infrastructure_stop")}, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
