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
  if (endpoint.method === 'GET' && endpoint.service_operation.includes('QueryService::')) {
    const servicePath = path.join(root, `apps/api/app/Domain/Academy/${service}.php`);
    check(fs.existsSync(servicePath), `query service missing ${service}`);
    const serviceSource = fs.existsSync(servicePath) ? fs.readFileSync(servicePath, 'utf8') : '';
    check(new RegExp(`function\\s+${method}\\s*\\(`).test(serviceSource), `query method missing ${endpoint.service_operation}`);
    check(typeof endpoint.scope === 'string' && endpoint.scope.length > 8, `scope provenance missing ${endpoint.method} ${endpoint.uri}`);
    check(typeof endpoint.concealment === 'string', `concealment missing ${endpoint.method} ${endpoint.uri}`);
    check(endpoint.filters instanceof Array && endpoint.sort instanceof Array, `filter/sort allowlist missing ${endpoint.method} ${endpoint.uri}`);
    if (endpoint.pagination === true) check(endpoint.request === 'AcademyQueryRequest' && endpoint.resource === 'AcademyReadCollectionResource', `paginated read contract incomplete ${endpoint.uri}`);
  }
}

const readEndpoints = manifest.endpoints.filter(e => e.method === 'GET' && e.service_operation.includes('QueryService::'));
check(readEndpoints.length === 32, `expected 32 A3.2 read endpoints, found ${readEndpoints.length}`);
const queryRequest = fs.readFileSync(process.env.WAVE5_HTTP_QUERY_REQUEST_PATH || path.join(root, 'apps/api/app/Http/Requests/Academy/AcademyQueryRequest.php'), 'utf8');
check(/'per_page'\s*=>\s*\[[^\]]*'max:100'/.test(queryRequest) && queryRequest.includes('Rule::in'), 'read pagination maximum 100 and sort allowlists must be explicit');
const readResourcePath = process.env.WAVE5_HTTP_READ_RESOURCE_PATH || path.join(root, 'apps/api/app/Http/Resources/Academy/AcademyReadResource.php');
const readResource = fs.readFileSync(readResourcePath, 'utf8');
check(readResource.includes('private const FIELDS') && !readResource.includes('Person::') && !readResource.includes('->toArray('), 'roster must use an explicit projection, never a raw Person model');
const classDetail = readEndpoints.find(e => e.service_operation === 'ClassQueryService::detail');
check(classDetail?.scope?.includes('persisted class'), 'class detail must declare persisted scope');
const personSearch = readEndpoints.find(e => e.service_operation === 'ClassQueryService::searchPeopleForEnrollment');
check(personSearch?.pagination === true && personSearch?.scope?.includes('existing academic relation'), 'Person search must be scoped and paginated');
const enrollmentDetail = readEndpoints.find(e => e.service_operation === 'ClassQueryService::enrollment');
check(enrollmentDetail?.concealment?.includes('404'), 'enrollment detail must be concealed');
const classQueryPath = process.env.WAVE5_HTTP_CLASS_QUERY_PATH || path.join(root, 'apps/api/app/Domain/Academy/ClassQueryService.php');
const classQuery = fs.readFileSync(classQueryPath, 'utf8');
check((classQuery.match(/\$target->classId !== \$classId/g) || []).length >= 2 && classQuery.includes('AcademyReason::TARGET_NOT_FOUND'), 'nested enrollment/session parent-child equality must be enforced and concealed');

// A3.2: detail endpoints expose truthful counts only; every MANY relation is a
// separately paginated collection with persisted parent equality.
const catalogQuery = fs.readFileSync(process.env.WAVE5_HTTP_CATALOG_QUERY_PATH || path.join(root, 'apps/api/app/Domain/Academy/AcademicCatalogQueryService.php'), 'utf8');
const methodBody = (source, name) => {
  const start = source.search(new RegExp(`public function ${name}\\(`));
  if (start < 0) return '';
  const next = source.indexOf('\n    public function ', start + 10);
  const priv = source.indexOf('\n    private function ', start + 10);
  const ends = [next, priv].filter(x => x >= 0);
  return source.slice(start, ends.length ? Math.min(...ends) : source.length);
};
const curriculumDetail = readEndpoints.find(e => e.service_operation === 'AcademicCatalogQueryService::curriculum');
const versionDetail = readEndpoints.find(e => e.service_operation === 'AcademicCatalogQueryService::courseVersion');
check(curriculumDetail?.nested_projection === 'counts_only' && methodBody(catalogQuery, 'curriculum').includes("['course_count']") && !methodBody(catalogQuery, 'curriculum').includes("['courses']"), 'curriculum detail must expose course_count and no courses collection');
check(versionDetail?.nested_projection === 'counts_only' && methodBody(catalogQuery, 'courseVersion').includes("['module_count']") && !methodBody(catalogQuery, 'courseVersion').includes("['modules']"), 'courseVersion detail must expose counts and no modules tree');
for (const operation of ['curriculumCourses', 'courseVersionModules', 'moduleLessons', 'lessonResources']) {
  const endpoint = readEndpoints.find(e => e.service_operation === `AcademicCatalogQueryService::${operation}`);
  check(endpoint?.pagination === true && endpoint.request === 'AcademyQueryRequest' && endpoint.resource === 'AcademyReadCollectionResource', `${operation} must remain a paginated child collection`);
}
check(methodBody(catalogQuery, 'moduleLessons').includes("where('course_version_id', $courseVersionId)") && methodBody(catalogQuery, 'moduleLessons').includes('AcademyReason::TARGET_NOT_FOUND'), 'moduleLessons must verify module belongs to course version');
check(methodBody(catalogQuery, 'lessonResources').includes("where('l.module_id', $moduleId)") && methodBody(catalogQuery, 'lessonResources').includes("where('m.course_version_id', $courseVersionId)"), 'lessonResources must verify the complete persisted parent chain');

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
