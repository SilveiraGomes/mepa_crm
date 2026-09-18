"""P0.3.4-M1.2.1 root-cause diagnostic (read-only against test-owned schemas).

For each run: create a disposable schema, run the real PHPUnit suite against it,
and the MOMENT the child process reports exit, snapshot MySQL server-side state
for that exact schema (information_schema.processlist, performance_schema.threads,
performance_schema.metadata_locks, information_schema.innodb_trx,
performance_schema.data_lock_waits) BEFORE issuing any DROP. Then attempt DROP
with a generous bounded retry loop, snapshotting again on every failed attempt,
so a slow cleanup is captured live instead of merely timed out on.

This script only touches its own throwaway `mepa_wave3_test_m121diag_*` schemas.
It never touches `laravel` and refuses any schema name that is not exactly of
that synthetic form.
"""
import concurrent.futures
import json
import os
import re
import secrets
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "docs/database/physical/wave4_m121_diagnostic"
OUT.mkdir(parents=True, exist_ok=True)
MYSQL = r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe"
PHP = r"C:\wamp64\bin\php\php8.1.33\php.exe"
SCHEMA_RE = re.compile(r"^mepa_wave3_test_m121diag_[0-9a-f]{24}$")
FILTER_TEXT = os.environ.get("M121_FILTER", "test_same_person_different_sessions")
if FILTER_TEXT == "NONE":
    FILTER_TEXT = None


def mysql_exec(statement, timeout=30):
    t0 = time.monotonic()
    r = subprocess.run(
        [MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306", "--table", "--execute=" + statement],
        capture_output=True, text=True, timeout=timeout,
    )
    return {"seconds": round(time.monotonic() - t0, 3), "returncode": r.returncode, "stdout": r.stdout, "stderr": r.stderr}


def snapshot(schema):
    assert SCHEMA_RE.match(schema), "refusing to snapshot a non-synthetic schema"
    q = (
        f"SELECT id, user, host, db, command, time, state, LEFT(info,200) AS info "
        f"FROM information_schema.processlist WHERE db = '{schema}';\n"
        f"SELECT * FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA = '{schema}';\n"
        f"SELECT trx_id, trx_state, trx_started, trx_mysql_thread_id, trx_query "
        f"FROM information_schema.innodb_trx t "
        f"JOIN information_schema.processlist p ON p.id = t.trx_mysql_thread_id "
        f"WHERE p.db = '{schema}';\n"
        f"SELECT w.*, tr.processlist_id, tb.processlist_id AS blocking_processlist_id "
        f"FROM performance_schema.data_lock_waits w "
        f"JOIN performance_schema.threads tr ON tr.thread_id = w.requesting_thread_id "
        f"LEFT JOIN performance_schema.threads tb ON tb.thread_id = w.blocking_thread_id "
        f"JOIN information_schema.processlist p ON p.id = tr.processlist_id WHERE p.db = '{schema}';\n"
    )
    return mysql_exec(q, timeout=20)["stdout"]


def kill_thread(thread_id):
    return mysql_exec(f"KILL {int(thread_id)}", timeout=10)


def sessions_for_schema(schema):
    assert SCHEMA_RE.match(schema)
    out = mysql_exec(
        f"SELECT id, command, time, state FROM information_schema.processlist WHERE db = '{schema}'",
        timeout=20,
    )["stdout"]
    rows = []
    lines = [l for l in out.splitlines() if l.strip()]
    for line in lines[1:]:
        parts = line.split("\t")
        if len(parts) >= 1 and parts[0].isdigit():
            rows.append(parts)
    return rows


def run_one(label):
    run_id = secrets.token_hex(12)
    schema = f"mepa_wave3_test_m121diag_{run_id}"
    assert SCHEMA_RE.match(schema)
    record = {"label": label, "run_id": run_id, "schema": schema, "started_utc": datetime.now(timezone.utc).isoformat()}
    t_created = time.monotonic()
    mysql_exec(f"CREATE DATABASE `{schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    env = os.environ.copy()
    env.update({
        "WAVE3_DSN": f"mysql:host=127.0.0.1;port=3306;dbname={schema}",
        "WAVE3_USER": "root", "WAVE3_PASSWORD": "", "WAVE3_ALLOW_SYNTHETIC": "1",
        "PHP_BIN": PHP, "XDEBUG_MODE": "off",
    })
    xml = OUT / f"{label}_{run_id}.xml"
    cmd = [PHP, "vendor/phpunit/phpunit/phpunit", "tests/Database/WaveThreeCheckinConcurrencyTest.php",
           "--log-junit", str(xml)]
    if FILTER_TEXT:
        cmd += ["--filter", FILTER_TEXT]
    t_launch = time.monotonic()
    proc = subprocess.Popen(cmd, cwd=ROOT / "apps/api", env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        stdout, stderr = proc.communicate(timeout=180)
        record["exit"] = proc.returncode
    except subprocess.TimeoutExpired:
        subprocess.run(["taskkill", "/PID", str(proc.pid), "/T", "/F"], capture_output=True, text=True)
        stdout, stderr = proc.communicate(timeout=15)
        record["exit"] = 124
    t_process_exited = time.monotonic()
    record["test_seconds"] = round(t_process_exited - t_launch, 2)
    (OUT / f"{label}_{run_id}.log").write_text((stdout or "") + (stderr or ""), encoding="utf-8")

    # THE INSTRUMENT: snapshot immediately, before any DROP attempt.
    immediate = snapshot(schema)
    record["immediate_post_exit_snapshot"] = immediate
    immediate_rows = sessions_for_schema(schema)
    record["immediate_session_count"] = len(immediate_rows)
    record["immediate_sessions_raw"] = immediate_rows

    # Bounded retry DROP loop with live diagnostics on every failed attempt.
    attempts = []
    dropped = False
    t_drop_start = time.monotonic()
    deadline = t_drop_start + 90
    attempt_n = 0
    while time.monotonic() < deadline and not dropped:
        attempt_n += 1
        a0 = time.monotonic()
        result = mysql_exec(f"DROP DATABASE IF EXISTS `{schema}`", timeout=20)
        elapsed = round(time.monotonic() - a0, 3)
        remaining_sessions = sessions_for_schema(schema)
        attempts.append({
            "n": attempt_n, "elapsed": elapsed, "returncode": result["returncode"],
            "stderr": result["stderr"].strip(), "remaining_sessions_after": len(remaining_sessions),
        })
        if result["returncode"] == 0 and len(remaining_sessions) == 0:
            dropped = True
        else:
            time.sleep(0.5)
    record["drop_attempts"] = attempts
    record["cleanup_seconds"] = round(time.monotonic() - t_drop_start, 2)
    record["cleanup_result"] = "PASS" if dropped else "FAIL"
    record["total_seconds"] = round(time.monotonic() - t_created, 2)
    print(json.dumps({k: v for k, v in record.items() if k not in ("immediate_post_exit_snapshot",)}), flush=True)
    return record


def main(n_pairs):
    rows = []
    for round_i in range(n_pairs):
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futs = [pool.submit(run_one, f"diag_{round_i:02d}_{i}") for i in range(2)]
            rows.extend(f.result() for f in futs)
    (OUT / "diagnostic.json").write_text(json.dumps(rows, indent=2) + "\n", encoding="utf-8")
    slow = [r for r in rows if r["cleanup_seconds"] > 3 or len(r["immediate_sessions_raw"]) > 0]
    print(json.dumps({"total_runs": len(rows), "slow_or_lingering": len(slow)}), flush=True)
    return 0


if __name__ == "__main__":
    n = int(sys.argv[1]) if len(sys.argv) > 1 else 6
    raise SystemExit(main(n))
