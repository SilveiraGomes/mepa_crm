$suites=@(
@('WaveTwoConcurrencyTest','WAVE2','mepa_wave2_test_wave3_number'),
@('WaveTwoIndependentReconciliationTest','WAVE2F','mepa_wave2f_test_wave3_regression'),
@('WaveTwoTransferConcurrencyTest','WAVE2M1','mepa_wave2m1_test_wave3_regression'),
@('WaveTwoM1IndependentAuditTest','M1AUDIT','mepa_m1audit_test_wave3_regression'),
@('WaveTwoTransferMigrationSafetyTest','M1SAFETY','mepa_m1safety_test_wave3_regression'))
foreach($suite in $suites){powershell -NoProfile -File scripts/run-wave3-suite.ps1 -Suite $suite[0] -Prefix $suite[1] -Database $suite[2];if($LASTEXITCODE -ne 0){exit $LASTEXITCODE}}
