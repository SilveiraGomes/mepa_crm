# P0.3.5-A2-R2 — evidence of the focused re-audit of A2.1 (`1de0dd8`)

Companion to `docs/reviews/P0.3.5_A2_R2_independent_reaudit.md`. **The executor was NOT independent of the author of `7cbc299`/`1de0dd8`** (declared in section 0 of the report); this directory is reproducible evidence, not an independent approval.

Sources carry a `.txt` suffix so nothing here is executed or collected by PHPUnit.

| File | What it is |
|---|---|
| `AcademyR2AuditProbeTest.php.txt` | 18 probes (A2R-01/02/03, child-safety runtime, audit atomicity). Copy to `apps/api/tests/DatabaseV2/AcademyR2AuditProbeTest.php` to run; delete afterwards. |
| `probes_at_1de0dd8.txt` | probes at HEAD: 18/18 (136 assertions) |
| `probes_at_7cbc299.txt` | subset of 13 probes against the audited baseline (real baseline code via an autoload shim): 10 fail, 3 pass by design |
| `mutation_campaign.py.txt` | own mutation harness (2 controls, M1–M5, 6 adversarial variants), restores bytes and verifies sha256 |
| `mutation_results.json`, `mutation_campaign_console.txt`, `pre_mutation_hashes.txt` | its results and the pre-mutation hashes |
| `variant_behaviour.py.txt`, `variant_behaviour_results.json`, `variant_behaviour_console.txt` | do the behavioural suites catch the variants the static validator cannot see? (yes, all six) |
| `reg_*.txt` | one run each of Unit, Authorization, Enrollment, Operations, AssessmentCertification, Concurrency, Remediation |
| `val_*.txt` | the four validators |

Test infrastructure: instance port 33062, session `99f4636292b5`, `STARTED_VERIFIED` → `STOPPED_VERIFIED`; nothing ran against 3306/`laravel`/MariaDB.
