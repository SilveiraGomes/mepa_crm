'use strict';

// P0.5-I People / Families contract validator. Cross-checks docs/contracts/people_families_contracts.json
// against the routes, error rendering, catalog, configuration, output guard, services, migrations and
// the implementation report, so a drift on either side is a FAIL.

const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (rel) => fs.readFileSync(path.join(root, rel), 'utf8');

function assert(condition, message, errors) {
  if (!condition) errors.push(message);
}

function loadInputs() {
  const migrations = fs.readdirSync(path.join(root, 'apps/api/database/migrations')).filter((f) => f.includes('_p05_')).sort()
    .map((f) => read(`apps/api/database/migrations/${f}`)).join('\n');
  return {
    contract: JSON.parse(read('docs/contracts/people_families_contracts.json')),
    routes: read('apps/api/routes/api.php'),
    handler: read('apps/api/app/Exceptions/Handler.php'),
    catalog: read('apps/api/app/Domain/People/PeopleCatalog.php'),
    config: read('apps/api/config/people.php'),
    output: read('apps/api/app/Http/People/PeopleOutput.php'),
    exportService: read('apps/api/app/Domain/People/ExportService.php'),
    personService: read('apps/api/app/Domain/People/PersonService.php'),
    authority: read('apps/api/app/Domain/People/PeopleAuthority.php'),
    migrations,
    report: fs.existsSync(path.join(root, 'docs/reviews/P0.5_people_families_implementation.md')) ? read('docs/reviews/P0.5_people_families_implementation.md') : '',
  };
}

function peopleRoutes(routes) {
  const start = routes.indexOf("Route::prefix('people')");
  const end = routes.indexOf("Route::prefix('academy')");
  const block = start >= 0 ? routes.slice(start, end > start ? end : undefined) : '';
  const out = [];
  for (const match of block.matchAll(/Route::(get|post|put|patch|delete)\(\s*'([^']*)'/g)) {
    const suffix = match[2] === '/' ? '' : `/${match[2]}`;
    out.push(`${match[1].toUpperCase()} /api/v1/people${suffix}`);
  }
  return { block, published: out };
}

function validate(i) {
  const errors = [];
  const c = i.contract;
  assert(c.contract === 'MEPA_PEOPLE_FAMILIES_V1', 'contract name drift', errors);
  assert(c.phase === 'P0.5-I', 'phase drift', errors);
  assert(c.status === 'IMPLEMENTED_PENDING_INDEPENDENT_AUDIT', 'contract status must stay pending independent audit', errors);

  const inv = c.invariants || {};
  assert(inv.person_is_identity_root === true, 'Person root invariant removed', errors);
  assert(inv.membership_is_separate === true, 'Membership separation removed', errors);
  assert(inv.non_member_allowed === true, 'non-member support removed', errors);
  assert(inv.member_number_issued === false, 'member number must remain untouched', errors);
  assert(inv.internal_numeric_ids_public === false, 'internal numeric ID exposure enabled', errors);
  assert(inv.silent_auto_merge_allowed === false, 'silent auto-merge enabled', errors);
  assert(inv.backend_authorization_required === true, 'backend authorization bypass enabled', errors);
  assert(inv.person_owning_unit === false && inv.household_owning_unit === false, 'owning unit introduced', errors);
  assert(inv.hard_delete_allowed === false, 'hard delete allowed', errors);
  assert(inv.default_page_size === 50 && inv.maximum_page_size === 100, 'pagination invariant drift', errors);

  assert(c.public_identifiers?.person === 'people.public_id', 'Person public identity must be public_id', errors);
  assert(c.public_identifiers?.household === 'households.public_id', 'Household public identity must be public_id', errors);

  const http = c.http || {};
  assert(http.pagination?.server_side === true && http.pagination?.default_per_page === 50 && http.pagination?.max_per_page === 100, 'server-side pagination drift', errors);
  assert(http.concealment?.not_found_and_out_of_scope_same_external_response === true, 'F-06 concealment removed', errors);
  assert(http.generic_status_endpoint === false, 'generic status endpoint allowed', errors);
  const { block, published } = peopleRoutes(i.routes);
  const declared = (http.published_paths || []).map((p) => `${p.method} ${p.path}`);
  assert(declared.length > 0, 'no published paths declared', errors);
  for (const route of published) assert(declared.includes(route), `route not in contract: ${route}`, errors);
  for (const route of declared) assert(published.includes(route), `contract path not registered: ${route}`, errors);
  assert(!/Route::delete\(/.test(block), 'DELETE route published in People', errors);
  assert(!/'[^']*\bstatus\b[^']*'/.test(block.replace(/'status'/g, '')), 'status endpoint published', errors);
  assert(/'api\.auth'/.test(block), 'People routes are not authenticated', errors);
  for (const p of http.published_paths || []) assert(typeof p.permission === 'string' && p.permission.length > 0, `path without permission: ${p.method} ${p.path}`, errors);

  assert(/renderable\(function \(PeopleError \$e/.test(i.handler), 'People error renderer missing', errors);
  assert(/\['TARGET_NOT_FOUND', 'OUT_OF_SCOPE'\][\s\S]{0,400}RESOURCE_NOT_FOUND[\s\S]{0,120}404/.test(i.handler), 'F-06: out-of-scope not rendered as the nonexistent response', errors);

  const catalogPermissions = [...i.catalog.matchAll(/self::(\w+) => 'CLASS_[BC]'/g)].map((m) => m[1]);
  const constants = Object.fromEntries([...i.catalog.matchAll(/public const (\w+) = '([A-Z_]+)';/g)].map((m) => [m[1], m[2]]));
  const resolved = catalogPermissions.map((name) => constants[name]);
  assert(JSON.stringify([...resolved].sort()) === JSON.stringify([...(c.permissions || [])].sort()), 'permission catalog drift between code and contract', errors);
  assert((c.permissions || []).length === 11, 'People permission catalog must have 11 codes', errors);
  assert(c.roles_created === false, 'roles must not be created', errors);
  for (const [code, [semantics, inverse]] of Object.entries(c.catalogs?.relationship_types || {})) {
    const re = new RegExp(`'${code}' => \\['[^']*', self::${semantics}, '${inverse}'\\]`);
    assert(re.test(i.catalog), `relationship semantics drift: ${code}`, errors);
  }
  assert(c.catalogs?.guardian_grants_children_authority === false, 'GUARDIAN must not grant Children authority', errors);
  assert(!/'MERGED'/.test(i.catalog), 'MERGED status present in the V1 catalog', errors);

  assert(/'default' => 50\b/.test(i.config) && /'max' => 100\b/.test(i.config), 'config pagination drift', errors);
  for (const key of ['id', 'member_number', 'key_version', 'value_blind_index']) assert(i.output.includes(`'${key}'`), `output guard misses ${key}`, errors);
  assert(/str_ends_with\(\$key, '_id'\) && \$key !== 'public_id'/.test(i.output), 'output guard does not reject *_id keys', errors);

  assert(/self::CLASS_C => \[PeopleCatalog::PEOPLE_EXPORT_CLASS_C\]/.test(i.exportService), 'Class C export does not require PEOPLE_EXPORT_CLASS_C', errors);
  assert(/PEOPLE_EXPORT/.test(i.exportService) && /covered\(PeopleCatalog::PEOPLE_EXPORT\)/.test(i.exportService), 'export not scope-bounded by PEOPLE_EXPORT', errors);
  assert(!/table\('(memberships|member_numbers)'\)/.test(i.personService), 'Person service writes membership data', errors);
  assert(/isset\(\$covered\[\$c\['unit'\]\]\)/.test(i.authority), 'resolver no longer intersects contexts with scope', errors);

  assert(!/ADD COLUMN `unit_id`/.test(i.migrations), 'unit_id added by the P0.5 delta', errors);
  assert(/CREATE TABLE `person_unit_contexts`/.test(i.migrations), 'person_unit_contexts migration missing', errors);
  assert(/`semantics` VARCHAR\(32\)/.test(i.migrations) && /inverse_relationship_type_id/.test(i.migrations), 'relationship semantics delta missing', errors);
  assert(/`birth_month` TINYINT UNSIGNED NULL/.test(i.migrations), 'birth_month delta missing', errors);

  const policy = c.production_data_policy || {};
  assert(policy.mock_or_synthetic_data_allowed === false, 'mock production data allowed', errors);
  assert(policy.real_personal_data_in_repository_allowed === false, 'real personal data may not be versioned', errors);
  assert(c.crypto?.fail_closed === true && c.crypto?.plaintext_fallback === false, 'crypto fail-closed contract weakened', errors);

  const statements = ['P0.5-I — PEOPLE/FAMILIES IMPLEMENTADO E PRONTO PARA AUDITORIA INDEPENDENTE', 'P0.5-I — PEOPLE/FAMILIES COM PENDÊNCIAS'];
  assert(statements.some((s) => i.report.trimEnd().endsWith(s)), 'implementation report missing or without a final gate statement', errors);
  return errors;
}

if (require.main === module) {
  const inputs = loadInputs();
  const errors = validate(inputs);
  process.stdout.write(`${JSON.stringify({
    validator: 'MEPA_PEOPLE_FAMILIES_IMPLEMENTATION_V1',
    status: errors.length === 0 ? 'PASS' : 'FAIL',
    endpoints: (inputs.contract.http?.published_paths || []).length,
    errors,
  }, null, 2)}\n`);
  process.exitCode = errors.length === 0 ? 0 : 1;
}

module.exports = { validate, loadInputs };
