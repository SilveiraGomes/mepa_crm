"""P0.3.4-M1.2.3 section 12/18 -- the mandatory 20-pair parallel ownership battery.

Distinct from qualify.py's real PHPUnit-driven parallel battery (section 19): this
is a lightweight, direct exercise of the ownership mechanism itself at scale,
reusing the same thread-safe pattern proven in
test_cleanup_ownership_and_failure_boundary.py::o8_parallel_rescue_isolation (a
discriminating monkeypatch scoped to one run_id, never a process-wide global, so
concurrently-running sibling runs are never affected). For each of 20 pairs: run A
is forced into CLEANUP_FAIL + rescue while run B proceeds and passes normally,
concurrently. Confirms A never touches B's root/DB in any of the 20 pairs.
"""
import concurrent.futures
import json
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import cleanup as cleanup_module  # noqa: E402
from cleanup import _mysql, cleanup_run, new_schema, run_id_of, schema_exists  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "docs/database/physical/wave4_m123_qualification"
OUT.mkdir(parents=True, exist_ok=True)


def _teardown_schema(schema):
    if schema_exists(schema):
        _mysql(f"DROP DATABASE IF EXISTS `{schema}`", timeout=30)


def _teardown_root(root):
    if root.exists():
        import shutil
        shutil.rmtree(root, ignore_errors=True)


def _new_run(wave="wave4"):
    schema = new_schema(wave)
    run_id = run_id_of(schema)
    _mysql(f"CREATE DATABASE `{schema}`")
    root = cleanup_module.create_run_root(run_id, schema)
    return schema, run_id, root


def run_one_pair(pair_index: int) -> dict:
    schema_a, run_id_a, root_a = _new_run()
    schema_b, run_id_b, root_b = _new_run()
    real_safe_remove = cleanup_module.safe_remove_run_root
    real_rescue_budget = cleanup_module.RESCUE_TIMEOUT_SECONDS

    def discriminating(root, run_id, schema):
        if run_id == run_id_a:
            return False
        return real_safe_remove(root, run_id, schema)

    cleanup_module.safe_remove_run_root = discriminating
    cleanup_module.RESCUE_TIMEOUT_SECONDS = 5
    try:
        def run_a_failing():
            return cleanup_run(run_id_a, schema_a, 0, "PASS", run_root=root_a)

        def run_b_normal():
            time.sleep(0.2)
            return cleanup_run(run_id_b, schema_b, 0, "PASS", run_root=root_b)

        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            fut_a = pool.submit(run_a_failing)
            fut_b = pool.submit(run_b_normal)
            t_a = fut_a.result()
            t_b = fut_b.result()

        result = {
            "pair": pair_index,
            "a_run_id": run_id_a, "b_run_id": run_id_b,
            "a_cleanup_result": t_a.cleanup_result, "a_rescue_used": t_a.rescue_used,
            "b_cleanup_result": t_b.cleanup_result, "b_rescue_used": t_b.rescue_used,
            "a_forced_fail_as_expected": t_a.cleanup_result == "CLEANUP_FAIL" and t_a.rescue_used is True,
            "b_passed_normally": t_b.cleanup_result == "PASS" and t_b.rescue_used is False,
            "b_root_gone": not root_b.exists(),
            "b_schema_gone": not schema_exists(schema_b),
            "a_root_persists_untouched": root_a.exists(),
        }
        result["pair_pass"] = all([
            result["a_forced_fail_as_expected"], result["b_passed_normally"],
            result["b_root_gone"], result["b_schema_gone"], result["a_root_persists_untouched"],
        ])
        return result
    finally:
        cleanup_module.safe_remove_run_root = real_safe_remove
        cleanup_module.RESCUE_TIMEOUT_SECONDS = real_rescue_budget
        _teardown_schema(schema_a)
        _teardown_schema(schema_b)
        _teardown_root(root_a)
        _teardown_root(root_b)


def main(n_pairs=20):
    rows = [run_one_pair(i) for i in range(1, n_pairs + 1)]
    passed = sum(r["pair_pass"] for r in rows)
    print(json.dumps({"total_pairs": n_pairs, "pairs_passed": passed}, indent=2))
    (OUT / "ownership_parallel_pairs.json").write_text(json.dumps(rows, indent=2) + "\n", encoding="utf-8")
    return 0 if passed == n_pairs else 1


if __name__ == "__main__":
    n = int(sys.argv[1]) if len(sys.argv) > 1 else 20
    sys.exit(main(n))
