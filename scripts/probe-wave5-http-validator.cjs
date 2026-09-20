#!/usr/bin/env node
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const cp = require('child_process');
const root = path.resolve(__dirname, '..');
const original = JSON.parse(fs.readFileSync(path.join(root, 'docs/api/wave5_academy_http_contracts.json'), 'utf8'));
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'mepa-a3-validator-'));
const validator = path.join(root, 'scripts/validate-wave5-http-contracts.cjs');
const results = [];

function expectFailure(name, mutate, extraEnv = {}) {
  const contract = structuredClone(original);
  mutate(contract);
  const file = path.join(temp, `${name}.json`);
  fs.writeFileSync(file, JSON.stringify(contract));
  const result = cp.spawnSync(process.execPath, [validator], {cwd: root, env: {...process.env, WAVE5_HTTP_CONTRACT_PATH: file, ...extraEnv}, encoding: 'utf8'});
  if (result.status === 0) throw new Error(`${name}: validator produced a false PASS`);
  results.push(name);
}

expectFailure('H1_DIRECT_MODEL', c => { c.endpoints[0].service_operation = 'Program::query'; });
expectFailure('H2_NO_CONCEALMENT', c => { c.concealment.sensitive = false; });
expectFailure('H3_GRADES_PERMISSION', c => { c.endpoints.find(e => e.service_operation === 'GradeService::history').permission = 'ACADEMY_VIEW'; });
expectFailure('H4_CERTIFICATE_SERVICE', c => { c.endpoints.find(e => e.uri === 'enrollments/{enrollment}/certificates').service_operation = 'EnrollmentService::transition'; });

const bulkPath = path.join(temp, 'AttendanceBulkRequest.php');
fs.writeFileSync(bulkPath, fs.readFileSync(path.join(root, 'apps/api/app/Http/Requests/Academy/AttendanceBulkRequest.php'), 'utf8').replace("'max:200'", "'max:2000'"));
expectFailure('H5_BULK_LIMIT', () => {}, {WAVE5_HTTP_BULK_REQUEST_PATH: bulkPath});

console.log(`HTTP_VALIDATOR_NEGATIVE_PROBES_PASS ${results.length}/5 ${results.join(',')}`);
