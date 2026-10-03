"""P0.10-F1A: controlled refresh of catalog_sha256 after the D-04A vocabulary reconciliation of model_catalog.json.

The physical manifests pin the SHA-256 of the WHOLE model_catalog.json file. F1A changes one Wave 6 column description
(chart_of_accounts.account_kind += INTERUNIT_CONTROL). This script:

1. proves that, compared with HEAD, the catalog differs ONLY inside Wave 6 table definitions (every other table and
   every top-level key is JSON-identical), so no already-physical wave is semantically affected;
2. runs the canonical Wave 2 generator and proves that its migrations and planned stats are byte-identical to HEAD
   (the generator also rewrites the manifest; that rewrite is discarded, exactly like P0.9-R1);
3. replaces catalog_sha256 only in the manifests that pinned the HEAD hash (wave2, wave5); manifests that were already
   stale before F1A (wave1/3/4, debt P09-R1-T01) are left untouched and reported.
Refuses to write anything if 1 or 2 fails.
"""
import hashlib
import json
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[4]
CATALOG = "docs/database/model_catalog.json"
MANIFESTS = ["wave1_manifest.json", "wave2_manifest.json", "wave3_manifest.json", "wave4_manifest.json", "wave5_migrations_manifest.json"]


def git(*args):
    return subprocess.run(["git", *args], cwd=REPO, capture_output=True, check=True).stdout


head_bytes = git("show", f"HEAD:{CATALOG}")
work_bytes = (REPO / CATALOG).read_bytes()
head_hash = hashlib.sha256(head_bytes).hexdigest()
new_hash = hashlib.sha256(work_bytes).hexdigest()
if head_hash == new_hash:
    sys.exit("REFUSED: catalog unchanged")

head = json.loads(head_bytes.decode("utf-8"))
work = json.loads(work_bytes.decode("utf-8"))
waves = json.loads((REPO / "docs/database/physical/migration_waves.json").read_text(encoding="utf-8"))
wave6 = set(next(w for w in waves["waves"] if w["wave"] == 6)["tables"])
for key in set(head) | set(work):
    if key != "tables" and head.get(key) != work.get(key):
        sys.exit(f"REFUSED: top-level key {key!r} changed")
head_tables = {t["name"]: t for t in head["tables"]}
work_tables = {t["name"]: t for t in work["tables"]}
if list(head_tables) != list(work_tables):
    sys.exit("REFUSED: table set or order changed")
changed = sorted(name for name in head_tables if head_tables[name] != work_tables[name])
outside = [name for name in changed if name not in wave6]
if outside:
    sys.exit(f"REFUSED: non-Wave-6 tables changed: {outside}")

generated = subprocess.run(["node", "scripts/generate-wave2-migrations.cjs"], cwd=REPO, capture_output=True, text=True)
if generated.returncode != 0:
    sys.exit("REFUSED: wave2 generator failed: " + generated.stderr[-500:])
dirty = [line[3:] for line in git("status", "--porcelain").decode().splitlines()]
unexpected = [p for p in dirty if p not in (CATALOG, "docs/database/02_data_dictionary.md", "docs/database/physical/wave2_manifest.json")
              and not p.startswith("docs/reviews/evidence/P0.10-F1A/")]
git("checkout", "--", "docs/database/physical/wave2_manifest.json")
if unexpected:
    sys.exit(f"REFUSED: wave2 generator output differs from HEAD in {unexpected}")

result = {"old_hash": head_hash, "new_hash": new_hash, "changed_tables": changed, "wave2_generator_byte_identical": True, "manifests": {}}
for name in MANIFESTS:
    path = REPO / "docs/database/physical" / name
    text = path.read_text(encoding="utf-8")
    pinned = json.loads(text)["catalog_sha256"]
    if pinned == head_hash:
        path.write_bytes(text.replace(head_hash, new_hash).encode("utf-8"))
        result["manifests"][name] = "REFRESHED"
    else:
        result["manifests"][name] = f"UNTOUCHED (already stale before F1A: {pinned[:12]}..., P09-R1-T01)"
print(json.dumps(result, indent=2))
