'use strict';

const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');

function assert(condition, message, errors) {
  if (!condition) errors.push(message);
}

function validate(contract, report, routes) {
  const errors = [];
  const topLevel = [
    'contract', 'phase', 'status', 'audited_head', 'gate_codes', 'invariants',
    'public_identifiers', 'blocked_decisions', 'http', 'projections', 'storage',
    'production_data_policy', 'evidence',
  ];

  assert(contract.contract === 'MEPA_PEOPLE_FAMILIES_V1', 'contract name drift', errors);
  assert(contract.phase === 'P0.5', 'phase drift', errors);
  assert(contract.status === 'BLOCKED_PRE_IMPLEMENTATION', 'blocked contract cannot claim readiness', errors);
  assert(Object.keys(contract).every((key) => topLevel.includes(key)), 'unexpected top-level contract field', errors);
  assert(contract.gate_codes?.includes('PEOPLE_INSTITUTIONAL_DECISION_REQUIRED'), 'institutional gate missing', errors);
  assert(contract.gate_codes?.includes('PEOPLE_SCOPE_DECISION_REQUIRED'), 'scope gate missing', errors);

  const invariants = contract.invariants || {};
  assert(invariants.person_is_identity_root === true, 'Person root invariant removed', errors);
  assert(invariants.membership_is_separate === true, 'Membership separation removed', errors);
  assert(invariants.non_member_allowed === true, 'non-member support removed', errors);
  assert(invariants.member_number_issued === false, 'member number must remain untouched', errors);
  assert(invariants.internal_numeric_ids_public === false, 'internal numeric ID exposure enabled', errors);
  assert(invariants.silent_auto_merge_allowed === false, 'silent auto-merge enabled', errors);
  assert(invariants.backend_authorization_required === true, 'backend authorization bypass enabled', errors);
  assert(invariants.default_page_size === 50, 'default pagination must be 50', errors);
  assert(invariants.maximum_page_size === 100, 'maximum pagination must be 100', errors);

  assert(contract.public_identifiers?.person === 'people.public_id', 'Person public identity must be public_id', errors);
  assert(contract.public_identifiers?.household === 'households.public_id', 'Household public identity must be public_id', errors);
  assert(contract.http?.published_paths?.length === 0, 'People HTTP paths published while scope is blocked', errors);
  assert(contract.http?.pagination?.server_side === true, 'server-side pagination removed', errors);
  assert(contract.http?.pagination?.default_per_page === 50, 'HTTP default pagination drift', errors);
  assert(contract.http?.pagination?.max_per_page === 100, 'HTTP maximum pagination drift', errors);
  assert(contract.http?.concealment?.not_found_and_out_of_scope_same_external_response === true, 'F-06 concealment removed', errors);

  const common = contract.projections?.person_common || {};
  assert(common.allowed?.includes('public_id'), 'Person projection lacks public_id', errors);
  assert(!common.allowed?.includes('id'), 'Person projection exposes internal id', errors);
  assert(common.forbidden?.includes('id'), 'internal id is not explicitly forbidden', errors);
  assert(common.forbidden?.includes('member_number'), 'member number is not explicitly forbidden', errors);

  const dataPolicy = contract.production_data_policy || {};
  assert(dataPolicy.mock_or_synthetic_data_allowed === false, 'mock production data allowed', errors);
  assert(dataPolicy.real_personal_data_in_repository_allowed === false, 'real personal data may not be versioned', errors);

  assert(report.includes('PEOPLE_INSTITUTIONAL_DECISION_REQUIRED'), 'report lacks institutional gate', errors);
  assert(report.includes('PEOPLE_SCOPE_DECISION_REQUIRED'), 'report lacks scope gate', errors);
  assert(report.includes('P0.5 — PEOPLE/FAMILIES COM PENDÊNCIAS'), 'report readiness statement is not pending', errors);

  const publishedPeopleRoute = /Route::prefix\(\s*['"]people['"]|Route::(?:get|post|put|patch|delete)\(\s*['"]\/?people(?:\/|['"])/i;
  assert(!publishedPeopleRoute.test(routes), 'unsafe People route published while contract is blocked', errors);

  return errors;
}

if (require.main === module) {
  const contractPath = path.join(root, 'docs', 'contracts', 'people_families_contracts.json');
  const reportPath = path.join(root, 'docs', 'reviews', 'P0.5_people_families_vertical.md');
  const routesPath = path.join(root, 'apps', 'api', 'routes', 'api.php');
  const contract = JSON.parse(fs.readFileSync(contractPath, 'utf8'));
  const errors = validate(
    contract,
    fs.readFileSync(reportPath, 'utf8'),
    fs.readFileSync(routesPath, 'utf8'),
  );
  const result = {
    validator: 'MEPA_PEOPLE_FAMILIES_BLOCKED_PREFLIGHT_V1',
    status: errors.length === 0 ? 'PASS' : 'FAIL',
    errors,
  };
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  process.exitCode = errors.length === 0 ? 0 : 1;
}

module.exports = { validate };
