$suites=@(
@('WaveOnePhysicalTest','WAVE1','mepa_wave1_test_wave4_physical'),
@('WaveTwoPhysicalTest','WAVE2','mepa_wave2_test_wave4_physical'),
@('WaveTwoConcurrencyTest','WAVE2','mepa_wave2_test_wave4_number'),
@('WaveTwoIndependentReconciliationTest','WAVE2F','mepa_wave2f_test_wave4_regression'),
@('WaveTwoTransferConcurrencyTest','WAVE2M1','mepa_wave2m1_test_wave4_regression'),
@('WaveTwoM1IndependentAuditTest','M1AUDIT','mepa_m1audit_test_wave4_regression'),
@('WaveTwoTransferMigrationSafetyTest','M1SAFETY','mepa_m1safety_test_wave4_regression'),
@('WaveThreePhysicalTest','WAVE3','mepa_wave3_test_wave4_physical'),
@('WaveThreeDomainTest','WAVE3','mepa_wave3_test_wave4_domain'),
@('WaveThreeCheckinConcurrencyTest','WAVE3','mepa_wave3_test_wave4_concurrency'))
foreach($suite in $suites){powershell -NoProfile -File scripts/run-wave4-suite.ps1 -Suite $suite[0] -Prefix $suite[1] -Database $suite[2];if($LASTEXITCODE -ne 0){exit $LASTEXITCODE}}
