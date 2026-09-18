"""Verify a schema reference without processlist.db context is identified and drained."""
import json
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "wave4-m121"))
import cleanup  # noqa: E402

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m121r_audit"
OUT.mkdir(parents=True, exist_ok=True)


def sql(q):
    r = cleanup._mysql(q)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout


def main():
    schema = cleanup.new_schema("wave4")
    sql(f"CREATE DATABASE `{schema}`")
    sql(f"CREATE TABLE `{schema}`.t (id INT PRIMARY KEY) ENGINE=InnoDB")
    sql(f"INSERT INTO `{schema}`.t VALUES (1)")
    proc = subprocess.Popen([cleanup.MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306", "--batch",
                             "--execute=" + f"SELECT SLEEP(20) FROM `{schema}`.t"],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        seen = []
        for _ in range(30):
            seen = cleanup.referencing_sessions(schema)
            if seen:
                break
            time.sleep(0.1)
        processlist_db = sql(f"SELECT id FROM information_schema.processlist WHERE db='{schema}'")
        locks = sql(f"SELECT LOCK_STATUS,OBJECT_TYPE,OBJECT_NAME FROM performance_schema.metadata_locks WHERE OBJECT_SCHEMA='{schema}'")
        t = cleanup.cleanup_run(schema.rsplit("_", 1)[-1], schema, 0, "PASS")
        try:
            proc.communicate(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
            proc.communicate()
        result = {"referencing_sessions": seen, "processlist_db": processlist_db.strip(),
                  "metadata_locks": locks.strip(), "client_returncode": proc.returncode,
                  "cleanup_result": t.cleanup_result, "blocking_sessions_seen": t.blocking_sessions_seen,
                  "schema_absent": not cleanup.schema_exists(schema)}
        (OUT / "metadata_probe.json").write_text(json.dumps(result, indent=2, default=str) + "\n", encoding="utf-8")
        print(json.dumps({k: v for k, v in result.items() if k != "blocking_sessions_seen"}))
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.communicate()
        if cleanup.schema_exists(schema):
            sql(f"DROP DATABASE IF EXISTS `{schema}`")


if __name__ == "__main__":
    main()
