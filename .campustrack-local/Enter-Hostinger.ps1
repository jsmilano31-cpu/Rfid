param([string]$RepoPath = (Join-Path $PSScriptRoot '..\rfid-attendance-web'))
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
$repo = (Resolve-Path -LiteralPath $RepoPath).Path
$envPath = Join-Path $repo '.env.hostinger'
& git -C $repo check-ignore -q -- .env.hostinger
if ($LASTEXITCODE -ne 0) { throw '.env.hostinger must be ignored by Git before entering credentials.' }
$tracked = & git -C $repo ls-files -- .env.hostinger
if ($LASTEXITCODE -ne 0 -or $tracked) { throw '.env.hostinger must be untracked; credential entry stopped.' }
$values = [ordered]@{
    APP_ENV = 'production'; APP_DEBUG = 'false'; APP_URL = 'https://jsmkj.space'; PUBLIC_DIRECTORY = 'public'
    DB_HOST = 'localhost'; DB_PORT = '3306'; DB_DATABASE = ''; DB_USERNAME = ''; DB_PASSWORD = ''
    FTP_HOST = ''; FTP_PORT = '21'; FTP_USERNAME = ''; FTP_PASSWORD = ''
}
if (Test-Path -LiteralPath $envPath) {
    foreach ($line in [IO.File]::ReadAllLines($envPath)) {
        if ($line -match '^([A-Z_]+)=(.*)$') { $values[$Matches[1]] = $Matches[2] }
    }
} else {
    [IO.File]::WriteAllLines($envPath, @($values.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }), [Text.UTF8Encoding]::new($false))
}
$identity = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
& icacls.exe $envPath /inheritance:r /grant:r "*$($identity):(F)" '*S-1-5-18:(F)' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not restrict credential-file permissions.' }
$form = New-Object Windows.Forms.Form
$form.Text = 'CampusTrack - private Hostinger credentials'
$form.Size = New-Object Drawing.Size(660, 600)
$form.StartPosition = 'CenterScreen'
$form.FormBorderStyle = 'FixedDialog'
$form.MaximizeBox = $false
$form.TopMost = $true
$intro = New-Object Windows.Forms.Label
$intro.Text = "Enter credentials from your Hostinger account here, never in chat. Passwords are masked.`nSaved only to the ignored .env.hostinger. This form does not connect or deploy."
$intro.Location = New-Object Drawing.Point(18, 15)
$intro.Size = New-Object Drawing.Size(610, 55)
$form.Controls.Add($intro)
$fields = @('DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','FTP_HOST','FTP_PORT','FTP_USERNAME','FTP_PASSWORD')
$inputs = @{}
$row = 80
foreach ($key in $fields) {
    $label = New-Object Windows.Forms.Label
    $label.Text = $key
    $label.Location = New-Object Drawing.Point(18, ($row + 5))
    $label.Size = New-Object Drawing.Size(155, 25)
    $form.Controls.Add($label)
    $input = New-Object Windows.Forms.TextBox
    $input.Location = New-Object Drawing.Point(180, $row)
    $input.Size = New-Object Drawing.Size(440, 25)
    $input.Text = $values[$key]
    if ($key -like '*PASSWORD') { $input.UseSystemPasswordChar = $true }
    $inputs[$key] = $input
    $form.Controls.Add($input)
    $row += 40
}
$note = New-Object Windows.Forms.Label
$note.Text = 'FTP_HOST: hostname or IP only. DB_HOST is the server-side database host. Production keys are fixed; existing additional settings are retained.'
$note.Location = New-Object Drawing.Point(18, 445)
$note.Size = New-Object Drawing.Size(610, 40)
$form.Controls.Add($note)
$save = New-Object Windows.Forms.Button
$save.Text = 'Save privately'
$save.Location = New-Object Drawing.Point(355, 505)
$save.Size = New-Object Drawing.Size(130, 30)
$save.Add_Click({
    $invalid = @($fields | Where-Object {
        $value = $inputs[$_].Text
        [string]::IsNullOrWhiteSpace($value) -or $value.Contains("`r") -or $value.Contains("`n") -or
        $value.Trim() -ne $value -or $value.StartsWith('"') -or $value.EndsWith('"') -or
        $value.StartsWith("'") -or $value.EndsWith("'")
    })
    foreach ($key in @('DB_PORT','FTP_PORT')) {
        $portValue = 0
        if (-not [int]::TryParse($inputs[$key].Text, [ref]$portValue) -or $portValue -lt 1 -or $portValue -gt 65535) { $invalid += $key }
    }
    if ($inputs['FTP_HOST'].Text -match '[/\\\s:]') { $invalid += 'FTP_HOST' }
    if ($invalid.Count) {
        [Windows.Forms.MessageBox]::Show('Check these fields: ' + (($invalid | Select-Object -Unique) -join ', ') + '. Values cannot have outer spaces/quotes or newlines because the repository parser strips them.', 'Nothing saved') | Out-Null
        return
    }
    foreach ($key in $fields) { $values[$key] = $inputs[$key].Text }
    $values['APP_ENV'] = 'production'; $values['APP_DEBUG'] = 'false'
    $values['APP_URL'] = 'https://jsmkj.space'; $values['PUBLIC_DIRECTORY'] = 'public'
    [IO.File]::WriteAllLines($envPath, @($values.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }), [Text.UTF8Encoding]::new($false))
    $form.DialogResult = [Windows.Forms.DialogResult]::OK
    $form.Close()
})
$form.Controls.Add($save)
$cancel = New-Object Windows.Forms.Button
$cancel.Text = 'Cancel'
$cancel.DialogResult = [Windows.Forms.DialogResult]::Cancel
$cancel.Location = New-Object Drawing.Point(495, 505)
$cancel.Size = New-Object Drawing.Size(125, 30)
$form.Controls.Add($cancel)
$form.CancelButton = $cancel
$result = $form.ShowDialog()
foreach ($input in $inputs.Values) { $input.Clear() }
$values.Clear()
$form.Dispose()
if ($result -eq [Windows.Forms.DialogResult]::OK) { Write-Host 'Hostinger credentials saved privately. No remote changes were made.' }
else { Write-Host 'Credential entry cancelled; any previously saved values were retained.' }
