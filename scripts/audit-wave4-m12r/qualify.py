"""Independent run namespaces and collection for Wave 4 qualification."""
import concurrent.futures
import json
import os
import secrets
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "docs/database/physical/wave4_m12r_audit"
OUT.mkdir(parents=True, exist_ok=True)
MYSQL = r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe"
PHP = r"C:\wamp64\bin\php\php8.1.33\php.exe"


def sql(statement):
    return subprocess.run([MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306", "--execute=" + statement], capture_output=True, text=True, timeout=45, check=True)


def run(label, suite, wave, filter_text=None):
    run_id = secrets.token_hex(12)
    # The existing test bootstrap requires dedicated synthetic prefixes per suite.
    prefix = wave if isinstance(wave, str) else f"WAVE{wave}"
    schema = f"mepa_{prefix.lower()}_test_m12r_{run_id}"
    started = datetime.now(timezone.utc).isoformat()
    start = time.monotonic()
    result = {"label": label, "run_id": run_id, "schema": schema, "started_utc": started, "suite": suite, "filter": filter_text}
    created = False
    try:
        sql(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        created = True
        env = os.environ.copy()
        env.update({prefix + "_DSN": f"mysql:host=127.0.0.1;port=3306;dbname={schema}", prefix + "_USER": "root", prefix + "_PASSWORD": "", prefix + "_ALLOW_SYNTHETIC": "1", "DB_CAPABILITIES_DSN": f"mysql:host=127.0.0.1;port=3306;dbname={schema}", "DB_CAPABILITIES_USER": "root", "DB_CAPABILITIES_PASSWORD": "", "PHP_BIN": PHP, "XDEBUG_MODE": "off"})
        xml = OUT / f"{label}_{run_id}.xml"
        command = [PHP, "vendor/phpunit/phpunit/phpunit", f"tests/Database/{suite}.php", "--log-junit", str(xml)]
        if filter_text:
            command += ["--filter", filter_text]
        proc = subprocess.Popen(command, cwd=ROOT / "apps/api", env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        result["pid"] = proc.pid
        try:
            stdout, stderr = proc.communicate(timeout=300)
            result["exit"] = proc.returncode
            log = stdout + stderr
        except subprocess.TimeoutExpired:
            # The PID comes directly from our child process. Terminate its worker tree.
            subprocess.run(["taskkill", "/PID", str(proc.pid), "/T", "/F"], capture_output=True, text=True)
            stdout, stderr = proc.communicate(timeout=15)
            result["exit"] = 124
            log = "FAIL_SAFE_TIMEOUT\n" + stdout + stderr
        logfile = OUT / f"{label}_{run_id}.log"
        logfile.write_text(log, encoding="utf-8")
        result.update({"log": str(logfile.relative_to(ROOT)), "junit": str(xml.relative_to(ROOT))})
    except Exception as err:
        result.update({"exit": 125, "error": repr(err)})
    finally:
        result["ended_utc"] = datetime.now(timezone.utc).isoformat()
        result["seconds"] = round(time.monotonic() - start, 2)
        if created:
            try:
                sql(f"DROP DATABASE `{schema}`")
                result["cleanup"] = "PASS"
            except Exception as err:
                result["cleanup"] = "FAIL: " + repr(err)
        else:
            result["cleanup"] = "NOT_CREATED"
    print(json.dumps(result), flush=True)
    return result


def main(group):
    rows = []
    if group == "smoke":
        for n in range(1, 6):
            item = run(f"smoke_{n:02d}", "WaveFourCommitAuthorizationTest", 4)
            rows.append(item)
            if item["exit"] != 0 or item["cleanup"] != "PASS":
                break
    elif group == "isolated":
        for n in range(1, 51):
            rows.append(run(f"isolated_{n:02d}", "WaveThreeCheckinConcurrencyTest", 3, "test_same_person_different_sessions"))
    elif group == "parallel":
        for n in range(1, 11):
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                tasks = [pool.submit(run, f"parallel_{n:02d}_{i}", "WaveThreeCheckinConcurrencyTest", 3) for i in range(2)]
                rows.extend(task.result() for task in tasks)
    elif group == "loaded":
        for n in range(1, 11):
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                a = pool.submit(run, f"loaded_{n:02d}_temporal", "WaveThreeCheckinConcurrencyTest", 3, "test_same_person_different_sessions")
                b = pool.submit(run, f"loaded_{n:02d}_child", "WaveFourChildCheckinBoundaryTest", 4, "test_concurrent")
                rows.extend([a.result(), b.result()])
    elif group == "progress":
        rows.append(run("progress_boundary", "WaveFourProgressCommitBoundaryTest", 4))
    elif group == "scope":
        rows.append(run("discipleship_scope", "WaveFourDiscipleshipScopeTest", 4))
    elif group == "full":
        suites = [
            ("WaveOnePhysicalTest", 1), ("WaveTwoPhysicalTest", 2),
            ("WaveTwoConcurrencyTest", 2), ("WaveTwoIndependentReconciliationTest", "WAVE2F"),
            ("WaveTwoTransferConcurrencyTest", "WAVE2M1"), ("WaveTwoM1IndependentAuditTest", "M1AUDIT"),
            ("WaveTwoTransferMigrationSafetyTest", "M1SAFETY"), ("WaveThreePhysicalTest", 3),
            ("WaveThreeDomainTest", 3), ("WaveThreeCheckinConcurrencyTest", 3),
            ("WaveFourPhysicalTest", 4), ("WaveFourCommitAuthorizationTest", 4),
            ("WaveFourProgressCommitBoundaryTest", 4), ("WaveFourCheckoutConcurrencyTest", 4),
            ("WaveFourChildCheckinBoundaryTest", 4), ("WaveFourChildrenSafetyTest", 4),
            ("WaveFourDiscipleshipScopeTest", 4), ("WaveFourEvangelismTemporalBoundaryTest", 4),
            ("WaveFourEvangelismTest", 4), ("WaveFourIndependentAuditM1RTest", 4),
            ("WaveFourTemporalAuthorizationTest", 4),
        ]
        for n, (suite, wave) in enumerate(suites, 1):
            item = run(f"full_{n:02d}_{suite}", suite, wave)
            rows.append(item)
            if item["exit"] != 0 or item["cleanup"] != "PASS":
                break
    else:
        raise SystemExit("group must be smoke, isolated, parallel, loaded, progress, or scope")
    (OUT / f"{group}.json").write_text(json.dumps(rows, indent=2) + "\n", encoding="utf-8")
    passed = sum(r.get("exit") == 0 and r.get("cleanup") == "PASS" for r in rows)
    print(json.dumps({"group": group, "passed": passed, "total": len(rows)}), flush=True)
    return 0 if passed == len(rows) else 1


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1]))
