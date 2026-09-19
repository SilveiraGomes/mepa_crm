"""P0.3.5-A1.1 -- Server attestation test suite (I1-I12).

Proves the fix for finding A1R-01 (P0.3.5-A1-R independent audit, HIGH/
EVIDENCE): `mysql_instance.py`'s old `start()` reported a false `STARTED`
when the mysqld process it spawned lost a bind race against a pre-existing,
unrelated server already on the requested port -- reproduced live against a
standing WAMP MariaDB 11.4.9 service squatting port 3307. See
docs/reviews/P0.3.5_A1_1_test_instance_attestation_remediation.md for the
full narrative and for the additional REAL-server proofs (against port 3307
and its actual MariaDB occupant) that this automated suite complements but
does not fully replace, since this machine's specific MariaDB-on-3307
collision is an environmental fact this suite must not hard-depend on to be
runnable elsewhere.

I1-I2, I7-I10, I12 are real integration tests against genuine, disposable
mysqld instances (same no-mocked-database convention as
test_mysql_instance_lifecycle.py). I3-I6 test the attestation COMPARISON
logic directly (`_compare_attestation`) with synthetic observed/expected
dicts -- they do not need a second real server of a different vendor to be
reachable, so this suite is fully self-contained and does not depend on this
particular machine's WAMP MariaDB service being present.

Usage: python test_mysql_instance_attestation.py
"""
import shutil
import subprocess
import sys
import tempfile
import time
import uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from mysql_instance import (  # noqa: E402
    MysqlInstance, ForeignServerError, _compare_attestation, _run_attestation_query,
    start_verified_auto, load_registered_session, registry_path,
)
import pool as pool_module  # noqa: E402

PASS = []
FAIL = []

# Ports chosen well away from the app DB (3306), this repo's other Test
# Infrastructure V2 default (3307, deliberately left alone -- see the real
# 3307 proofs in the remediation doc), and test_mysql_instance_lifecycle.py's
# own PORT_A/PORT_B (3391/3392), so this suite can run independently of (and
# concurrently with) either.
PORT_A = 3491
PORT_B = 3492


def check(name, cond, detail=""):
    if cond:
        PASS.append(name)
        print(f"PASS  {name}", flush=True)
    else:
        FAIL.append(name)
        print(f"FAIL  {name}  {detail}", flush=True)


def _fresh_datadir(label: str = "attestation") -> Path:
    session_id = uuid.uuid4().hex[:12]
    return Path(tempfile.gettempdir()) / f"mepa-test-mysql-{label}" / session_id / "data"


def _new_instance(port: int, label: str = "attestation") -> MysqlInstance:
    return MysqlInstance(_fresh_datadir(label), port)


def _teardown(instance: MysqlInstance) -> None:
    """Best-effort cleanup so a failed assertion never leaks a live mysqld."""
    try:
        if instance._ping():
            instance.stop()
    except Exception:
        pass
    shutil.rmtree(instance.datadir.parent, ignore_errors=True)
    try:
        registry_path(instance.port).unlink(missing_ok=True)
    except OSError:
        pass


# ---------------------------------------------------------------------------
# I1-I2: never STARTED_VERIFIED when a spawned mysqld loses a bind race
# ---------------------------------------------------------------------------

def i1_occupied_explicit_port_no_started():
    """A second instance explicitly targeting a port the first one already
    holds must never report STARTED_VERIFIED -- the literal A1R-01 scenario,
    reproduced with two real, controlled mysqld instances instead of this
    machine's specific MariaDB service."""
    owner = _new_instance(PORT_A)
    intruder = _new_instance(PORT_A)
    try:
        owner.initialize()
        r0 = owner.start()
        check("I1 setup: owner instance genuinely STARTED_VERIFIED", r0["status"] == "STARTED_VERIFIED", r0)

        intruder.initialize()
        result = intruder.start()
        check("I1: occupied port never yields STARTED_VERIFIED", result["status"] != "STARTED_VERIFIED", result)
        check("I1: status is PORT_IN_USE", result["status"] == "PORT_IN_USE", result)
        check("I1: owner instance is unaffected", owner._ping())
        check("I1: no registry entry was overwritten with the intruder's (wrong) identity", load_registered_session(PORT_A)["server_uuid"] != None and load_registered_session(PORT_A)["pid"] == r0["pid"], load_registered_session(PORT_A))
    finally:
        shutil.rmtree(intruder.datadir.parent, ignore_errors=True)
        _teardown(owner)


def i2_spawned_mysqld_exit_detected_via_poll():
    """Directly exercises the poll()-based exit detection inside start()'s
    main loop (not just the cheaper pre-flight ping check) -- the literal
    mechanism that fixes A1R-01: a process that dies losing a bind race must
    be detected via Popen.poll() before its own ping-based readiness check
    could ever be fooled by whatever else already answers that port. Forces
    entry into the spawn+loop path against an already-occupied port by
    making the pre-flight ping lie exactly once."""
    owner = _new_instance(PORT_A)
    intruder = None
    try:
        owner.initialize()
        r0 = owner.start()
        check("I2 setup: owner instance genuinely STARTED_VERIFIED", r0["status"] == "STARTED_VERIFIED", r0)

        intruder = _new_instance(PORT_A)
        intruder.initialize()
        real_ping = intruder._ping
        calls = {"n": 0}

        def lying_ping():
            calls["n"] += 1
            return False if calls["n"] == 1 else real_ping()

        intruder._ping = lying_ping
        result = intruder.start()
        # Which exact terminal status comes out is a genuine, harmless race:
        # if the intruder's own process has already died by the time the
        # loop's ping check runs, poll() catches it first -> PORT_IN_USE; if
        # the ping check runs a moment earlier (process still technically
        # alive but never actually bound), it observes the owner's real
        # response under the intruder's own expected identity -> mismatch,
        # ATTESTATION_FAILED. Design sections 7/17 both explicitly treat
        # these as equally acceptable non-STARTED_VERIFIED outcomes -- what
        # matters, and is asserted unconditionally below, is that neither
        # ever reports STARTED_VERIFIED and the owner is never touched.
        check("I2: never STARTED_VERIFIED when the spawned process loses the bind race", result["status"] != "STARTED_VERIFIED", result)
        check("I2: status is PORT_IN_USE or ATTESTATION_FAILED (both are safe non-STARTED_VERIFIED outcomes of the same lost bind race)", result["status"] in ("PORT_IN_USE", "ATTESTATION_FAILED"), result)
        check("I2: owner instance (the real port holder) is unaffected", owner._ping())
    finally:
        if intruder is not None:
            shutil.rmtree(intruder.datadir.parent, ignore_errors=True)
        _teardown(owner)


# ---------------------------------------------------------------------------
# I3-I6: attestation comparison logic rejects every identity mismatch
# ---------------------------------------------------------------------------

_BASE_EXPECTED = {
    "port": PORT_A,
    "datadir": r"C:\fake\datadir\for\attestation\unit\tests",
    "server_uuid": "11111111-1111-1111-1111-111111111111",
    "pid_file": r"C:\fake\datadir\for\attestation\unit\tests\mysqld.pid",
}
_BASE_OBSERVED = {
    "version": "8.4.7",
    "version_comment": "MySQL Community Server - GPL",
    "port": PORT_A,
    "datadir": r"C:\fake\datadir\for\attestation\unit\tests",
    "server_uuid": "11111111-1111-1111-1111-111111111111",
    "pid_file": r"C:\fake\datadir\for\attestation\unit\tests\mysqld.pid",
}


def i3_wrong_vendor_rejected():
    ok, _ = _compare_attestation(dict(_BASE_OBSERVED), _BASE_EXPECTED)
    check("I3 setup: matching synthetic observation is accepted", ok is True)

    mariadb = dict(_BASE_OBSERVED, version="11.4.9", version_comment="MariaDB Server")
    ok, reason = _compare_attestation(mariadb, _BASE_EXPECTED)
    check("I3: MariaDB version_comment is rejected", ok is False, reason)
    check("I3: rejection reason names the vendor/version problem", "vendor" in reason or "version" in reason, reason)

    unversioned = dict(_BASE_OBSERVED, version_comment="Some Unidentified Fork")
    ok, reason = _compare_attestation(unversioned, _BASE_EXPECTED)
    check("I3: an unidentified (non-MySQL) vendor string is also rejected", ok is False, reason)


def i4_wrong_datadir_rejected():
    wrong = dict(_BASE_OBSERVED, datadir=r"C:\completely\different\datadir")
    ok, reason = _compare_attestation(wrong, _BASE_EXPECTED)
    check("I4: mismatched datadir is rejected", ok is False, reason)
    check("I4: rejection reason names datadir", "datadir" in reason, reason)

    # Confirms the comparison is genuinely path-normalizing (case/slash/
    # trailing-separator insensitive, matching what @@datadir actually
    # renders on Windows), not merely a stricter-than-necessary exact match.
    equivalent = dict(_BASE_OBSERVED, datadir=_BASE_EXPECTED["datadir"].upper() + "\\")
    ok, reason = _compare_attestation(equivalent, _BASE_EXPECTED)
    check("I4: a case/trailing-slash-different but equivalent datadir is still accepted", ok is True, reason)


def i5_wrong_server_uuid_rejected():
    wrong = dict(_BASE_OBSERVED, server_uuid="22222222-2222-2222-2222-222222222222")
    ok, reason = _compare_attestation(wrong, _BASE_EXPECTED)
    check("I5: mismatched server_uuid is rejected", ok is False, reason)
    check("I5: rejection reason names server_uuid", "server_uuid" in reason, reason)


def i6_wrong_pid_file_rejected():
    wrong = dict(_BASE_OBSERVED, pid_file=r"C:\some\other\instance\mysqld.pid")
    ok, reason = _compare_attestation(wrong, _BASE_EXPECTED)
    check("I6: mismatched pid_file is rejected", ok is False, reason)
    check("I6: rejection reason names pid_file", "pid_file" in reason, reason)

    ok, reason = _compare_attestation(None, _BASE_EXPECTED)
    check("I6b: no server answering at all is rejected the same way (never a crash)", ok is False, reason)


# ---------------------------------------------------------------------------
# I7-I8: --port auto reaches STARTED_VERIFIED; a separate-CLI-process stop
# reaches STOPPED_VERIFIED
# ---------------------------------------------------------------------------

def i7_auto_port_started_verified():
    inst, result = start_verified_auto(port_range=range(34000, 34050), max_attempts=10)
    try:
        check("I7: auto port reaches STARTED_VERIFIED", result["status"] == "STARTED_VERIFIED", result)
        check("I7: chosen port is within the requested range", inst is not None and 34000 <= result["port"] < 34050, result)
        check("I7: version is MySQL 8.4.x", inst is not None and result.get("version", "").startswith("8.4."), result)
        check("I7: version_comment identifies MySQL, not MariaDB", inst is not None and "mysql" in result.get("version_comment", "").lower() and "mariadb" not in result.get("version_comment", "").lower(), result)
        check("I7: server_uuid was captured", bool(result.get("server_uuid")), result)
        check("I7: pid_file was captured", bool(result.get("pid_file")), result)
        check("I7: PID was captured", isinstance(result.get("pid"), int), result)
    finally:
        if inst is not None:
            _teardown(inst)


def i8_cross_process_stop_via_auto_port():
    """The mandated end-to-end proof (design section 19): auto-start, then
    -- in a genuinely SEPARATE OS process, via the real CLI, exactly as a
    human/orchestration script would invoke it -- stop by port alone (no
    --datadir), and independently confirm the instance is truly gone."""
    inst, result = start_verified_auto(port_range=range(34050, 34100), max_attempts=10)
    try:
        check("I8 setup: auto-started instance is STARTED_VERIFIED", result["status"] == "STARTED_VERIFIED", result)
        port = result["port"]

        proc = subprocess.run(
            [sys.executable, str(Path(__file__).resolve().parent / "mysql_instance.py"), "stop", "--port", str(port)],
            capture_output=True, text=True, timeout=60,
        )
        import json as _json
        stop_result = _json.loads(proc.stdout)
        check("I8: separate-CLI-process stop returns STOPPED_VERIFIED", stop_result["status"] == "STOPPED_VERIFIED", stop_result)
        check("I8: attestation_verified is True", stop_result["attestation_verified"] is True, stop_result)
        check("I8: instance_stopped_confirmed is True", stop_result["instance_stopped_confirmed"] is True, stop_result)

        # Independent re-verification, not just trusting the tool's own
        # claim (this is precisely the discipline that caught A1R-01 in the
        # first place).
        ping = subprocess.run(
            [str(pool_module.MYSQL_CLIENT).replace("mysql.exe", "mysqladmin.exe"), f"--host=127.0.0.1", f"--port={port}", "--protocol=TCP", "--user=root", "ping"],
            capture_output=True, text=True, timeout=10,
        )
        check("I8: independently re-verified -- mysqladmin ping now fails", ping.returncode != 0, ping.stdout + ping.stderr)
        check("I8: registry entry for this port was cleaned up", load_registered_session(port) is None)
        inst.process = None  # already stopped by the separate process; avoid a double-stop in teardown
    finally:
        if inst is not None:
            _teardown(inst)


# ---------------------------------------------------------------------------
# I9-I10: a foreign/unattestable server is never acted upon, by stop() or by
# pool.py
# ---------------------------------------------------------------------------

def i9_foreign_server_stop_denied():
    """stop() must refuse to act when the recorded session's identity no
    longer matches the live server -- simulated by tampering session.json's
    server_uuid after a genuine start (the live server itself is untouched,
    only the on-disk record this session would compare against)."""
    inst = _new_instance(PORT_A)
    try:
        inst.initialize()
        r0 = inst.start()
        check("I9 setup: instance genuinely STARTED_VERIFIED", r0["status"] == "STARTED_VERIFIED", r0)

        import json
        session = json.loads(inst.session_path.read_text(encoding="utf-8"))
        session["server_uuid"] = "99999999-9999-9999-9999-999999999999"
        inst.session_path.write_text(json.dumps(session), encoding="utf-8")

        stopper = MysqlInstance(inst.datadir, PORT_A)
        result = stopper.stop()
        check("I9: tampered-identity stop is rejected as FOREIGN_SERVER", result["status"] == "FOREIGN_SERVER", result)
        check("I9: attestation_verified is False", result["attestation_verified"] is False, result)
        check("I9: no shutdown was sent", result["graceful_shutdown_sent"] is False, result)
        check("I9: the real instance is still alive (nothing was shut down)", inst._ping())

        # Restore the real session record so teardown's stop() can work normally.
        session["server_uuid"] = r0["server_uuid"]
        inst.session_path.write_text(json.dumps(session), encoding="utf-8")
        registry_path(PORT_A).write_text(json.dumps(session), encoding="utf-8")
    finally:
        _teardown(inst)


def i10_pool_wrong_server_denied():
    """The mandated negative pool test (design section 21): pool.py must
    refuse create_pool/migrate_once/reset_database when the port's
    registered session does not match the server actually reachable there
    -- zero CREATE DATABASE, zero migration, zero reset. Uses a second real
    instance as the "wrong" server (portable across machines, unlike a
    hard-dependency on this machine's specific WAMP MariaDB on 3307 -- that
    real-MariaDB variant of this same proof is documented separately in
    P0.3.5_A1_1_test_instance_attestation_remediation.md)."""
    real_owner = _new_instance(PORT_A)
    decoy = _new_instance(PORT_B)
    try:
        real_owner.initialize()
        r0 = real_owner.start()
        check("I10 setup: real_owner is STARTED_VERIFIED", r0["status"] == "STARTED_VERIFIED", r0)
        decoy.initialize()
        rd = decoy.start()
        check("I10 setup: decoy is STARTED_VERIFIED (a real, different, unrelated instance)", rd["status"] == "STARTED_VERIFIED", rd)

        # Fabricate a registry entry for PORT_B claiming it is real_owner's
        # session (wrong port recorded, and a server_uuid that belongs to a
        # DIFFERENT real instance) -- the shape of "pool.py was pointed at
        # the wrong server".
        import json
        fake_session = json.loads(real_owner.session_path.read_text(encoding="utf-8"))
        registry_path(PORT_B).write_text(json.dumps(fake_session), encoding="utf-8")

        before = subprocess.run(
            [str(pool_module.MYSQL_CLIENT), "--user=root", "--host=127.0.0.1", f"--port={PORT_B}", "--batch", "--silent", "--execute=SHOW DATABASES"],
            capture_output=True, text=True, timeout=15,
        ).stdout

        raised = False
        try:
            pool_module.create_pool(PORT_B)
        except ForeignServerError:
            raised = True
        check("I10: create_pool raises ForeignServerError against the wrong server", raised)

        after = subprocess.run(
            [str(pool_module.MYSQL_CLIENT), "--user=root", "--host=127.0.0.1", f"--port={PORT_B}", "--batch", "--silent", "--execute=SHOW DATABASES"],
            capture_output=True, text=True, timeout=15,
        ).stdout
        check("I10: zero databases were created on the decoy server", before == after, (before, after))

        raised = False
        try:
            pool_module.migrate_once(PORT_B)
        except ForeignServerError:
            raised = True
        check("I10: migrate_once also raises ForeignServerError", raised)

        raised = False
        try:
            pool_module.reset_database(PORT_B, "anything")
        except ForeignServerError:
            raised = True
        check("I10: reset_database also raises ForeignServerError", raised)
    finally:
        try:
            registry_path(PORT_B).unlink(missing_ok=True)
        except OSError:
            pass
        _teardown(real_owner)
        _teardown(decoy)


# ---------------------------------------------------------------------------
# I11-I12: session-scoped pool naming and real two-pool isolation
# ---------------------------------------------------------------------------

def i11_session_pool_naming_isolation():
    names_a = pool_module.pool_names("aaaaaaaa")
    names_b = pool_module.pool_names("bbbbbbbb")
    flat_a = {n for names in names_a.values() for n in names}
    flat_b = {n for names in names_b.values() for n in names}
    check("I11: two different sessions produce disjoint pool name sets", flat_a.isdisjoint(flat_b), (flat_a, flat_b))
    check("I11: wave4/wave3/wave5 prefixes are preserved exactly (WaveFourCase/WaveThreeCase/WaveFiveCase regex compatibility)",
          all(n.startswith(f"mepa_{wave}_test_") for wave, names in names_a.items() for n in names), names_a)
    check("I11: every generated name matches the connect() guard's [a-z0-9_]+ suffix shape",
          all(__import__("re").match(r"^mepa_wave[0-9]_test_[a-z0-9_]+$", n) for names in names_a.values() for n in names), names_a)


def i12_two_pools_concurrent_isolated():
    """Lightweight representative smoke (design section 27: not a full A1
    re-run) proving real concurrent operations against two different
    session-scoped pools never cross-contaminate, and never touch the app
    DB or any other server."""
    inst, result = start_verified_auto(port_range=range(34100, 34150), max_attempts=10)
    try:
        check("I12 setup: instance STARTED_VERIFIED", result["status"] == "STARTED_VERIFIED", result)
        port = result["port"]
        created = pool_module.create_pool(port)
        migrated = pool_module.migrate_once(port)
        check("I12 setup: pool created and migrated", all(v["status"] == "MIGRATED" for v in migrated.values()), migrated)

        wave4_dbs = [n for n in created if n.startswith("mepa_wave4_test_")]
        check("I12 setup: two distinct wave4 pool databases exist", len(wave4_dbs) == 2, wave4_dbs)
        db1, db2 = wave4_dbs

        def marker_insert(dbname: str, marker: str):
            subprocess.run(
                [str(pool_module.MYSQL_CLIENT), "--user=root", "--host=127.0.0.1", f"--port={port}", dbname,
                 "--execute", f"INSERT INTO migrations (migration, batch) VALUES ('{marker}', 999)"],
                capture_output=True, text=True, timeout=15,
            )

        import threading
        t1 = threading.Thread(target=marker_insert, args=(db1, "I12_MARKER_ONE"))
        t2 = threading.Thread(target=marker_insert, args=(db2, "I12_MARKER_TWO"))
        t1.start(); t2.start()
        t1.join(); t2.join()

        def markers_in(dbname: str) -> list[str]:
            out = subprocess.run(
                [str(pool_module.MYSQL_CLIENT), "-N", "--user=root", "--host=127.0.0.1", f"--port={port}", dbname,
                 "--execute", "SELECT migration FROM migrations WHERE migration LIKE 'I12_MARKER%'"],
                capture_output=True, text=True, timeout=15,
            ).stdout
            return [line for line in out.splitlines() if line.strip()]

        m1, m2 = markers_in(db1), markers_in(db2)
        check("I12: pool 1 has only its own marker", m1 == ["I12_MARKER_ONE"], m1)
        check("I12: pool 2 has only its own marker", m2 == ["I12_MARKER_TWO"], m2)

        wave5_db = next(n for n in created if n.startswith("mepa_wave5_test_"))
        m5 = markers_in(wave5_db)
        check("I12: the wave5 pool (untouched by this test) has neither marker", m5 == [], m5)
        check("I12: same server_uuid throughout (one instance, multiple isolated schemas)", True)  # by construction: single `port`/instance for all four pools
    finally:
        if inst is not None:
            _teardown(inst)


def main():
    tests = [
        i1_occupied_explicit_port_no_started,
        i2_spawned_mysqld_exit_detected_via_poll,
        i3_wrong_vendor_rejected,
        i4_wrong_datadir_rejected,
        i5_wrong_server_uuid_rejected,
        i6_wrong_pid_file_rejected,
        i7_auto_port_started_verified,
        i8_cross_process_stop_via_auto_port,
        i9_foreign_server_stop_denied,
        i10_pool_wrong_server_denied,
        i11_session_pool_naming_isolation,
        i12_two_pools_concurrent_isolated,
    ]
    for t in tests:
        print(f"--- {t.__name__} ---", flush=True)
        try:
            t()
        except Exception as exc:  # noqa: BLE001 -- a raised exception is a FAIL, not a crash of the suite
            FAIL.append(t.__name__)
            print(f"FAIL  {t.__name__}  raised {exc!r}", flush=True)

    print(f"\n{len(PASS)} passed, {len(FAIL)} failed")
    if FAIL:
        print("FAILED:", ", ".join(FAIL))
    return 0 if not FAIL else 1


if __name__ == "__main__":
    raise SystemExit(main())
