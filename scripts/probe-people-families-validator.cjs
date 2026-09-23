'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { validate } = require('./validate-people-families-contracts.cjs');

const root = path.resolve(__dirname, '..');
const source = JSON.parse(fs.readFileSync(path.join(root, 'docs', 'contracts', 'people_families_contracts.json'), 'utf8'));
const report = fs.readFileSync(path.join(root, 'docs', 'reviews', 'P0.5_people_families_vertical.md'), 'utf8');
const routes = fs.readFileSync(path.join(root, 'apps', 'api', 'routes', 'api.php'), 'utf8');

function copy(value) {
  return JSON.parse(JSON.stringify(value));
}

const probes = [
  ['internal people.id exposure', (c) => { c.public_identifiers.person = 'people.id'; c.projections.person_common.allowed.push('id'); }],
  ['permission bypass', (c) => { c.invariants.backend_authorization_required = false; }],
  ['pagination removal', (c) => { c.http.pagination.server_side = false; delete c.http.pagination.max_per_page; }],
  ['F-06 regression', (c) => { c.http.concealment.not_found_and_out_of_scope_same_external_response = false; }],
  ['non-member forced membership', (c) => { c.invariants.non_member_allowed = false; c.invariants.member_number_issued = true; }],
  ['mock production data', (c) => { c.production_data_policy.mock_or_synthetic_data_allowed = true; }],
];

const results = probes.map(([name, mutate]) => {
  const contract = copy(source);
  mutate(contract);
  const errors = validate(contract, report, routes);
  return { name, detected: errors.length > 0, errors };
});

const missed = results.filter((result) => !result.detected);
process.stdout.write(`${JSON.stringify({
  probe: 'MEPA_PEOPLE_FAMILIES_VALIDATOR_MUTATIONS_V1',
  status: missed.length === 0 ? 'PASS' : 'FAIL',
  mutations: results,
}, null, 2)}\n`);
process.exitCode = missed.length === 0 ? 0 : 1;
