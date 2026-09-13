param([string]$Suite,[string]$Prefix,[string]$Database)
Set-Location -LiteralPath 'C:\xampp\htdocs\mepa-crm\apps\api'
[Environment]::SetEnvironmentVariable($Prefix + '_DSN','mysql:host=127.0.0.1;port=33096;dbname=' + $Database)
[Environment]::SetEnvironmentVariable($Prefix + '_USER','m1safety')
[Environment]::SetEnvironmentVariable($Prefix + '_ALLOW_SYNTHETIC','1')
php vendor/phpunit/phpunit/phpunit ('tests/Database/' + $Suite + '.php') --log-junit ('../../docs/database/physical/m1_1_' + $Suite + '_junit.xml')
exit $LASTEXITCODE
