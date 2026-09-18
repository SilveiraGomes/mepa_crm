"""Re-run every official tests/Database class with an isolated schema."""
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "wave4-m121"))
from cleanup import schema_exists  # noqa: E402
from qualify import FULL_REGRESSION_SUITES  # noqa: E402
from run_suite import run_one  # noqa: E402

OUT = ROOT / "docs" / "database" / "physical" / "wave4_m121r_audit"
OUT.mkdir(parents=True, exist_ok=True)


def main():
    official = {p.stem for p in (ROOT / "apps" / "api" / "tests" / "Database").glob("*Test.php")}
    listed = {name for name, _ in FULL_REGRESSION_SUITES}
    if official != listed:
        print(json.dumps({"unlisted": sorted(official - listed), "missing": sorted(listed - official)}))
        return 2
    rows = []
    for i, (suite, wave) in enumerate(FULL_REGRESSION_SUITES, 1):
        row = run_one(f"independent_regression_{i:02d}", suite, wave)
        row["independent_schema_absent"] = not schema_exists(row["schema"])
        rows.append(row)
        (OUT / "regression.json").write_text(json.dumps(rows, indent=2, default=str) + "\n", encoding="utf-8")
        if not (row["overall_pass"] and row["independent_schema_absent"] and not row["cleanup"]["rescue_used"]):
            return 1
    print(f"REGRESSION {len(rows)}/{len(official)} classes PASS", flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
