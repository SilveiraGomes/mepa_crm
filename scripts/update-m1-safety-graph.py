from pathlib import Path
import json
from graphify.extract import extract
from graphify.build import build_merge
from graphify.cluster import cluster, score_all
from graphify.analyze import god_nodes, surprising_connections, suggest_questions
from graphify.export import to_json
from graphify.report import generate
from graphify.detect import detect, save_manifest
from graphify.diagnostics import diagnose_extraction

root = Path.cwd()
out = root / 'graphify-out'
old = json.loads((out / 'graph.json').read_text(encoding='utf-8'))
prior_path = root/'docs/database/physical/m1_1_graphify_validation.json'
prior = json.loads(prior_path.read_text(encoding='utf-8')) if prior_path.exists() else {}
paths = [root / 'apps/api/database/migrations' / name for name in (
    '2026_09_14_000000_wave2m1_add_transfers_closed_at.php',
    '2026_09_14_000001_wave2m1_add_transfers_open_guard.php')]
paths += [root / 'apps/api/tests/Database' / name for name in (
    'WaveTwoTransferConcurrencyTest.php', 'WaveTwoM1IndependentAuditTest.php',
    'WaveTwoTransferMigrationSafetyTest.php')]
fresh = extract(paths, cache_root=root, root=root, parallel=False)
report_file = 'docs/reviews/P0.3.2_M1_1_migration_safety_fix.md'
report = root / report_file
if not report.exists():
    raise SystemExit('Required safety report missing')
nodes = [
    {'id':'m1_1_safety_fix','label':'P0.3.2-M1.1 migration safety fix', 'source_file':report_file,'source_location':'L1','type':'document'},
    {'id':'m1_1_durable_history','label':'closed_at durable domain history survives rollback and reapply','source_file':report_file,'source_location':'Migration corrigida','type':'concept'},
    {'id':'m1_1_invalid_precheck','label':'Invalid open transfer duplicates abort before guard DDL','source_file':report_file,'source_location':'Invalid data precheck','type':'concept'},
    {'id':'m1_1_finding','label':'F-M1R-01 RESOLVED migration safety evidence','source_file':report_file,'source_location':'Gate','type':'finding'},
]
edges = [{'source':'m1_1_safety_fix','target':n['id'],'relation':'documents','confidence':'EXTRACTED'} for n in nodes[1:]]
for n in fresh['nodes']:
    if n.get('label') in ('WaveTwoTransferMigrationSafetyTest','up()','down()'):
        edges.append({'source':'m1_1_safety_fix','target':n['id'],'relation':'verified_by' if n.get('label') == 'WaveTwoTransferMigrationSafetyTest' else 'implemented_by','confidence':'EXTRACTED'})
# Existing historical reports stay represented. Link the correction to the original finding.
for n in old['nodes']:
    if 'F-M1R-01' in n.get('label','') and n['id'] != 'm1_1_finding':
        edges.append({'source':'m1_1_finding','target':n['id'],'relation':'resolves','confidence':'EXTRACTED'})
for edge in edges:
    edge['source_file'] = report_file
    edge['source_location'] = 'Migration corrigida / Gate'
fresh['nodes'] += nodes
fresh['edges'] += edges
fresh['input_tokens'] = len(report.read_text(encoding='utf-8')) // 4
fresh['output_tokens'] = len(json.dumps({'nodes':nodes,'edges':edges})) // 4
G = build_merge([fresh], graph_path=out/'graph.json', root=root, directed=bool(old.get('directed',False)), ast_sources=paths)
communities = cluster(G)
labels = {}
for cid, members in communities.items():
    names = [G.nodes[n].get('community_name') for n in members if G.nodes[n].get('community_name')]
    labels[cid] = max(set(names), key=names.count) if names else 'Migration safety'
# Real shrink guard: never force a smaller graph.
if not to_json(G, communities, str(out/'graph.json'), community_labels=labels):
    raise SystemExit('SHRINK GUARD REJECTED: graph unchanged; no report published')
detection = detect(root)
cohesion = score_all(G,communities)
gods = god_nodes(G)
surprises = surprising_connections(G,communities)
questions = suggest_questions(G,communities,labels)
tokens = {'input':fresh['input_tokens'],'output':fresh['output_tokens']}
(out/'GRAPH_REPORT.md').write_text(generate(G,communities,cohesion,labels,gods,surprises,detection,tokens,str(root),suggested_questions=questions),encoding='utf-8')
(out/'.graphify_labels.json').write_text(json.dumps({str(k):v for k,v in labels.items()},ensure_ascii=False),encoding='utf-8')
save_manifest({'code':[str(p) for p in paths], 'document':[str(report)]}, root=root)
full = {'nodes':[{'id':n,**d} for n,d in G.nodes(data=True)],'edges':[{**d,'source':u,'target':v} for u,v,d in G.edges(data=True)]}
health = diagnose_extraction(full,directed=bool(old.get('directed',False)),root=str(root))
summary = {'before_nodes':prior.get('before_nodes',len(old['nodes'])),'after_nodes':G.number_of_nodes(),'before_edges':prior.get('before_edges',len(old.get('links',[]))),'after_edges':G.number_of_edges(),'shrink_guard':'PASS (force=False)','health':health,'tokens':tokens,'token_count_method':'estimated chars/4 for inline session extraction','semantic_extraction':'inline, grounded in safety report; scoped update; historical report nodes retained'}
(root/'docs/database/physical/m1_1_graphify_validation.json').write_text(json.dumps(summary,indent=2,ensure_ascii=False),encoding='utf-8')
print(json.dumps(summary,ensure_ascii=False))
