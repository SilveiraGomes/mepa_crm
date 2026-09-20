#!/usr/bin/env node
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const cp = require('child_process');
const root = path.resolve(__dirname, '..');
const validator = path.join(root, 'scripts/validate-wave5-http-contracts.cjs');
const manifestSource = fs.readFileSync(path.join(root, 'docs/api/wave5_academy_http_contracts.json'), 'utf8');
const catalogSource = fs.readFileSync(path.join(root, 'apps/api/app/Domain/Academy/AcademicCatalogQueryService.php'), 'utf8');
const requestSource = fs.readFileSync(path.join(root, 'apps/api/app/Http/Requests/Academy/AcademyQueryRequest.php'), 'utf8');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'mepa-a32-bounded-'));
const passed = [];

function expectFailure(name, env) {
  const result = cp.spawnSync(process.execPath, [validator], {cwd: root, env: {...process.env, ...env}, encoding: 'utf8'});
  if (result.status === 0) throw new Error(`${name}: validator produced a false PASS`);
  passed.push(name);
}

function temporary(name, source) {
  const file = path.join(temp, name);
  fs.writeFileSync(file, source);
  return file;
}

expectFailure('B1_CURRICULUM_DETAIL_UNBOUNDED', {
  WAVE5_HTTP_CATALOG_QUERY_PATH: temporary('B1Catalog.php', catalogSource.replace("$row['course_count']", "$row['courses']")),
});
expectFailure('B2_COURSE_VERSION_TREE_UNBOUNDED', {
  WAVE5_HTTP_CATALOG_QUERY_PATH: temporary('B2Catalog.php', catalogSource.replace("$row['module_count']", "$row['modules']")),
});
const b3 = JSON.parse(manifestSource);
b3.endpoints.find(e => e.service_operation === 'AcademicCatalogQueryService::courseVersionModules').pagination = false;
expectFailure('B3_CHILD_PAGINATION_REMOVED', {WAVE5_HTTP_CONTRACT_PATH: temporary('B3Contract.json', JSON.stringify(b3))});
expectFailure('B4_MAX_PAGE_SIZE', {
  WAVE5_HTTP_QUERY_REQUEST_PATH: temporary('B4Request.php', requestSource.replace("'max:100'", "'max:1000'")),
});
expectFailure('B5_PARENT_CHILD_CHECK', {
  WAVE5_HTTP_CATALOG_QUERY_PATH: temporary('B5Catalog.php', catalogSource.replace("->where('id', $moduleId)->where('course_version_id', $courseVersionId)", "->where('id', $moduleId)")),
});

console.log(`BOUNDED_VALIDATOR_NEGATIVE_PROBES_PASS ${passed.length}/5 ${passed.join(',')}`);
