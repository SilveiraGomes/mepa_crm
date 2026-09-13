from pathlib import Path
import json, hashlib, subprocess, os, sys
from graphify.extract import extract
from graphify.build import build_merge
from graphify.cluster import cluster, score_all
from graphify.analyze import god_nodes, surprising_connections, suggest_questions
from graphify.export import to_json
from graphify.report import generate
from graphify.detect import detect, save_manifest
from graphify.diagnostics import diagnose_extraction

root=Path.cwd();out=root/'graphify-out';old=json.loads((out/'graph.json').read_text(encoding='utf-8'))
manifest=json.loads((root/'docs/database/physical/wave3_manifest.json').read_text())
paths=[root/'apps/api/database/migrations'/f for f in manifest['migrations']]
paths+=list((root/'apps/api/app/Domain/Events').glob('*.php'))+list((root/'apps/api/tests/Database').glob('WaveThree*Test.php'))
paths+=[root/'scripts/lib/wave3-catalog.cjs',root/'scripts/generate-wave3-migrations.cjs',root/'scripts/validate-wave3-schema.cjs',root/'scripts/format-wave3.php',root/'apps/api/tests/Database/Support/WaveThreeCase.php',root/'scripts/inspect-wave3-schema.php',root/'scripts/wave3-checkin-worker.php']
fresh=extract(paths,cache_root=root,root=root,parallel=False)
report_file='docs/reviews/P0.3.3_wave3_implementation.md'
report=root/report_file
if not report.exists():raise SystemExit('Wave 3 report required before graph update')
nodes=[{'id':'wave3_implementation','label':'P0.3.3 Wave 3 governance events invitations credentials check-in implementation','source_file':report_file,'source_location':'L1','type':'document'},
{'id':'wave3_token_validation','label':'QR token validation random_bytes SHA-256 opaque credential authenticated scoped eligibility','source_file':'docs/adr/0012-wave3-token-policy-audit.md','source_location':'L7','type':'concept'},
{'id':'wave3_checkin_guard','label':'event_checkins UNIQUE session_id person_id transactional idempotency real 2 10 30 50 workers','source_file':report_file,'source_location':'Check-in concurrency','type':'concept'},
{'id':'wave3_durable_denial','label':'CHECKIN_DENIED after rollback audit_logs no QR token PII external guest','source_file':report_file,'source_location':'QR/token','type':'concept'},
{'id':'wave3_tracking','label':'F-W1-03 tracking completed owner OrganizationalStructureService P0.4.1 service deferred','source_file':'docs/reviews/P0.3.3_previous_findings_addendum.md','source_location':'L5','type':'finding'},
{'id':'wave3_encoding_erratum','label':'F-M11R-01 append-only readable encoding erratum historical domain durable closed_at preserved','source_file':'docs/reviews/P0.3.3_previous_findings_addendum.md','source_location':'L9','type':'document'}]
edges=[]
for t,file in zip(manifest['tables'],manifest['migrations']):
    source='apps/api/database/migrations/'+file
    nodes.append({'id':'wave3_table_'+t,'label':t+' Wave3 approved physical table','canonical_name':t,'source_file':source,'source_location':'up() SQL','type':'table'})
    edges.append({'source':'wave3_implementation','target':'wave3_table_'+t,'relation':'implements','confidence':'EXTRACTED','source_file':report_file,'source_location':'Novas tabelas'})
models=json.loads((root/'docs/database/model_catalog.json').read_text())['tables']
for t in models:
    if t['name'] not in manifest['tables']:continue
    source='apps/api/database/migrations/'+manifest['migrations'][manifest['tables'].index(t['name'])]
    for c in t['columns']:
        target=c.get('fk')
        if target in manifest['tables']:
            edges.append({'source':'wave3_table_'+t['name'],'target':'wave3_table_'+target,'relation':'references '+c['name'],'confidence':'EXTRACTED','source_file':source,'source_location':'FOREIGN KEY '+c['name']})
for node in nodes[1:6]:edges.append({'source':'wave3_implementation','target':node['id'],'relation':'documents','confidence':'EXTRACTED','source_file':report_file,'source_location':'Gate interno'})
for n in fresh['nodes']:
    if any(x in n.get('label','')for x in ['CheckinService','CredentialService','InvitationService','WaveThree']):
        edges.append({'source':'wave3_implementation','target':n['id'],'relation':'verified_by' if 'Test' in n.get('label','') else 'implemented_by','confidence':'EXTRACTED','source_file':report_file,'source_location':'Test suites'})
fresh['nodes']+=nodes;fresh['edges']+=edges
fresh['input_tokens']=len(report.read_text(encoding='utf-8'))//4;fresh['output_tokens']=len(json.dumps({'nodes':nodes,'edges':edges}))//4
G=build_merge([fresh],graph_path=out/'graph.json',root=root,directed=bool(old.get('directed',False)),ast_sources=paths)
communities=cluster(G);labels={}
for cid,members in communities.items():
    names=[G.nodes[n].get('community_name')for n in members if G.nodes[n].get('community_name')]
    labels[cid]=max(set(names),key=names.count)if names else 'Events governance credentials check-in'
if not to_json(G,communities,str(out/'graph.json'),community_labels=labels):raise SystemExit('Shrink guard refused; force not used')
# Exercise rejection on a scratch file, preserve the real graph exactly.
probe=root/'docs/database/physical/.wave3_graph_guard_probe.json';probe.write_bytes((out/'graph.json').read_bytes());before=hashlib.sha256(probe.read_bytes()).hexdigest()
small=G.subgraph(list(G.nodes)[:1]).copy();rejected=not to_json(small,{0:list(small.nodes)},str(probe));unchanged=hashlib.sha256(probe.read_bytes()).hexdigest()==before;probe.unlink()
if not rejected or not unchanged:raise SystemExit('Shrink rejection probe failed')
full={'nodes':[{'id':n,**d}for n,d in G.nodes(data=True)],'edges':[{**d,'source':u,'target':v}for u,v,d in G.edges(data=True)]}
health=diagnose_extraction(full,directed=bool(old.get('directed',False)),root=str(root))
detection=detect(root);cohesion=score_all(G,communities);gods=god_nodes(G);surprises=surprising_connections(G,communities);questions=suggest_questions(G,communities,labels);tokens={'input':fresh['input_tokens'],'output':fresh['output_tokens']}
(out/'GRAPH_REPORT.md').write_text(generate(G,communities,cohesion,labels,gods,surprises,detection,tokens,str(root),suggested_questions=questions),encoding='utf-8')
(out/'.graphify_labels.json').write_text(json.dumps({str(k):v for k,v in labels.items()},ensure_ascii=False),encoding='utf-8')
save_manifest({'code':[str(p)for p in paths],'document':[str(report)]},root=root)
queries=['governance_bodies','governance_body_memberships','events','event_sessions','event_invitation_lists','event_invitees','event_invitations','event_checkins','event_attendance','credentials','event_credentials','QR token validation random_bytes SHA-256']
coverage=[]
if '--refresh-only' in sys.argv:
    coverage=json.loads((root/'docs/database/physical/wave3_graphify_queries.json').read_text(encoding='utf-8'))
for q in ([] if '--refresh-only' in sys.argv else queries):
    result=subprocess.run(['graphify','query',q,'--budget','1500'],capture_output=True,text=True,encoding='utf-8',errors='replace',cwd=root,env={**os.environ,'PYTHONIOENCODING':'utf-8'})
    node='wave3_token_validation'if q.startswith('QR')else 'wave3_table_'+q
    expanded=subprocess.run(['graphify','query',G.nodes[node]['label'],'--budget','1500'],capture_output=True,text=True,encoding='utf-8',errors='replace',cwd=root,env={**os.environ,'PYTHONIOENCODING':'utf-8'})
    source_visible=G.nodes[node]['source_file'] in expanded.stdout
    coverage.append({'expanded_output':expanded.stdout,'expanded_exit_code':expanded.returncode,'expected_source_in_expanded_cli':source_visible,'query':q,'vocabulary_expansion':G.nodes[node]['label'],'exit_code':result.returncode,'expected_source':G.nodes[node]['source_file'],'source_node_present':node in G,'output':result.stdout,'stderr':result.stderr,'budget':1500})
(root/'docs/database/physical/wave3_graphify_queries.json').write_text(json.dumps(coverage,indent=2,ensure_ascii=False),encoding='utf-8')
summary={'before_nodes':len(old['nodes']),'after_nodes':G.number_of_nodes(),'before_edges':len(old.get('links',old.get('edges',[]))),'after_edges':G.number_of_edges(),'force_used':False,'shrink_guard':'PASS','shrink_rejection_probe':rejected,'scratch_bytes_unchanged':unchanged,'health':health,'queries':len(coverage),'queries_pass':all(c['exit_code']==0 and c['expanded_exit_code']==0 and c['source_node_present'] and c['expected_source_in_expanded_cli']for c in coverage),'tokens':tokens,'token_count_method':'estimated chars/4 for inline grounded semantic extraction; AST deterministic','semantic_extraction':'inline from Wave 3 report and approved migration DDL; historical graph retained'}
(root/'docs/database/physical/wave3_graphify_validation.json').write_text(json.dumps(summary,indent=2,ensure_ascii=False),encoding='utf-8');print(json.dumps(summary,ensure_ascii=False))
