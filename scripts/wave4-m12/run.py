from pathlib import Path
import concurrent.futures
import json
import os
import secrets
import subprocess
import sys
import threading
import time

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / 'docs/database/physical/wave4_m12_runs'
OUTPUT.mkdir(parents=True, exist_ok=True)
CONTAINER = os.environ.get('WAVE4_M12_CONTAINER', 'mepa-wave4-m12-test')
DB_PORT = os.environ.get('WAVE4_M12_PORT', '33115')
DB_USER = os.environ.get('WAVE4_M12_DB_USER', 'm12audit')
LOCK = threading.Lock()
REGRESSIONS = [
    ('WaveFourCommitAuthorizationTest', 'WAVE4', None),
    ('WaveFourProgressCommitBoundaryTest', 'WAVE4', None),
    ('WaveFourChildCheckinBoundaryTest', 'WAVE4', None),
    ('WaveFourChildrenSafetyTest', 'WAVE4', None),
    ('WaveFourCheckoutConcurrencyTest', 'WAVE4', None),
    ('WaveFourTemporalAuthorizationTest', 'WAVE4', None),
    ('WaveFourDiscipleshipScopeTest', 'WAVE4', None),
    ('WaveFourEvangelismTest', 'WAVE4', None),
    ('WaveFourEvangelismTemporalBoundaryTest', 'WAVE4', None),
    ('WaveFourIndependentAuditM1RTest', 'WAVE4', None),
    ('WaveFourPhysicalTest', 'WAVE4', None),
    ('WaveThreeDomainTest', 'WAVE3', None),
    ('WaveThreeCheckinConcurrencyTest', 'WAVE3', None),
    ('WaveThreePhysicalTest', 'WAVE3', None),
]

def record(group, item):
    with LOCK:
        path = OUTPUT / f'{group}.json'
        rows = json.loads(path.read_text(encoding='utf-8')) if path.exists() else []
        rows.append(item)
        path.write_text(json.dumps(rows, indent=2) + '\n', encoding='utf-8')
        print(json.dumps(item), flush=True)

def run(group, label, suite, prefix, filter_text=None):
    run_id = secrets.token_hex(12)
    schema = f"mepa_{'wave3' if prefix == 'WAVE3' else 'wave4'}_test_m12_{run_id}"
    schema_sql = f"CREATE DATABASE {schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON {schema}.* TO '{DB_USER}'@'%';"
    created = subprocess.run(['docker', 'exec', CONTAINER, 'mysql', '-uroot', '-e', schema_sql],
                             capture_output=True, text=True, timeout=30)
    if created.returncode:
        result = {'label': label, 'run_id': run_id, 'schema': schema, 'suite': suite,
                  'exit': created.returncode, 'error': 'SCHEMA_CREATE_FAILED', 'stderr': created.stderr}
        record(group, result)
        return result
    try:
        env = os.environ.copy()
        env.update({
            prefix + '_DSN': f'mysql:host=127.0.0.1;port={DB_PORT};dbname={schema}',
            prefix + '_USER': DB_USER,
            prefix + '_PASSWORD': '',
            prefix + '_ALLOW_SYNTHETIC': '1',
            'DB_CAPABILITIES_DSN': f'mysql:host=127.0.0.1;port={DB_PORT};dbname={schema}',
            'DB_CAPABILITIES_USER': DB_USER,
            'DB_CAPABILITIES_PASSWORD': '',
        })
        junit = OUTPUT / f'{group}_{label}_{run_id}.xml'
        command = ['php', 'vendor/phpunit/phpunit/phpunit', f'tests/Database/{suite}.php', '--log-junit', str(junit)]
        if filter_text:
            command += ['--filter', filter_text]
        start = time.monotonic()
        try:
            completed = subprocess.run(command, cwd=ROOT / 'apps/api', env=env,
                                       capture_output=True, text=True, timeout=360)
            exit_code = completed.returncode
            log = completed.stdout + completed.stderr
        except subprocess.TimeoutExpired as error:
            exit_code = 124
            log = 'OUTER_FAIL_SAFE_TIMEOUT\n' + str(error.stdout) + '\n' + str(error.stderr)
        log_file = OUTPUT / f'{group}_{label}_{run_id}.log'
        log_file.write_text(log, encoding='utf-8')
        result = {'label': label, 'run_id': run_id, 'schema': schema, 'suite': suite,
                  'filter': filter_text, 'exit': exit_code, 'seconds': round(time.monotonic() - start, 2),
                  'log': str(log_file.relative_to(ROOT)), 'junit': str(junit.relative_to(ROOT))}
        record(group, result)
        return result
    finally:
        subprocess.run(['docker', 'exec', CONTAINER, 'mysql', '-uroot', '-e',
                        f'DROP DATABASE IF EXISTS {schema};'], capture_output=True, text=True, timeout=30)

def main(group):
    outcomes = []
    if group == 'regressions':
        for suite, prefix, filter_text in REGRESSIONS:
            outcomes.append(run(group, suite, suite, prefix, filter_text))
    elif group == 'isolated':
        for n in range(1, 51):
            outcomes.append(run(group, f'{n:02d}', 'WaveThreeCheckinConcurrencyTest',
                                'WAVE3', 'test_same_person_different_sessions'))
    elif group == 'parallel':
        for pair in range(1, 11):
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                tasks = [pool.submit(run, group, f'{pair:02d}_{worker}', 'WaveThreeCheckinConcurrencyTest',
                                     'WAVE3', None) for worker in (0, 1)]
                outcomes += [task.result() for task in tasks]
    elif group == 'loaded':
        for n in range(1, 11):
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                one = pool.submit(run, group, f'{n:02d}_temporal', 'WaveThreeCheckinConcurrencyTest',
                                  'WAVE3', 'test_same_person_different_sessions')
                two = pool.submit(run, group, f'{n:02d}_child', 'WaveFourChildCheckinBoundaryTest',
                                  'WAVE4', 'test_concurrent')
                outcomes += [one.result(), two.result()]
    else:
        raise SystemExit('Use regressions, isolated, parallel or loaded')
    passed = sum(item['exit'] == 0 for item in outcomes)
    print(json.dumps({'group': group, 'passed': passed, 'total': len(outcomes), 'status': 'PASS' if passed == len(outcomes) else 'FAIL'}), flush=True)
    return 0 if passed == len(outcomes) else 1

if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1] if len(sys.argv) > 1 else ''))

