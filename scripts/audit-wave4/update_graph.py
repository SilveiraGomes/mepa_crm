from pathlib import Path
import json,hashlib
from graphify.build import build_merge
from graphify.cluster import cluster,score_all
from graphify.export import to_json
from graphify.detect import detect,save_manifest
from graphify.report import generate
from graphify.analyze import god_nodes,surprising_connections,suggest_questions
from graphify.diagnostics import diagnose_extraction
root=Path.cwd();out=root/'graphify-out';proof=root/'docs/database/physical/wave4_audit'
old=json.loads((out/'graph.json').read_text(encoding='utf8'));before=hashlib.sha256((out/'graph.json').read_bytes()).hexdigest()
paths=list((root/'scripts/audit-wave4').glob('*.py'))+list((root/'scripts/audit-wave4').glob('*.php'))
fresh=json.loads((out/'.graphify_wave4_audit_ast.json').read_text(encoding='utf8'))
semantic=json.loads((out/'.graphify_chunk_wave4_audit_docs.json').read_text(encoding='utf8'))
fresh['nodes']+=semantic['nodes'];fresh['edges']+=semantic['edges'];fresh['hyperedges']=semantic.get('hyperedges',[])
fresh['input_tokens']=semantic.get('input_tokens',0);fresh['output_tokens']=semantic.get('output_tokens',0)
G=build_merge([fresh],graph_path=out/'graph.json',root=root,directed=bool(old.get('directed',False)),ast_sources=paths)
communities=cluster(G);labels={}
for cid,members in communities.items():
 names=[G.nodes[n].get('community_name') for n in members if G.nodes[n].get('community_name')]
 labels[cid]=max(set(names),key=names.count) if names else 'Auditoria independente Wave 4'
assert to_json(G,communities,str(out/'graph.json'),community_labels=labels),'shrink guard refused; force never used'
full={'nodes':[{'id':n,**d} for n,d in G.nodes(data=True)],'edges':[{**d,'source':u,'target':v} for u,v,d in G.edges(data=True)]}
health=diagnose_extraction(full,directed=bool(old.get('directed',False)),root=str(root))
report=generate(G,communities,score_all(G,communities),labels,god_nodes(G),surprising_connections(G,communities),detect(root/'scripts/audit-wave4'),{'input':fresh['input_tokens'],'output':fresh['output_tokens']},str(root),suggested_questions=suggest_questions(G,communities,labels))
(out/'GRAPH_REPORT.md').write_text(report,encoding='utf8')
reports=[root/'docs/reviews/P0.3.4_wave4_audit.md',root/'docs/reviews/P0.3.4_wave4_gate.md']
save_manifest({'code':[str(p) for p in paths],'document':[str(p) for p in reports]},root=root)
current=json.loads((out/'graph.json').read_text(encoding='utf8'))
result={'status':'PASS','before_nodes':len(old['nodes']),'after_nodes':len(current['nodes']),'before_edges':len(old.get('links',old.get('edges',[]))),'after_edges':G.number_of_edges(),'before_sha256':before,'after_sha256':hashlib.sha256((out/'graph.json').read_bytes()).hexdigest(),'shrink':len(current['nodes'])<len(old['nodes']),'force':False,'extraction_health':health,'new_report_sources':[str(p.relative_to(root)) for p in reports],'token_estimates':{'input':fresh['input_tokens'],'output':fresh['output_tokens']},'token_estimates_are_not_billing':True}
(proof/'graph_update.json').write_text(json.dumps(result,indent=2,ensure_ascii=False),encoding='utf8');print(json.dumps(result,ensure_ascii=False))
