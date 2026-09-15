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
manifest=json.loads((root/'docs/database/physical/wave4_manifest.json').read_text())
paths=[root/'apps/api/database/migrations'/f for f in manifest['migrations']]
paths+=list((root/'apps/api/app/Domain/WaveFour').glob('*.php'))+list((root/'apps/api/tests/Database').glob('WaveFour*Test.php'))
paths+=[root/'scripts/lib/wave4-catalog.cjs',root/'scripts/generate-wave4-migrations.cjs',root/'scripts/validate-wave4-schema.cjs',root/'scripts/format-wave4.php',root/'apps/api/tests/Database/Support/WaveFourCase.php',root/'scripts/inspect-wave4-schema.php',root/'scripts/wave4-checkout-worker.php',root/'scripts/validate-wave4-contracts.cjs',root/'scripts/summarize-wave4.py',root/'scripts/finalize-wave4.cjs']
fresh=extract(paths,cache_root=root,root=root,parallel=False)
report_file='docs/reviews/P0.3.4_wave4_implementation.md'
report=root/report_file
if not report.exists():raise SystemExit('Wave 4 report required before graph update')
nodes=[{'id':'wave4_implementation','label':'P0.3.4 Wave 4 outreach evangelism discipleship children guardians consents custody check-in check-out implementation','source_file':report_file,'source_location':'L1','type':'document'},
{'id':'wave4_child_checkin','label':'child check-in reuses Event Session CheckinService event_attendance same Person custody child_profiles lock','source_file':report_file,'source_location':'Segurança infantil','type':'concept'},
{'id':'wave4_child_checkout','label':'child check-out pickup authorization guardian revocation transactional single checkout real 2 10 30 50 workers','source_file':report_file,'source_location':'Checkout concurrency','type':'concept'},
{'id':'wave4_identity','label':'One Person outreach contact decision discipleship integration membership non-member child guardian identity reuse','source_file':report_file,'source_location':'Pipeline','type':'concept'},
{'id':'wave4_rollback','label':'Wave 4 durable data rollback blocked before first DROP empty remigrate previous waves preserved','source_file':'docs/adr/0013-wave4-custody-and-rollback.md','source_location':'Rollback','type':'concept'},
{'id':'wave4_tracking','label':'Wave 4 APPLICATION_ENFORCED rules tracked owner implementation_wave test_wave status reason','source_file':'docs/database/physical/wave4_application_rules_tracking.json','source_location':'rules','type':'document'}]

edges=[]
for t,file in zip(manifest['tables'],manifest['migrations']):
    source='apps/api/database/migrations/'+file
    nodes.append({'id':'wave4_table_'+t,'label':t+' Wave4 approved physical table','canonical_name':t,'source_file':source,'source_location':'up() SQL','type':'table'})
    edges.append({'source':'wave4_implementation','target':'wave4_table_'+t,'relation':'implements','confidence':'EXTRACTED','source_file':report_file,'source_location':'Tabelas f?sicas'})
models=json.loads((root/'docs/database/model_catalog.json').read_text())['tables']
for t in models:
    if t['name'] not in manifest['tables']:continue
    source='apps/api/database/migrations/'+manifest['migrations'][manifest['tables'].index(t['name'])]
    for c in t['columns']:
        target=c.get('fk')
        if target in manifest['tables']:
            edges.append({'source':'wave4_table_'+t['name'],'target':'wave4_table_'+target,'relation':'references '+c['name'],'confidence':'EXTRACTED','source_file':source,'source_location':'FOREIGN KEY '+c['name']})
for target in ('people','events','event_sessions','event_checkins','event_attendance'):
    matches=[n for n in old['nodes'] if n.get('canonical_name')==target or n.get('label')==target]
    if matches:
        nid=matches[0]['id']
        edges.append({'source':'wave4_identity' if target=='people' else 'wave4_child_checkin','target':nid,'relation':'reuses','confidence':'EXTRACTED','source_file':report_file,'source_location':'Segurança infantil'})
for node in nodes[1:6]:edges.append({'source':'wave4_implementation','target':node['id'],'relation':'documents','confidence':'EXTRACTED','source_file':report_file,'source_location':'Provas'})
for n in fresh['nodes']:
    if any(x in n.get('label','')for x in ['ChildrenService','EvangelismService','DomainAccess','DomainPolicy','WaveFour']):
        edges.append({'source':'wave4_implementation','target':n['id'],'relation':'verified_by' if 'Test' in n.get('label','') else 'implemented_by','confidence':'EXTRACTED','source_file':report_file,'source_location':'Test suites'})
fresh['nodes']+=nodes;fresh['edges']+=edges
fresh['input_tokens']=len(report.read_text(encoding='utf-8'))//4;fresh['output_tokens']=len(json.dumps({'nodes':nodes,'edges':edges}))//4
semantic=json.loads((out/'.graphify_chunk_wave4_docs.json').read_text(encoding='utf-8'))
fresh['nodes']+=semantic['nodes'];fresh['edges']+=semantic['edges'];fresh['hyperedges']=semantic.get('hyperedges',[])
fresh['input_tokens']+=semantic.get('input_tokens',0);fresh['output_tokens']+=semantic.get('output_tokens',0)
G=build_merge([fresh],graph_path=out/'graph.json',root=root,directed=bool(old.get('directed',False)),ast_sources=paths)
communities=cluster(G);labels={}
for cid,members in communities.items():
    names=[G.nodes[n].get('community_name')for n in members if G.nodes[n].get('community_name')]
    labels[cid]=max(set(names),key=names.count)if names else 'Events governance credentials check-in'
if not to_json(G,communities,str(out/'graph.json'),community_labels=labels):raise SystemExit('Shrink guard refused; force not used')
# Exercise rejection on a scratch file, preserve the real graph exactly.
probe=root/'docs/database/physical/.wave4_graph_guard_probe.json';probe.write_bytes((out/'graph.json').read_bytes());before=hashlib.sha256(probe.read_bytes()).hexdigest()
small=G.subgraph(list(G.nodes)[:1]).copy();rejected=not to_json(small,{0:list(small.nodes)},str(probe));unchanged=hashlib.sha256(probe.read_bytes()).hexdigest()==before;probe.unlink()
if not rejected or not unchanged:raise SystemExit('Shrink rejection probe failed')
full={'nodes':[{'id':n,**d}for n,d in G.nodes(data=True)],'edges':[{**d,'source':u,'target':v}for u,v,d in G.edges(data=True)]}
health=diagnose_extraction(full,directed=bool(old.get('directed',False)),root=str(root))
detection=detect(root);cohesion=score_all(G,communities);gods=god_nodes(G);surprises=surprising_connections(G,communities);questions=suggest_questions(G,communities,labels);tokens={'input':fresh['input_tokens'],'output':fresh['output_tokens']}
(out/'GRAPH_REPORT.md').write_text(generate(G,communities,cohesion,labels,gods,surprises,detection,tokens,str(root),suggested_questions=questions),encoding='utf-8')
(out/'.graphify_labels.json').write_text(json.dumps({str(k):v for k,v in labels.items()},ensure_ascii=False),encoding='utf-8')
save_manifest({'code':[str(p)for p in paths],'document':[str(report)]},root=root)
queries=['outreach_campaigns','outreach_contacts','followups','decisions','discipleship_tracks','discipleship_steps','discipleship_enrollments','discipleship_progress','integration_events','child_profiles','guardian_authorizations','person_consents','child_emergency_contacts','child_custody_visits','age_band_rules','department_transition_recommendations','child check-in','child check-out']
coverage=[]
if '--refresh-only' in sys.argv:
    coverage=json.loads((root/'docs/database/physical/wave4_graphify_queries.json').read_text(encoding='utf-8'))
for q in ([] if '--refresh-only' in sys.argv else queries):
    result=subprocess.run(['graphify','query',q,'--budget','1500'],capture_output=True,text=True,encoding='utf-8',errors='replace',cwd=root,env={**os.environ,'PYTHONIOENCODING':'utf-8'})
    node={'child check-in':'wave4_child_checkin','child check-out':'wave4_child_checkout'}.get(q,'wave4_table_'+q)
    expanded=subprocess.run(['graphify','query',G.nodes[node]['label'],'--budget','1500'],capture_output=True,text=True,encoding='utf-8',errors='replace',cwd=root,env={**os.environ,'PYTHONIOENCODING':'utf-8'})
    source_visible=G.nodes[node]['source_file'] in expanded.stdout
    coverage.append({'expanded_output':expanded.stdout,'expanded_exit_code':expanded.returncode,'expected_source_in_expanded_cli':source_visible,'query':q,'vocabulary_expansion':G.nodes[node]['label'],'exit_code':result.returncode,'expected_source':G.nodes[node]['source_file'],'source_node_present':node in G,'output':result.stdout,'stderr':result.stderr,'budget':1500})
(root/'docs/database/physical/wave4_graphify_queries.json').write_text(json.dumps(coverage,indent=2,ensure_ascii=False),encoding='utf-8')
summary={'before_nodes':len(old['nodes']),'after_nodes':G.number_of_nodes(),'before_edges':len(old.get('links',old.get('edges',[]))),'after_edges':G.number_of_edges(),'force_used':False,'shrink_guard':'PASS','shrink_rejection_probe':rejected,'scratch_bytes_unchanged':unchanged,'health':health,'queries':len(coverage),'queries_pass':all(c['exit_code']==0 and c['expanded_exit_code']==0 and c['source_node_present'] and c['expected_source_in_expanded_cli']for c in coverage),'tokens':tokens,'token_count_method':'reported agent tokens plus estimated chars/4 for grounded report and DDL; AST deterministic','semantic_extraction':'Agent extraction of ADR 0013 and physical tables; grounded report and approved DDL; historical graph retained'}
(root/'docs/database/physical/wave4_graphify_validation.json').write_text(json.dumps(summary,indent=2,ensure_ascii=False),encoding='utf-8');print(json.dumps(summary,ensure_ascii=False))

if '--finalize' in sys.argv:
    subprocess.run(['node',str(root/'scripts/finalize-wave4.cjs')],cwd=root,check=True)
    save_manifest({'code':[str(p) for p in paths],'document':[str(report),str(root/'docs/adr/0013-wave4-custody-and-rollback.md'),str(root/'docs/database/physical/P0.3.4_wave4_tables.md')]},root=root)
