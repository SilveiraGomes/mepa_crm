"""Independent ten-run gate with server-side schema verification."""
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


def main():
    initial_barriers = set(map(str, barrier_dirs()))
    rows = []
    for number in range(1, 11):
        row = run_one(f"independent_short_{number:02d}", "WaveThreeCheckinConcurrencyTest", "wave3",
                      "test_same_person_different_sessions")
        row["independent_schema_absent"] = not schema_exists(row["schema"])
        row["new_barriers"] = sorted(set(map(str, barrier_dirs())) - initial_barriers)
        rows.append(row)
        (OUT / "short_qualification.json").write_text(json.dumps(rows, indent=2, default=str) + "\n", encoding="utf-8")
        if not (row["overall_pass"] and row["independent_schema_absent"] and not row["new_barriers"]
                and not row["cleanup"]["rescue_used"]):
            print("STOP: short qualification failed", flush=True)
            return 1
    print("SHORT 10/10 domain, cleanup, schema absence, barriers, no rescue", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
