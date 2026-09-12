$ErrorActionPreference = 'Stop'
$taskRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$taskPhp = 'C:/xampp/php/php.exe'
$taskMysql = 'C:/xampp/mysql/bin/mysql.exe'
$taskMysqld = 'C:/xampp/mysql/bin/mysqld.exe'
$taskInstaller = 'C:/xampp/mysql/bin/mysql_install_db.exe'
foreach ($taskExe in @($taskPhp,$taskMysql,$taskMysqld,$taskInstaller)) { if (-not (Test-Path -LiteralPath $taskExe)) { throw "Required binary missing: $taskExe" } }
if (Get-NetTCPConnection -LocalPort 33079 -State Listen -ErrorAction SilentlyContinue) { throw 'Port 33079 is occupied; no existing server will be used or stopped.' }
$taskData = Join-Path ([IO.Path]::GetTempPath()) ('mepa-p02f-' + [guid]::NewGuid().ToString('N'))
$taskData = [IO.Path]::GetFullPath($taskData)
$taskTemp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
if (-not $taskData.StartsWith($taskTemp,[StringComparison]::OrdinalIgnoreCase)) { throw 'Datadir must remain within TEMP' }
$taskVars = @('ARCH_DB_DSN','ARCH_DB_ALLOW_SYNTHETIC','ARCH_DB_USER','ARCH_DB_PASSWORD','ARCH_WORKER_HOLD')
$taskSaved = @{}; foreach ($taskVar in $taskVars) { $taskSaved[$taskVar] = [Environment]::GetEnvironmentVariable($taskVar,'Process') }
$taskProcess = $null
try {
    & $taskInstaller "--datadir=$taskData" --port=33079 --silent | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic datadir initialization failed' }
    $taskProcess = Start-Process -FilePath $taskMysqld -ArgumentList @('--no-defaults',("--datadir=`"$taskData`""),'--port=33079','--bind-address=127.0.0.1','--character-set-server=utf8mb4','--collation-server=utf8mb4_unicode_ci','--innodb-buffer-pool-size=64M','--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput "$taskData.stdout.log" -RedirectStandardError "$taskData.stderr.log"
    $taskReady = $false
    for ($taskAttempt=0; $taskAttempt -lt 60; $taskAttempt++) {
        if ($taskProcess.HasExited) { throw "Synthetic server exited; inspect $taskData.stderr.log" }
        $taskOldPreference = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        & $taskMysql --no-defaults --host=127.0.0.1 --port=33079 --user=root --execute='SELECT 1' 2>$null | Out-Null
        $taskNativeCode = $LASTEXITCODE; $ErrorActionPreference = $taskOldPreference
        if ($taskNativeCode -eq 0) { $taskReady=$true; break }
        Start-Sleep -Milliseconds 250
    }
    if (-not $taskReady) { throw 'Synthetic server readiness timeout' }
    $taskListener = Get-NetTCPConnection -LocalPort 33079 -State Listen
    if ($taskListener.OwningProcess -ne $taskProcess.Id) { throw 'Listener does not belong to the synthetic process' }
    & $taskMysql --no-defaults --host=127.0.0.1 --port=33079 --user=root --execute='CREATE DATABASE p02f_architecture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic schema creation failed' }
    $env:ARCH_DB_DSN='mysql:host=127.0.0.1;port=33079;dbname=p02f_architecture;charset=utf8mb4'
    $env:ARCH_DB_ALLOW_SYNTHETIC='1'; $env:ARCH_DB_USER='root'; $env:ARCH_DB_PASSWORD=''; $env:ARCH_WORKER_HOLD=''
    & $taskPhp (Join-Path $PSScriptRoot 'run.php')
    if ($LASTEXITCODE -ne 0) { throw 'Architecture suite failed' }
} finally {
    if ($taskProcess -and -not $taskProcess.HasExited) {
        # Stop only the Process object returned by this invocation, never another service.
        Stop-Process -InputObject $taskProcess -Force
    }
    foreach ($taskVar in $taskVars) { [Environment]::SetEnvironmentVariable($taskVar,$taskSaved[$taskVar],'Process') }
}