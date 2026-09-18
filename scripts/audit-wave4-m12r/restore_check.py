"""Independent, schema-only restore check. Never writes to the live database."""
import json
import os
import re
import secrets
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MYSQL = Path(r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe")
PHP = Path(r"C:\wamp64\bin\php\php8.1.33\php.exe")
DUMP = ROOT / "backups/schema/laravel_schema_wamp_20260917_180403.sql"
TARGET = "mepa_m12r_restore_" + secrets.token_hex(6)
BASE = [str(MYSQL), "--user=root", "--host=127.0.0.1", "--port=3306", "--batch", "--skip-column-names"]


def sql(statement, db=None):
    command = BASE + (["--database=" + db] if db else []) + ["--execute=" + statement]
    return subprocess.run(command, check=True, capture_output=True, text=True).stdout.strip().splitlines()


def snapshot(db):
    quoted = "'" + db + "'"
    queries = {
        "tables": f"SELECT table_name,engine,table_collation FROM information_schema.tables WHERE table_schema={quoted} AND table_type='BASE TABLE' ORDER BY table_name",
        "columns": f"SELECT table_name,column_name,column_type,is_nullable,COALESCE(column_default,'<NULL>'),extra,COALESCE(generation_expression,''),COALESCE(character_set_name,''),COALESCE(collation_name,'') FROM information_schema.columns WHERE table_schema={quoted} ORDER BY table_name,ordinal_position",
        "constraints": f"SELECT table_name,constraint_name,constraint_type FROM information_schema.table_constraints WHERE constraint_schema={quoted} ORDER BY table_name,constraint_name",
        "checks": f"SELECT tc.table_name,tc.constraint_name,cc.check_clause FROM information_schema.table_constraints tc JOIN information_schema.check_constraints cc ON cc.constraint_schema=tc.constraint_schema AND cc.constraint_name=tc.constraint_name WHERE tc.constraint_schema={quoted} ORDER BY tc.table_name,tc.constraint_name",
        "keys": f"SELECT table_name,constraint_name,column_name,ordinal_position,COALESCE(referenced_table_name,''),COALESCE(referenced_column_name,'') FROM information_schema.key_column_usage WHERE constraint_schema={quoted} ORDER BY table_name,constraint_name,ordinal_position",
        "references": f"SELECT table_name,constraint_name,referenced_table_name,update_rule,delete_rule FROM information_schema.referential_constraints WHERE constraint_schema={quoted} ORDER BY table_name,constraint_name",
        "indexes": f"SELECT table_name,index_name,non_unique,seq_in_index,column_name,COALESCE(sub_part,0),index_type,collation FROM information_schema.statistics WHERE table_schema={quoted} ORDER BY table_name,index_name,seq_in_index",
        "migrations": "SELECT id,migration,batch FROM migrations ORDER BY id",
    }
    return {key: sql(query, db) for key, query in queries.items()}


def main():
    dump = DUMP.read_bytes()
    ddl = re.sub(rb"(?m)^--[^\r\n]*", b"", dump)
    forbidden = [p for p in (rb"\bCREATE\s+DATABASE\s+`?laravel\b", rb"\bDROP\s+DATABASE\s+`?laravel\b", rb"\bUSE\s+`?laravel\b") if re.search(p, ddl, re.I)]
    inserts = re.findall(rb"(?mi)^INSERT\s+INTO\s+`?([^`\s(]+)", ddl)
    if forbidden or any(name != b"migrations" for name in inserts):
        raise RuntimeError("Dump contains prohibited database binding or business inserts")
    created = False
    try:
        sql(f"CREATE DATABASE `{TARGET}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        created = True
        subprocess.run(BASE + ["--database=" + TARGET], input=dump, check=True, capture_output=True)
        live = snapshot("laravel")
        restored = snapshot(TARGET)
        counts = {key: len(value) for key, value in restored.items()}
        diff = {key: {"live": len(live[key]), "restore": len(restored[key])} for key in live if live[key] != restored[key]}
        env = os.environ.copy()
        env.update({"PHP_BIN": str(PHP), "WAVE4_DSN": f"mysql:host=127.0.0.1;port=3306;dbname={TARGET}", "WAVE4_USER": "root", "WAVE4_PASSWORD": ""})
        validators = {}
        for name in ("validate-wave4-schema.cjs", "validate-wave4-contracts.cjs", "validate-database-docs.cjs"):
            run = subprocess.run(["node", str(ROOT / "scripts" / name)], cwd=ROOT, env=env, capture_output=True, text=True, timeout=120)
            validators[name] = {"exit": run.returncode, "stdout": run.stdout[-1200:], "stderr": run.stderr[-400:]}
        result = {"target": TARGET, "dump": str(DUMP.relative_to(ROOT)), "dump_bytes": len(dump), "business_inserts": 0, "counts": counts, "drift": diff, "validators": validators, "status": "PASS" if not diff and all(x["exit"] == 0 for x in validators.values()) else "FAIL"}
        output = ROOT / "docs/database/physical/wave4_m12r_audit/restore.json"
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
        print(json.dumps(result, indent=2))
        return 0 if result["status"] == "PASS" else 1
    finally:
        if created and TARGET.startswith("mepa_m12r_restore_"):
            sql(f"DROP DATABASE `{TARGET}`")


if __name__ == "__main__":
    raise SystemExit(main())
