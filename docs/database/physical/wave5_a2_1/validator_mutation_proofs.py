"""P0.3.5-A2.1 / A2R-04 -- proof that the application-contract validator no longer gives a false PASS when a
critical child-safety integration is removed.

Not a mutation-testing framework: exactly the mutations the remediation mission names (M1-M5) plus the
variants of the two false PASSes the independent audit reproduced. Each mutation is applied to the real
source, the validator is run, and the file is restored byte-for-byte (verified by hash) before the next one.

Usage (repo root):  python docs/database/physical/wave5_a2_1/validator_mutation_proofs.py
Exit code 0 only when the clean tree passes, EVERY mutation is detected and every file is restored.
"""
import hashlib
import json
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[4]
DOMAIN = ROOT / 'apps/api/app/Domain/Academy'
CONTRACT = ROOT / 'docs/database/physical/wave5_application_contracts.json'
ENROLL = DOMAIN / 'EnrollmentService.php'
ATTEND = DOMAIN / 'AcademicAttendanceService.php'

LOCK_ENROLL = '                $cover = $this->rt->safety->lock($person);\n'
ASSERT_ENROLL = '                $this->rt->safety->assertCover($cover, $this->rt->now());\n                return [\'enrollment_id\' => $id,'
LOCK_ATTEND = '        $covers[] = $this->rt->safety->lock((int) $enrollment->person_id);\n'
ASSERT_ATTEND = '            $this->rt->safety->assertCover($cover, $at);\n'
CALL_ASSERTS_RECORD = '                $result = $this->apply($decision, $target, $enrollmentId, $status, $expectedLockVersion, $covers);\n                $this->assertCovers($covers);\n'
UNUSED = '\n    private function neverCalled(int $person, $cover): void\n    {\n        $this->rt->safety->lock($person);\n        $this->rt->safety->assertCover($cover, $this->rt->now());\n    }\n}\n'


def replace_once(text, old, new):
    assert text.count(old) == 1, f'anchor not found exactly once: {old[:60]!r}'
    return text.replace(old, new, 1)


def contract_flags(false_for):
    def mutate(text):
        data = json.loads(text)
        hit = 0
        for service in data['services']:
            for op in service['operations']:
                if op['operation'] in false_for:
                    op['child_safety_required'] = False
                    op['commit_time_child_safety_required'] = False
                    hit += 1
        assert hit, false_for
        return json.dumps(data, indent=2, ensure_ascii=False) + '\n'
    return mutate


def move_to_unused(text):
    text = replace_once(text, LOCK_ENROLL, '')
    text = replace_once(text, ASSERT_ENROLL, "                return ['enrollment_id' => $id,")
    assert text.rstrip().endswith('}')
    return text.rstrip()[:-1].rstrip() + '\n' + UNUSED


MUTATIONS = [
    # id, description, file, mutation, check ids expected among the failures
    ('M1', 'remove the child-safety lock from EnrollmentService::enroll (audit false PASS #1; transition still has one)', ENROLL, lambda t: replace_once(t, LOCK_ENROLL, ''), ['op.child_safety.lock_declared.EnrollmentService.enroll']),
    ('M1b', 'remove the commit-time assertion from EnrollmentService::enroll (transition still has one)', ENROLL, lambda t: replace_once(t, ASSERT_ENROLL, "                return ['enrollment_id' => $id,"), ['op.child_safety.commit_declared.EnrollmentService.enroll']),
    ('M2', 'remove the initial safety lock from attendance apply()', ATTEND, lambda t: replace_once(t, LOCK_ATTEND, ''), ['op.child_safety.lock_declared.AcademicAttendanceService.record', 'op.child_safety.lock_declared.AcademicAttendanceService.recordBulk']),
    ('M3', 'remove the late (commit-time) assertion from attendance (audit false PASS #2)', ATTEND, lambda t: replace_once(t, ASSERT_ATTEND, ''), ['op.child_safety.commit_declared.AcademicAttendanceService.record', 'op.child_safety.commit_declared.AcademicAttendanceService.recordBulk']),
    ('M3b', 'drop only the assertCovers() call of record() (recordBulk keeps it)', ATTEND, lambda t: replace_once(t, CALL_ASSERTS_RECORD, '                $result = $this->apply($decision, $target, $enrollmentId, $status, $expectedLockVersion, $covers);\n'), ['op.child_safety.commit_declared.AcademicAttendanceService.record']),
    ('M4', 'remove the child-safety requirement from the CONTRACT of enrollment.create', CONTRACT, contract_flags({'enrollment.create'}), ['op.child_safety.floor.EnrollmentService.enroll', 'op.child_safety.lock_declared.EnrollmentService.enroll']),
    ('M5', 'move the safety calls of enroll into a private method nothing calls', ENROLL, move_to_unused, ['op.child_safety.lock_declared.EnrollmentService.enroll', 'op.child_safety.commit_declared.EnrollmentService.enroll']),
]


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def validate():
    run = subprocess.run(['node', 'scripts/validate-wave5-application-contracts.cjs'], cwd=ROOT, capture_output=True, text=True)
    failed = [line.strip()[2:].split(':', 1)[0] for line in run.stderr.splitlines() if line.strip().startswith('- ')]
    return run.returncode, failed


def main():
    originals = {p: (p.read_bytes(), sha(p)) for p in {ENROLL, ATTEND, CONTRACT}}
    report = {'phase': 'P0.3.5-A2.1', 'finding': 'A2R-04', 'mutations': []}
    ok = True
    code, failed = validate()
    report['clean_tree'] = {'exit_code': code, 'failed_checks': failed}
    ok &= code == 0
    for mid, description, path, mutate, expected in MUTATIONS:
        original = originals[path][0]
        try:
            path.write_bytes(mutate(original.decode('utf-8')).encode('utf-8'))
            code, failed = validate()
        finally:
            path.write_bytes(original)
        restored = sha(path) == originals[path][1]
        detected = code != 0 and all(e in failed for e in expected)
        report['mutations'].append({'id': mid, 'description': description, 'file': str(path.relative_to(ROOT)).replace('\\', '/'), 'validator_exit_code': code,
                                    'detected': detected, 'expected_checks': expected, 'failed_checks': failed, 'restored_byte_identical': restored})
        ok &= detected and restored
        print(f"{mid:4} {'DETECTED' if detected else 'MISSED  '} restored={restored} exit={code} :: {description}")
    code, failed = validate()          # regenerates wave5_application_contracts_validation.json from the clean tree
    report['clean_tree_after'] = {'exit_code': code, 'failed_checks': failed}
    ok &= code == 0
    report['result'] = 'ALL_MUTATIONS_DETECTED_AND_RESTORED' if ok else 'FAIL'
    (ROOT / 'docs/database/physical/wave5_a2_1/validator_mutation_proofs.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(report['result'])
    sys.exit(0 if ok else 1)


if __name__ == '__main__':
    main()
