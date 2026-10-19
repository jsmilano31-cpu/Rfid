param(
    [string] $ConfigPath = (Join-Path $PSScriptRoot '..\.env.hostinger'),
    [ValidateRange(1, 2147483647)]
    [int] $StartAt = 1,
    [string[]] $Only = @()
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$configFile = (Resolve-Path $ConfigPath).Path
$config = @{}

Get-Content -LiteralPath $configFile | ForEach-Object {
    if ($_ -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
        $config[$Matches[1]] = $Matches[2]
    }
}

$required = @(
    'APP_ENV', 'APP_DEBUG', 'APP_URL', 'PUBLIC_DIRECTORY',
    'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'FTP_HOST', 'FTP_PORT', 'FTP_USERNAME', 'FTP_PASSWORD'
)
$invalid = @($required | Where-Object {
    -not $config.ContainsKey($_) -or
    [string]::IsNullOrWhiteSpace($config[$_]) -or
    $config[$_] -match 'YOUR[-_]'
})
if ($invalid.Count -gt 0) {
    throw "Missing or invalid deployment settings: $($invalid -join ', ')"
}

$appUri = [uri] $config['APP_URL']
if ($appUri.Scheme -ne 'https' -or [string]::IsNullOrWhiteSpace($appUri.DnsSafeHost)) {
    throw 'APP_URL must be an HTTPS URL with a valid hostname.'
}
if ($config['FTP_HOST'] -match '^[a-z]+://') {
    throw 'FTP_HOST must contain only a hostname or IP address.'
}

$temporaryRoot = [System.IO.Path]::GetTempPath()
$temporaryDirectory = Join-Path $temporaryRoot ("rfid-hostinger-deploy-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null
$productionEnv = Join-Path $temporaryDirectory '.env'
$ftpCredential = [System.Net.NetworkCredential]::new($config['FTP_USERNAME'], $config['FTP_PASSWORD'])
$ftpBaseUrl = "ftp://$($config['FTP_HOST']):$($config['FTP_PORT'])"
$previousCertificateCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
$previousSecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol
$certificateCallbackChanged = $false

try {
    [System.Net.ServicePointManager]::SecurityProtocol =
        $previousSecurityProtocol -bor [System.Net.SecurityProtocolType]::Tls12

    $parsedFtpAddress = $null
    if ([System.Net.IPAddress]::TryParse($config['FTP_HOST'], [ref] $parsedFtpAddress)) {
        # Hostinger presents a valid *.hstgr.io certificate when its FTP endpoint is addressed by IP.
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = {
            param($sender, $certificate, $chain, $sslPolicyErrors)

            if ($sslPolicyErrors -eq [System.Net.Security.SslPolicyErrors]::None) {
                return $true
            }

            return (
                $sslPolicyErrors -eq [System.Net.Security.SslPolicyErrors]::RemoteCertificateNameMismatch -and
                $certificate.Subject -like 'CN=*.hstgr.io*'
            )
        }
        $certificateCallbackChanged = $true
    }

    $appKeys = @(
        'APP_ENV', 'APP_DEBUG', 'APP_URL', 'PUBLIC_DIRECTORY',
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'
    )
    $environmentLines = $appKeys | ForEach-Object { "$_=$($config[$_])" }
    [System.IO.File]::WriteAllLines($productionEnv, $environmentLines, [System.Text.UTF8Encoding]::new($false))

    $transfers = [System.Collections.Generic.List[object]]::new()
    $domainRoot = "domains/$($appUri.DnsSafeHost)"

    foreach ($directory in @('config', 'src', 'templates', 'storage')) {
        Get-ChildItem (Join-Path $projectRoot $directory) -Recurse -File |
            Where-Object { $_.Extension -ne '.log' -and $_.Name -ne '.DS_Store' } |
            ForEach-Object {
                $relativePath = $_.FullName.Substring($projectRoot.Length + 1).Replace('\', '/')
                $transfers.Add([pscustomobject]@{
                    Local = $_.FullName
                    Remote = "$domainRoot/$relativePath"
                })
            }
    }

    $publicRoot = Join-Path $projectRoot 'public'
    Get-ChildItem $publicRoot -Recurse -File |
        Where-Object { $_.Name -ne '.DS_Store' } |
        ForEach-Object {
            $relativePath = $_.FullName.Substring($publicRoot.Length + 1).Replace('\', '/')
            $transfers.Add([pscustomobject]@{
                Local = $_.FullName
                Remote = "$domainRoot/public_html/$relativePath"
            })
        }

    $transfers.Add([pscustomobject]@{
        Local = $productionEnv
        Remote = "$domainRoot/.env"
    })

    if ($Only.Count -gt 0) {
        if ($StartAt -ne 1) {
            throw 'Only and StartAt cannot be used together.'
        }

        $requestedFiles = @($Only | ForEach-Object {
            (Resolve-Path (Join-Path $projectRoot $_)).Path
        })
        $selectedTransfers = @($transfers | Where-Object { $_.Local -in $requestedFiles })
        if ($selectedTransfers.Count -ne $requestedFiles.Count) {
            throw 'One or more requested files are outside the deployable application paths.'
        }

        $transfers = [System.Collections.Generic.List[object]]::new()
        $transfers.AddRange([object[]] $selectedTransfers)
    }

    if ($StartAt -gt $transfers.Count) {
        throw "StartAt cannot exceed the transfer count of $($transfers.Count)."
    }
    $activeTransfers = @($transfers | Select-Object -Skip ($StartAt - 1))

    function ConvertTo-FtpUrl([string] $remotePath) {
        $escapedPath = (($remotePath -split '/') | ForEach-Object {
            [uri]::EscapeDataString($_)
        }) -join '/'
        return "$ftpBaseUrl/$escapedPath"
    }

    function New-FtpRequest([string] $remotePath, [string] $method) {
        $request = [System.Net.FtpWebRequest]::Create((ConvertTo-FtpUrl $remotePath))
        $request.Credentials = $ftpCredential
        $request.EnableSsl = $true
        $request.UseBinary = $true
        $request.UsePassive = $true
        $request.KeepAlive = $false
        $request.Timeout = 60000
        $request.ReadWriteTimeout = 180000
        $request.Method = $method
        return $request
    }

    $remoteDirectories = @($activeTransfers | ForEach-Object {
        $_.Remote.Substring(0, $_.Remote.LastIndexOf('/'))
    } | Sort-Object -Unique | Sort-Object Length)
    $visitedDirectories = [System.Collections.Generic.HashSet[string]]::new(
        [System.StringComparer]::Ordinal
    )

    foreach ($remoteDirectory in $remoteDirectories) {
        $currentPath = ''
        foreach ($segment in ($remoteDirectory -split '/')) {
            $currentPath = if ($currentPath) { "$currentPath/$segment" } else { $segment }
            if (-not $visitedDirectories.Add($currentPath)) {
                continue
            }

            try {
                $directoryRequest = New-FtpRequest $currentPath ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)
                $directoryResponse = $directoryRequest.GetResponse()
                $directoryResponse.Close()
            } catch [System.Net.WebException] {
                $ftpResponse = $_.Exception.Response
                if ($ftpResponse -isnot [System.Net.FtpWebResponse] -or
                    $ftpResponse.StatusCode -ne [System.Net.FtpStatusCode]::ActionNotTakenFileUnavailable) {
                    if ($ftpResponse) {
                        $ftpResponse.Close()
                    }
                    throw
                }
                $ftpResponse.Close()
            }
        }
    }

    for ($index = $StartAt - 1; $index -lt $transfers.Count; $index++) {
        $transfer = $transfers[$index]
        $uploadRequest = New-FtpRequest $transfer.Remote ([System.Net.WebRequestMethods+Ftp]::UploadFile)
        $sourceStream = [System.IO.File]::OpenRead($transfer.Local)
        try {
            $uploadRequest.ContentLength = $sourceStream.Length
            $requestStream = $uploadRequest.GetRequestStream()
            try {
                $sourceStream.CopyTo($requestStream)
            } finally {
                $requestStream.Close()
            }
        } finally {
            $sourceStream.Close()
        }

        $uploadResponse = $uploadRequest.GetResponse()
        $uploadResponse.Close()

        if (($index + 1) % 10 -eq 0 -or $index + 1 -eq $transfers.Count) {
            Write-Output "Uploaded $($index + 1)/$($transfers.Count) files"
        }
    }
} finally {
    [System.Net.ServicePointManager]::SecurityProtocol = $previousSecurityProtocol
    if ($certificateCallbackChanged) {
        [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $previousCertificateCallback
    }
    if (Test-Path -LiteralPath $productionEnv) {
        Remove-Item -LiteralPath $productionEnv -Force
    }
    if (
        (Test-Path -LiteralPath $temporaryDirectory) -and
        $temporaryDirectory.StartsWith($temporaryRoot, [System.StringComparison]::OrdinalIgnoreCase) -and
        (Split-Path $temporaryDirectory -Leaf).StartsWith('rfid-hostinger-deploy-')
    ) {
        Remove-Item -LiteralPath $temporaryDirectory -Force
    }
}
