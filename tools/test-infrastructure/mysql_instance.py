"""P0-TI.1 Test Infrastructure V2 -- dedicated MySQL instance lifecycle.

Starts/stops a MySQL 8.4 server instance completely separate from the app's
own WAMP-managed server (127.0.0.1:3306 / `laravel`): its own datadir, its
own port, its own process. Never touches the app database. See
docs/database/physical/test_infrastructure_v2_design.md for the overall
design; this module implements the START/STOP lifecycle (design sections
12/15) plus SERVER ATTESTATION (P0.3.5-A1.1).

P0.3.5-A1.0 fixed a false-success bug in `stop()`: a `stop` invoked from a
separate CLI process than the one that called `start()` has no in-memory
`self.process` handle, and the previous implementation silently reported
`mysqld_stopped: true` in that case without ever sending a shutdown.

P0.3.5-A1-R's independent audit then found the mirror-image bug in
`start()` (finding A1R-01, HIGH/EVIDENCE): `start()`'s old readiness check
was "does *something* answer `mysqladmin ping` on this host:port", which
succeeds just as well against an unrelated, pre-existing server already
occupying that port as it does against the mysqld process this call just
spawned. Reproduced live: with port 3307 already occupied by a standing
WAMP MariaDB 11.4.9 service, `init-start --port 3307` reported `STARTED`
while the real spawned MySQL 8.4.7 process had actually failed to bind and
exited -- and old T1-style logic then happily "attested" against the
MariaDB service instead. See
docs/reviews/P0.3.5_A1_1_test_instance_attestation_remediation.md.

P0.3.5-A1.1 fixes this with SERVER ATTESTATION (design sections 3-9):
neither a free port, nor a successful TCP connect, nor a database name is
treated as proof of identity. After a candidate server answers `ping`, this
module runs `SELECT @@version, @@version_comment, @@port, @@datadir,
@@server_uuid, @@pid_file` and compares every field against what THIS call
expects (the `server-uuid` MySQL itself wrote into `<datadir>/auto.cnf`
during `--initialize-insecure` is the one identity fact no other instance's
datadir can coincidentally share). Only a full match yields
`STARTED_VERIFIED` -- the sole status that permits any pool/migration/test
operation to follow. A process that exits early because it lost a bind race
is detected via `Popen.poll()` BEFORE any ping-based readiness check can be
fooled by whatever else is already listening (the root cause of A1R-01).

Session identity is now persisted twice: once at `<datadir>/../session.json`
(this exact instance's own record, keyed by datadir -- unchanged from
P0.3.5-A1.0) and once in a per-port registry
(`%TEMP%/mepa-test-mysql/sessions/port-<port>.json`) so a caller that only
knows the port (e.g. `pool.py`, or `stop --port N` with no `--datadir`) can
still look up and attest the exact session it should be talking to.
"""
import json
import random
import re
import subprocess
import tempfile
import time
import uuid
from pathlib import Path
from typing import Optional

MYSQL_BASEDIR = Path(r"C:\wamp64\bin\mysql\mysql8.4.7")
MYSQLD = MYSQL_BASEDIR / "bin" / "mysqld.exe"
MYSQLADMIN = MYSQL_BASEDIR / "bin" / "mysqladmin.exe"
MYSQL_CLIENT = MYSQL_BASEDIR / "bin" / "mysql.exe"

# P0.3.5-A1.1 (design section 9/10): 3307 is no longer a trusted fixed
# default -- a whole bounded range is available for `--port auto`, and
# identity is what makes any one of them safe to use, never the number
# itself. DEFAULT_PORT is kept only so an explicit `--port 3307` (still
# useful for reproducing/regression-testing A1R-01 itself, design section
# 16) continues to mean what it always meant.
DEFAULT_PORT = 3307
AUTO_PORT_RANGE = range(33000, 33100)
AUTO_PORT_MAX_ATTEMPTS = 15

READY_TIMEOUT_SECONDS = 60
SHUTDOWN_TIMEOUT_SECONDS = 30
SHUTDOWN_POLL_INTERVAL_SECONDS = 1
FALLBACK_KILL_TIMEOUT_SECONDS = 10
APP_DATABASE_PORT = 3306

SESSIONS_DIR = Path(tempfile.gettempdir()) / "mepa-test-mysql" / "sessions"


def _expected_version_prefix() -> str:
    """Derives the accepted `@@version` prefix (e.g. "8.4.") from the pinned
    MYSQL_BASEDIR name, so the vendor/version check below is never a second,
    independently-drifting hardcoded literal."""
    m = re.search(r"mysql(\d+\.\d+)\.\d+", MYSQL_BASEDIR.name)
    return (m.group(1) + ".") if m else "8.4."


EXPECTED_VERSION_PREFIX = _expected_version_prefix()


class ForeignServerError(RuntimeError):
    """Raised whenever the server actually reachable at a given host:port
    cannot be proven (design section 6) to be the Test Infrastructure V2
    session it is expected to be. Callers (pool.py, stop()) must treat this
    as an absolute stop: no CREATE/DROP/TRUNCATE/shutdown may follow."""


def _normalize_path(value: str) -> str:
    """Case/slash/trailing-separator-insensitive comparison for Windows
    paths coming back from `@@datadir`/`@@pid_file` (which render with a
    trailing separator and may differ in slash style from how Python built
    the same path)."""
    return str(Path(value.strip())).rstrip("\\/").lower()


def registry_path(port: int) -> Path:
    return SESSIONS_DIR / f"port-{port}.json"


def load_registered_session(port: int) -> Optional[dict]:
    """Reads the per-port session registry a caller with only a port number
    (never a datadir) needs to attest against -- e.g. pool.py."""
    try:
        return json.loads(registry_path(port).read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None


def _run_attestation_query(host: str, port: int) -> Optional[dict]:
    """Best-effort, strictly read-only. Returns None if nothing answers or
    the query itself fails, so callers can treat "no answer" and "wrong
    answer" uniformly as "cannot attest" -- this function never raises and
    never issues anything but a SELECT."""
    try:
        result = subprocess.run(
            [str(MYSQL_CLIENT), f"--host={host}", f"--port={port}", "--protocol=TCP", "--user=root",
             "--connect-timeout=5", "-N", "-B",
             "-e", "SELECT @@version, @@version_comment, @@port, @@datadir, @@server_uuid, @@pid_file"],
            capture_output=True, text=True, timeout=10,
        )
    except subprocess.TimeoutExpired:
        return None
    if result.returncode != 0 or not result.stdout.strip():
        return None
    parts = result.stdout.rstrip("\n").split("\t")
    if len(parts) != 6:
        return None
    try:
        port_value = int(parts[2])
    except ValueError:
        return None
    return {
        "version": parts[0], "version_comment": parts[1], "port": port_value,
        "datadir": parts[3], "server_uuid": parts[4], "pid_file": parts[5],
    }


def _compare_attestation(observed: Optional[dict], expected: dict) -> tuple[bool, str]:
    """The one place every identity rule from design section 6/22 is
    enforced. `expected` must carry at least port/datadir/server_uuid;
    pid_file is compared when present. Vendor is checked via
    `version`+`version_comment` together -- neither alone is trusted (a
    MariaDB build can report a version_comment that merely omits "MySQL"
    without ever claiming "MariaDB")."""
    if observed is None:
        return False, "no server answered the attestation query"
    if not observed["version"].startswith(EXPECTED_VERSION_PREFIX):
        return False, f"unexpected version {observed['version']!r} (expected {EXPECTED_VERSION_PREFIX}x)"
    comment = observed["version_comment"].lower()
    if "mariadb" in comment or "mysql" not in comment:
        return False, f"unexpected vendor: version_comment={observed['version_comment']!r} (only MySQL Community/Enterprise Server accepted, not MariaDB or an unidentified vendor)"
    if observed["port"] != expected["port"]:
        return False, f"port mismatch: expected {expected['port']}, got {observed['port']}"
    if _normalize_path(observed["datadir"]) != _normalize_path(expected["datadir"]):
        return False, f"datadir mismatch: expected {expected['datadir']!r}, got {observed['datadir']!r}"
    if observed["server_uuid"] != expected.get("server_uuid"):
        return False, f"server_uuid mismatch: expected {expected.get('server_uuid')}, got {observed['server_uuid']}"
    expected_pid_file = expected.get("pid_file")
    if expected_pid_file and _normalize_path(observed["pid_file"]) != _normalize_path(expected_pid_file):
        return False, f"pid_file mismatch: expected {expected_pid_file!r}, got {observed['pid_file']!r}"
    return True, ""


def assert_server_attestation(port: int, host: str = "127.0.0.1") -> dict:
    """Module-level attestation entry point for callers that only have a
    port (design section 12 -- e.g. `pool.py` before create/migrate/reset).
    Loads the registered session for that port and proves the server
    actually answering it right now is that exact session -- never merely
    "something answers". Raises ForeignServerError on any mismatch or
    missing registration; callers must never proceed to a
    destructive/DDL operation if this raises."""
    session = load_registered_session(port)
    if session is None:
        raise ForeignServerError(
            f"no registered Test Infrastructure V2 session for port {port}; "
            "refusing to operate on an unattested server"
        )
    observed = _run_attestation_query(host, port)
    ok, reason = _compare_attestation(observed, session)
    if not ok:
        raise ForeignServerError(f"server attestation failed for port {port}: {reason}")
    return session


class MysqlInstance:
    """One dedicated, disposable mysqld instance. Never the app's own
    server. `STARTED_VERIFIED` is the only start() status that proves this;
    `STOPPED_VERIFIED`/`ALREADY_STOPPED` are the only stop() statuses that
    prove the instance is genuinely gone. See module docstring."""

    def __init__(self, datadir: Path, port: int = DEFAULT_PORT, session_id: Optional[str] = None):
        if port == APP_DATABASE_PORT:
            # A real, unconditionally-raised guard (not `assert`, which
            # Python strips under -O) -- this class must never be pointed at
            # the app's own MySQL server. Kept as an absolute belt-and-
            # suspenders check: attestation (below) is the PRIMARY defense
            # for every other server this module might encounter, but this
            # one port is refused outright, before attestation even applies
            # (design section 13).
            raise ValueError(f"refusing to manage a MysqlInstance on the app database port ({APP_DATABASE_PORT})")
        self.datadir = Path(datadir)
        self.port = port
        self.session_id = session_id or uuid.uuid4().hex[:12]
        self.process: Optional[subprocess.Popen] = None
        self.log_path = self.datadir.parent / "mysqld.log"
        self.session_path = self.datadir.parent / "session.json"
        self.pid_file = self.datadir / "mysqld.pid"

    def initialize(self) -> None:
        self.datadir.mkdir(parents=True, exist_ok=True)
        result = subprocess.run(
            [str(MYSQLD), f"--datadir={self.datadir}", f"--basedir={MYSQL_BASEDIR}", "--initialize-insecure"],
            capture_output=True, text=True, timeout=120,
        )
        if result.returncode != 0:
            raise RuntimeError(f"mysqld --initialize-insecure failed: {result.stderr}")

    def _expected_server_uuid(self) -> Optional[str]:
        """Reads the server-uuid MySQL itself generated into `auto.cnf`
        during `--initialize-insecure` -- the one piece of identity no other
        mysqld instance's datadir can coincidentally share (design
        section 5)."""
        try:
            text = (self.datadir / "auto.cnf").read_text(encoding="utf-8")
        except OSError:
            return None
        m = re.search(r"server-uuid\s*=\s*([0-9a-fA-F-]+)", text)
        return m.group(1) if m else None

    def _ping(self) -> bool:
        """True if *a* MySQL-protocol server answers on this instance's
        host:port right now. Deliberately weak by itself -- this is only
        ever used as a cheap "is anything there" gate; identity is always
        proven separately via `_run_attestation_query`/`_compare_attestation`
        before any state is trusted or any destructive action is taken."""
        try:
            result = subprocess.run(
                [str(MYSQLADMIN), "--host=127.0.0.1", f"--port={self.port}", "--protocol=TCP", "--user=root",
                 "--connect-timeout=3", "ping"],
                capture_output=True, text=True, timeout=10,
            )
        except subprocess.TimeoutExpired:
            return False
        return result.returncode == 0

    def start(self) -> dict:
        """Starts mysqld and does not report readiness until SERVER
        ATTESTATION (design section 6) proves the server answering this
        exact host:port is genuinely the process this call just spawned --
        never merely "something responded to ping". Returns a result dict;
        `status` is one of STARTED_VERIFIED, PORT_IN_USE, START_FAILED,
        ATTESTATION_FAILED. Only STARTED_VERIFIED permits any subsequent
        pool/migration/test operation (design section 7)."""
        result = {
            "status": "STARTING", "port": self.port, "datadir": str(self.datadir),
            "session_id": self.session_id, "pid": None,
            "version": None, "version_comment": None, "server_uuid": None, "pid_file": None,
            "notes": "",
        }

        expected_uuid = self._expected_server_uuid()
        if expected_uuid is None:
            result["status"] = "START_FAILED"
            result["notes"] = "datadir not initialized (auto.cnf/server-uuid missing) -- call initialize() first"
            return result

        # Pre-flight (design section 8): for an explicitly-requested port,
        # if a server already answers before this instance even tries to
        # spawn, there is no point spawning a doomed process -- fail fast
        # and never touch whatever is already there. (For --port auto, the
        # caller does an even cheaper version of this same check before
        # calling start() at all -- see start_verified_auto below -- but
        # this instance-level check still applies as defense in depth.)
        if self._ping():
            result["status"] = "PORT_IN_USE"
            result["notes"] = "a server already answers on this port before this instance was even started"
            observed = _run_attestation_query("127.0.0.1", self.port)
            if observed:
                result.update({k: v for k, v in observed.items() if k in result})
            return result

        expected = {
            "port": self.port, "datadir": str(self.datadir),
            "server_uuid": expected_uuid, "pid_file": str(self.pid_file),
        }

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
                f"--pid-file={self.pid_file}",
                "--innodb-buffer-pool-size=128M",
                # No --skip-name-resolve: --initialize-insecure only creates
                # 'root'@'localhost', and skip-name-resolve would stop MySQL
                # reverse-resolving a 127.0.0.1 TCP connection to 'localhost'
                # to match that grant, rejecting every connection.
            ],
            stdout=log_file, stderr=subprocess.STDOUT,
        )

        deadline = time.monotonic() + READY_TIMEOUT_SECONDS
        while time.monotonic() < deadline:
            if self.process.poll() is not None:
                # THE A1R-01 FIX (design section 15/16): the process this
                # call spawned has already exited -- e.g. it lost a bind
                # race. Never keep polling `ping` against whatever else
                # might answer this port; that ping succeeding is exactly
                # what fooled the old start() into reporting a false
                # STARTED.
                exit_code = self.process.returncode
                foreign = self._ping()
                result["notes"] = f"spawned mysqld exited early (code {exit_code}); see {self.log_path}"
                if foreign:
                    result["status"] = "PORT_IN_USE"
                    result["notes"] += "; a different server answers on this port"
                    observed = _run_attestation_query("127.0.0.1", self.port)
                    if observed:
                        result.update({k: v for k, v in observed.items() if k in result})
                else:
                    result["status"] = "START_FAILED"
                return result

            if self._ping():
                observed = _run_attestation_query("127.0.0.1", self.port)
                ok, reason = _compare_attestation(observed, expected)
                if ok:
                    result["status"] = "STARTED_VERIFIED"
                    result["pid"] = self.process.pid
                    result.update(observed)
                    self._persist_session(expected, observed)
                    return result
                # Something answered and it does look like a real MySQL
                # server, but its own reported identity does not match what
                # THIS instance's own datadir/port/server-uuid should be --
                # e.g. a wrong-vendor/wrong-version build, or (defense in
                # depth) an exotic same-port race this module cannot fully
                # rule out. Never STARTED_VERIFIED.
                result["status"] = "ATTESTATION_FAILED"
                result["notes"] = reason
                if observed:
                    result.update(observed)
                return result

            time.sleep(1)

        result["status"] = "START_FAILED"
        result["notes"] = f"mysqld did not answer within {READY_TIMEOUT_SECONDS}s; see {self.log_path}"
        return result

    def _persist_session(self, expected: dict, observed: dict) -> None:
        """Design section 4: persistent metadata that survives between CLI
        invocations, written twice -- once keyed by datadir (this exact
        instance's own record) and once in the per-port registry (so a
        caller with only a port, e.g. pool.py or `stop --port N`, can still
        find and attest it). No passwords/secrets are recorded (the
        disposable instances this module manages use `--initialize-insecure`
        with an empty root password by convention; nothing here would be a
        secret worth protecting even if included)."""
        session = {
            "session_id": self.session_id,
            "host": "127.0.0.1",
            "port": self.port,
            "pid": self.process.pid if self.process else None,
            "datadir": str(self.datadir),
            "mysql_version_expected": observed.get("version"),
            "server_uuid": expected["server_uuid"],
            "pid_file": expected["pid_file"],
            "started_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "mysqld_path": str(MYSQLD),
        }
        payload = json.dumps(session, indent=2)
        self.session_path.write_text(payload, encoding="utf-8")
        SESSIONS_DIR.mkdir(parents=True, exist_ok=True)
        registry_path(self.port).write_text(payload, encoding="utf-8")

    def _load_session_for_stop(self) -> Optional[dict]:
        """Prefers this exact instance's own datadir-local record; falls
        back to the port registry (covers `stop --port N` with no
        `--datadir`), but only if that registry entry actually names this
        same datadir -- never trusts the registry blindly for a datadir it
        was not told to expect."""
        try:
            return json.loads(self.session_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            pass
        registered = load_registered_session(self.port)
        if registered and _normalize_path(registered.get("datadir", "")) == _normalize_path(str(self.datadir)):
            return registered
        return None

    def _read_pid_file(self) -> Optional[int]:
        """Reads mysqld's own --pid-file -- authoritative and always present
        while that mysqld is running, regardless of which process asks."""
        try:
            return int(self.pid_file.read_text(encoding="utf-8").strip())
        except (OSError, ValueError):
            return None

    @staticmethod
    def _process_identity(pid: int) -> Optional[dict]:
        """Looks up a live process's name and full command line via WMI.
        Returns None if no process with that PID exists right now. This is
        a point-in-time check -- callers must not treat a positive result as
        valid indefinitely (PIDs are reused by the OS), which is why
        ownership is re-verified immediately before any fallback kill, not
        cached from an earlier check."""
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
        """True only if `identity` is a mysqld process whose OWN command
        line names exactly this instance's datadir and port. Never trust a
        PID number or a port number alone -- the command line is the one
        thing an unrelated process cannot coincidentally share with this
        instance. Distinct from and in addition to server attestation
        (which proves the SERVER's identity via SQL); this proves the OS
        PROCESS's identity, and gates only the more dangerous OS-level
        fallback kill specifically."""
        if not identity:
            return False
        name = (identity.get("Name") or "").lower()
        cmdline = identity.get("CommandLine") or ""
        if "mysqld" not in name:
            return False
        return f"--datadir={self.datadir}" in cmdline and f"--port={self.port}" in cmdline

    def stop(self) -> dict:
        """Stops mysqld and does not return a verified-stopped status unless
        independently reconfirmed. Design section 14: (1) load metadata,
        (2) attest the server currently on this host:port is genuinely this
        session's -- if not, FOREIGN_SERVER and NO shutdown is sent, ever;
        (3) only then attempt a graceful shutdown; (4) poll until the port
        stops answering; (5) only then STOPPED_VERIFIED. A slow/failed
        datadir removal AFTER the instance is confirmed stopped is
        INFRASTRUCTURE_RESULT=WARN, never a product/evidence FAIL -- but a
        stop that cannot confirm the instance actually stopped is
        STOP_FAILED, never a false success.
        """
        result = {
            "status": "STOPPED_VERIFIED",
            "instance_stopped_confirmed": False,
            "attestation_verified": False,
            "ownership_verified": False,
            "graceful_shutdown_sent": False,
            "fallback_kill_used": False,
            "datadir_removed": False,
            "infrastructure_result": "PASS",
            "notes": "",
        }

        if not self._ping():
            # T3: already stopped. Idempotent -- no shutdown action is
            # taken, and none is falsely claimed either.
            result["status"] = "ALREADY_STOPPED"
            result["instance_stopped_confirmed"] = True
            self._cleanup_datadir(result)
            return result

        session = self._load_session_for_stop()
        if session is None:
            # Something answers, but there is no recorded identity to
            # attest it against. Design section 3: a free port, a TCP
            # connect, or a database name never proves identity -- and
            # neither does "nothing on disk says otherwise". Refuse.
            result["status"] = "FOREIGN_SERVER"
            result["notes"] = "no session metadata recorded for this datadir/port; refusing to act on an unattested server"
            return result

        observed = _run_attestation_query("127.0.0.1", self.port)
        attested, reason = _compare_attestation(observed, session)
        result["attestation_verified"] = attested
        if not attested:
            result["status"] = "FOREIGN_SERVER"
            result["notes"] = reason
            return result

        # Attestation (SQL-level, proves the SERVER's identity) gates
        # whether ANY shutdown action happens at all. Separately, OS-level
        # PID/command-line ownership (unchanged from P0.3.5-A1.0) still
        # gates only the more dangerous OS-level fallback kill specifically.
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

        # Graceful shutdown is only ever sent once attestation above has
        # already proven this is the session's own server -- mysqladmin
        # authenticates by host/port/credentials, never by OS process
        # identity, so without that prior proof it could otherwise shut
        # down an unrelated, already-attested-as-foreign server.
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
            # Bounded OS-level fallback -- only ever reached when ownership
            # was verified, and re-verified again immediately before the
            # kill to narrow (never eliminate) the TOCTOU window. A PID that
            # no longer re-verifies is never killed.
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
            # The exact false-success P0.3.5-A1.0 eliminated: never report
            # a verified-stopped status while the port still answers.
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
            registry_path(self.port).unlink(missing_ok=True)
        except OSError:
            pass
        try:
            shutil.rmtree(self.datadir.parent, ignore_errors=False)
            result["datadir_removed"] = True
        except OSError as exc:
            result["datadir_removed"] = False
            result["infrastructure_result"] = "WARN"
            result["notes"] += f"datadir removal failed after clean stop: {exc}; "


def start_verified_auto(
    datadir: Optional[Path] = None,
    port_range=AUTO_PORT_RANGE,
    max_attempts: int = AUTO_PORT_MAX_ATTEMPTS,
    session_id: Optional[str] = None,
) -> tuple[Optional[MysqlInstance], dict]:
    """Design section 9: `--port auto`. Picks a free-looking candidate port
    and relies on full server attestation inside `start()` as the actual
    proof -- a pre-check alone is never sufficient (race: another process
    can grab the port between the check and the real bind attempt), so this
    is only an optimization to skip obviously-occupied candidates without
    paying for a full `initialize()` on each one. A bind loss on the real
    attempt is a bounded infrastructure retry onto the next candidate port,
    never a retry of any functional test outcome. Returns
    `(instance_or_None, result_dict)`; only a non-None instance with
    `result["status"] == "STARTED_VERIFIED"` is usable."""
    import shutil

    session_id = session_id or uuid.uuid4().hex[:12]
    candidates = list(port_range)
    random.shuffle(candidates)
    last_result = {"status": "START_FAILED", "notes": "no candidate ports were attempted"}
    attempts = 0

    for port in candidates:
        if attempts >= max_attempts:
            break
        try:
            quick = subprocess.run(
                [str(MYSQLADMIN), "--host=127.0.0.1", f"--port={port}", "--protocol=TCP", "--user=root",
                 "--connect-timeout=1", "ping"],
                capture_output=True, text=True, timeout=3,
            )
            if quick.returncode == 0:
                continue  # obviously occupied -- skip without an initialize()
        except subprocess.TimeoutExpired:
            pass

        attempts += 1
        base = Path(datadir) if datadir else Path(tempfile.gettempdir()) / "mepa-test-mysql" / session_id / "data"
        inst = MysqlInstance(base, port, session_id=session_id)
        inst.initialize()
        result = inst.start()
        last_result = result
        if result["status"] == "STARTED_VERIFIED":
            return inst, result
        shutil.rmtree(base.parent, ignore_errors=True)

    return None, last_result


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["init-start", "stop"])
    parser.add_argument("--datadir", default=None)
    parser.add_argument("--port", default="auto",
                         help="port number, or 'auto' (default) to pick a free, attested port from a bounded range")
    args = parser.parse_args()

    if args.action == "init-start":
        session_id = uuid.uuid4().hex[:12]
        if args.port == "auto":
            inst, result = start_verified_auto(datadir=Path(args.datadir) if args.datadir else None, session_id=session_id)
        else:
            port = int(args.port)
            datadir = Path(args.datadir) if args.datadir else Path(tempfile.gettempdir()) / "mepa-test-mysql" / session_id / "data"
            inst = MysqlInstance(datadir, port, session_id=session_id)
            inst.initialize()
            result = inst.start()
        print(json.dumps(result, indent=2))
        raise SystemExit(0 if result["status"] == "STARTED_VERIFIED" else 1)
    else:
        if args.port == "auto":
            raise SystemExit("--port auto is not valid for stop; pass the specific port to stop")
        port = int(args.port)
        if args.datadir:
            datadir = Path(args.datadir)
        else:
            registered = load_registered_session(port)
            if not registered:
                print(json.dumps({"status": "FOREIGN_SERVER", "notes": f"no registered session for port {port} and no --datadir given"}, indent=2))
                raise SystemExit(1)
            datadir = Path(registered["datadir"])
        inst = MysqlInstance(datadir, port)
        result = inst.stop()
        print(json.dumps(result, indent=2))
        raise SystemExit(0 if result["status"] in ("STOPPED_VERIFIED", "ALREADY_STOPPED") else 1)
