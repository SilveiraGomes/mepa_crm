#!/bin/bash
set -uo pipefail
MYSQL="/c/wamp64/bin/mysql/mysql8.4.7/bin/mysql.exe"
PHP="/c/wamp64/bin/php/php8.1.33/php.exe"
export PHP_BIN="/c/wamp64/bin/php/php8.1.33/php.exe"
cd /c/wamp64/www/mepa-crm/apps/api
RESULTS="/c/wamp64/www/mepa-crm/docs/database/physical/wave4_m12_runs/wamp_full_regression.log"
> "$RESULTS"

run_one() {
  local test_class="$1" prefix="$2" db_prefix="$3" suffix="$4"
  local schema="mepa_${db_prefix}_test_regression_${suffix}"
  "$MYSQL" -h127.0.0.1 -P3306 -uroot -e "DROP DATABASE IF EXISTS ${schema}; CREATE DATABASE ${schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null 2>&1
  export ${prefix}_DSN="mysql:host=127.0.0.1;port=3306;dbname=${schema}"
  export ${prefix}_USER="root"
  export ${prefix}_PASSWORD=""
  export ${prefix}_ALLOW_SYNTHETIC="1"
  export DB_CAPABILITIES_DSN="mysql:host=127.0.0.1;port=3306;dbname=${schema}"
  export DB_CAPABILITIES_USER="root"
  export DB_CAPABILITIES_PASSWORD=""
  local start=$(date +%s)
  echo "=== ${test_class} (schema=${schema}) start=$(date -u +%Y-%m-%dT%H:%M:%SZ) ===" >> "$RESULTS"
  "$PHP" vendor/phpunit/phpunit/phpunit "tests/Database/${test_class}.php" >> "$RESULTS" 2>&1
  local exit_code=$?
  local end=$(date +%s)
  echo "=== ${test_class} exit=${exit_code} seconds=$((end-start)) ===" >> "$RESULTS"
  "$MYSQL" -h127.0.0.1 -P3306 -uroot -e "DROP DATABASE IF EXISTS ${schema};" >/dev/null 2>&1
  unset ${prefix}_DSN ${prefix}_USER ${prefix}_PASSWORD ${prefix}_ALLOW_SYNTHETIC
}

run_one WaveOnePhysicalTest WAVE1 wave1 physical
run_one WaveTwoPhysicalTest WAVE2 wave2 physical
run_one WaveTwoConcurrencyTest WAVE2 wave2 concurrency
run_one WaveTwoIndependentReconciliationTest WAVE2F wave2f regression
run_one WaveTwoTransferConcurrencyTest WAVE2M1 wave2m1 regression
run_one WaveTwoM1IndependentAuditTest M1AUDIT m1audit regression
run_one WaveTwoTransferMigrationSafetyTest M1SAFETY m1safety regression
run_one WaveThreePhysicalTest WAVE3 wave3 physical
run_one WaveThreeDomainTest WAVE3 wave3 domain
run_one WaveThreeCheckinConcurrencyTest WAVE3 wave3 concurrency
run_one WaveFourPhysicalTest WAVE4 wave4 physical
run_one WaveFourCommitAuthorizationTest WAVE4 wave4 commitauth
run_one WaveFourProgressCommitBoundaryTest WAVE4 wave4 progress
run_one WaveFourCheckoutConcurrencyTest WAVE4 wave4 checkout
run_one WaveFourChildCheckinBoundaryTest WAVE4 wave4 childboundary
run_one WaveFourChildrenSafetyTest WAVE4 wave4 childsafety
run_one WaveFourDiscipleshipScopeTest WAVE4 wave4 discipleshipscope
run_one WaveFourEvangelismTemporalBoundaryTest WAVE4 wave4 evangtemporal
run_one WaveFourEvangelismTest WAVE4 wave4 evang
run_one WaveFourIndependentAuditM1RTest WAVE4 wave4 auditm1r
run_one WaveFourTemporalAuthorizationTest WAVE4 wave4 temporal

echo "ALL REGRESSION CLASSES DONE" >> "$RESULTS"
grep -E "^===|OK \(|FAILURES|Tests:|Errors:" "$RESULTS"
