$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'Activate-Tools.ps1')
$local = $PSScriptRoot
$web = (Resolve-Path -LiteralPath (Join-Path $local '..\rfid-attendance-web')).Path
foreach ($folder in @('logs', 'state')) { New-Item -ItemType Directory -Path (Join-Path $local $folder) -Force | Out-Null }
$dbExe = Join-Path $local 'tools\mariadb-11.8.8-winx64\bin\mariadbd.exe'
$dbConfig = Join-Path $local 'config\my.ini'
$httpdExe = Join-Path $local 'tools\Apache24\bin\httpd.exe'
$httpdConfig = Join-Path $local 'config\httpd.conf'

function Assert-PortOwner([int]$Port, [string]$ExpectedExe, [string]$ExpectedArgument) {
    $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
    foreach ($listener in $listeners) {
        $owner = Get-CimInstance Win32_Process -Filter "ProcessId = $($listener.OwningProcess)"
        if ($listener.LocalAddress -ne '127.0.0.1' -or $owner.ExecutablePath -ne $ExpectedExe -or
            $owner.CommandLine -notlike ('*' + $ExpectedArgument + '*')) {
            throw "Port $Port belongs to another process; it was left unchanged."
        }
    }
    return ($listeners.Count -gt 0)
}
function Wait-LocalPort([int]$Port) {
    $deadline = (Get-Date).AddSeconds(30)
    do {
        if (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue) { return }
        Start-Sleep -Milliseconds 250
    } while ((Get-Date) -lt $deadline)
    throw "Local service on port $Port did not start. Inspect .campustrack-local\logs."
}
if (-not (Test-Path -LiteralPath (Join-Path $local 'data\mysql\mysql'))) {
    throw 'Run the local database bootstrap before starting services.'
}
if (-not (Assert-PortOwner 3306 $dbExe $dbConfig)) {
    $dbProcess = Start-Process -FilePath $dbExe -ArgumentList @('"--defaults-file=' + $dbConfig + '"') -WindowStyle Hidden -PassThru
    [IO.File]::WriteAllText((Join-Path $local 'state\database.pid'), [string]$dbProcess.Id)
    Wait-LocalPort 3306
}
& $httpdExe -f $httpdConfig -t
if ($LASTEXITCODE -ne 0) { throw 'Apache configuration validation failed.' }
if (-not (Assert-PortOwner 8080 $httpdExe $httpdConfig)) {
    Start-Process -FilePath $httpdExe -ArgumentList @('-f', ('"' + $httpdConfig + '"')) -WindowStyle Hidden | Out-Null
    Wait-LocalPort 8080
}
$phpExe = (Get-Command php.exe).Source
$apiRoot = Join-Path $web 'mobile-api\public'
if (-not (Assert-PortOwner 8097 $phpExe $apiRoot)) {
    $apiProcess = Start-Process -FilePath $phpExe -ArgumentList @('-S','127.0.0.1:8097','-t',('"' + $apiRoot + '"')) -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $local 'logs\api-output.log') -RedirectStandardError (Join-Path $local 'logs\api-error.log')
    [IO.File]::WriteAllText((Join-Path $local 'state\api.pid'), [string]$apiProcess.Id)
    Wait-LocalPort 8097
}
Write-Host 'CampusTrack web: http://127.0.0.1:8080/'
Write-Host 'Local API:      http://127.0.0.1:8097/'
Write-Host 'Database:       127.0.0.1:3306 (MariaDB, MySQL-compatible)'
