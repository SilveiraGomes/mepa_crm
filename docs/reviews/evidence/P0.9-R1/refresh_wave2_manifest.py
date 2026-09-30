"""P0.9-R1: canonical refresh of docs/database/physical/wave2_manifest.json after P09-R-E01.

1. `node scripts/generate-wave2-migrations.cjs` (the canonical generator) is run first. It recomputes catalog_sha256 from
   docs/database/model_catalog.json and rewrites the 44 Wave 2 migrations, wave2_planned_stats.json and the manifest.
   The migrations and stats come out byte-identical to HEAD (verified with `git diff`).
2. The generator writes its PRE-EXECUTION default (`schema_execution_status = BLOCKED_FOR_EXECUTION_PENDING_AUTHORIZED_MYSQL`)
   and has no `test_engine`. The committed manifest carries the execution attestation added in 27d21c1 after the physical
   MySQL run. This script carries those two fields over verbatim from HEAD, so the only net change is the generator's hash.
   It asserts every other key equals HEAD, and refuses to write otherwise.
"""
import json
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[4]
MANIFEST = "docs/database/physical/wave2_manifest.json"
PRESERVED = ["schema_execution_status", "test_engine"]

head = json.loads(subprocess.run(["git", "show", f"HEAD:{MANIFEST}"], cwd=REPO, capture_output=True, text=True, check=True).stdout)
generated = json.loads((REPO / MANIFEST).read_text(encoding="utf-8"))
for key in set(head) | set(generated):
    if key in PRESERVED or key == "catalog_sha256":
        continue
    if head.get(key) != generated.get(key):
        sys.exit(f"REFUSED: generator output differs from HEAD in {key!r}; not a pure stale hash")
result = {}
for key in head:
    result[key] = generated["catalog_sha256"] if key == "catalog_sha256" else head[key]
(REPO / MANIFEST).write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
print(json.dumps({"old_hash": head["catalog_sha256"], "new_hash": generated["catalog_sha256"], "preserved_from_head": PRESERVED}, indent=2))
