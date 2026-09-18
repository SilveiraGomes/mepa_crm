"""P0-TI.1 Test Infrastructure V2 pilot -- fixed database pool + migrate-once.

Creates the pool schemas (named to satisfy WaveFourCase/WaveThreeCase's
existing, unmodified name-guard regex) against the dedicated MysqlInstance,
then migrates each exactly once via the new
apps/api/tests/DatabaseV2/Support/migrate_pool_db.php CLI script -- the same
migration manifests WaveFourCase/WaveThreeCase already use, so there is no
drift between what V1 and V2 consider "the schema."
"""
import json
import subprocess
from pathlib import Path

MYSQL_CLIENT = r"C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe"
REPO_ROOT = Path(__file__).resolve().parents[2]
PHP_BIN = r"C:\wamp64\bin\php\php8.1.33\php.exe"

POOL = {
    "wave4": ["mepa_wave4_test_pool_01", "mepa_wave4_test_pool_02"],
    "wave3": ["mepa_wave3_test_pool_01"],
    # P0.3.5-A1: additive, mirrors the wave3/wave4 pilot pool exactly. Uses
    # migrate_pool_db.php's `wave5` target (WaveFiveCase, its own schema-name
    # guard) -- does not touch the wave3/wave4 pool entries or WaveFourCase.php.
    "wave5": ["mepa_wave5_test_pool_01"],
}


def _mysql_client(port: int, statement: str) -> None:
    result = subprocess.run(
        [MYSQL_CLIENT, "--user=root", "--host=127.0.0.1", f"--port={port}", "--batch", "--silent", f"--execute={statement}"],
        capture_output=True, text=True, timeout=30,
    )
    if result.returncode != 0:
        raise RuntimeError(f"mysql client failed for {statement!r}: {result.stderr}")


def create_pool(port: int) -> list[str]:
    created = []
    for wave, names in POOL.items():
        for name in names:
            _mysql_client(port, f"CREATE DATABASE IF NOT EXISTS `{name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
            created.append(name)
    return created


def migrate_once(port: int) -> dict:
    results = {}
    for wave, names in POOL.items():
        env_prefix = wave.upper()
        for name in names:
            env = {
                f"{env_prefix}_DSN": f"mysql:host=127.0.0.1;port={port};dbname={name}",
                f"{env_prefix}_USER": "root",
                f"{env_prefix}_PASSWORD": "",
                f"{env_prefix}_ALLOW_SYNTHETIC": "1",
            }
            import os
            full_env = {**os.environ, **env}
            proc = subprocess.run(
                [PHP_BIN, str(REPO_ROOT / "apps/api/tests/DatabaseV2/Support/migrate_pool_db.php"), wave],
                capture_output=True, text=True, timeout=180, cwd=str(REPO_ROOT), env=full_env,
            )
            if proc.returncode != 0:
                raise RuntimeError(f"migrate_pool_db.php {wave} for {name} failed (rc={proc.returncode}): {proc.stderr}\n{proc.stdout}")
            results[name] = json.loads(proc.stdout)
    return results


def reset_database(port: int, name: str) -> None:
    """Fallback CLI-level reset (the PHPUnit path normally does this itself
    inside PooledWaveFourCase/PooledWaveThreeCase::setUpBeforeClass()); kept
    here so the pool can also be reset between two independent PHPUnit
    process invocations without relying on that class ever running.
    """
    rows = subprocess.run(
        [MYSQL_CLIENT, "--user=root", "--host=127.0.0.1", f"--port={port}", "--batch", "--silent",
         f"--execute=SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA='{name}' AND TABLE_NAME != 'migrations'"],
        capture_output=True, text=True, timeout=30,
    )
    tables = [t for t in rows.stdout.splitlines() if t.strip()]
    if not tables:
        return
    statements = ["SET FOREIGN_KEY_CHECKS=0"] + [f"TRUNCATE TABLE `{name}`.`{t}`" for t in tables] + ["SET FOREIGN_KEY_CHECKS=1"]
    _mysql_client(port, "; ".join(statements))


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["create-and-migrate"])
    parser.add_argument("--port", type=int, required=True)
    args = parser.parse_args()

    created = create_pool(args.port)
    migrated = migrate_once(args.port)
    print(json.dumps({"created": created, "migrated": migrated}, indent=2))
