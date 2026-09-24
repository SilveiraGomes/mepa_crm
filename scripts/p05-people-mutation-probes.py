"""P0.5-I negative probes (section 21) + P0.5-R1 (M11..M13): each mutation is applied to PRODUCTION code, the test
that must catch it is run and has to FAIL, and the file is restored byte-for-byte (SHA-256 checked).
A control run of every test on the unmodified tree must PASS first.

Requires the Wave 5 pool environment (WAVE5_DSN, WAVE5_USER, WAVE5_PASSWORD, WAVE5_ALLOW_SYNTHETIC=1).
Writes docs/reviews/evidence/P0.5-I/mutation-probes.json.
"""
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
API = REPO / "apps" / "api"
PHP = os.environ.get("MEPA_PHP_BIN", r"C:\wamp64\bin\php\php8.1.33\php.exe")
D = "app/Domain/People/"

M6_CODE = """            $ms = $this->rt->db->table('membership_statuses')->value('id') ?? $this->rt->db->table('membership_statuses')->insertGetId(['code' => 'M6_PROBE', 'name' => 'M6', 'is_active' => 1, 'created_at' => $now, 'lock_version' => 0]);
            $this->rt->db->table('memberships')->insert(['public_id' => (string) Str::ulid(), 'person_id' => $personId, 'status_id' => $ms, 'date_precision' => 'UNKNOWN', 'origin' => 'M6_PROBE', 'created_at' => $now, 'lock_version' => 0]);
            $contextId = (int) $this->rt->db->table('person_unit_contexts')->insertGetId(["""

PROBES = [
    ("M1", "internal numeric ID exposed", [
        (D + "PersonRecords.php", "            'public_id' => (string) $row->public_id,\n", "            'id' => (int) $row->id,\n            'public_id' => (string) $row->public_id,\n"),
        ("app/Http/People/PeopleOutput.php", "            if (is_string($key) && (in_array($key, self::FORBIDDEN, true) || (str_ends_with($key, '_id') && $key !== 'public_id'))) {", "            if (false) {"),
    ], "tests/DatabaseV2/PeoplePersonTest.php", "test_list_is_scoped_paginated_and_exposes_public_ids_only"),
    ("M2", "permission bypass", [
        (D + "PeopleAuthority.php", "->where('p.code', $permission)->where('p.data_type', PeopleCatalog::DATA_TYPE)", "->where('p.data_type', PeopleCatalog::DATA_TYPE)"),
    ], "tests/DatabaseV2/PeoplePersonTest.php", "test_permission_is_required_and_never_replaces_scope"),
    ("M3", "contextual scope bypass", [
        (D + "PeopleAuthority.php", "in_array($c['tier'], $tiers, true) && isset($covered[$c['unit']])", "in_array($c['tier'], $tiers, true)"),
        (D + "PeopleAuthority.php", "        if ($eligible === []) {\n            throw new PeopleError(PeopleReason::OUT_OF_SCOPE", "        if ($eligible === [] && false) {\n            throw new PeopleError(PeopleReason::OUT_OF_SCOPE"),
    ], "tests/DatabaseV2/PeoplePersonTest.php", "test_out_of_scope_and_nonexistent_targets_are_indistinguishable"),
    ("M4", "pagination removed", [
        (D + "PersonService.php", "->orderBy('p.id')->forPage($page, $perPage)->get(['p.*', 'ps.code as status_code'])", "->orderBy('p.id')->get(['p.*', 'ps.code as status_code'])"),
    ], "tests/DatabaseV2/PeoplePersonTest.php", "test_list_is_scoped_paginated_and_exposes_public_ids_only"),
    ("M5", "F-06 concealment broken", [
        (D + "PeopleGuard.php", "throw new PeopleError($visible ? PeopleReason::FORBIDDEN : PeopleReason::TARGET_NOT_FOUND", "throw new PeopleError(PeopleReason::FORBIDDEN"),
    ], "tests/DatabaseV2/PeoplePersonTest.php", "test_out_of_scope_and_nonexistent_targets_are_indistinguishable"),
    ("M6", "non-member forced into membership", [
        (D + "PersonService.php", "            $contextId = (int) $this->rt->db->table('person_unit_contexts')->insertGetId([", M6_CODE),
    ], "tests/DatabaseV2/PeoplePersonTest.php", "test_create_non_member_person_writes_onboarding_context_and_audit_from_that_context"),
    ("M7", "plaintext fallback at rest", [
        (D + "ContactService.php", "            [$ciphertext, $version] = $crypto->encrypt($value, $this->aad($person));\n            $primary", "            [$ciphertext, $version] = [$value, $crypto->activeVersion()];\n            $primary"),
    ], "tests/DatabaseV2/PeopleContactTest.php", "test_contact_is_encrypted_with_key_version_and_blind_index_and_round_trips"),
    ("M8", "relationship inverse broken", [
        (D + "RelationshipService.php", "                $ids[] = $this->insert($related, $subject, $inverse, $now);\n", ""),
    ], "tests/DatabaseV2/PeopleRelationshipTest.php", "test_parent_produces_child_atomically_and_end_closes_both"),
    ("M9", "minor projection exposes sensitive data", [
        (D + "PersonRecords.php", "        return $childProfile || in_array($this->ageBand($row), [BirthDate::MINOR, BirthDate::UNCERTAIN], true);", "        return false;"),
    ], "tests/DatabaseV2/PeoplePersonTest.php", "test_class_b_birth_requires_sensitive_view_and_minor_projection_is_minimal"),
    ("M10", "Class C export without PEOPLE_EXPORT_CLASS_C", [
        (D + "ExportService.php", "self::CLASS_C => [PeopleCatalog::PEOPLE_EXPORT_CLASS_C],", "self::CLASS_C => [],"),
    ], "tests/DatabaseV2/PeopleExportTest.php", "test_class_c_export_requires_separate_permission_and_reason"),
    # P0.5-R1 (P05R-F01): the source domain decides which registrations / enrollments still authorize.
    ("M11", "cancelled event registration authorizes (Events semantics ignored)", [
        (D + "PeopleAuthority.php", "[$registration, $registrationBindings] = $this->events->predicate('er', 'ev');", "[$registration, $registrationBindings] = ['1 = 1', []];"),
    ], "tests/DatabaseV2/PeopleContextAuthorityTest.php", "test_ctx_e2_cancelled_event_registration_no_longer_grants_context"),
    ("M12", "non-operational enrollment authorizes (Academy semantics ignored)", [
        (D + "PeopleAuthority.php", "[$enrollment, $enrollmentBindings] = $this->academy->predicate('e');", "[$enrollment, $enrollmentBindings] = ['1 = 1', []];"),
    ], "tests/DatabaseV2/PeopleContextAuthorityTest.php", "test_ctx_a2_enrollment_that_becomes_non_operational_no_longer_grants_context"),
    ("M13", "commit-time context re-check removed (TOCTOU)", [
        (D + "PeopleRuntime.php", "                    $this->authority->recheck($actor, $decision);\n", ""),
    ], "tests/DatabaseV2/PeopleContextAuthorityTest.php", "test_ctx_r1_source_state_change_between_read_and_commit_is_denied"),
]


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def phpunit(test: str, method: str) -> tuple[int, str]:
    env = {**os.environ, "XDEBUG_MODE": "off"}
    proc = subprocess.run([PHP, "vendor/phpunit/phpunit/phpunit", "--filter", f"/::{method}$/", test], cwd=API, env=env, capture_output=True, text=True, encoding="utf-8", errors="replace")
    summary = [l for l in proc.stdout.splitlines() if l.startswith(("OK (", "Tests:", "FAILURES", "ERRORS", "No tests"))]
    return proc.returncode, " | ".join(summary)


def main() -> int:
    if os.environ.get("WAVE5_ALLOW_SYNTHETIC") != "1":
        print("WAVE5 pool environment required", file=sys.stderr)
        return 2
    # Optional: --only M11,M12 --out <repo-relative json> (default: every probe, P0.5-I evidence file).
    args = sys.argv[1:]
    only = set(args[args.index("--only") + 1].split(",")) if "--only" in args else None
    out = REPO / (args[args.index("--out") + 1] if "--out" in args else "docs/reviews/evidence/P0.5-I/mutation-probes.json")
    results = []
    for mid, name, edits, test, method in PROBES:
        if only is not None and mid not in only:
            continue
        control_rc, control = phpunit(test, method)
        files = {}
        for rel, old, new in edits:
            path = API / rel
            if rel not in files:
                files[rel] = (path.read_bytes(), sha(path))
            text = path.read_bytes().decode("utf-8")
            if text.count(old) != 1:
                raise SystemExit(f"{mid}: anchor not unique in {rel}")
            path.write_bytes(text.replace(old, new, 1).encode("utf-8"))
        try:
            mutated_rc, mutated = phpunit(test, method)
        finally:
            restored = True
            for rel, (original, digest) in files.items():
                (API / rel).write_bytes(original)
                restored = restored and sha(API / rel) == digest
        results.append({"id": mid, "mutation": name, "files": sorted(files), "test": f"{test}::{method}",
                        "control_passed": control_rc == 0, "control": control, "mutant_detected": mutated_rc != 0, "mutant": mutated, "restored_byte_for_byte": restored})
        print(f"{mid}: control={'PASS' if control_rc == 0 else 'FAIL'} detected={'YES' if mutated_rc != 0 else 'NO'} restored={restored}")
    ok = all(r["control_passed"] and r["mutant_detected"] and r["restored_byte_for_byte"] for r in results)
    out.write_text(json.dumps({"probe": "P0.5-I_PEOPLE_MUTATIONS", "status": "PASS" if ok else "FAIL", "results": results}, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
