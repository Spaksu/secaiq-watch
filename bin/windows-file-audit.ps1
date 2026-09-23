<#
.SYNOPSIS
  Turns Windows file-access auditing on (or off) for credential folders, so SecAIQ Watch can show which AI tools
  read them (Files tab). Run once per computer in an ELEVATED PowerShell. Nothing is sent anywhere by this script.

.DESCRIPTION
  enable  - switches on the "File System" audit subcategory (success only), adds an audit rule (read data, success) to the
            credential folders of every user profile, and adds your user to "Event Log Readers" so it can read
            the Security log (takes effect at the user's next sign-in).
  disable - removes exactly the audit rules this script added and the group membership. The audit subcategory is left as is,
            because other policies may rely on it; the command to switch it off is printed.
  status  - shows the subcategory, which folders carry the rule, and group membership.

  Only credential locations are audited (SSH, cloud CLIs, GPG, Windows Credential Manager files). Browser profiles and
  document folders are deliberately NOT audited: they are read constantly and would flood the Security log.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File windows-file-audit.ps1 enable -AgentUser <your Windows user>
#>
param(
  [Parameter(Mandatory = $true, Position = 0)][ValidateSet('enable', 'disable', 'status')][string]$Action,
  [string]$AgentUser = $env:USERNAME
)
$ErrorActionPreference = 'Stop'

# Locale-independent identifiers (Turkish, German … Windows use translated names)
$FileSystemSubcategory = '{0CCE921D-69AE-11D9-BED3-505054503030}'
$EventLogReadersSid    = 'S-1-5-32-573'
$EveryoneSid           = New-Object System.Security.Principal.SecurityIdentifier('S-1-1-0')
$Targets = @('.ssh', '.aws', '.kube', '.azure', '.gnupg', '.config\gcloud', '.docker',
             'AppData\Roaming\Microsoft\Credentials', 'AppData\Local\Microsoft\Credentials')

function Assert-Admin {
  $p = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
  if (-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run this in an elevated PowerShell (Run as administrator).' }
}

function Get-Profiles {
  Get-CimInstance Win32_UserProfile | Where-Object { -not $_.Special -and $_.LocalPath -and (Test-Path $_.LocalPath) } | ForEach-Object { $_.LocalPath }
}

function Get-Folders {
  foreach ($p in Get-Profiles) { foreach ($t in $Targets) { $f = Join-Path $p $t; if (Test-Path -LiteralPath $f -PathType Container) { $f } } }
}

function New-Rule {
  New-Object System.Security.AccessControl.FileSystemAuditRule($EveryoneSid, 'ReadData',
    'ContainerInherit,ObjectInherit', 'None', 'Success')
}

function Test-Rule($acl) {
  foreach ($r in $acl.GetAuditRules($true, $false, [System.Security.Principal.SecurityIdentifier])) {
    if ($r.IdentityReference -eq $EveryoneSid -and ($r.FileSystemRights -band [System.Security.AccessControl.FileSystemRights]::ReadData) -and $r.AuditFlags -eq 'Success') { return $true }
  }
  return $false
}

switch ($Action) {
  'enable' {
    Assert-Admin
    auditpol /set /subcategory:$FileSystemSubcategory /success:enable | Out-Null
    foreach ($f in Get-Folders) {
      $acl = Get-Acl -LiteralPath $f -Audit
      if (Test-Rule $acl) { Write-Host "  already audited  $f"; continue }
      $acl.AddAuditRule((New-Rule))
      Set-Acl -LiteralPath $f -AclObject $acl
      Write-Host "  audited          $f"
    }
    try { Add-LocalGroupMember -SID $EventLogReadersSid -Member $AgentUser -ErrorAction Stop; Write-Host "  $AgentUser added to Event Log Readers (sign out and in again)" }
    catch { if ($_.Exception.Message -match 'already') { Write-Host "  $AgentUser is already in Event Log Readers" } else { throw } }
    Write-Host "`nDone. Tip: a larger Security log keeps more history, e.g.  wevtutil sl Security /ms:268435456"
  }
  'disable' {
    Assert-Admin
    foreach ($f in Get-Folders) {
      $acl = Get-Acl -LiteralPath $f -Audit
      if (-not (Test-Rule $acl)) { continue }
      $acl.RemoveAuditRuleSpecific((New-Rule))
      Set-Acl -LiteralPath $f -AclObject $acl
      Write-Host "  rule removed     $f"
    }
    try { Remove-LocalGroupMember -SID $EventLogReadersSid -Member $AgentUser -ErrorAction Stop; Write-Host "  $AgentUser removed from Event Log Readers" } catch { }
    Write-Host "`nThe File System audit subcategory was left on. To switch it off:  auditpol /set /subcategory:$FileSystemSubcategory /success:disable"
  }
  'status' {
    Write-Host 'File System auditing:'; auditpol /get /subcategory:$FileSystemSubcategory
    Write-Host "`nAudited credential folders:"
    foreach ($f in Get-Folders) {
      $on = $false; try { $on = Test-Rule (Get-Acl -LiteralPath $f -Audit) } catch { $on = 'unknown (run elevated)' }
      Write-Host ("  {0,-8} {1}" -f $(if ($on -eq $true) { 'on' } elseif ($on -eq $false) { 'off' } else { $on }), $f)
    }
    $m = @(); try { $m = Get-LocalGroupMember -SID $EventLogReadersSid | ForEach-Object { $_.Name } } catch { }
    Write-Host "`nEvent Log Readers: $($m -join ', ')"
  }
}
