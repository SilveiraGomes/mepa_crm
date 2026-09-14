from pathlib import Path
import json,hashlib,subprocess
root=Path.cwd();out=root/'docs/database/physical/wave4_m11_audit';report=root/'docs/reviews/P0.3.4_M1_1_temporal_remediation_audit.md';s=report.read_text(encoding='utf8')
s=s.replace('| Suite realmente executada | Testes | Assertions | Fail | Error | Skip |\n','| Suite realmente executada | Testes | Assertions | Fail | Error | Skip |\n|---|---:|---:|---:|---:|---:|\n')
s=s.replace('não houve sinal cruzado encontrado.', '**Isolamento de barreiras: PASS.** A captura identifica os directórios `mepa_wave3_barrier_b00b42d7f901e0e4` e `mepa_wave3_barrier_fdd8d9ca08a7cb5a`, com ambos os schemas observados simultaneamente; colisão de namespace: NÃO. `parallel_namespace_evidence.json`. O resultado 1/2 da suite é falha de contenção, distinta do isolamento de ficheiros.')
s=s.replace('A origem exacta de aquisição do lock supremum requer trace interno adicional;', '`blocker_traces.json` liga o lock X/supremum ao worker bloqueado no SELECT do claim; a conexão holder detém X/REC_NOT_GAP da row 1. A cadeia é inteiramente local a cada schema, sem blocker cruzado. A origem exacta de aquisição interna do lock supremum requer trace interno adicional;')
s=s.replace('nenhum órfão. W4R-03:', 'nenhum órfão; SQL próprio em `child_concurrency_independent_sql.json` confirma orphan_visits=0 e protected_attendance_without_custody=0. W4R-03:')
report.write_text(s,encoding='utf8')
gatepath=root/'docs/reviews/P0.3.4_wave4_gate.md';gb=gatepath.read_bytes();mark=gb.find(b'## Adenda P0.3.4-M1.1-R');suffix=gb[mark:].decode('utf8').replace('dual-run = 1/2 PASS.', 'dual-run = 1/2 PASS; isolamento das barreiras PASS, dois directórios distintos e nenhuma colisão de namespace.');gatepath.write_bytes(gb[:mark]+suffix.encode('utf8'))
baseline={k.replace('\\','/'):v for k,v in json.loads((out/'source_baseline.json').read_text()).items()};changed=[]
for name,h in baseline.items():
 if name=='docs/reviews/P0.3.4_wave4_gate.md':continue
 p=root/name
 if not p.exists() or hashlib.sha256(p.read_bytes()).hexdigest()!=h:changed.append(name)
gb=gatepath.read_bytes();mark=gb.find(b'## Adenda P0.3.4-M1.1-R');prefix=any(hashlib.sha256(gb[:i]).hexdigest()==baseline['docs/reviews/P0.3.4_wave4_gate.md'] for i in range(max(0,mark-40),mark+1));g=json.loads((out/'graph_queries.json').read_text());gsame=hashlib.sha256((root/'graphify-out/graph.json').read_bytes()).hexdigest()==g['graph_sha256'];summary=json.loads((out/'audit_summary.json').read_text());summary['barrier_isolation']=json.loads((out/'parallel_namespace_evidence.json').read_text());(out/'audit_summary.json').write_text(json.dumps(summary,indent=2),encoding='utf8')
check={'unexpected_source_changes':changed,'source_status':'PASS' if not changed else 'FAIL','files':len(baseline),'gate_original_prefix_preserved':prefix,'graph_hash_preserved':gsame,'report_last_line':report.read_text(encoding='utf8').strip().splitlines()[-1],'regression_table_delimiter_present':'|---|---:|---:|---:|---:|---:|' in s};(out/'final_preservation.json').write_text(json.dumps(check,indent=2),encoding='utf8');print(json.dumps(check),flush=True)
assert not changed and prefix and gsame
r=subprocess.run(['docker','stop','mepa-wave4-m11-independent-mysql'],capture_output=True,text=True);(out/'container_stop.txt').write_text(r.stdout+r.stderr,encoding='utf8');print('Container stop',r.returncode,r.stdout,flush=True)
