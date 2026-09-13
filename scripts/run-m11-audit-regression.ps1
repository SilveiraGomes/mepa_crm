param([string]$Suite,[string]$Prefix,[string]$Database)
$taskRoot = 'C:\xampp\htdocs\mepa-crm'
Set-Location -LiteralPath (Join-Path $taskRoot 'apps/api')
[Environment]::SetEnvironmentVariable($Prefix + '_DSN','mysql:host=127.0.0.1;port=33097;dbname=' + $Database)
[Environment]::SetEnvironmentVariable($Prefix + '_USER','m11audit')
[Environment]::SetEnvironmentVariable($Prefix + '_ALLOW_SYNTHETIC','1')
$taskEvidence = Join-Path $taskRoot 'docs/database/physical/m1_1_migration_safety_evidence.json'
$taskBefore = $null
if ($Suite -eq 'WaveTwoTransferMigrationSafetyTest') { $taskBefore = [IO.File]::ReadAllBytes($taskEvidence) }
try {
    php vendor/phpunit/phpunit/phpunit ('tests/Database/' + $Suite + '.php') --log-junit ('../../docs/database/physical/m11r_' + $Suite + '_junit.xml')
    $taskExit = $LASTEXITCODE
    if ($null -ne $taskBefore) { Copy-Item -LiteralPath $taskEvidence -Destination (Join-Path $taskRoot 'docs/database/physical/m11r_reexecuted_safety_evidence.json') }
} finally {
    if ($null -ne $taskBefore) { [IO.File]::WriteAllBytes($taskEvidence, $taskBefore) }
}
exit $taskExit
