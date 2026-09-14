from pathlib import Path
import json,re,subprocess,os,hashlib
root=Path(__file__).resolve().parents[2];out=root/'docs/database/physical/wave4_m11_audit';py=(root/'graphify-out/.graphify_python').read_text().strip();env={**os.environ,'PYTHONIOENCODING':'utf-8'}
r=subprocess.run([py,'-m','graphify','reflect','--if-stale'],cwd=root,env=env,capture_output=True,text=True,encoding='utf8');(out/'graph_reflect.txt').write_text(r.stdout+r.stderr,encoding='utf8')
p=root/'graphify-out/reflections/LESSONS.md'
if p.exists():(out/'graph_lessons.md').write_bytes(p.read_bytes())
g=json.loads((root/'graphify-out/graph.json').read_text(encoding='utf8'));v=set()
for n in g['nodes']:
 for c in re.findall(r'[^\W\d_]+',n.get('label',''),re.UNICODE):
  for x in re.findall(r'[A-Z]+(?=[A-Z][a-z])|[A-Z]?[a-z]+|[A-Z]+',c) or [c]:
   if 3<=len(x)<=30:v.add(x.lower())
queries={'EvangelismService::run':['evangelism','service','run'],'DomainClock':['domain','clock'],'ChildParticipationSafetyGate':['child','participation','safety','gate'],'DiscipleshipEnrollmentScope':['discipleship','enrollment','scope']}
for x in ['campaign','contact','followup','decision','track','step','enroll','integrate','progress']:queries[x]=['evangelism',x]
records=[]
for name,words in queries.items():
 words=[w for w in words if w in v];r=subprocess.run([py,'-m','graphify','query',' '.join(words),'--budget','2500'],cwd=root,env=env,capture_output=True,text=True,encoding='utf8',errors='replace');(out/('graph_'+name.replace('::','_')+'.txt')).write_text(r.stdout+r.stderr,encoding='utf8');records.append({'concept':name,'expanded':words,'exit':r.returncode,'sources_visible':'WaveFour' in r.stdout})
ids={n['id'] for n in g['nodes']};links=g.get('links',g.get('edges',[]));dangling=[e for e in links if e['source'] not in ids or e['target'] not in ids]
(out/'graph_queries.json').write_text(json.dumps({'nodes':len(ids),'edges':len(links),'dangling':len(dangling),'graph_sha256':hashlib.sha256((root/'graphify-out/graph.json').read_bytes()).hexdigest(),'mutated':False,'queries':records},indent=2));print(json.dumps(records),flush=True)
for cmd,file in [(['node','scripts/validate-database-docs.cjs'],'documents'),(['node','scripts/validate-wave4-schema.cjs','--static','--output',str(out/'static_parity.json')],'static'),(['node','scripts/validate-wave4-contracts.cjs'],'contracts')]:
 r=subprocess.run(cmd,cwd=root,capture_output=True,text=True);(out/(file+'.log')).write_text(r.stdout+r.stderr,encoding='utf8');print(file,r.returncode,flush=True)
