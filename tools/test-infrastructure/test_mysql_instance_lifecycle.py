"""P0.3.5-A1.0 -- MysqlInstance lifecycle test suite (T1-T10).

Reproduces and proves the fix for the false-success `stop()` bug found while
auditing Wave 5's Test Infrastructure V2 usage (a `stop` invoked from a
separate CLI process than the one that called `start()` used to report
`mysqld_stopped: true` without ever sending a shutdown). See
docs/reviews/P0.3.5_A1_0_pre_audit_evidence_hygiene.md for the narrative.

Runs against real, disposable, dedicated mysqld instances -- never the app's
own 127.0.0.1:3306/laravel server, and never a mocked database (same
no-mocked-database convention as scripts/wave4-m121/test_cleanup*.py: only
the *observation* of OS/network state is ever faked, in T8 specifically, to
prove the fail-closed contract under a condition that is otherwise
impractical to reproduce safely).

Usage: python test_mysql_instance_lifecycle.py
"""
import shutil
import sys
import tempfile
import time
import uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from mysql_instance import MysqlInstance, APP_DATABASE_PORT  # noqa: E402

PASS = []
FAIL = []

# Ports chosen well away from both the app DB (3306) and the port this repo's
# other Test Infrastructure V2 tooling defaults to (3307), so this suite can
# run independently of (and concurrently with) anything else using that port.
PORT_A = 3391
PORT_B = 3392


def check(name, cond, detail=""):
    if cond:
        PASS.append(name)
        print(f"PASS  {name}", flush=True)
    else:
        FAIL.append(name)
        print(f"FAIL  {name}  {detail}", flush=True)


def _new_instance(port: int) -> MysqlInstance:
    session_id = uuid.uuid4().hex[:12]
    datadir = Path(tempfile.gettempdir()) / "mepa-test-mysql-lifecycle" / session_id / "data"
    return MysqlInstance(datadir, port)


def _teardown(instance: MysqlInstance) -> None:
    """Best-effort cleanup so a failed assertion never leaks a live mysqld."""
    try:
        if instance._ping():
            instance.stop()
    except Exception:
        pass
    shutil.rmtree(instance.datadir.parent, ignore_errors=True)


def t1_same_invocation_stop():
    inst = _new_instance(PORT_A)
    try:
        inst.initialize()
        inst.start()
        check("T1: instance alive after start", inst._ping())
        result = inst.stop()
        check("T1: stop() same-invocation returns SUCCESS", result["status"] == "SUCCESS", result)
        check("T1: stop() confirms instance_stopped_confirmed", result["instance_stopped_confirmed"] is True, result)
        check("T1: independent re-check -- port no longer answers", not inst._ping())
    finally:
        _teardown(inst)


def t2_separate_invocation_stop():
    """The exact bug scenario: init-start in one MysqlInstance object, stop()
    called on a brand-new object built only from datadir+port -- exactly what
    a separate CLI process does."""
    inst_a = _new_instance(PORT_A)
    try:
        inst_a.initialize()
        inst_a.start()
        check("T2: instance alive after start (process A)", inst_a._ping())

        inst_b = MysqlInstance(inst_a.datadir, PORT_A)  # process B: no self.process handle at all
        check("T2: process B has no in-memory process handle", inst_b.process is None)
        result = inst_b.stop()
        check("T2: cross-process stop() returns SUCCESS", result["status"] == "SUCCESS", result)
        check("T2: cross-process stop() confirms instance_stopped_confirmed", result["instance_stopped_confirmed"] is True, result)
        check("T2: cross-process stop() verified ownership via mysqld.pid", result["ownership_verified"] is True, result)
        check("T2: cross-process stop() actually sent a shutdown", result["graceful_shutdown_sent"] is True, result)
        check("T2: independent re-check -- port no longer answers", not inst_a._ping())
    finally:
        _teardown(inst_a)


def t3_stop_when_already_stopped():
    inst = _new_instance(PORT_A)
    try:
        inst.initialize()
        inst.start()
        first = inst.stop()
        check("T3 setup: first stop succeeds", first["status"] == "SUCCESS", first)
        second = inst.stop()
        check("T3: stop() on an already-stopped instance returns ALREADY_STOPPED", second["status"] == "ALREADY_STOPPED", second)
        check("T3: idempotent stop still confirms stopped", second["instance_stopped_confirmed"] is True, second)
    finally:
        _teardown(inst)


def t4_pid_file_present_but_stale():
    """Metadata (mysqld.pid) exists but no longer corresponds to a live
    process at all -- e.g. left over from an unclean shutdown. Ownership
    cannot be verified, but the real, currently-running instance must still
    be stopped via the protocol-level path (which does not depend on the PID
    being correct). Uses a fresh MysqlInstance object with no in-memory
    process handle (like T2) so stop() is actually forced onto the
    pid-file fallback path instead of preferring the live handle it would
    otherwise still have from start()."""
    inst = _new_instance(PORT_A)
    try:
        inst.initialize()
        inst.start()
        (inst.datadir / "mysqld.pid").write_text("999999", encoding="utf-8")  # a PID astronomically unlikely to exist
        stopper = MysqlInstance(inst.datadir, PORT_A)
        result = stopper.stop()
        check("T4: stale/nonexistent PID -> ownership_verified is False", result["ownership_verified"] is False, result)
        check("T4: instance still stopped via graceful protocol path", result["status"] == "SUCCESS", result)
        check("T4: no fallback kill was needed/used", result["fallback_kill_used"] is False, result)
        check("T4: independent re-check -- port no longer answers", not inst._ping())
    finally:
        _teardown(inst)


def t5_pid_exists_but_wrong_process():
    """mysqld.pid exists and names a PID that IS currently running -- just not
    as mysqld. Ownership must be rejected by name/command-line, and no
    fallback kill may ever target that (unrelated, real, live) process. Uses
    a fresh MysqlInstance object with no in-memory process handle (like T2)
    so stop() is forced onto the pid-file fallback path."""
    inst = _new_instance(PORT_A)
    try:
        inst.initialize()
        inst.start()
        this_test_pid = __import__("os").getpid()  # a real, live, non-mysqld process
        (inst.datadir / "mysqld.pid").write_text(str(this_test_pid), encoding="utf-8")
        stopper = MysqlInstance(inst.datadir, PORT_A)
        result = stopper.stop()
        check("T5: PID belongs to a real but non-mysqld process -> ownership_verified is False", result["ownership_verified"] is False, result)
        check("T5: no fallback kill was attempted against the unrelated live process", result["fallback_kill_used"] is False, result)
        check("T5: this test process is still alive after stop()", __import__("os").kill(this_test_pid, 0) is None)
        check("T5: instance still stopped via graceful protocol path", result["status"] == "SUCCESS", result)
    finally:
        _teardown(inst)


def t6_port_belongs_to_different_instance():
    """The port answers, but the identity information available (another
    instance's own datadir/pid-file) describes a DIFFERENT instance on a
    DIFFERENT port -- the realistic shape of 'this port belongs to an
    unrelated instance'. Ownership must be rejected on the port mismatch."""
    inst_a = _new_instance(PORT_A)
    inst_b = _new_instance(PORT_B)
    try:
        inst_a.initialize()
        inst_a.start()
        inst_b.initialize()
        inst_b.start()

        # Ask to stop "port B" using identity info that actually belongs to
        # instance A's datadir (A's mysqld.pid names A's own PID, which runs
        # with --port=PORT_A, not PORT_B).
        confused = MysqlInstance(inst_a.datadir, PORT_B)
        result = confused.stop()
        check("T6: PID resolves to a real mysqld, but for a different port -> ownership_verified is False", result["ownership_verified"] is False, result)
        check("T6: no fallback kill was attempted", result["fallback_kill_used"] is False, result)
        check("T6: instance A (wrongly targeted) is unaffected", inst_a._ping())
        # The graceful, protocol-level shutdown command in `confused.stop()` was
        # addressed to PORT_B and so affects instance B, not A -- expected and
        # safe (Section 6 explicitly allows mysqladmin shutdown regardless of
        # verified ownership; only the OS-level kill is ownership-gated).
        check("T6: instance B (the actual port owner) received the graceful shutdown", not inst_b._ping())
    finally:
        _teardown(inst_a)
        _teardown(inst_b)


def t7_graceful_shutdown_path():
    inst = _new_instance(PORT_A)
    try:
        inst.initialize()
        inst.start()
        result = inst.stop()
        check("T7: graceful_shutdown_sent is True on the normal path", result["graceful_shutdown_sent"] is True, result)
        check("T7: fallback_kill_used is False on the normal (graceful) path", result["fallback_kill_used"] is False, result)
        check("T7: status is SUCCESS", result["status"] == "SUCCESS", result)
    finally:
        _teardown(inst)


def t8_shutdown_failure_is_reported_not_hidden():
    """Fault-injects a persistently-answering ping (the one, minimal seam
    needed to force the 'graceful shutdown did not work and ownership can't
    be verified' branch without requiring an actual unkillable mysqld) to
    prove the fail-closed contract: stop() must return STOP_FAILED, never a
    false SUCCESS, when it cannot independently confirm the instance is gone.
    Only the *observation* (_ping) is faked here, restored immediately after;
    the real instance underneath is still genuinely stopped and torn down."""
    inst = _new_instance(PORT_A)
    try:
        inst.initialize()
        inst.start()
        (inst.datadir / "mysqld.pid").write_text("999999", encoding="utf-8")  # ownership unverifiable
        real_ping = inst._ping
        inst._ping = lambda: True  # simulate a port that never stops answering
        try:
            result = inst.stop()
        finally:
            inst._ping = real_ping
        check("T8: unconfirmable stop returns STOP_FAILED, not SUCCESS", result["status"] == "STOP_FAILED", result)
        check("T8: instance_stopped_confirmed is False", result["instance_stopped_confirmed"] is False, result)
        check("T8: no fallback kill without verified ownership", result["fallback_kill_used"] is False, result)
    finally:
        _teardown(inst)


def t9_never_touches_app_database_port():
    try:
        MysqlInstance(Path(tempfile.gettempdir()) / "should-never-exist", APP_DATABASE_PORT)
        check("T9: constructing a MysqlInstance on port 3306 must raise", False, "no exception was raised")
    except ValueError as exc:
        check("T9: constructing a MysqlInstance on port 3306 raises ValueError", True)
        check("T9: the error names the app database port", "3306" in str(exc), str(exc))


def t10_double_stop_is_idempotent_and_factual():
    inst = _new_instance(PORT_A)
    try:
        inst.initialize()
        inst.start()
        first = inst.stop()
        second = inst.stop()
        third = inst.stop()
        check("T10: first stop SUCCESS", first["status"] == "SUCCESS", first)
        check("T10: second stop ALREADY_STOPPED (not a repeated false SUCCESS claim)", second["status"] == "ALREADY_STOPPED", second)
        check("T10: third stop still ALREADY_STOPPED, no error", third["status"] == "ALREADY_STOPPED", third)
        check("T10: all three calls agree the instance is stopped", all(r["instance_stopped_confirmed"] for r in (first, second, third)))
    finally:
        _teardown(inst)


def main():
    tests = [
        t1_same_invocation_stop,
        t2_separate_invocation_stop,
        t3_stop_when_already_stopped,
        t4_pid_file_present_but_stale,
        t5_pid_exists_but_wrong_process,
        t6_port_belongs_to_different_instance,
        t7_graceful_shutdown_path,
        t8_shutdown_failure_is_reported_not_hidden,
        t9_never_touches_app_database_port,
        t10_double_stop_is_idempotent_and_factual,
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
