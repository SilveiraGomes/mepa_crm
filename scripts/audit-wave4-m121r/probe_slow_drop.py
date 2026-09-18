"""Attempt a slow DROP/client-kill observation on one owned disposable schema."""
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


def sql(q, timeout=30):
    result = cleanup._mysql(q, timeout=timeout)
    if result.returncode:
        raise RuntimeError(result.stderr)
    return result.stdout


def main():
    schema = cleanup.new_schema("wave4")
    report = {"schema": schema, "tables": 127, "client_killed": False, "samples": []}
    sql(f"CREATE DATABASE `{schema}`")
    client = None
    try:
        ddl = [f"CREATE TABLE `{schema}`.parent (id INT PRIMARY KEY) ENGINE=InnoDB"]
        ddl += [f"CREATE TABLE `{schema}`.child_{i:03d} (id INT PRIMARY KEY, parent_id INT, "
                f"KEY ix_parent (parent_id), CONSTRAINT fk_child_{i:03d} FOREIGN KEY (parent_id) "
                f"REFERENCES `{schema}`.parent(id)) ENGINE=InnoDB" for i in range(126)]
        sql(";".join(ddl), timeout=180)
        report["created_tables"] = int(sql(f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{schema}'").strip())
        client = subprocess.Popen([cleanup.MYSQL, "--user=root", "--host=127.0.0.1", "--port=3306",
                                   "--execute=" + f"DROP DATABASE `{schema}`"],
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        start = time.monotonic()
        observed = False
        while time.monotonic() - start < 15 and client.poll() is None:
            q = ("SELECT id,time,state FROM information_schema.processlist WHERE info LIKE "
                 f"'DROP DATABASE%{schema}%'")
            rows = sql(q)
            if rows.strip():
                observed = True
                report["samples"].append({"elapsed": round(time.monotonic() - start, 3), "processlist": rows.strip(),
                                          "locks": sql(f"SELECT OBJECT_TYPE,LOCK_STATUS FROM performance_schema.metadata_locks "
                                                       f"WHERE OBJECT_SCHEMA='{schema}' LIMIT 10").strip(),
                                          "data_lock_waits": sql("SELECT COUNT(*) FROM performance_schema.data_lock_waits").strip(),
                                          "innodb_trx": sql("SELECT COUNT(*) FROM information_schema.innodb_trx").strip()})
                if time.monotonic() - start > 0.2:
                    client.kill()
                    report["client_killed"] = True
                    break
            time.sleep(0.02)
        client.communicate(timeout=20)
        report["drop_seen_on_server"] = observed
        report["client_returncode"] = client.returncode
        deadline = time.monotonic() + 150
        while cleanup.schema_exists(schema) and time.monotonic() < deadline:
            time.sleep(0.5)
        report["schema_absent_after_client_exit"] = not cleanup.schema_exists(schema)
        report["innodb_status_excerpt"] = sql("SHOW ENGINE INNODB STATUS")[:1200]
        print(json.dumps({k: v for k, v in report.items() if k != "innodb_status_excerpt"}))
    finally:
        if client is not None and client.poll() is None:
            client.kill()
            client.communicate()
        if cleanup.schema_exists(schema):
            cleanup.cleanup_run(schema.rsplit("_", 1)[-1], schema, 3, "FAIL", OUT)
        report["final_schema_absent"] = not cleanup.schema_exists(schema)
        (OUT / "slow_drop_probe.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
