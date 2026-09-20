#!/usr/bin/env node
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const cp = require('child_process');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'docs/api/wave5_academy_http_contracts.json'), 'utf8');
const original = JSON.parse(source);
const validator = path.join(root, 'scripts/validate-wave5-http-contracts.cjs');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'mepa-a31-validator-'));
const passed = [];

function fail(name, mutate, env = {}) {
  const contract = structuredClone(original);
  mutate(contract);
  const contractPath = path.join(temp, `${name}.json`);
  fs.writeFileSync(contractPath, JSON.stringify(contract));
  const result = cp.spawnSync(process.execPath, [validator], {cwd: root, env: {...process.env, WAVE5_HTTP_CONTRACT_PATH: contractPath, ...env}, encoding: 'utf8'});
  if (result.status === 0) throw new Error(`${name}: validator produced a false PASS`);
  passed.push(name);
}

fail('Q1_CLASS_SCOPE', c => { c.endpoints.find(e => e.service_operation === 'ClassQueryService::detail').scope = ''; });

const resourcePath = path.join(temp, 'AcademyReadResource.php');
fs.writeFileSync(resourcePath, fs.readFileSync(path.join(root, 'apps/api/app/Http/Resources/Academy/AcademyReadResource.php'), 'utf8').replace('private const FIELDS', 'private const RAW_FIELDS'));
fail('Q2_RAW_PERSON_RESOURCE', () => {}, {WAVE5_HTTP_READ_RESOURCE_PATH: resourcePath});

fail('Q3_PERSON_SEARCH_PAGINATION', c => { c.endpoints.find(e => e.service_operation === 'ClassQueryService::searchPeopleForEnrollment').pagination = false; });
fail('Q4_ENROLLMENT_CONCEALMENT', c => { c.endpoints.find(e => e.service_operation === 'ClassQueryService::enrollment').concealment = 'none'; });

const classPath = path.join(temp, 'ClassQueryService.php');
fs.writeFileSync(classPath, fs.readFileSync(path.join(root, 'apps/api/app/Domain/Academy/ClassQueryService.php'), 'utf8').replaceAll('$target->classId !== $classId', '$target->classId === $classId'));
fail('Q5_NESTED_RELATION', () => {}, {WAVE5_HTTP_CLASS_QUERY_PATH: classPath});

console.log(`READ_VALIDATOR_NEGATIVE_PROBES_PASS ${passed.length}/5 ${passed.join(',')}`);
