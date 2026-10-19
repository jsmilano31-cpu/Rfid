$ErrorActionPreference = 'Stop'
$toolPaths = @(
    (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe'),
    'C:\Program Files\GitHub CLI',
    (Join-Path $env:LOCALAPPDATA 'Python\pythoncore-3.14-64'),
    (Join-Path $PSScriptRoot 'tools\mariadb-11.8.8-winx64\bin'),
    (Join-Path $PSScriptRoot 'tools\Apache24\bin'),
    (Join-Path $PSScriptRoot 'python\Scripts')
)
$env:ANDROID_HOME = Join-Path $env:LOCALAPPDATA 'Android\Sdk'
$env:ANDROID_SDK_ROOT = $env:ANDROID_HOME
$toolPaths += Join-Path $env:ANDROID_HOME 'platform-tools'
foreach ($toolPath in $toolPaths) {
    if ((Test-Path -LiteralPath $toolPath) -and ($env:Path.Split(';') -notcontains $toolPath)) {
        $env:Path = "$toolPath;$env:Path"
    }
}
$env:PYTHONUTF8 = '1'
Write-Host 'CampusTrack tools activated for this PowerShell session.'
