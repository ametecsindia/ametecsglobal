# AmetecsGlobal sync. Run through SYNC.bat (one click) or AUTO-SYNC.bat (automatic).
#
#   1. PLUGINS   plugins/<app> is the master copy, edited here; the original app folders are never read (17 Nov 2026).
#                The AmetecsGlobal fixes are re-applied if missing and the hooks are checked before anything is pushed.
#   2. GITHUB    this folder <-> the AmetecsGlobal repository (sync-apps.json "git"): local changes are
#                committed, GitHub's changes are merged in, everything is pushed. A guard refuses to push
#                passwords, client data or keys (the repository is public).
#
# Writes ONLY inside this folder and to the AmetecsGlobal repository. No other folder, repository or setting.
param([string]$Only = '', [switch]$NoGit)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$cfg = Get-Content -Raw -Path (Join-Path $root 'sync-apps.json') | ConvertFrom-Json
$utf8 = New-Object System.Text.UTF8Encoding $false
$log = Join-Path $root 'sync-log.txt'
function Say($m) { Write-Host $m; Add-Content -Path $log -Value ("{0}  {1}" -f (Get-Date -Format 'dd-MM-yyyy HH:mm:ss'), $m) }
$failed = 0

# One sync at a time (a click and the automatic sync never run together).
$mutex = New-Object System.Threading.Mutex($false, 'AmetecsGlobalSync')
if (-not $mutex.WaitOne([TimeSpan]::FromMinutes(5))) { Say 'Another sync is still running - skipped.'; exit 1 }

try {
# NO COPY FROM THE SOURCE APPS (owner, 17 Nov 2026): plugins/<app> inside AmetecsGlobal IS the master copy and is
# edited here. The original folders (C:\laragon\www\SmartPRS3 etc.) are never read or touched again. Plugins will take
# their own releases through "Check for updates" (under each plugin's licence information) from that plugin's central.
# Each sync still re-applies the AmetecsGlobal fixes (they are skipped when already in place - they matter again after
# a plugin update replaces files) and refuses to push if a plugin lost its AmetecsGlobal hooks.
foreach ($app in $cfg.apps) {
  if ($Only -and $app.name -ne $Only) { continue }
  try {
    $dst = [IO.Path]::GetFullPath((Join-Path $root $app.target)).TrimEnd('\','/')
    if (-not (Test-Path -LiteralPath $dst)) { continue }
    foreach ($fx in $app.fixes) {
      foreach ($rel in $fx.files) {
        $f = Join-Path $dst $rel
        if (-not (Test-Path $f)) { continue }
        $text = [IO.File]::ReadAllText($f)
        if (-not $fx.regex -and $text.Contains($fx.replace)) { continue }   # already applied: never apply twice
        $new = if ($fx.regex) { [regex]::Replace($text, $fx.find, $fx.replace) } else { $text.Replace($fx.find, $fx.replace) }
        if ($new -ne $text) { [IO.File]::WriteAllText($f, $new, $utf8); Say "  $($app.name): re-applied the AmetecsGlobal fix in $rel" }
      }
    }
    foreach ($chk in $app.mustNotContain) {
      foreach ($rel in $chk.files) {
        $f = Join-Path $dst $rel
        if ((Test-Path $f) -and ([regex]::IsMatch([IO.File]::ReadAllText($f), $chk.pattern))) { throw "$rel points at the original app's database - NOT safe. Tell Claude." }
      }
    }
    foreach ($chk in $app.mustContain) {
      foreach ($rel in $chk.files) {
        $f = Join-Path $dst $rel
        if (-not (Test-Path $f) -or -not [IO.File]::ReadAllText($f).Contains($chk.text)) { throw "$rel is missing the AmetecsGlobal hook ($($chk.text)). Tell Claude." }
      }
    }
  } catch { $failed++; Say "  FAILED $($app.name): $($_.Exception.Message)" }
}

# ------------------------------------------------------------------ GitHub
if (-not $NoGit -and $cfg.git -and $cfg.git.remote -and $failed -gt 0) {
  Say '== GitHub: skipped this time because an app above failed - a half-done copy is never pushed.'
} elseif (-not $NoGit -and $cfg.git -and $cfg.git.remote) {
  try {
    $git = (Get-Command git -ErrorAction SilentlyContinue).Source
    foreach ($p in @('C:\Program Files\Git\cmd\git.exe', 'C:\laragon\bin\git\cmd\git.exe', 'C:\laragon\bin\git\bin\git.exe')) { if (-not $git -and (Test-Path $p)) { $git = $p } }
    if (-not $git) { throw 'git is not installed on this computer.' }
    $branch = if ($cfg.git.branch) { $cfg.git.branch } else { 'main' }
    # Git writes normal progress ("To https://...", line-ending notes) to stderr; Windows PowerShell would treat that as
    # an error, so only git's exit code decides success.
    function G { $ErrorActionPreference = 'Continue'; $out = & $git -C $root @args 2>&1 | ForEach-Object { "$_" }; $script:gx = $LASTEXITCODE; return ($out -join "`n").Trim() }
    Say "== GitHub: $($cfg.git.remote) ($branch)"

    if (-not (Test-Path (Join-Path $root '.git'))) {
      if (-not (Test-Path (Join-Path $root '.gitignore'))) { throw '.gitignore is missing - refusing to start (it keeps passwords and client data off GitHub).' }
      G init -b $branch | Out-Null
      G remote add origin $cfg.git.remote | Out-Null
      Say '  started the local repository'
    }
    # Settings live in THIS folder's .git only - nothing on the computer outside it.
    G config user.name 'AmetecsGlobal Auto-Sync' | Out-Null
    G config user.email 'autosync@ametecsglobal.local' | Out-Null
    G config core.longpaths true | Out-Null
    if ((G remote get-url origin) -ne $cfg.git.remote) { G remote set-url origin $cfg.git.remote | Out-Null }

    # 1. Local changes -> one commit. The guard runs on exactly what is about to be committed.
    G add -A | Out-Null
    $staged = @((G diff --cached --name-only) -split "`r?`n" | Where-Object { $_ })
    $bad = @($staged | Where-Object { $_ -match $cfg.git.neverPush })
    if ($bad.Count -gt 0) { G reset -q | Out-Null; throw ('refusing to upload private files: ' + (($bad | Select-Object -First 5) -join ', ') + ' - check .gitignore, tell Claude.') }
    if ($staged.Count -gt 0) {
      G commit -q -m ("Auto-sync from {0}: {1} file(s)" -f $env:COMPUTERNAME, $staged.Count) | Out-Null
      if ($gx -ne 0) { throw 'commit failed' }
      Say "  saved $($staged.Count) local change(s)"
    }

    # 2. GitHub's changes -> merged in here. Both sides changed the same lines = stop, change nothing, ask.
    $fetch = G fetch origin
    if ($gx -ne 0) { throw "cannot reach GitHub: $fetch" }
    $remoteHas = (G ls-remote --heads origin $branch) -ne ''
    $hasLocal = (G rev-parse --verify -q HEAD) -ne ''
    if ($remoteHas -and -not $hasLocal) {
      G checkout -q -B $branch "origin/$branch" | Out-Null
      Say '  took the repository from GitHub'
    } elseif ($remoteHas) {
      $behind = [int](G rev-list --count "HEAD..origin/$branch")
      if ($behind -gt 0) {
        $m = G merge --no-edit --allow-unrelated-histories "origin/$branch"
        if ($gx -ne 0) {
          $conf = (G diff --name-only --diff-filter=U) -replace "`r?`n", ', '
          G merge --abort | Out-Null
          throw ("the same file was changed here and on GitHub ($conf). Nothing was overwritten. Tell Claude.")
        }
        Say "  brought in $behind change(s) from GitHub"
      }
    }

    # 3. Push.
    if ((G rev-parse --verify -q HEAD) -ne '') {
      $ahead = if ($remoteHas) { [int](G rev-list --count "origin/$branch..HEAD") } else { 1 }
      if ($ahead -gt 0) {
        $p = G push -u origin "HEAD:$branch"
        if ($gx -ne 0) { throw "push to GitHub failed: $p" }
        Say '  pushed to GitHub'
      }
    }
    Say '   OK  GitHub in step'
  } catch { $failed++; Say "  FAILED GitHub: $($_.Exception.Message)" }
}

# ------------------------------------------------------------------ every plugin runnable on THIS PC
# A plugin that arrived through GitHub (tested on the other PC) has its code but not this PC's own parts: its .env,
# its PHP libraries (vendor) and its database. Each sync makes every plugin folder runnable, whichever PC brought it in:
#   .env from .env.example once; composer install whenever composer.lock changes; the app's "install" commands once
#   (creates the database and its accounts); the database step below then builds its tables.
$phpExe = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $phpExe) { $phpExe = @(Get-ChildItem 'C:\laragon\bin\php\*\php.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1).FullName }
$composer = (Get-Command composer -ErrorAction SilentlyContinue).Source
if (-not $composer -and (Test-Path 'C:\laragon\bin\composer\composer.phar')) { $composer = 'C:\laragon\bin\composer\composer.phar' }
function Run-In([string]$dir, [string]$exe, [string[]]$argv) {
  $ErrorActionPreference = 'Continue'
  Push-Location $dir
  $out = if ($exe -like '*.phar') { & $phpExe $exe @argv 2>&1 | ForEach-Object { "$_" } } else { & $exe @argv 2>&1 | ForEach-Object { "$_" } }
  $code = $LASTEXITCODE
  Pop-Location
  $ErrorActionPreference = 'Stop'
  if ($code -ne 0) { throw ("'" + ($argv -join ' ') + "' failed: " + (($out | Select-Object -Last 3) -join ' ')) }
}
foreach ($app in $cfg.apps) {
  if ($Only -and $app.name -ne $Only) { continue }
  $dir = [IO.Path]::GetFullPath((Join-Path $root $app.target))
  if (-not (Test-Path -LiteralPath $dir)) { continue }
  $tag = ($app.name -replace '[^A-Za-z0-9]', '')
  $dataDir = Join-Path $root 'core/data'
  New-Item -ItemType Directory -Force -Path $dataDir | Out-Null
  try {
    $env = Join-Path $dir '.env'; $ex = Join-Path $dir '.env.example'
    if (-not (Test-Path $env) -and (Test-Path $ex)) { Copy-Item $ex $env; Say "== $($app.name): made this PC's .env from .env.example" }
    $lock = Join-Path $dir 'composer.lock'
    if (Test-Path $lock) {
      $want = (Get-FileHash -LiteralPath $lock -Algorithm SHA256).Hash
      $seenF = Join-Path $dataDir "composer-$tag.txt"
      $have = if (Test-Path $seenF) { [IO.File]::ReadAllText($seenF).Trim() } else { '' }
      if ($want -ne $have -or -not (Test-Path (Join-Path $dir 'vendor/autoload.php'))) {
        if (-not $composer -or -not $phpExe) { throw 'Composer / PHP not found (start Laragon) - its PHP libraries could not be installed.' }
        Say "== $($app.name): installing its PHP libraries on this PC (composer install)"
        Run-In $dir $composer @('install', '--no-interaction', '--no-progress')
        [IO.File]::WriteAllText($seenF, $want, $utf8)
        Say "   OK  $($app.name) libraries installed"
      }
    }
    $doneF = Join-Path $dataDir "installed-$tag.txt"
    if ($app.install -and -not (Test-Path $doneF)) {
      if (-not $phpExe) { throw 'PHP was not found (start Laragon), so the plugin could not be set up on this PC.' }
      Say "== $($app.name): first time on this PC - setting up its database"
      foreach ($cmd in $app.install) { Run-In $dir $phpExe @($cmd -split ' ') }
      [IO.File]::WriteAllText($doneF, (Get-Date -Format s), $utf8)
      Say "   OK  $($app.name) set up on this PC"
    }
  } catch { $failed++; Say "  FAILED $($app.name) setup on this PC: $($_.Exception.Message)" }
}

# ------------------------------------------------------------------ this PC's own database
# Code reaches every PC through GitHub, but each PC has its own database. When an app's database changes
# (new migration files) arrive, this PC's database is brought up to date with the app's own tools.
foreach ($app in $cfg.apps) {
  if (-not $app.database -or ($Only -and $app.name -ne $Only)) { continue }
  try {
    $dir = [IO.Path]::GetFullPath((Join-Path $root $app.target))
    $migDir = Join-Path $dir $app.database.migrations
    if (-not (Test-Path $migDir) -or -not (Test-Path (Join-Path $dir '.env'))) { continue }
    $now = (@(Get-ChildItem -LiteralPath $migDir -File | ForEach-Object { $_.Name } | Sort-Object) -join "`n")
    $seenFile = Join-Path (Join-Path $root 'core/data') ("db-" + ($app.name -replace '[^A-Za-z0-9]', '') + '.txt')
    $seen = if (Test-Path $seenFile) { [IO.File]::ReadAllText($seenFile) } else { '' }
    if ($now -eq $seen) { continue }
    $php = (Get-Command php -ErrorAction SilentlyContinue).Source
    if (-not $php) { $php = @(Get-ChildItem 'C:\laragon\bin\php\*\php.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1).FullName }
    if (-not $php) { throw 'PHP was not found (start Laragon), so the database could not be updated.' }
    Say "== $($app.name): database changes arrived - updating this PC's database"
    foreach ($cmd in $app.database.commands) {
      $ErrorActionPreference = 'Continue'
      Push-Location $dir
      $out = & $php @($cmd -split ' ') 2>&1 | ForEach-Object { "$_" }
      $code = $LASTEXITCODE
      Pop-Location
      $ErrorActionPreference = 'Stop'
      if ($code -ne 0) { throw ("'php $cmd' failed: " + (($out | Select-Object -Last 3) -join ' ')) }
    }
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $seenFile) | Out-Null
    [IO.File]::WriteAllText($seenFile, $now, $utf8)
    Say "   OK  $($app.name) database up to date"
  } catch { $failed++; Say "  FAILED $($app.name) database: $($_.Exception.Message)" }
}
} finally { $mutex.ReleaseMutex() }

if ($failed) { Say "Sync finished with $failed failure(s)."; exit 1 } else { Say "Sync finished. All OK."; exit 0 }
