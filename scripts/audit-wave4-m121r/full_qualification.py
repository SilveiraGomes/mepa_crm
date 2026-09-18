"""Independent per-run observations around the executor's unmodified runner."""
import concurrent.futures
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "wave4-m121"))
from cleanup import schema_exists  # noqa: E402
from run_suite import run_one  # noqa: E402
from verify import barrier_dirs  # noqa: E402

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m121r_audit"
OUT.mkdir(parents=True, exist_ok=True)
FILTER = "test_same_person_different_sessions"


def observed(*args):
    row = run_one(*args)
    row["independent_schema_absent"] = not schema_exists(row["schema"])
    row["pass_verified"] = bool(row["overall_pass"] and row["independent_schema_absent"]
                                and not row["cleanup"]["rescue_used"])
    return row


def save(group, rows):
    (OUT / f"{group}.json").write_text(json.dumps(rows, indent=2, default=str) + "\n", encoding="utf-8")


def main():
    baseline = set(map(str, barrier_dirs()))
    isolated = []
    for i in range(1, 51):
        isolated.append(observed(f"independent_full_iso_{i:02d}", "WaveThreeCheckinConcurrencyTest", "wave3", FILTER))
        save("isolated", isolated)
        if not isolated[-1]["pass_verified"]:
            return 1
    parallel = []
    for i in range(1, 11):
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            future = [pool.submit(observed, f"independent_full_parallel_{i:02d}_{j}",
                                  "WaveThreeCheckinConcurrencyTest", "wave3") for j in range(2)]
            parallel.extend(f.result() for f in future)
        save("parallel", parallel)
        if not all(r["pass_verified"] for r in parallel):
            return 1
    loaded = []
    for i in range(1, 11):
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            future = [pool.submit(observed, f"independent_full_loaded_{i:02d}_temporal",
                                  "WaveThreeCheckinConcurrencyTest", "wave3", FILTER),
                      pool.submit(observed, f"independent_full_loaded_{i:02d}_child",
                                  "WaveFourChildCheckinBoundaryTest", "wave4", "test_concurrent")]
            loaded.extend(f.result() for f in future)
        save("loaded", loaded)
        if not all(r["pass_verified"] for r in loaded):
            return 1
    summary = {"isolated": len(isolated), "parallel": len(parallel), "loaded_processes": len(loaded),
               "distinct_schemas": len({r["schema"] for r in isolated + parallel + loaded}),
               "new_barriers": sorted(set(map(str, barrier_dirs())) - baseline)}
    (OUT / "full_summary.json").write_text(json.dumps(summary, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(summary), flush=True)
    return 0 if not summary["new_barriers"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
