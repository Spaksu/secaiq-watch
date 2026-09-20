<#
 Runs SecAIQ Watch on Windows as two per-user Task Scheduler tasks (no admin rights needed):
   SecAIQ-Watch-Collector  the collector (starts at logon, restarts if it stops, restartable from Settings)
   SecAIQ-Watch-Panel      the web panel on http://127.0.0.1:8099/ (PHP built-in server)

 Usage (PowerShell):
   powershell -ExecutionPolicy Bypass -File bin\install-agent.ps1 install [-Php C:\xampp\php\php.exe]
   powershell -ExecutionPolicy Bypass -File bin\install-agent.ps1 uninstall
   powershell -ExecutionPolicy Bypass -File bin\install-agent.ps1 status

 Windows limits: no per-connection byte counters and no open-file listing (the panel says so).
#>
param(
    [Parameter(Position = 0)][ValidateSet('install', 'uninstall', 'status')][string]$Action = 'status',
    [string]$Php = '',
    [int]$Port = 8099
)
$ErrorActionPreference = 'Stop'
$Dir = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Var = Join-Path $Dir 'var'
$Tasks = @('SecAIQ-Watch-Collector', 'SecAIQ-Watch-Panel')
$LegacyTasks = @('AIWatch-Collector', 'AIWatch-Panel') # names used before the SecAIQ rename

function Find-Php {
    if ($Php -ne '') { return $Php }
    foreach ($c in @('C:\xampp\php\php.exe', "$env:ProgramFiles\PHP\php.exe", 'C:\php\php.exe')) {
        if (Test-Path $c) { return $c }
    }
    $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw 'php.exe not found. Install PHP 8.1+ (XAMPP works) or pass -Php <path>.'
}

# A tiny supervisor loop: runs the command, waits 5 s and starts it again (also after "Restart collector" in the UI)
function Write-Runner([string]$Name, [string]$PhpExe, [string]$ArgLine, [hashtable]$Env) {
    $envLines = ($Env.GetEnumerator() | ForEach-Object { "`$env:$($_.Key) = '$($_.Value)'" }) -join "`r`n"
    $log = Join-Path $Var "$Name.log"
    $script = @"
$envLines
Set-Location '$Dir'
while (`$true) {
    & '$PhpExe' $ArgLine 2>&1 | Out-File -FilePath '$log' -Append -Encoding utf8
    Start-Sleep -Seconds 5
}
"@
    $path = Join-Path $Var "run-$Name.ps1"
    Set-Content -Path $path -Value $script -Encoding UTF8
    return $path
}

function Register-Task([string]$Task, [string]$Runner) {
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$Runner`""
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero)
    $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
    Register-ScheduledTask -TaskName $Task -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
    Start-ScheduledTask -TaskName $Task
}

function Stop-Ours {
    # stop the supervisor loops and the php processes started from this folder
    Get-CimInstance Win32_Process | Where-Object {
        $_.CommandLine -and (($_.CommandLine -like "*$Var*run-*.ps1*") -or ($_.Name -eq 'php.exe' -and ($_.CommandLine -like '*collect.php*' -or $_.CommandLine -like "*-S 127.0.0.1:$Port*")))
    } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

switch ($Action) {
    'install' {
        $phpExe = Find-Php
        & $phpExe -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'
        if ($LASTEXITCODE -ne 0) { throw 'PHP is missing the pdo_sqlite extension (enable extension=pdo_sqlite and extension=sqlite3 in php.ini).' }
        New-Item -ItemType Directory -Force -Path $Var | Out-Null
        foreach ($t in ($Tasks + $LegacyTasks)) { Unregister-ScheduledTask -TaskName $t -Confirm:$false -ErrorAction SilentlyContinue }
        Stop-Ours
        $common = @{ LC_ALL = 'C'; AIWATCH_SERVICE = '1' }
        $collector = Write-Runner 'collector' $phpExe "'bin\collect.php'" $common
        $panel = Write-Runner 'panel' $phpExe "-S 127.0.0.1:$Port -t '$Dir' '$Dir\router.php'" $common
        Register-Task 'SecAIQ-Watch-Collector' $collector
        Register-Task 'SecAIQ-Watch-Panel' $panel
        Write-Host 'Installed and started: SecAIQ-Watch-Collector, SecAIQ-Watch-Panel'
        Write-Host "Panel: http://127.0.0.1:$Port/"
        Write-Host 'Windows shows processes, destinations and configuration audits; it has no per-connection byte counters or open-file view.'
    }
    'uninstall' {
        foreach ($t in ($Tasks + $LegacyTasks)) { Unregister-ScheduledTask -TaskName $t -Confirm:$false -ErrorAction SilentlyContinue }
        Stop-Ours
        Write-Host 'Removed both tasks (you can still run the collector by hand: php bin\collect.php)'
    }
    'status' {
        foreach ($t in $Tasks) {
            Write-Host "${t}:"
            $task = Get-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue
            if ($task) {
                $info = Get-ScheduledTaskInfo -TaskName $t
                Write-Host "  state = $($task.State), last run = $($info.LastRunTime), last result = $($info.LastTaskResult)"
            } else {
                Write-Host '  not installed (bin\install-agent.ps1 install)'
            }
        }
    }
}
