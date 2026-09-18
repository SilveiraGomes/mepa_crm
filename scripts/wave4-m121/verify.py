"""Global, batch-level verification: section 12 (barrier files) and section 20/21
(orphan DB / process / barrier state). Read-only except for removing directories
that match the harness's own barrier naming convention -- never touches anything
else in the temp directory.

M1.2.2 hardening: the same false-absence class fixed in cleanup.py (H1/M2 -- a
verification query/command that fails must never be read as "zero orphans") applies
here too. orphan_schemas() and orphan_php_processes() now raise cleanup.VerificationError
on a failed check instead of silently returning an empty list, which used to be
indistinguishable from "genuinely nothing orphaned".
"""
import subprocess
import tempfile
from pathlib import Path


def _verification_error(msg: str):
    from cleanup import VerificationError
    return VerificationError(msg)


BARRIER_GLOB = "mepa_wave4_m12_*"


def barrier_dirs() -> list[Path]:
    root = Path(tempfile.gettempdir())
    return sorted(p for p in root.glob(BARRIER_GLOB) if p.is_dir())


def orphan_php_processes() -> list[str]:
    """Best-effort, read-only. Returns raw tasklist lines for php.exe; never kills
    anything -- other legitimate php.exe processes (e.g. the developer's own dev
    server) may be running on this host and must never be touched by this harness.

    Raises VerificationError if `tasklist` itself could not be run/completed --
    an empty result is only ever returned when the check genuinely succeeded.
    """
    try:
        r = subprocess.run(["tasklist", "/FI", "IMAGENAME eq php.exe", "/FO", "CSV"],
                            capture_output=True, text=True, timeout=15)
    except subprocess.TimeoutExpired as exc:
        raise _verification_error(f"orphan_php_processes: tasklist timed out: {exc}") from exc
    except OSError as exc:
        raise _verification_error(f"orphan_php_processes: could not invoke tasklist: {exc}") from exc
    if r.returncode != 0:
        raise _verification_error(f"orphan_php_processes: tasklist failed rc={r.returncode}: {r.stderr.strip()}")
    lines = [l for l in r.stdout.splitlines() if l.strip() and not l.startswith('"Image Name"')]
    return lines


def orphan_schemas(prefix_like: str) -> list[str]:
    from cleanup import _mysql
    try:
        r = _mysql(f"SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME LIKE '{prefix_like}'", timeout=15)
    except subprocess.TimeoutExpired as exc:
        raise _verification_error(f"orphan_schemas({prefix_like}): query timed out: {exc}") from exc
    except OSError as exc:
        raise _verification_error(f"orphan_schemas({prefix_like}): could not invoke mysql client: {exc}") from exc
    if r.returncode != 0:
        raise _verification_error(f"orphan_schemas({prefix_like}): query failed rc={r.returncode}: {r.stderr.strip()}")
    return [l.strip() for l in r.stdout.splitlines() if l.strip()]


def global_state_report() -> dict:
    return {
        "orphan_schemas_m121": orphan_schemas("mepa_%_test_m121_%"),
        "barrier_dirs": [str(p) for p in barrier_dirs()],
        "php_processes_raw": orphan_php_processes(),
    }
