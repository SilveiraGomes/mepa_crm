"""Independent P0.3.4-M1.2.3-R probe: deterministic TOCTOU test between
verify_ownership() and shutil.rmtree() inside safe_remove_run_root().

Instead of trying to win a real race (microsecond window, unreliable), this
monkeypatches verify_ownership() to perform the hijack AT THE EXACT MOMENT
right after it returns True -- equivalent to "the race was won every time" --
which is the correct way to test a TOCTOU deterministically. If the victim
directory survives, the window is not exploitable even in the worst case
alignment.
"""
import json
import os
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "wave4-m121"))
import cleanup  # noqa: E402

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m123r_audit"
OUT.mkdir(parents=True, exist_ok=True)


def sql(statement, timeout=30):
    r = cleanup._mysql(statement, timeout=timeout)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout


def main():
    report = {}
    schema = cleanup.new_schema("wave4")
    run_id = cleanup.run_id_of(schema)
    sql(f"CREATE DATABASE `{schema}`")
    root = cleanup.create_run_root(run_id, schema)

    victim = Path(tempfile.gettempdir()) / f"audit_toctou_victim_{run_id}"
    victim.mkdir()
    sentinel = victim / "sentinel.txt"
    sentinel.write_text("do not delete", encoding="utf-8")

    real_verify = cleanup.verify_ownership

    def hijacking_verify(check_root, check_run_id, check_schema):
        result = real_verify(check_root, check_run_id, check_schema)
        if result:
            # Exactly the worst-case TOCTOU alignment: the instant after
            # ownership was confirmed, before shutil.rmtree() runs, replace
            # the run root with a symlink to an unrelated victim directory.
            import shutil
            shutil.rmtree(check_root, ignore_errors=True)
            os.symlink(str(victim), str(check_root), target_is_directory=True)
        return result

    cleanup.verify_ownership = hijacking_verify
    try:
        try:
            outcome = cleanup.safe_remove_run_root(root, run_id, schema)
            report["safe_remove_run_root_returned"] = outcome
        except Exception as exc:
            report["safe_remove_run_root_raised"] = f"{type(exc).__name__}: {exc}"
        report["victim_sentinel_survived"] = sentinel.exists()
        report["victim_dir_survived"] = victim.exists()
        # What actually got deleted at the hijacked path, if anything?
        report["hijack_path_is_now"] = (
            "gone" if not root.exists() and not root.is_symlink()
            else "symlink_removed" if root.is_symlink() is False and not root.exists()
            else "still_symlink" if root.is_symlink()
            else "something_else"
        )
    finally:
        cleanup.verify_ownership = real_verify
        # Cleanup: remove the symlink itself (never follow it), then the victim.
        try:
            if root.is_symlink():
                root.unlink()
            elif root.exists():
                import shutil
                shutil.rmtree(root, ignore_errors=True)
        except OSError:
            pass
        import shutil
        shutil.rmtree(victim, ignore_errors=True)
        if cleanup.schema_exists(schema):
            sql(f"DROP DATABASE IF EXISTS `{schema}`")

    path = OUT / "toctou_probe.json"
    path.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
