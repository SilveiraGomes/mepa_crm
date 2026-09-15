from pathlib import Path
import json
import subprocess

ROOT = Path(__file__).resolve().parents[2]
GRAPH = ROOT / 'graphify-out/graph.json'
BACKUP = ROOT / 'graphify-out/2026-09-15/graph.json'
before = json.loads(BACKUP.read_text(encoding='utf-8'))
after = json.loads(GRAPH.read_text(encoding='utf-8'))
old = {node['id']: node for node in before['nodes']}
new = {node['id']: node for node in after['nodes']}
removed = [old[key] for key in old.keys() - new.keys()]
removed_sources = sorted(set(str(node.get('source_file') or node.get('source_location') or '') for node in removed))
shrink_guard = {
    'before_nodes': len(old), 'after_nodes': len(new),
    'removed_nodes': len(removed), 'added_nodes': len(new.keys() - old.keys()),
    'removed_sources': removed_sources,
    'status': 'PASS_INTENTIONAL_HELPER_PRUNE' if all(
        source.startswith('scripts/wave4-m1/') and source.endswith('_m12.py')
        for source in removed_sources
    ) and len(new) >= 3439 else 'FAIL_UNEXPECTED_SHRINK'
}
queries = [
    ('EvangelismService', 'EvangelismService run commitAuthorize DomainAccess authorizeMany'),
    ('track', 'EvangelismService track DISCIPLESHIP_CONFIGURE'),
    ('step', 'EvangelismService step DISCIPLESHIP_CONFIGURE'),
    ('progress', 'EvangelismService progress DiscipleshipEnrollmentScope'),
    ('DomainClock', 'DomainClock UTC_TIMESTAMP(6)'),
    ('commit authorization', 'DomainAccess authorizeMany commitAuthorize'),
    ('audit writer', 'DomainAccess audit audit_logs OUTREACH_RECORD_CREATED'),
    ('worker harness', 'WaveFourWorkerHarness poll collect close'),
    ('collector', 'WaveThreeCheckinConcurrencyTest collect process supervision'),
]
results = []
for label, question in queries:
    run = subprocess.run(['graphify', 'query', question, '--budget', '900'],
                         cwd=ROOT, capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=45)
    output = run.stdout + run.stderr
    results.append({'label': label, 'question': question, 'exit': run.returncode,
                    'node_count': sum(line.startswith('NODE ') for line in output.splitlines()),
                    'output': output})
    print(json.dumps({'label': label, 'exit': run.returncode, 'node_count': results[-1]['node_count']}), flush=True)
diagnose = subprocess.run(['graphify', 'diagnose', 'multigraph', '--json'],
                          cwd=ROOT, capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=45)
report = {'shrink_guard': shrink_guard, 'queries': results,
          'diagnose': {'exit': diagnose.returncode, 'output': diagnose.stdout + diagnose.stderr}}
report['status'] = 'PASS' if shrink_guard['status'].startswith('PASS') and all(
    item['exit'] == 0 and item['node_count'] > 0 for item in results
) and diagnose.returncode == 0 else 'FAIL'
path = ROOT / 'docs/database/physical/wave4_m12_graphify_queries.json'
path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
print(json.dumps({'status': report['status'], 'shrink_guard': shrink_guard['status'],
                  'queries': len(results), 'diagnose_exit': diagnose.returncode}), flush=True)
raise SystemExit(0 if report['status'] == 'PASS' else 1)

