"""P0-TI.1 Test Infrastructure V2 pilot -- dedicated MySQL instance lifecycle.

Starts/stops a MySQL 8.4 server instance completely separate from the app's
own WAMP-managed server (127.0.0.1:3306 / `laravel`): its own datadir, its
own port, its own process. Never touches the app database. See
docs/database/physical/test_infrastructure_v2_design.md for the full design;
this module implements only the START/STOP lifecycle (section 12/15).
"""
import json
import subprocess
import time
from pathlib import Path
from typing import Optional

MYSQL_BASEDIR = Path(r"C:\wamp64\bin\mysql\mysql8.4.7")
MYSQLD = MYSQL_BASEDIR / "bin" / "mysqld.exe"
MYSQLADMIN = MYSQL_BASEDIR / "bin" / "mysqladmin.exe"

DEFAULT_PORT = 3307
READY_TIMEOUT_SECONDS = 60
SHUTDOWN_TIMEOUT_SECONDS = 30


class MysqlInstance:
    """One dedicated, disposable mysqld instance. Never the app's own server."""

    def __init__(self, datadir: Path, port: int = DEFAULT_PORT):
        assert port != 3306, "must never reuse the app DB's port"
        self.datadir = Path(datadir)
        self.port = port
        self.process: Optional[subprocess.Popen] = None
        self.log_path = self.datadir.parent / "mysqld.log"

    def initialize(self) -> None:
        self.datadir.mkdir(parents=True, exist_ok=True)
        result = subprocess.run(
            [str(MYSQLD), f"--datadir={self.datadir}", f"--basedir={MYSQL_BASEDIR}", "--initialize-insecure"],
            capture_output=True, text=True, timeout=120,
        )
        if result.returncode != 0:
            raise RuntimeError(f"mysqld --initialize-insecure failed: {result.stderr}")

    def start(self) -> None:
        self.log_path.parent.mkdir(parents=True, exist_ok=True)
        log_file = open(self.log_path, "wb")
        self.process = subprocess.Popen(
            [
                str(MYSQLD),
                f"--datadir={self.datadir}",
                f"--basedir={MYSQL_BASEDIR}",
                f"--port={self.port}",
                "--bind-address=127.0.0.1",
                f"--socket={self.datadir}/mysql.sock",
                f"--pid-file={self.datadir}/mysqld.pid",
                "--innodb-buffer-pool-size=128M",
                # No --skip-name-resolve: --initialize-insecure only creates
                # 'root'@'localhost', and skip-name-resolve would stop MySQL
                # reverse-resolving a 127.0.0.1 TCP connection to 'localhost'
                # to match that grant, rejecting every connection.
            ],
            stdout=log_file, stderr=subprocess.STDOUT,
        )
        self._wait_ready()

    def _wait_ready(self) -> None:
        deadline = time.monotonic() + READY_TIMEOUT_SECONDS
        while time.monotonic() < deadline:
            if self.process.poll() is not None:
                raise RuntimeError(f"mysqld exited early (code {self.process.returncode}); see {self.log_path}")
            ping = subprocess.run(
                [str(MYSQLADMIN), "--host=127.0.0.1", f"--port={self.port}", "--protocol=TCP", "--user=root", "ping"],
                capture_output=True, text=True, timeout=10,
            )
            if ping.returncode == 0:
                return
            time.sleep(1)
        raise RuntimeError(f"mysqld did not become ready within {READY_TIMEOUT_SECONDS}s; see {self.log_path}")

    def stop(self) -> dict:
        """Stops mysqld cleanly, then best-effort removes the datadir. Per
        section 15: a slow/failed datadir removal AFTER mysqld is confirmed
        stopped is INFRASTRUCTURE_RESULT=WARN, never a product/evidence FAIL.
        """
        result = {"mysqld_stopped": False, "datadir_removed": False, "infrastructure_result": "PASS", "notes": ""}
        if self.process is not None and self.process.poll() is None:
            subprocess.run(
                [str(MYSQLADMIN), "--host=127.0.0.1", f"--port={self.port}", "--protocol=TCP", "--user=root", "shutdown"],
                capture_output=True, text=True, timeout=30,
            )
            try:
                self.process.wait(timeout=SHUTDOWN_TIMEOUT_SECONDS)
                result["mysqld_stopped"] = True
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait(timeout=10)
                result["mysqld_stopped"] = True
                result["infrastructure_result"] = "WARN"
                result["notes"] += "mysqladmin shutdown timed out, had to kill(); "
        else:
            result["mysqld_stopped"] = True

        import shutil
        try:
            shutil.rmtree(self.datadir.parent, ignore_errors=False)
            result["datadir_removed"] = True
        except OSError as exc:
            result["datadir_removed"] = False
            result["infrastructure_result"] = "WARN"
            result["notes"] += f"datadir removal failed after clean stop: {exc}; "
        return result


if __name__ == "__main__":
    import argparse
    import tempfile
    import uuid

    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["init-start", "stop"])
    parser.add_argument("--datadir", default=None)
    parser.add_argument("--port", type=int, default=DEFAULT_PORT)
    args = parser.parse_args()

    if args.action == "init-start":
        session_id = uuid.uuid4().hex[:12]
        datadir = Path(args.datadir) if args.datadir else Path(tempfile.gettempdir()) / "mepa-test-mysql" / session_id / "data"
        inst = MysqlInstance(datadir, args.port)
        inst.initialize()
        inst.start()
        print(json.dumps({"status": "STARTED", "datadir": str(datadir), "port": args.port}))
    else:
        if not args.datadir:
            raise SystemExit("--datadir required for stop")
        inst = MysqlInstance(Path(args.datadir), args.port)
        print(json.dumps(inst.stop()))
