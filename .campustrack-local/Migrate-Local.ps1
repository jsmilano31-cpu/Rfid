$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'Activate-Tools.ps1')
& (Join-Path $PSScriptRoot 'python\Scripts\python.exe') (Join-Path $PSScriptRoot 'bootstrap_local_db.py')
if ($LASTEXITCODE -ne 0) { throw 'Local database validation/bootstrap failed.' }
$backupDirectory = Join-Path $PSScriptRoot 'private\backups'
New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
$backup = Join-Path $backupDirectory ('local-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0,8) + '.sql')
$dump = Join-Path $PSScriptRoot 'tools\mariadb-11.8.8-winx64\bin\mariadb-dump.exe'
$credentials = Join-Path $PSScriptRoot 'private\mysql-client.ini'
& $dump "--defaults-extra-file=$credentials" --single-transaction --routines --events --triggers --databases rfid_attendance "--result-file=$backup"
if ($LASTEXITCODE -ne 0) { throw 'Local database backup failed; migrations were not started.' }
Write-Host "Local backup: $backup"
& php (Join-Path $PSScriptRoot 'migrate-local.php')
if ($LASTEXITCODE -ne 0) { throw 'A local migration failed. The backup and migration ledger are retained.' }
