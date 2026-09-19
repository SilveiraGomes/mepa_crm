'use strict';
// P0.3.5-A2 static validator for the Academy application layer.
//
// Content-based, not an existence check: it parses the PHP sources (comments stripped, line numbers
// preserved) and cross-checks them against docs/database/physical/wave5_application_contracts.json:
//   services + methods, permission matrix parity, audit / transaction / child-safety integration,
//   absence of a membership dependency, of hardcoded D-09 values, of a closed D-11 status catalog,
//   of a new scope kind, of swallowed exceptions, of schema / UI / API / payroll drift.
// Exit code 1 on any failed check. Writes wave5_application_contracts_validation.json.
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');

const root = path.resolve(__dirname, '..');
const rel = p => path.join(root, p);
const read = p => fs.readFileSync(rel(p), 'utf8');
const domainDir = 'apps/api/app/Domain/Academy';
const files = fs.readdirSync(rel(domainDir)).filter(f => f.endsWith('.php')).map(f => `${domainDir}/${f}`).sort();
const configFile = 'apps/api/config/academy.php';
const contractFile = 'docs/database/physical/wave5_application_contracts.json';

const checks = [];
const failures = [];
function check(id, ok, detail) {
  checks.push({ id, ok: !!ok, detail: ok ? undefined : detail });
  if (!ok) failures.push(`${id}: ${detail}`);
}

// Replaces comments by spaces (newlines kept) so findings keep their real line numbers. String
// literals are honoured, so '//' inside a string is not treated as a comment.
function stripComments(src) {
  let out = '';
  for (let i = 0; i < src.length;) {
    const c = src[i], n = src[i + 1];
    if (c === "'" || c === '"') {
      let j = i + 1;
      while (j < src.length && src[j] !== c) j += src[j] === '\\' ? 2 : 1;
      out += src.slice(i, j + 1);
      i = j + 1;
    } else if (c === '/' && n === '/' || c === '#') {
      while (i < src.length && src[i] !== '\n') { out += ' '; i++; }
    } else if (c === '/' && n === '*') {
      const end = src.indexOf('*/', i + 2);
      const stop = end < 0 ? src.length : end + 2;
      out += src.slice(i, stop).replace(/[^\n]/g, ' ');
      i = stop;
    } else { out += c; i++; }
  }
  return out;
}
const source = Object.fromEntries(files.map(f => [f, read(f)]));
const code = Object.fromEntries(files.map(f => [f, stripComments(source[f])]));
const codeAll = files.map(f => ({ f, lines: code[f].split('\n') }));
const findAll = (regex, opts = {}) => {
  const hits = [];
  for (const { f, lines } of codeAll) lines.forEach((line, i) => { if (regex.test(line)) hits.push({ file: f, line: i + 1, text: line.trim() }); });
  return hits;
};
const methodBody = (file, name) => {
  const m = new RegExp(`public function ${name}\\(`).exec(code[file]);
  if (!m) return null;
  const next = code[file].indexOf('\n    public function ', m.index + 10);
  const priv = code[file].indexOf('\n    private function ', m.index + 10);
  const ends = [next, priv].filter(x => x >= 0);
  return code[file].slice(m.index, ends.length ? Math.min(...ends) : code[file].length);
};

// ---- contract + matrix --------------------------------------------------------------------
const contract = JSON.parse(read(contractFile));
const opSrc = code[`${domainDir}/AcademyOperation.php`];
const matrix = {};
for (const m of opSrc.matchAll(/'([a-z.]+)' => \[self::([A-Z_]+), (true|false), (true|false), (true|false)\]/g)) {
  matrix[m[1]] = { permission: m[2].startsWith('ACADEMY_') ? m[2] : `ACADEMY_${m[2]}`, class_assignment: m[3] === 'true', admin_override: m[4] === 'true', audit: m[5] === 'true' };
}
const constants = {};
for (const m of opSrc.matchAll(/public const ([A-Z_]+) = '([A-Z_]+)';/g)) constants[m[1]] = m[2];
for (const k of Object.keys(matrix)) matrix[k].permission = constants[matrix[k].permission.replace(/^ACADEMY_/, '')] || matrix[k].permission;

// 1. expected services and foundation classes exist and are declared -------------------------
const expectedServices = ['EnrollmentService', 'InstructorAssignmentService', 'ClassSessionService', 'AcademicAttendanceService', 'AssessmentService', 'AssessmentAttemptService', 'GradeService', 'ProgressService', 'CompletionService', 'CertificateService', 'TranscriptService', 'CurriculumService'];
const foundation = ['AcademyAccess', 'AcademyRuntime', 'AcademyPolicy', 'AcademyStateMachine', 'AcademicPolicyResolver', 'AcademyScopeResolver', 'AcademyChildSafety', 'AcademyPersonResolver', 'DatabaseAcademyAudit', 'AcademyAuditWriter', 'AcademyError', 'AcademyReason', 'AcademyOperation', 'AcademicSessionLocationResolver'];
for (const s of [...expectedServices, ...foundation]) check(`file.${s}`, files.includes(`${domainDir}/${s}.php`), `${s}.php missing`);
check('contract.services', JSON.stringify(contract.services.map(s => s.name).sort()) === JSON.stringify([...expectedServices].sort()), 'contract services differ from the expected service set');
check('contract.schema_changes', contract.schema_changes === 0 && contract.structural_conflicts.length === 0, 'contract must declare 0 schema changes and 0 structural conflicts');

// 2. permission mapping ------------------------------------------------------------------------
const phpPermissions = (/public const PERMISSIONS = \[([^\]]*)\]/.exec(opSrc) || [])[1]?.split(',').map(x => constants[x.trim().replace('self::', '')]) || [];
check('permissions.set', JSON.stringify([...phpPermissions].sort()) === JSON.stringify(contract.permissions.map(p => p.code).sort()) && phpPermissions.length === 9, 'PHP PERMISSIONS differs from the contract permission list (expected the 9 approved permissions)');
for (const p of contract.permissions) check(`permissions.mapping.${p.code}`, p.enforced_row.code === p.code && p.enforced_row.action === p.code && p.enforced_row.data_type === 'ACADEMY' && p.mapping_note, `${p.code} must document the action = code / data_type ACADEMY mapping`);
const accessSrc = code[`${domainDir}/AcademyAccess.php`];
check('permissions.enforced_convention', /whereColumn\('p\.action', 'p\.code'\)/.test(accessSrc) && /AcademyOperation::DATA_TYPE/.test(accessSrc) && constants.DATA_TYPE === 'ACADEMY', 'AcademyAccess must match permissions by code, action = code and data_type ACADEMY');
check('permissions.no_admin_direct', !Object.values(matrix).some(m => m.permission === 'ACADEMY_ADMIN'), 'ACADEMY_ADMIN must never be the direct permission of an operation');

// 3. matrix parity + per-operation source checks ---------------------------------------------
check('matrix.parity', JSON.stringify(Object.keys(matrix).sort()) === JSON.stringify(Object.keys(contract.permission_matrix).sort()), 'PHP matrix keys differ from the contract permission_matrix');
for (const [k, v] of Object.entries(matrix)) {
  const c = contract.permission_matrix[k];
  check(`matrix.row.${k}`, c && c.permission === v.permission && c.class_assignment === v.class_assignment && c.admin_override === v.admin_override && c.audit === v.audit, `matrix row ${k} differs between PHP and the contract`);
}
const usedOps = new Set();
let opCount = 0;
const reasonConsts = new Set([...code[`${domainDir}/AcademyReason.php`].matchAll(/public const ([A-Z_]+) =/g)].map(m => m[1]));
for (const svc of contract.services) {
  const file = `${domainDir}/${svc.name}.php`;
  for (const o of svc.operations) {
    opCount++;
    const id = `${svc.name}.${o.method}`;
    usedOps.add(o.operation);
    const m = matrix[o.operation];
    check(`op.matrix.${id}`, m && o.required_permission === m.permission && o.class_assignment_required === m.class_assignment && o.admin_override === m.admin_override && o.audit_required === m.audit, `${id}: contract flags differ from the PHP matrix row ${o.operation}`);
    check(`op.scope_from_db.${id}`, o.institutional_scope_required === true && o.scope_derived_from_database === true && o.scope_source, `${id}: scope must be institutional and DB-derived`);
    const body = methodBody(file, o.method);
    check(`op.method.${id}`, body !== null, `${id}: public method ${o.method} not found in ${file}`);
    if (body === null) continue;
    const isWrite = o.transaction.startsWith('write');
    check(`op.boundary.${id}`, body.includes(isWrite ? "$this->rt->write(" : "$this->rt->read(") && body.includes(`'${o.operation}'`), `${id}: must go through rt->${isWrite ? 'write' : 'read'}('${o.operation}')`);
    check(`op.claimed_context.${id}`, /\$claimed|claimed/.test(body) || o.operation === 'structure.manage' && /claimed/.test(body), `${id}: must accept (never trust) a claimed client context`);
    // rt->audit() may sit in a private helper the public method calls (e.g. attendance apply()).
    const auditHelpers = [...code[file].matchAll(/private function (\w+)\([\s\S]*?(?=\n    (?:public|private) function |\n}\n?$)/g)].filter(x => /\$this->rt->audit\(/.test(x[0])).map(x => x[1]);
    const auditsViaBody = /\$this->rt->audit\(/.test(body) || auditHelpers.some(h => new RegExp('\\$this->' + h + '\\(').test(body));
    if (m && m.audit) {
      check(`op.audit.${id}`, auditsViaBody && o.audit_actions.length > 0 && o.audit_actions.every(a => source[file].includes(`'${a}'`)), `${id}: required audit is not written through rt->audit() or a listed audit action is missing`);
    } else {
      check(`op.no_audit.${id}`, !auditsViaBody && o.audit_actions.length === 0, `${id}: an operation without a required audit must not claim audit actions`);
    }
    if (isWrite) check(`op.no_direct_tx.${id}`, !/->transaction\(|beginTransaction|->commit\(/.test(body), `${id}: transaction handling belongs to AcademyRuntime::write only`);
    if (o.child_safety) check(`op.child_safety.${id}`, /safety->lock\(/.test(code[file]) && /safety->assertCover/.test(code[file]) && /assertCovers|assertCover\(/.test(body + code[file]), `${id}: minor safety (lock early + assert late) missing`);
    for (const e of o.errors) check(`op.error_known.${id}.${e}`, reasonConsts.has(e), `${id}: error ${e} is not an AcademyReason constant`);
    const ownReasons = new Set([...code[file].matchAll(/AcademyReason::([A-Z_]+)/g)].map(x => x[1]));
    const anyReasons = new Set(files.flatMap(f => [...code[f].matchAll(/AcademyReason::([A-Z_]+)/g)].map(x => x[1])));
    for (const e of o.errors) check(`op.error_used.${id}.${e}`, ownReasons.has(e) || anyReasons.has(e), `${id}: listed error ${e} is never produced by the layer`);
  }
}
for (const k of Object.keys(matrix)) check(`matrix.used.${k}`, usedOps.has(k), `matrix row ${k} is used by no service operation`);
check('op.count', opCount === contract.services.reduce((n, s) => n + s.operations.length, 0) && opCount >= 28, 'unexpected operation count');
const missingReasons = files.flatMap(f => [...code[f].matchAll(/AcademyReason::([A-Z_]+)/g)].map(m => m[1])).filter(r => !reasonConsts.has(r));
check('errors.constants', missingReasons.length === 0, `undefined AcademyReason constants used: ${[...new Set(missingReasons)]}`);
check('errors.contract_known', contract.errors.every(e => reasonConsts.has(e)), 'contract lists an unknown error');

// 4. authorization core --------------------------------------------------------------------------
const runtime = code[`${domainDir}/AcademyRuntime.php`];
const write = /public function write\([\s\S]*?\n    }\n/.exec(runtime)?.[0] || '';
const iProvisional = write.search(/->authorize\(\$op, \$actor, \$session, \$resolved, \$overrideReason\)/);
const iWork = write.search(/\$work\(\$decision, \$resolved\)/);
const iFinal = write.search(/->authorize\(\$op, \$actor, \$session, \$resolved, null, true, \$decision\)/);
check('auth.provisional_work_final_order', iProvisional >= 0 && iWork > iProvisional && iFinal > iWork, 'AcademyRuntime::write must authorize, run the work, then re-authorize (final, locking) before commit');
check('auth.transaction', /->transaction\(function/.test(write) && /,\s*5\)/.test(write), 'write() must run in a transaction with deadlock retry');
check('auth.class_assignment', /requireAssignment\(/.test(accessSrc) && /class_instructors/.test(accessSrc) && /'instructors'/.test(accessSrc), 'class-level provenance via class_instructors is missing');
check('auth.admin_override_explicit', /OVERRIDE_NOT_ALLOWED/.test(accessSrc) && /ADMIN_REASON_REQUIRED/.test(accessSrc) && /\$overrideReason !== null/.test(accessSrc), 'admin override must be explicit, allowed per matrix and carry a reason');
check('auth.override_audited', /ACADEMY_ADMIN_OVERRIDE/.test(code[`${domainDir}/DatabaseAcademyAudit.php`]) && /isOverride\(\)/.test(code[`${domainDir}/DatabaseAcademyAudit.php`]), 'admin override must be audited');
check('auth.no_silent_admin', (accessSrc.match(/AcademyOperation::ADMIN/g) || []).length === 1 && /\$override \? AcademyOperation::ADMIN : \$op->permission/.test(accessSrc), 'ACADEMY_ADMIN may only be used as the explicit override permission (no "if admin then allow" shortcut)');
check('auth.scope_from_grants', /user_role_scopes/.test(accessSrc) && /'scopes'/.test(accessSrc) && /include_descendants/.test(accessSrc) && /role_permissions/.test(accessSrc), 'institutional scope must be derived from user_role_scopes/scopes/role_permissions');
check('auth.claimed_never_authority', /assertClaimed/.test(runtime) && /CONTEXT_MISMATCH/.test(runtime), 'claimed client context must only be compared with the derived target');
const requestUnitTrust = findAll(/\$(request|payload)\b|\$_(GET|POST|REQUEST|COOKIE)|\brequest\(|Illuminate\\Http\\Request/i);
check('auth.no_request_payload', requestUnitTrust.length === 0, `services must not read request payloads: ${JSON.stringify(requestUnitTrust)}`);

// 5. no membership dependency ---------------------------------------------------------------------
const membership = findAll(/member(ships?|_numbers?|s)\b|MemberNumber/i);
check('no_membership_dependency', membership.length === 0, `Academy code must not depend on Membership / member numbers: ${JSON.stringify(membership)}`);
const person = code[`${domainDir}/AcademyPersonResolver.php`];
check('person.resolution', /'people'/.test(person) && /public_id/.test(person) && /INVALID_PERSON_REFERENCE/.test(person), 'person resolution must use people.id / public_id and reject anything else');

// 6. D-09: no hardcoded policy values ---------------------------------------------------------------
const d09Allow = [
  // [file suffix, pattern ids the entry may excuse, line regex, reason]. Each entry excuses ONLY the listed
  // pattern ids, so a hardcoded value cannot hide on an allowlisted line under a different pattern.
  ['AcademicPolicyResolver.php', ['named'], /min_score|min_ratio/, 'name of a policy-JSON key read from course_versions.completion_policy_metadata; the value is stored data'],
  ['AcademicPolicyResolver.php', ['literal'], /DECIMAL\(20,6\)/, 'SQL type width of the exact DECIMAL comparison'],
  ['AcademyInput.php', ['literal'], /min\(100, max\(1, \$perPage\)\)/, 'pagination cap (technical), not academic'],
  ['EnrollmentService.php', ['literal'], /\$perPage = 50/, 'default page size of a bounded listing (technical, not academic)'],
  ['AcademicAttendanceService.php', ['literal'], /\$perPage = 100/, 'default page size of a bounded listing (technical, not academic)'],
  ['AssessmentAttemptService.php', ['attempts_literal'], /\$attemptNumber < 1/, 'schema CHECK ck_assessment_attempts_number (attempt_number >= 1): technical invariant, not an attempt limit'],
];
const d09Patterns = [
  { id: 'named', re: /\b(pass_mark|passmark|minimum_score|approved_threshold|passing_score|pass_threshold|min_score|min_ratio)\b/i },
  { id: 'assign_compare', re: /\b(max_attempts|weight|pass_score|max_score|score|ratio)\b['"]?\s*(=>|=|>=|<=|>|<|===|!==)\s*-?\d/ },
  // camelCase / snake_case variables that look like an institutional value being given a literal.
  { id: 'assign_compare_var', re: /\$\w*(max_?attempts|pass_?(mark|score|threshold)|min(imum)?_?(score|grade|ratio)|weight|threshold|attempt_?limit)\w*\s*(=|>=|<=|>|<|===)\s*-?\d/i },
  { id: 'literal', re: /(?<![\w.$'"\\-])(10|12|15|20|25|30|40|50|60|70|75|80|90|100)(?![\w.'"])/ },
  { id: 'percent', re: /\d+\s*%|\b10\s*\/\s*20\b/ },
  { id: 'attempts_literal', re: /attempt\w*\s*(>=|<=|>|<|===)\s*\d/i },
  { id: 'approved_by_threshold', re: /\bAPROVADO\b|\bREPROVADO\b/i },
];
const d09Findings = [];
for (const { f, lines } of codeAll) lines.forEach((line, i) => {
  for (const p of d09Patterns) if (p.re.test(line)) {
    const allowed = d09Allow.find(([suffix, ids, re]) => f.endsWith(suffix) && ids.includes(p.id) && re.test(line));
    d09Findings.push({ file: f, line: i + 1, pattern: p.id, text: line.trim(), allowed: allowed ? allowed[3] : null });
  }
});
const d09Unreviewed = d09Findings.filter(x => !x.allowed);
check('d09.no_hardcoded_policy_values', d09Unreviewed.length === 0, `unreviewed possible D-09 hardcode(s): ${JSON.stringify(d09Unreviewed)}`);
check('d09.config_has_no_values', !/\d/.test(stripComments(read(configFile)).replace(/strict_types=1/, '')), 'config/academy.php must not carry numeric policy values');

// 7. D-11: no closed status catalog ---------------------------------------------------------------------
const proposedNames = ['PENDING', 'APPROVED', 'ACTIVE', 'COMPLETED', 'REJECTED', 'SUSPENDED', 'WITHDRAWN', 'FAILED', 'DRAFT', 'PUBLISHED', 'IN_PROGRESS', 'CLOSED', 'CANCELLED', 'STARTED', 'SUBMITTED', 'GRADED', 'EXPIRED', 'HOMOLOGATED', 'ISSUED', 'REVOKED', 'ENDED', 'PRESENT', 'ABSENT', 'EXCUSED', 'LATE', 'JUSTIFIED'];
const literalRe = new RegExp(`(['"])(${proposedNames.join('|')})\\1`);
const d11Allow = [['AcademyPolicy.php', /const (APPROVED|PENDING) = /, 'verdict labels of the transition guard, not entity states']];
const d11 = [...findAll(literalRe), ...(() => { const c = stripComments(read(configFile)).split('\n'); return c.flatMap((l, i) => literalRe.test(l) ? [{ file: configFile, line: i + 1, text: l.trim() }] : []); })()]
  .filter(h => !d11Allow.some(([s, re]) => h.file.endsWith(s) && re.test(h.text)));
check('d11.no_closed_status_catalog', d11.length === 0, `status names of the (proposed) D-11 catalog appear as string literals: ${JSON.stringify(d11)}`);
const cfg = read(configFile);
check('d11.config_approves_nothing', /'states' => \[\]/.test(cfg) && /'approved' => \[\]/.test(cfg) && /'pending' => \[\]/.test(cfg) && /'policy_version' => null/.test(cfg), 'config/academy.php must ship an EMPTY approved catalog (D-11 open)');
check('d11.contract_separates_proposed', contract.state_catalog.status === 'D-11 OPEN' && Object.keys(contract.state_catalog.approved).length === 0 && contract.state_catalog.proposed_not_executable, 'contract must keep proposed states separate from the (empty) approved catalog');
const machine = code[`${domainDir}/AcademyStateMachine.php`];
check('d11.machine_denies_pending', /STATE_POLICY_PENDING/.test(machine) && /INVALID_TRANSITION/.test(machine) && /AcademyPolicy::PENDING/.test(machine) && /!== AcademyPolicy::APPROVED/.test(machine), 'state machine must execute only APPROVED transitions and deny PENDING / unknown ones');
const statusWrites = findAll(/->update\(\[\s*'status'/).concat(findAll(/'status' => '/))
  // academic_attendance.status is a RECORDED VALUE validated against the configured 'recordable' set before the write (not a lifecycle state).
  .filter(h => !(h.file.endsWith('AcademicAttendanceService.php') && /table\('academic_attendance'\)->where\('id', \$row->id\)->update/.test(h.text)));
check('d11.no_direct_status_write', statusWrites.length === 0, `status must change only through the state machine / policy: ${JSON.stringify(statusWrites)}`);

// 8. audit integration ----------------------------------------------------------------------------------------
const auditImpl = code[`${domainDir}/DatabaseAcademyAudit.php`];
check('audit.reuses_audit_logs', /table\('audit_logs'\)/.test(auditImpl) && /'WAVE5_DOMAIN'/.test(auditImpl), 'audit must reuse audit_logs with source WAVE5_DOMAIN');
check('audit.no_parallel_table', findAll(/academy_audit|academic_audit|academy_logs/i).length === 0, 'no academy-specific audit table may exist');
check('audit.same_transaction', /\$work\(\$decision, \$resolved\)/.test(runtime) && /function audit\(/.test(runtime) && !/catch/.test(auditImpl), 'audit must run inside the business transaction and its failures must propagate');

// 9. child safety ------------------------------------------------------------------------------------------------------
check('safety.canonical_gate', /ChildParticipationSafetyGate/.test(code[`${domainDir}/AcademyChildSafety.php`]) && /lockParticipationCover/.test(code[`${domainDir}/AcademyChildSafety.php`]) && /assertParticipationCover/.test(code[`${domainDir}/AcademyChildSafety.php`]), 'child safety must delegate to ChildParticipationSafetyGate');
check('safety.no_parallel_logic', findAll(/['"](guardian_authorizations|person_consents|child_profiles|child_custody_visits)['"]/).length === 0 && !files.some(f => /ChildSafetyService/.test(f)), 'Academy code must not read Wave 4 safety tables directly nor define a parallel safety service');
for (const s of ['EnrollmentService', 'AcademicAttendanceService']) check(`safety.integrated.${s}`, /safety->lock\(/.test(code[`${domainDir}/${s}.php`]) && /safety->assertCover/.test(code[`${domainDir}/${s}.php`]), `${s} must lock the cover early and assert it late`);
const gate = read('apps/api/app/Domain/WaveFour/ChildParticipationSafetyGate.php');
check('safety.gate_fail_closed', /public function assertParticipationCover/.test(gate) && /throw new DomainError\(\$consented \? 'GUARDIAN_AUTHORIZATION_INVALID' : 'CONSENT_REQUIRED'\)/.test(gate), 'the gate extension must end in a denial unless a valid consent + guardian authorization is found');

// 10. no swallowed exceptions (fail closed) --------------------------------------------------------------------------
const swallowed = [];
for (const { f } of codeAll) {
  const src = code[f];
  for (const m of src.matchAll(/catch\s*\([^)]*\)\s*\{/g)) {
    let depth = 1, i = m.index + m[0].length;
    while (i < src.length && depth) { depth += src[i] === '{' ? 1 : src[i] === '}' ? -1 : 0; i++; }
    const block = src.slice(m.index, i);
    if (!/\bthrow\b|\breturn\s+\$this->translate|throw\s+\$this->translate/.test(block)) swallowed.push({ file: f, at: src.slice(0, m.index).split('\n').length });
  }
}
check('failclosed.no_swallowed_exception', swallowed.length === 0, `catch blocks that do not rethrow: ${JSON.stringify(swallowed)}`);

// 11. no new scope kind / schema / seeds / UI / API / payroll / retention -----------------------------------------------
check('scope.no_new_kind', findAll(/table\('scopes'\)\s*->\s*(insert|update)/).length === 0 && findAll(/['"](CLASS|ACADEMIC|ACADEMY|CLASS_SCOPE)['"]/).filter(h => /scope_kind/.test(h.text)).length === 0, 'no scope kind may be created or written');
check('scope.only_unit_kind_read', /scope_kind !== 'UNIT'/.test(accessSrc), 'only the existing UNIT scope kind is honoured');
const git = args => cp.execFileSync('git', args, { cwd: root, encoding: 'utf8' }).trim();
const changed = (paths) => git(['status', '--porcelain', '--', ...paths]).split(/\r?\n/).filter(Boolean);
check('schema.no_migration_change', changed(['apps/api/database']).length === 0 && changed(['docs/database/model_catalog.json', 'docs/database/physical/migration_waves.json', 'docs/database/physical/wave5_manifest.json', 'docs/database/physical/wave5_migrations_manifest.json']).length === 0, 'migrations / catalog / manifests must be untouched by A2');
const gitGrep = (pattern, ...paths) => { try { return git(['grep', '-l', pattern, '--', ...paths]); } catch (e) { return ''; } };
check('schema.no_seeds', findAll(/table\('(permissions|role_permissions|roles|user_role_scopes|scopes)'\)\s*->\s*(insert|update|delete|upsert)/).length === 0 && gitGrep('ACADEMY_', 'apps/api/database') === '', 'no Academy permission/role/grant seed may exist in production code or database/ (TEST fixtures only)');
check('ui.none', changed(['apps/web', 'apps/api/routes', 'apps/api/app/Http', 'apps/api/resources']).length === 0, 'A2 must not touch UI, routes, controllers or resources');
check('fin_payroll.absent', findAll(/salary|payroll|allowance|pension|\bINSS\b|remuneration|ledger/i).length === 0, 'FIN-PAYROLL-01 is out of scope: no payroll/pension/ledger identifiers');
check('d06.absent', findAll(/retention|legal_hold|\bpurge\b|data_subject|gdpr/i).length === 0, 'D-06 is out of scope: no purge/retention/legal-hold logic');

// 12. tests exist for the mission matrix ------------------------------------------------------------------------------------------
const testFiles = ['AuthorizationTest', 'EnrollmentTest', 'OperationsTest', 'AssessmentCertificationTest', 'ConcurrencyTest'].map(n => read(`apps/api/tests/DatabaseV2/Academy${n}.php`)).concat(read('apps/api/tests/Unit/AcademyDomainUnitTest.php')).join('\n');
const markers = ['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7', 'c8', 'c9', 'c10', 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'a10', 'e1', 'e2', 'e3', 'e4'];
for (const m of markers) check(`tests.marker.${m}`, new RegExp(`function test_(\\w*_)?${m}_`).test(testFiles), `no test named for ${m.toUpperCase()}`);
check('tests.uses_v2_pool', /PooledWaveFiveCase/.test(testFiles) && !/DROP DATABASE|CREATE DATABASE/i.test(testFiles), 'tests must use the pooled Test Infrastructure V2 base and never CREATE/DROP DATABASE');

// ---- report ------------------------------------------------------------------------------------------------------------------------------
const report = {
  status: failures.length ? 'FAIL' : 'APPLICATION_CONTRACTS_PASS',
  validator: 'scripts/validate-wave5-application-contracts.cjs',
  checks_total: checks.length,
  checks_failed: failures.length,
  services: contract.services.length,
  operations: opCount,
  permission_matrix_rows: Object.keys(matrix).length,
  php_files_scanned: files.length,
  d09_scan: { patterns: d09Patterns.map(p => p.id), findings: d09Findings.length, reviewed_false_positives: d09Findings.filter(x => x.allowed).map(x => ({ file: x.file, line: x.line, pattern: x.pattern, text: x.text, reason: x.allowed })), unreviewed: d09Unreviewed.length },
  d11_scan: { proposed_names_checked: proposedNames.length, string_literal_hits: d11.length },
  failures,
};
fs.writeFileSync(rel('docs/database/physical/wave5_application_contracts_validation.json'), JSON.stringify(report, null, 2) + '\n');
console.log({ status: report.status, checks: report.checks_total, failed: report.checks_failed, operations: opCount, d09_reviewed_false_positives: report.d09_scan.reviewed_false_positives.length });
if (failures.length) { for (const f of failures) console.error(' - ' + f); process.exit(1); }
