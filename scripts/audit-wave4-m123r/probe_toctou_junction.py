"""Independent P0.3.4-M1.2.3-R probe: TOCTOU via a Windows JUNCTION (not a
symlink) between verify_ownership() and shutil.rmtree() inside
safe_remove_run_root(). Windows junctions are a DIFFERENT NTFS reparse-point
type than symlinks; os.path.islink()/Path.is_symlink() return False for them
on this Python/Windows combination (confirmed separately) -- meaning
shutil.rmtree()'s own top-level-symlink protection, which relies on
os.path.islink(), does NOT recognize a junction and may recurse into it.
Deterministic hijack (injected at the exact vulnerable instant), same
technique as probe_toctou.py's symlink variant.
"""
import json
import subprocess
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


def make_junction(link_path: Path, target_path: Path):
    r = subprocess.run(["cmd", "/c", "mklink", "/J", str(link_path), str(target_path)],
                        capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"mklink /J failed: {r.stderr or r.stdout}")


def main():
    report = {}
    schema = cleanup.new_schema("wave4")
    run_id = cleanup.run_id_of(schema)
    sql(f"CREATE DATABASE `{schema}`")
    root = cleanup.create_run_root(run_id, schema)

    victim = Path(tempfile.gettempdir()) / f"audit_toctou_junction_victim_{run_id}"
    victim.mkdir()
    sentinel = victim / "sentinel.txt"
    sentinel.write_text("do not delete via junction hijack", encoding="utf-8")
    # Give the victim some depth, since rmtree recursing into a hijacked
    # junction would delete contents, not just the top marker file.
    (victim / "subdir").mkdir()
    (victim / "subdir" / "nested.txt").write_text("nested victim data", encoding="utf-8")

    real_verify = cleanup.verify_ownership

    def hijacking_verify(check_root, check_run_id, check_schema):
        result = real_verify(check_root, check_run_id, check_schema)
        if result:
            import shutil
            shutil.rmtree(check_root, ignore_errors=True)
            make_junction(check_root, victim)
        return result

    cleanup.verify_ownership = hijacking_verify
    try:
        try:
            outcome = cleanup.safe_remove_run_root(root, run_id, schema)
            report["safe_remove_run_root_returned"] = outcome
        except Exception as exc:
            report["safe_remove_run_root_raised"] = f"{type(exc).__name__}: {exc}"
        report["victim_sentinel_survived"] = sentinel.exists()
        report["victim_nested_survived"] = (victim / "subdir" / "nested.txt").exists()
        report["victim_dir_survived"] = victim.exists()
        report["hijack_path_exists_after"] = root.exists()
    finally:
        cleanup.verify_ownership = real_verify
        # Cleanup: if the junction point still exists, remove ONLY the
        # reparse point itself (rmdir on the link path does not recurse into
        # the target for a junction), never rmtree through it.
        try:
            if root.exists():
                subprocess.run(["cmd", "/c", "rmdir", str(root)], capture_output=True, text=True)
        except OSError:
            pass
        import shutil
        shutil.rmtree(victim, ignore_errors=True)
        if cleanup.schema_exists(schema):
            sql(f"DROP DATABASE IF EXISTS `{schema}`")

    path = OUT / "toctou_junction_probe.json"
    path.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
