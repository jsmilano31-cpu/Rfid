param(
    [ValidateSet('build', 'run', 'test', 'lint')][string]$Action = 'run',
    [string]$SdkPath = '',
    [string]$Serial = '',
    [string]$Avd = 'Pixel_8',
    [string]$EmulatorDataPath = ''
)

$ErrorActionPreference = 'Stop'
$projectPath = Split-Path $PSScriptRoot -Parent
if (-not $SdkPath) {
    $localProperties = Join-Path $projectPath 'local.properties'
    if (Test-Path -LiteralPath $localProperties) {
        $sdkSetting = Get-Content -LiteralPath $localProperties | Select-String '^sdk\.dir=(.+)$' | Select-Object -First 1
        if ($sdkSetting) { $SdkPath = $sdkSetting.Matches[0].Groups[1].Value.Replace('\:', ':').Replace('\\', '\') }
    }
    if (-not $SdkPath) { $SdkPath = $env:ANDROID_HOME }
    if (-not $SdkPath) { $SdkPath = "$env:USERPROFILE\Documents\android-sdk" }
}
$adbPath = Join-Path $SdkPath 'platform-tools\adb.exe'
$emulatorPath = Join-Path $SdkPath 'emulator\emulator.exe'
$previousJava = $env:JAVA_HOME
if (-not $env:JAVA_HOME -and (Test-Path 'C:\Program Files\Android\Android Studio\jbr')) {
    $env:JAVA_HOME = 'C:\Program Files\Android\Android Studio\jbr'
}
Push-Location $projectPath
try {
    $task = switch ($Action) {
        'build' { ':app:assembleDebug' }
        'test' { ':app:testDebugUnitTest' }
        'lint' { ':app:lintDebug' }
        'run' { ':app:assembleDebug' }
    }
    & .\gradlew.bat $task --console=plain
    if ($LASTEXITCODE -ne 0) { throw 'Gradle failed. See the build output above.' }
    if ($Action -ne 'run') { return }
    if (-not (Test-Path $adbPath)) { throw "ADB not found at $adbPath" }
    $devices = @(& $adbPath devices | Select-String '^\S+\s+(device|offline)$' | ForEach-Object { ($_.Line -split '\s+')[0] })
    if (-not $Serial -and $devices.Count -gt 1) { throw 'Multiple devices connected. Pass -Serial to choose one.' }
    if (-not $Serial -and $devices.Count -eq 1) { $Serial = $devices[0] }
    if (-not $Serial) {
        if (-not (Test-Path -LiteralPath $emulatorPath)) { throw "Emulator not found at $emulatorPath. Connect an Android device with USB debugging enabled." }
        $availableAvds = @(& $emulatorPath -list-avds)
        if ($Avd -notin $availableAvds) { throw "AVD '$Avd' is not available. Pass -Avd with an installed AVD name or connect an Android device with USB debugging enabled." }
        $emulatorArgs = @('-avd', $Avd, '-no-boot-anim')
        if ($EmulatorDataPath) {
            New-Item -ItemType Directory -Path $EmulatorDataPath -Force | Out-Null
            $emulatorArgs += @('-data', ('"' + (Join-Path $EmulatorDataPath 'userdata-qemu.img') + '"'), '-cache', ('"' + (Join-Path $EmulatorDataPath 'cache.img') + '"'), '-no-snapshot')
        }
        $logPath = Join-Path $projectPath '.local'
        New-Item -ItemType Directory -Path $logPath -Force | Out-Null
        Start-Process -FilePath $emulatorPath -ArgumentList $emulatorArgs -WindowStyle Hidden -RedirectStandardOutput "$logPath\emulator.log" -RedirectStandardError "$logPath\emulator-error.log"
        $deadline = (Get-Date).AddMinutes(5)
        do {
            Start-Sleep -Seconds 2
            $devices = @(& $adbPath devices | Select-String '^emulator-\d+\s+device$' | ForEach-Object { ($_.Line -split '\s+')[0] })
        } until ($devices.Count -gt 0 -or (Get-Date) -gt $deadline)
        if ($devices.Count -ne 1) { throw "Emulator did not connect unambiguously. Inspect $logPath\emulator.log or pass -Serial." }
        $Serial = $devices[0]
    }
    $deadline = (Get-Date).AddMinutes(10)
    do {
        $booted = & $adbPath -s $Serial shell getprop sys.boot_completed 2>$null
        if ($booted -eq '1') { break }
        Start-Sleep -Seconds 2
    } until ((Get-Date) -gt $deadline)
    if ($booted -ne '1') { throw "Device $Serial did not finish booting." }
    & $adbPath -s $Serial install -r '.\app\build\outputs\apk\debug\app-debug.apk'
    if ($LASTEXITCODE -ne 0) { throw 'APK installation failed.' }
    & $adbPath -s $Serial shell am start -n com.example.campustrack/.MainActivity
    if ($LASTEXITCODE -ne 0) { throw 'App launch failed.' }
} finally {
    Pop-Location
    $env:JAVA_HOME = $previousJava
}
