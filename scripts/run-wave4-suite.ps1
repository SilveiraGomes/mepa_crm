param([string]$Suite,[string]$Prefix='WAVE4',[string]$Database)
if($Database -notmatch '^mepa_(?:wave[1234a-z0-9]*|m1audit|m1safety)_test_[a-z0-9_]+$'){throw 'Synthetic schema name required'}
docker exec mepa-wave3-mysql mysql -uroot -e "DROP DATABASE IF EXISTS $Database; CREATE DATABASE $Database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON $Database.* TO 'wave3'@'%';"
if($LASTEXITCODE -ne 0){exit $LASTEXITCODE}
$taskRoot=Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath (Join-Path $taskRoot 'apps/api')
[Environment]::SetEnvironmentVariable($Prefix+'_DSN','mysql:host=127.0.0.1;port=33098;dbname='+$Database)
[Environment]::SetEnvironmentVariable($Prefix+'_USER','wave3')
[Environment]::SetEnvironmentVariable($Prefix+'_ALLOW_SYNTHETIC','1')
[Environment]::SetEnvironmentVariable('WAVE1_KEEP_SCHEMA','1')
[Environment]::SetEnvironmentVariable('WAVE2_KEEP_SCHEMA','1')
$taskEvidence=Join-Path $taskRoot 'docs/database/physical/m1_1_migration_safety_evidence.json'
$taskPreserved=@{}
$taskGenerated=@()
if($Suite -eq 'WaveOnePhysicalTest'){$taskGenerated=@('wave1_physical_inspection.json','wave1_physical_validation.json')}
if($Suite -eq 'WaveTwoPhysicalTest'){$taskGenerated=@('wave2_physical_inspection.json','wave2_physical_validation.json')}
if($Suite -eq 'WaveThreePhysicalTest'){$taskGenerated=@('wave3_preflight.json','wave3_lifecycle_evidence.json')}
if($Suite -eq 'WaveThreeDomainTest'){$taskGenerated=@('wave3_query_evidence.json')}
if($Suite -eq 'WaveThreeCheckinConcurrencyTest'){$taskGenerated=@('wave3_concurrency_evidence.json')}
foreach($taskName in $taskGenerated){
 $taskPath=Join-Path $taskRoot ('docs/database/physical/'+$taskName)
 if(Test-Path -LiteralPath $taskPath){$taskPreserved[$taskName]=[IO.File]::ReadAllBytes($taskPath)}
}
$taskBefore=$null
if($Suite -eq 'WaveTwoTransferMigrationSafetyTest'){$taskBefore=[IO.File]::ReadAllBytes($taskEvidence)}
try {
php vendor/phpunit/phpunit/phpunit ('tests/Database/'+$Suite+'.php') --log-junit ('../../docs/database/physical/wave4_'+$Suite+'_junit.xml')
$taskExit=$LASTEXITCODE
if($null -ne $taskBefore){Copy-Item -LiteralPath $taskEvidence -Destination (Join-Path $taskRoot 'docs/database/physical/wave4_transfer_safety_evidence.json')}
}finally{
foreach($taskName in $taskPreserved.Keys){$taskPath=Join-Path $taskRoot ('docs/database/physical/'+$taskName);if(Test-Path -LiteralPath $taskPath){Copy-Item -LiteralPath $taskPath -Destination (Join-Path $taskRoot ('docs/database/physical/wave4_regression_'+$taskName))};[IO.File]::WriteAllBytes($taskPath,$taskPreserved[$taskName])}
if($null -ne $taskBefore){[IO.File]::WriteAllBytes($taskEvidence,$taskBefore)}}
exit $taskExit
