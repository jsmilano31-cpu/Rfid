$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'Activate-Tools.ps1')
$local = $PSScriptRoot
$httpdExe = Join-Path $local 'tools\Apache24\bin\httpd.exe'
$httpdConfig = Join-Path $local 'config\httpd.conf'
$apache = @(Get-CimInstance Win32_Process -Filter "Name = 'httpd.exe'" | Where-Object {
    $_.ExecutablePath -eq $httpdExe -and $_.CommandLine -like ('*' + $httpdConfig + '*')
})
if ($apache.Count) {
    # Windows -k shutdown addresses an installed service; this server runs as
    # this user's foreground-process executable, without a service registration.
    foreach ($process in $apache) { Stop-Process -Id $process.ProcessId -ErrorAction SilentlyContinue }
    $deadline = (Get-Date).AddSeconds(10)
    while ((Get-NetTCPConnection -State Listen -LocalPort 8080 -ErrorAction SilentlyContinue) -and (Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 200
    }
    if (Get-NetTCPConnection -State Listen -LocalPort 8080 -ErrorAction SilentlyContinue) {
        throw 'Local Apache did not stop; no unrelated processes were stopped.'
    }
}
$apiRoot = Join-Path (Join-Path (Split-Path $local -Parent) 'rfid-attendance-web') 'mobile-api\public'
$api = @(Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Where-Object {
    $_.CommandLine -like '*127.0.0.1:8097*' -and $_.CommandLine -like ('*' + $apiRoot + '*')
})
foreach ($process in $api) { Stop-Process -Id $process.ProcessId }
$dbExe = Join-Path $local 'tools\mariadb-11.8.8-winx64\bin\mariadbd.exe'
$dbConfig = Join-Path $local 'config\my.ini'
$database = @(Get-CimInstance Win32_Process -Filter "Name = 'mariadbd.exe'" | Where-Object {
    $_.ExecutablePath -eq $dbExe -and $_.CommandLine -like ('*' + $dbConfig + '*')
})
if ($database.Count) {
    $admin = Join-Path $local 'tools\mariadb-11.8.8-winx64\bin\mariadb-admin.exe'
    $credentials = Join-Path $local 'private\mysql-client.ini'
    & $admin "--defaults-extra-file=$credentials" shutdown
    if ($LASTEXITCODE -ne 0) { throw 'Local database shutdown failed.' }
}
Write-Host 'Workspace CampusTrack services stopped. Local data is retained.'
