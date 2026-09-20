#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const manifestPath = process.env.WAVE5_HTTP_CONTRACT_PATH
  ? path.resolve(process.env.WAVE5_HTTP_CONTRACT_PATH)
  : path.join(root, 'docs/api/wave5_academy_http_contracts.json');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const routes = fs.readFileSync(path.join(root, 'apps/api/routes/api.php'), 'utf8');
const operationMatrix = fs.readFileSync(path.join(root, 'apps/api/app/Domain/Academy/AcademyOperation.php'), 'utf8');
const handler = fs.readFileSync(path.join(root, 'apps/api/app/Exceptions/Handler.php'), 'utf8');
const failures = [];
let checks = 0;
const check = (condition, message) => { checks++; if (!condition) failures.push(message); };

check(manifest.base_uri === '/api/v1/academy', 'base URI must preserve project v1 convention');
check(manifest.authentication === 'auth_sessions opaque bearer token', 'existing auth_sessions authentication must be used');
check(manifest.pagination?.max === 100, 'pagination maximum must be 100');
check(manifest.concealment?.sensitive === true, 'sensitive endpoint concealment must be enabled');
for (const reason of ['TARGET_NOT_FOUND','NOT_AUTHORIZED','OUT_OF_SCOPE','CLASS_ASSIGNMENT_REQUIRED']) {
  check(manifest.concealment?.internal_reasons?.includes(reason), `concealment missing ${reason}`);
  check(handler.includes(`'${reason}'`), `central handler missing ${reason}`);
}
check(manifest.concealment?.external_status === 404 && manifest.concealment?.external_code === 'RESOURCE_NOT_FOUND', 'sensitive concealment must be indistinguishable 404');

for (const endpoint of manifest.endpoints) {
  const [controller, action] = endpoint.controller.split('@');
  const [service, method] = endpoint.service_operation.split('::');
  const verb = endpoint.method.toLowerCase() === 'get' ? 'get' : endpoint.method.toLowerCase();
  check(routes.includes(`Route::${verb}('${endpoint.uri}'`), `route missing ${endpoint.method} ${endpoint.uri}`);
  const controllerPath = path.join(root, `apps/api/app/Http/Controllers/Api/V1/Academy/${controller}.php`);
  check(fs.existsSync(controllerPath), `controller missing ${controller}`);
  if (!fs.existsSync(controllerPath)) continue;
  const source = fs.readFileSync(controllerPath, 'utf8');
  check(new RegExp(`function\\s+${action}\\s*\\(`).test(source), `controller method missing ${endpoint.controller}`);
  check(source.includes(`${service}::class`) && source.includes(`->${method}(`), `approved service mapping missing ${endpoint.service_operation}`);
  check(!source.includes('DB::') && !source.includes("->table("), `${endpoint.controller} bypasses service with direct persistence access`);
  check(source.includes(endpoint.request), `request class missing from ${endpoint.controller}`);
  check(source.includes(endpoint.resource), `resource class missing from ${endpoint.controller}`);
  check(operationMatrix.includes(`self::${endpoint.permission.replace('ACADEMY_', '')}`), `permission not represented in operation matrix: ${endpoint.permission}`);
  check(endpoint.sensitive_fields instanceof Array, `sensitive_fields missing for ${endpoint.method} ${endpoint.uri}`);
}

const gradeHistory = manifest.endpoints.find(e => e.service_operation === 'GradeService::history');
check(gradeHistory?.permission === 'ACADEMY_GRADES_VIEW', 'grade history must map to ACADEMY_GRADES_VIEW');
const certificateIssue = manifest.endpoints.find(e => e.uri === 'enrollments/{enrollment}/certificates');
check(certificateIssue?.service_operation === 'CertificateService::issue', 'certificate issue must delegate to CertificateService::issue');
const bulkRequest = fs.readFileSync(process.env.WAVE5_HTTP_BULK_REQUEST_PATH || path.join(root, 'apps/api/app/Http/Requests/Academy/AttendanceBulkRequest.php'), 'utf8');
const attendanceService = fs.readFileSync(process.env.WAVE5_HTTP_ATTENDANCE_SERVICE_PATH || path.join(root, 'apps/api/app/Domain/Academy/AcademicAttendanceService.php'), 'utf8');
check(bulkRequest.includes("'max:200'") && attendanceService.includes('MAX_BULK = 200'), 'bulk attendance must be capped at 200 in request and service');
check(Object.keys(manifest.domain_error_mapping || {}).length >= 12, 'domain error mapping is incomplete');

if (failures.length) {
  console.error(`HTTP_CONTRACTS_FAIL ${failures.length}/${checks}`);
  failures.forEach(f => console.error(`- ${f}`));
  process.exit(1);
}
console.log(`HTTP_CONTRACTS_PASS ${checks} checks ${manifest.endpoints.length} endpoints`);
