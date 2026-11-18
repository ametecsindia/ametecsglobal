# AmetecsGlobal AUTOMATIC sync (started by AUTO-SYNC.bat; leave its window open or minimised).
#   - any saved change in AmetecsGlobal -> sync about a minute later (the original app folders are not watched or read)
#     (it waits for a minute with no further saves, so a half-finished edit is not pushed mid-way)
#   - every 5 minutes anyway, to pick up changes made on GitHub
param([int]$quietSeconds = 60, [int]$pollMinutes = 5)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$single = New-Object System.Threading.Mutex($false, 'AmetecsGlobalAutoSync')
if (-not $single.WaitOne(0)) { Write-Host 'AUTO-SYNC is already running in another window.'; Start-Sleep 5; exit }

$watched = @($root)
# Changes here never start a sync: git's own folder, logs, runtime data, libraries.
$ignore = '[\\/](\.git|storage|vendor|node_modules|core[\\/]data|public[\\/]uploads|Claude outputs|build|\.gradle|\.idea)([\\/]|$)|sync-log\.txt$|\.(log|tmp|swp)$|~$'
$i = 0
foreach ($dir in $watched) {
  $w = New-Object IO.FileSystemWatcher $dir
  $w.IncludeSubdirectories = $true
  $w.NotifyFilter = [IO.NotifyFilters]'FileName, DirectoryName, LastWrite, Size'
  foreach ($ev in 'Changed', 'Created', 'Deleted', 'Renamed') { Register-ObjectEvent $w $ev -SourceIdentifier "agw$i$ev" | Out-Null }
  $w.EnableRaisingEvents = $true
  $i++
}
function Drain { $hit = $false; foreach ($e in @(Get-Event -ErrorAction SilentlyContinue)) { if ($e.SourceIdentifier -like 'agw*') { if ($e.SourceEventArgs.FullPath -notmatch $ignore) { $hit = $true }; Remove-Event -EventIdentifier $e.EventIdentifier } }; return $hit }
function RunSync($why) {
  Write-Host ("`n{0}  sync ({1})" -f (Get-Date -Format 'HH:mm:ss'), $why) -ForegroundColor Cyan
  & (Get-Process -Id $PID).Path -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'sync.ps1')
  if ($LASTEXITCODE -ne 0) { Write-Host 'Sync reported a problem - see sync-log.txt.' -ForegroundColor Red }
  Drain | Out-Null   # the sync's own writes are not a reason to sync again
}

Write-Host "AmetecsGlobal AUTO-SYNC is on. Watching:" -ForegroundColor Green
$watched | ForEach-Object { Write-Host "  $_" }
Write-Host "Syncs about $quietSeconds s after changes stop, and every $pollMinutes min for GitHub. Close this window to stop."
RunSync 'start'
$lastChange = $null; $lastRun = Get-Date
while ($true) {
  Start-Sleep -Seconds 5
  if (Drain) { $lastChange = Get-Date }
  if ($lastChange -and ((Get-Date) - $lastChange).TotalSeconds -ge $quietSeconds) { RunSync 'files changed'; $lastChange = $null; $lastRun = Get-Date }
  elseif (((Get-Date) - $lastRun).TotalMinutes -ge $pollMinutes) { RunSync 'GitHub check'; $lastRun = Get-Date }
}
