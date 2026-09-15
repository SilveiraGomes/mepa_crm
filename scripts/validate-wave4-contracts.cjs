'use strict';
const fs=require('node:fs');
const cp=require('node:child_process');
const crypto=require('node:crypto');
const protectedPaths=['apps/api/database/migrations','docs/database/model_catalog.json','docs/database/physical/migration_waves.json','docs/database/09_open_database_decisions.md','docs/reviews/P0.2_open_decisions_matrix.md','mepa_crm_v1.1.1.md'];
const files=cp.execFileSync('git',['ls-files',...protectedPaths],{encoding:'utf8'}).trim().split(/\r?\n/);
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const normalize=bytes=>bytes.toString('utf8').replace(/\r\n/g,'\n');
// FIN-PAYROLL-01 is an explicitly authorized tracking-only extension. Accept only
// these exact blocks at their exact locations; any other canonical change is drift.
const tracking={
 'mepa_crm_v1.1.1.md':{start:'\n**Tracking FIN-PAYROLL-01',end:'\n## P5',hash:'44cba1cd2d62d04209c4a4b871d98ded3cd9ed00e6abb62841e0b28862da3900'},
 'docs/database/09_open_database_decisions.md':{start:'\n## FIN-PAYROLL-01',end:null,hash:'bcae642e41c0d6f01383ae9d5617907ccb1c61975fb2066500fd61a854e44bed'}
};
const results=files.map(file=>{
 const before=cp.execFileSync('git',['show','HEAD:'+file]);
 const current=fs.readFileSync(file);
 const base=normalize(before),working=normalize(current);
 let compared=working,tracking_extension=null;
 const rule=tracking[file];
 if(rule&&!base.includes('FIN-PAYROLL-01')){
  const start=working.indexOf(rule.start);
  const end=rule.end?working.indexOf(rule.end,start+rule.start.length):working.length;
  if(start>=0&&end>start&&working.indexOf(rule.start,start+rule.start.length)<0){
   const block=working.slice(start,end);
   if(sha(block)===rule.hash){
    compared=working.slice(0,start)+working.slice(end);
    tracking_extension='FIN-PAYROLL-01_EXACT';
   }
  }
 }
 return {file,unchanged:base===compared,tracking_extension,git_lf_sha256:sha(base),working_lf_sha256:sha(working),approved_comparison_lf_sha256:sha(compared),raw_checkout_matches_git_blob:sha(before)===sha(current)};
});
const report={status:results.every(x=>x.unchanged)?'APPROVED_CONTRACTS_UNCHANGED':'DRIFT',comparison:'Git LF-normalized text; only exact FIN-PAYROLL-01 tracking blocks are excluded from approved business-rule comparison',files:results.length,drift:results.filter(x=>!x.unchanged).length,tracking_extensions:results.filter(x=>x.tracking_extension).map(x=>x.file),results};
fs.writeFileSync('docs/database/physical/wave4_approved_contracts_validation.json',JSON.stringify(report,null,2)+'\n');
console.log({status:report.status,files:report.files,drift:report.drift});
process.exit(report.drift?1:0);
