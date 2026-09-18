"""P0-TI.1 Test Infrastructure V2 pilot -- dedicated MySQL instance lifecycle.

Starts/stops a MySQL 8.4 server instance completely separate from the app's
own WAMP-managed server (127.0.0.1:3306 / `laravel`): its own datadir, its
own port, its own process. Never touches the app database. See
docs/database/physical/test_infrastructure_v2_design.md for the full design;
this module implements only the START/STOP lifecycle (section 12/15).

P0.3.5-A1.0: `stop()` was rewritten to fix a false-success bug (see
docs/reviews/P0.3.5_A1_0_pre_audit_evidence_hygiene.md) -- a `stop` invoked
from a separate CLI process than the one that called `start()` has no
in-memory `self.process` handle, and the previous implementation silently
reported `mysqld_stopped: true` in that case without ever sending a
shutdown. `stop()` now resolves the instance's identity from mysqld's own
`--pid-file` when no in-memory handle exists, verifies OWNERSHIP by
cross-checking the live process's command line against this instance's own
datadir and port (never by PID number or port number alone), always
attempts a protocol-level graceful shutdown (safe against any server,
verified owner or not), polls for the port to actually stop answering
before ever claiming success, and returns `STOP_FAILED` -- never a false
`SUCCESS` -- when it cannot independently confirm the instance is gone.
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
SHUTDOWN_POLL_INTERVAL_SECONDS = 1
FALLBACK_KILL_TIMEOUT_SECONDS = 10
APP_DATABASE_PORT = 3306


class MysqlInstance:
    """One dedicated, disposable mysqld instance. Never the app's own server."""

    def __init__(self, datadir: Path, port: int = DEFAULT_PORT):
        if port == APP_DATABASE_PORT:
            # A real, unconditionally-raised guard (not `assert`, which Python
            # strips under -O) -- this class must never be pointed at the app's
            # own MySQL server.
            raise ValueError(f"refusing to manage a MysqlInstance on the app database port ({APP_DATABASE_PORT})")
        self.datadir = Path(datadir)
        self.port = port
        self.process: Optional[subprocess.Popen] = None
        self.log_path = self.datadir.parent / "mysqld.log"
        self.session_path = self.datadir.parent / "session.json"

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
        # Persistent identity (Section 5, P0.3.5-A1.0): a `stop` invoked from a
        # different CLI process than this `start()` has no Python object to
        # inspect. Record enough on disk -- alongside mysqld's own --pid-file --
        # that a separate invocation can find and verify this exact instance,
        # not just guess from the port number.
        self.session_path.write_text(json.dumps({
            "port": self.port,
            "datadir": str(self.datadir),
            "pid": self.process.pid,
            "started_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "mysqld_path": str(MYSQLD),
        }, indent=2), encoding="utf-8")

    def _wait_ready(self) -> None:
        deadline = time.monotonic() + READY_TIMEOUT_SECONDS
        while time.monotonic() < deadline:
            if self.process.poll() is not None:
                raise RuntimeError(f"mysqld exited early (code {self.process.returncode}); see {self.log_path}")
            if self._ping():
                return
            time.sleep(1)
        raise RuntimeError(f"mysqld did not become ready within {READY_TIMEOUT_SECONDS}s; see {self.log_path}")

    def _ping(self) -> bool:
        """True if a MySQL server answers on this instance's host:port right now."""
        try:
            result = subprocess.run(
                [str(MYSQLADMIN), "--host=127.0.0.1", f"--port={self.port}", "--protocol=TCP", "--user=root", "ping"],
                capture_output=True, text=True, timeout=10,
            )
        except subprocess.TimeoutExpired:
            return False
        return result.returncode == 0

    def _read_pid_file(self) -> Optional[int]:
        """Reads mysqld's own --pid-file -- authoritative and always present
        while that mysqld is running, regardless of which process asks."""
        try:
            return int((self.datadir / "mysqld.pid").read_text(encoding="utf-8").strip())
        except (OSError, ValueError):
            return None

    @staticmethod
    def _process_identity(pid: int) -> Optional[dict]:
        """Looks up a live process's name and full command line via WMI. Returns
        None if no process with that PID exists right now. This is a point-in-time
        check -- callers must not treat a positive result as valid indefinitely
        (PIDs are reused by the OS), which is why ownership is re-verified
        immediately before any fallback kill, not cached from an earlier check."""
        try:
            proc = subprocess.run(
                ["powershell.exe", "-NoProfile", "-NonInteractive", "-Command",
                 f"Get-CimInstance Win32_Process -Filter 'ProcessId={int(pid)}' | "
                 "Select-Object ProcessId,Name,CommandLine | ConvertTo-Json -Compress"],
                capture_output=True, text=True, timeout=15,
            )
        except subprocess.TimeoutExpired:
            return None
        if proc.returncode != 0 or not proc.stdout.strip():
            return None
        try:
            return json.loads(proc.stdout)
        except json.JSONDecodeError:
            return None

    def _owns(self, identity: Optional[dict]) -> bool:
        """True only if `identity` is a mysqld process whose OWN command line
        names exactly this instance's datadir and port. Never trust a PID
        number or a port number alone (Section 7/8: ownership must be proven,
        not assumed) -- the command line is the one thing an unrelated process
        cannot coincidentally share with this instance."""
        if not identity:
            return False
        name = (identity.get("Name") or "").lower()
        cmdline = identity.get("CommandLine") or ""
        if "mysqld" not in name:
            return False
        return f"--datadir={self.datadir}" in cmdline and f"--port={self.port}" in cmdline

    def stop(self) -> dict:
        """Stops mysqld and does not return SUCCESS unless independently
        reconfirmed: the port stops answering AND (when a PID could be
        identified) that PID's process is gone. Per section 15: a slow/failed
        datadir removal AFTER the instance is confirmed stopped is
        INFRASTRUCTURE_RESULT=WARN, never a product/evidence FAIL -- but a
        stop that cannot confirm the instance actually stopped is
        STOP_FAILED, never a false SUCCESS.
        """
        result = {
            "status": "SUCCESS",
            "instance_stopped_confirmed": False,
            "ownership_verified": False,
            "graceful_shutdown_sent": False,
            "fallback_kill_used": False,
            "datadir_removed": False,
            "infrastructure_result": "PASS",
            "notes": "",
        }

        if not self._ping():
            # T3: already stopped. Idempotent -- no shutdown action is taken,
            # and none is falsely claimed either.
            result["status"] = "ALREADY_STOPPED"
            result["instance_stopped_confirmed"] = True
            self._cleanup_datadir(result)
            return result

        # Resolve identity: prefer this process's own handle from start() (T1);
        # otherwise fall back to mysqld's own --pid-file (T2: a separate CLI
        # invocation never has a Python object handle to begin with).
        pid = None
        if self.process is not None and self.process.poll() is None:
            pid = self.process.pid
        if pid is None:
            pid = self._read_pid_file()

        identity = self._process_identity(pid) if pid else None
        result["ownership_verified"] = self._owns(identity)
        if pid is None:
            result["notes"] += "no PID available from either an in-memory handle or mysqld.pid; "
        elif not result["ownership_verified"]:
            result["notes"] += f"PID {pid} does not verify as this instance's own mysqld (datadir/port not found in its command line); "

        # Graceful shutdown is always attempted via the MySQL wire protocol --
        # mysqladmin authenticates by host/port/credentials, never by OS process
        # identity, so it is safe to send even when ownership could not be
        # verified: it can only ever shut down whatever MySQL server answers on
        # this exact host:port, never an arbitrary OS process.
        try:
            shutdown = subprocess.run(
                [str(MYSQLADMIN), "--host=127.0.0.1", f"--port={self.port}", "--protocol=TCP", "--user=root", "shutdown"],
                capture_output=True, text=True, timeout=SHUTDOWN_TIMEOUT_SECONDS,
            )
            result["graceful_shutdown_sent"] = shutdown.returncode == 0
            if shutdown.returncode != 0:
                result["notes"] += f"mysqladmin shutdown returned {shutdown.returncode}: {shutdown.stderr.strip()}; "
        except subprocess.TimeoutExpired:
            result["notes"] += "mysqladmin shutdown timed out; "

        # Poll until the port genuinely stops answering -- never assume the
        # shutdown command's own exit code means the server is actually down.
        deadline = time.monotonic() + SHUTDOWN_TIMEOUT_SECONDS
        stopped_gracefully = False
        while time.monotonic() < deadline:
            if not self._ping():
                stopped_gracefully = True
                break
            time.sleep(SHUTDOWN_POLL_INTERVAL_SECONDS)

        if not stopped_gracefully and result["ownership_verified"] and pid is not None:
            # Bounded OS-level fallback -- only ever reached when ownership was
            # verified, and re-verified again immediately before the kill to
            # narrow (never eliminate) the TOCTOU window. A PID that no longer
            # re-verifies is never killed.
            if self._owns(self._process_identity(pid)):
                subprocess.run(["taskkill", "/PID", str(pid), "/F"], capture_output=True, text=True, timeout=FALLBACK_KILL_TIMEOUT_SECONDS)
                result["fallback_kill_used"] = True
                kill_deadline = time.monotonic() + FALLBACK_KILL_TIMEOUT_SECONDS
                while time.monotonic() < kill_deadline:
                    if not self._ping():
                        stopped_gracefully = True
                        break
                    time.sleep(0.5)
            else:
                result["notes"] += "ownership no longer verifiable at fallback-kill time; refused to force-kill; "

        if self._ping():
            # The exact false-success this rewrite exists to eliminate: never
            # report SUCCESS while the port still answers.
            result["status"] = "STOP_FAILED"
            result["instance_stopped_confirmed"] = False
            return result

        if pid is not None and result["ownership_verified"] and self._process_identity(pid) is not None:
            result["status"] = "STOP_FAILED"
            result["instance_stopped_confirmed"] = False
            result["notes"] += "port stopped responding but the owned mysqld process entry is still present; "
            return result

        result["instance_stopped_confirmed"] = True
        self.process = None
        self._cleanup_datadir(result)
        return result

    def _cleanup_datadir(self, result: dict) -> None:
        import shutil
        try:
            self.session_path.unlink(missing_ok=True)
        except OSError:
            pass
        try:
            shutil.rmtree(self.datadir.parent, ignore_errors=False)
            result["datadir_removed"] = True
        except OSError as exc:
            result["datadir_removed"] = False
            result["infrastructure_result"] = "WARN"
            result["notes"] += f"datadir removal failed after clean stop: {exc}; "


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
        result = inst.stop()
        print(json.dumps(result))
        if result["status"] not in ("SUCCESS", "ALREADY_STOPPED"):
            raise SystemExit(1)
