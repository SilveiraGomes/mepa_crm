'use strict';

// Proves the People / Families validator detects each regression it guards (in-memory mutations only).
const { validate, loadInputs } = require('./validate-people-families-contracts.cjs');

const source = loadInputs();
const copy = () => ({ ...source, contract: JSON.parse(JSON.stringify(source.contract)) });

const probes = [
  ['internal people.id exposure', (i) => { i.contract.public_identifiers.person = 'people.id'; }],
  ['output guard no longer rejects *_id', (i) => { i.output = i.output.replace("str_ends_with($key, '_id') && $key !== 'public_id'", 'false'); }],
  ['permission bypass', (i) => { i.contract.invariants.backend_authorization_required = false; }],
  ['scope bypass in resolver', (i) => { i.authority = i.authority.replace("isset($covered[$c['unit']])", 'true'); }],
  ['pagination removal', (i) => { i.contract.http.pagination.server_side = false; delete i.contract.http.pagination.max_per_page; }],
  ['config pagination widened', (i) => { i.config = i.config.replace("'max' => 100", "'max' => 1000"); }],
  ['F-06 regression in contract', (i) => { i.contract.http.concealment.not_found_and_out_of_scope_same_external_response = false; }],
  ['F-06 regression in handler', (i) => { i.handler = i.handler.replace("['TARGET_NOT_FOUND', 'OUT_OF_SCOPE']", "['TARGET_NOT_FOUND']"); }],
  ['non-member forced membership', (i) => { i.contract.invariants.non_member_allowed = false; i.contract.invariants.member_number_issued = true; }],
  ['person service writes memberships', (i) => { i.personService += "\n$this->rt->db->table('memberships')->insert([]);"; }],
  ['mock production data', (i) => { i.contract.production_data_policy.mock_or_synthetic_data_allowed = true; }],
  ['unregistered route', (i) => { i.routes = i.routes.replace("Route::post('exports'", "Route::post('exports-all'"); }],
  ['undeclared DELETE route', (i) => { i.routes = i.routes.replace("Route::get('context'", "Route::delete('{person}', [PersonController::class, 'destroy']);\n        Route::get('context'"); }],
  ['Class C export without its permission', (i) => { i.exportService = i.exportService.replace('self::CLASS_C => [PeopleCatalog::PEOPLE_EXPORT_CLASS_C]', 'self::CLASS_C => []'); }],
  ['relationship inverse drift', (i) => { i.contract.catalogs.relationship_types.PARENT = ['INVERSE_PAIRED', 'PARENT']; }],
  ['people.unit_id introduced', (i) => { i.migrations += "\nALTER TABLE `people` ADD COLUMN `unit_id` BIGINT UNSIGNED NULL"; }],
  ['plaintext fallback allowed', (i) => { i.contract.crypto.plaintext_fallback = true; }],
];

const control = validate(copy());
// A mutation counts as detected only when it adds an error the unmutated control does not have.
const results = probes.map(([name, mutate]) => {
  const inputs = copy();
  mutate(inputs);
  const errors = validate(inputs).filter((e) => !control.includes(e));
  return { name, detected: errors.length > 0, errors };
});
const missed = results.filter((r) => !r.detected);
process.stdout.write(`${JSON.stringify({
  probe: 'MEPA_PEOPLE_FAMILIES_VALIDATOR_MUTATIONS_V2',
  status: missed.length === 0 && control.length === 0 ? 'PASS' : 'FAIL',
  control_errors: control,
  detected: results.length - missed.length,
  total: results.length,
  mutations: results,
}, null, 2)}\n`);
process.exitCode = missed.length === 0 && control.length === 0 ? 0 : 1;
