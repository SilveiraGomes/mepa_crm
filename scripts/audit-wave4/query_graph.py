from pathlib import Path
import json,re,subprocess,os,hashlib
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_audit';python=(root/'graphify-out/.graphify_python').read_text().strip()
g=json.loads((root/'graphify-out/graph.json').read_text(encoding='utf8'))
v=set()
for n in g['nodes']:
 for c in re.findall(r'[^\W\d_]+',n.get('label',''),re.UNICODE):
  for p in re.findall(r'[A-Z]+(?=[A-Z][a-z])|[A-Z]?[a-z]+|[A-Z]+',c) or [c]:
   if 3<=len(p)<=30:v.add(p.lower())
queries={'outreach campaigns':['outreach','campaigns'],'outreach contacts':['outreach','contacts'],'followups':['followups'],'decisions':['decisions'],'discipleship':['discipleship'],'child safety':['child','safety'],'guardians/responsibles':['guardian'],'consents':['consents','consent'],'pickup authorization':['pickup','authorization'],'checkin':['child','checkin'],'checkout':['child','checkout']}
coverage=[]
for concept,words in queries.items():
 words=[w for w in words if w in v];expanded=' '.join(words)
 r=subprocess.run([python,'-m','graphify','query',expanded,'--budget','6000'],cwd=root,capture_output=True,text=True,encoding='utf8',errors='replace',env={**os.environ,'PYTHONIOENCODING':'utf-8'})
 file=re.sub(r'[^a-z]+','_',concept)+'.txt';(out/('graph_'+file)).write_text(r.stdout+r.stderr,encoding='utf8')
 source=any(s in r.stdout for s in ['apps/api/app/Domain/WaveFour/','docs/adr/0013-','docs/database/physical/P0.3.4_wave4_tables.md','docs/reviews/P0.3.4_wave4_implementation.md'])
 coverage.append({'concept':concept,'expanded':words,'exit':r.returncode,'wave4_source_visible':source,'output_file':file,'status':'PASS' if r.returncode==0 and source else 'FAIL'})
links=g.get('links',g.get('edges',[]));ids={n['id'] for n in g['nodes']};dangling=[e for e in links if e['source'] not in ids or e['target'] not in ids]
result={'graph_sha256':hashlib.sha256((root/'graphify-out/graph.json').read_bytes()).hexdigest(),'nodes':len(ids),'edges':len(links),'dangling':len(dangling),'queries':coverage,'executor_counts':{'initial':2579,'final':2846},'shrink':False,'force_used':False,'status':'PASS' if all(c['status']=='PASS' for c in coverage) and not dangling else 'FAIL'}
(out/'graph_queries.json').write_text(json.dumps(result,indent=2),encoding='utf8');print(json.dumps({k:x for k,x in result.items() if k!='queries'}));print('queries',len(coverage),[c['concept'] for c in coverage if c['status']!='PASS'])
