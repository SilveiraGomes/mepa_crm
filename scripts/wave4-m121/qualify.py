"""P0.3.4-M1.2.1 qualification battery (sections 17/18/20/21).

Every run goes through run_suite.run_one(), which uses the verified cleanup state
machine in cleanup.py -- so "cleanup PASS" here means the same DATABASE_DROPPED +
absence-verified state as the unit tests in test_cleanup.py, not a naive DROP.
"""
import concurrent.futures
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from run_suite import OUT, ROOT, run_one  # noqa: E402
from cleanup import _mysql  # noqa: E402
from verify import global_state_report  # noqa: E402

FILTER = "test_same_person_different_sessions"


def connections_baseline():
    r = _mysql("SHOW STATUS LIKE 'Threads_connected'")
    return int(r.stdout.split()[-1])


def write(group, rows):
    (OUT / f"{group}.json").write_text(json.dumps(rows, indent=2, default=str) + "\n", encoding="utf-8")
    domain_pass = sum(r["domain_result"] == "PASS" for r in rows)
    cleanup_pass = sum(r["cleanup_result"] == "PASS" for r in rows)
    summary = {"group": group, "total": len(rows), "domain_pass": domain_pass, "cleanup_pass": cleanup_pass}
    print(json.dumps(summary), flush=True)
    return summary


def group_smoke(n=5):
    rows = [run_one(f"smoke_{i:02d}", "WaveFourCommitAuthorizationTest", "wave4") for i in range(1, n + 1)]
    return write("smoke", rows)


def group_isolated(n):
    rows = [run_one(f"isolated_{i:02d}", "WaveThreeCheckinConcurrencyTest", "wave3", FILTER) for i in range(1, n + 1)]
    return write("isolated", rows)


def group_parallel(pairs):
    # Unfiltered (full class, all dataProvider worker counts 2/10/30/50) to match the
    # exact workload that originally reproduced M12R-01 in the independent audit --
    # a filtered/lighter suite here would under-test the fix.
    rows = []
    for p in range(1, pairs + 1):
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            futs = [pool.submit(run_one, f"parallel_{p:02d}_{i}", "WaveThreeCheckinConcurrencyTest", "wave3")
                    for i in range(2)]
            rows.extend(f.result() for f in futs)
    return write("parallel", rows)


def group_loaded(cycles):
    rows = []
    for c in range(1, cycles + 1):
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            a = pool.submit(run_one, f"loaded_{c:02d}_temporal", "WaveThreeCheckinConcurrencyTest", "wave3", FILTER)
            b = pool.submit(run_one, f"loaded_{c:02d}_child", "WaveFourChildCheckinBoundaryTest", "wave4", "test_concurrent")
            rows.extend([a.result(), b.result()])
    return write("loaded", rows)


FULL_REGRESSION_SUITES = [
    ("WaveOnePhysicalTest", "wave1"), ("WaveTwoPhysicalTest", "wave2"),
    ("WaveTwoConcurrencyTest", "wave2"), ("WaveTwoIndependentReconciliationTest", "wave2f"),
    ("WaveTwoTransferConcurrencyTest", "wave2m1"), ("WaveTwoM1IndependentAuditTest", "m1audit"),
    ("WaveTwoTransferMigrationSafetyTest", "m1safety"), ("WaveThreePhysicalTest", "wave3"),
    ("WaveThreeDomainTest", "wave3"), ("WaveThreeCheckinConcurrencyTest", "wave3"),
    ("WaveFourPhysicalTest", "wave4"), ("WaveFourCommitAuthorizationTest", "wave4"),
    ("WaveFourProgressCommitBoundaryTest", "wave4"), ("WaveFourCheckoutConcurrencyTest", "wave4"),
    ("WaveFourChildCheckinBoundaryTest", "wave4"), ("WaveFourChildrenSafetyTest", "wave4"),
    ("WaveFourDiscipleshipScopeTest", "wave4"), ("WaveFourEvangelismTemporalBoundaryTest", "wave4"),
    ("WaveFourEvangelismTest", "wave4"), ("WaveFourIndependentAuditM1RTest", "wave4"),
    ("WaveFourTemporalAuthorizationTest", "wave4"),
]


def group_full_regression():
    rows = []
    for n, (suite, wave) in enumerate(FULL_REGRESSION_SUITES, 1):
        row = run_one(f"regress_{n:02d}_{suite}", suite, wave)
        rows.append(row)
        if not row["overall_pass"]:
            break
    return write("full_regression", rows)


def main(stage):
    baseline_before = connections_baseline()
    result = {"stage": stage, "connections_before": baseline_before}
    if stage == "gate10":
        result["smoke"] = group_smoke(5)
        result["isolated"] = group_isolated(10)
    elif stage == "full":
        result["isolated"] = group_isolated(50)
        result["parallel"] = group_parallel(10)
        result["loaded"] = group_loaded(10)
    elif stage == "regression":
        result["progress_boundary"] = write("progress_boundary",
            [run_one("progress_boundary", "WaveFourProgressCommitBoundaryTest", "wave4")])
        result["full_regression"] = group_full_regression()
    else:
        raise SystemExit("stage must be gate10, full, or regression")
    result["connections_after"] = connections_baseline()
    result["global_state"] = global_state_report()
    (OUT / f"{stage}_summary.json").write_text(json.dumps(result, indent=2, default=str) + "\n", encoding="utf-8")
    print(json.dumps({k: v for k, v in result.items() if k != "global_state"}, default=str), flush=True)
    print(json.dumps(result["global_state"]), flush=True)
    return result


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "gate10")
