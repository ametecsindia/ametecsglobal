# AmetecsGlobal sync. Run through SYNC.bat (one click) or AUTO-SYNC.bat (automatic).
#
#   1. APPS IN   every app in sync-apps.json is copied in from its own folder (that folder is ONLY read),
#                then the app's fixes are applied so the copy never uses the app's own database or folders.
#   2. GITHUB    this folder <-> the AmetecsGlobal repository (sync-apps.json "git"): local changes are
#                committed, GitHub's changes are merged in, everything is pushed. A guard refuses to push
#                passwords, client data or keys (the repository is public).
#
# Writes ONLY inside this folder and to the AmetecsGlobal repository. No other folder, repository or setting.
# ponytail: copies new/changed files only and never deletes here (no /PURGE), so AmetecsGlobal-only files survive.
param([string]$Only = '', [switch]$NoCopy, [switch]$NoGit)
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

# Copy new or changed files (robocopy on Windows; this plain copy where robocopy is missing). Never deletes.
function Copy-Newer([string]$from, [string]$to, $skipFolders, $skipFiles) {
  if (Get-Command robocopy -ErrorAction SilentlyContinue) {
    $xd = @($skipFolders | ForEach-Object { Join-Path $from $_ })
    # /XO: a file already newer here (an applied fix, or a change pulled from GitHub) is kept until the app changes it again.
    $rc = @($from, $to, '/E', '/XO', '/R:1', '/W:1', '/NFL', '/NDL', '/NP', '/NJH', '/NJS', '/XD') + $xd + @('/XF') + @($skipFiles)
    & robocopy @rc | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "copy failed (robocopy code $LASTEXITCODE)" }
    return [bool]($LASTEXITCODE -band 1)
  }
  $skipD = @($skipFolders | ForEach-Object { ($_ -replace '[\\/]', '/').TrimEnd('/') + '/' })
  $n = 0
  foreach ($f in Get-ChildItem -LiteralPath $from -Recurse -File -Force) {
    $rel = $f.FullName.Substring($from.Length).TrimStart('\', '/') -replace '\\', '/'
    if (@($skipD | Where-Object { $rel.StartsWith($_, 'OrdinalIgnoreCase') }).Count -or @($skipFiles | Where-Object { $f.Name -like $_ }).Count) { continue }
    $t = Join-Path $to $rel
    if (-not (Test-Path -LiteralPath $t) -or (Get-Item -LiteralPath $t -Force).LastWriteTimeUtc -lt $f.LastWriteTimeUtc) {
      New-Item -ItemType Directory -Force -Path (Split-Path -Parent $t) | Out-Null
      Copy-Item -LiteralPath $f.FullName -Destination $t -Force; $n++
    }
  }
  return $n -gt 0
}

try {
# ------------------------------------------------------------------ one-time move to the plugin layout
# Until 17 Nov 2026 SmartPRS3's files sat in the AmetecsGlobal root and the core in global/. Now every app has its own
# folder (plugins/<app>) and the core is core/. Everything is MOVED inside this folder (its .env, storage and uploads
# included, so the database and logins carry on); only the files the sync re-creates from the app are refreshed.
$prs = @($cfg.apps | Where-Object { $_.target -eq 'plugins/smartprs3' })[0]
if ($prs -and (Test-Path (Join-Path $root 'surface')) -and (Test-Path (Join-Path (Join-Path $root 'public') 'app.php'))) {
  $to = Join-Path $root 'plugins/smartprs3'
  New-Item -ItemType Directory -Force -Path $to | Out-Null
  $names = @('.env', '.env.bak', '.env.example', 'env-local.txt', 'CLAUDE.md', 'README.md', 'composer.json', 'composer.lock', 'config', 'database',
    'deploy', 'deptrac.layers.yaml', 'deptrac.modules.yaml', 'deptrac.yaml', 'docs', 'laragon-setup.bat', 'make-client-package.bat', 'make-release.bat',
    'mobile', 'packs', 'phpstan.neon', 'public', 'src', 'storage', 'surface', 'tests', 'tools', 'vendor', 'node_modules', '.phpunit.cache')
  if (Test-Path $prs.source) { $names += @(Get-ChildItem -Force -LiteralPath $prs.source | Where-Object { $_.Name -notin @('.git', '.gitignore') } | ForEach-Object { $_.Name }) }
  $moved = 0
  foreach ($n in ($names | Select-Object -Unique)) {
    $from = Join-Path $root $n
    if ((Test-Path -LiteralPath $from) -and -not (Test-Path -LiteralPath (Join-Path $to $n))) { Move-Item -LiteralPath $from -Destination (Join-Path $to $n); $moved++ }
  }
  # The files the sync patches are taken fresh from the app on THIS pc (if it brings the app in), so they get the new paths.
  if (Test-Path $prs.source) { foreach ($fx in $prs.fixes) { foreach ($rel in $fx.files) { $f = Join-Path $to $rel; if (Test-Path $f) { Remove-Item -LiteralPath $f -Force } } } }
  Say "== moved SmartPRS3 ($moved items) into plugins/smartprs3"
}
$oldCore = Join-Path $root 'global'
if (Test-Path (Join-Path $oldCore 'super.php')) {
  $newData = Join-Path (Join-Path $root 'core') 'data'
  New-Item -ItemType Directory -Force -Path $newData | Out-Null
  if (Test-Path (Join-Path $oldCore 'data')) { foreach ($f in Get-ChildItem -Force -LiteralPath (Join-Path $oldCore 'data')) { if (-not (Test-Path (Join-Path $newData $f.Name))) { Move-Item -LiteralPath $f.FullName -Destination (Join-Path $newData $f.Name) } } }
  Remove-Item -LiteralPath $oldCore -Recurse -Force
  Say '== moved global/ (clients, licences, activity) into core/'
}

foreach ($app in $cfg.apps) {
  if ($Only -and $app.name -ne $Only) { continue }
  # THE PC THAT HAS THE APP'S FOLDER BRINGS IT IN. A PC without that folder gets the app through GitHub.
  if (-not (Test-Path -LiteralPath $app.source)) {
    Say "== $($app.name): its folder ($($app.source)) is not on this PC - this PC gets it through GitHub."
    continue
  }
  try {
    $src = (Resolve-Path $app.source).Path.TrimEnd('\','/')
    $dst = [IO.Path]::GetFullPath((Join-Path $root $app.target)).TrimEnd('\','/')
    $rootFull = [IO.Path]::GetFullPath($root).TrimEnd('\','/')
    # Safety: the target must be inside AmetecsGlobal, and source and target must not overlap.
    if (-not ($dst -eq $rootFull -or $dst.StartsWith($rootFull + [IO.Path]::DirectorySeparatorChar))) { throw "target '$dst' is outside AmetecsGlobal" }
    if ($dst.StartsWith($src, 'OrdinalIgnoreCase') -or $src.StartsWith($dst, 'OrdinalIgnoreCase')) { throw "source and target overlap ($src / $dst)" }
    Say "== $($app.name): $src  ->  $dst"

    if (-not $NoCopy) {
      New-Item -ItemType Directory -Force -Path $dst | Out-Null
      if (Copy-Newer $src $dst $app.skipFolders $app.skipFiles) { Say '  copied new or changed files' }
    }

    foreach ($fx in $app.fixes) {
      foreach ($rel in $fx.files) {
        $f = Join-Path $dst $rel
        if (-not (Test-Path $f)) { if (-not $fx.optional) { Say "  WARN  $rel not found" }; continue }
        $text = [IO.File]::ReadAllText($f)
        if (-not $fx.regex -and $text.Contains($fx.replace)) { continue }   # already applied: never apply twice
        $new = if ($fx.regex) { [regex]::Replace($text, $fx.find, $fx.replace) } else { $text.Replace($fx.find, $fx.replace) }
        if ($new -ne $text) { [IO.File]::WriteAllText($f, $new, $utf8); Say "  fixed $rel" }
        elseif (-not $fx.regex -and -not $fx.optional) { Say "  WARN  rule no longer matches in $rel : $($fx.find)" }
      }
    }

    if ($app.makeEnvFromExample) {
      $env = Join-Path $dst '.env'; $ex = Join-Path $dst '.env.example'
      if (-not (Test-Path $env) -and (Test-Path $ex)) { Copy-Item $ex $env; Say "  made .env from .env.example" }
    }

    # Last line of defence: refuse to finish if the copy still points at the source app.
    foreach ($chk in $app.mustNotContain) {
      foreach ($rel in $chk.files) {
        $f = Join-Path $dst $rel
        if ((Test-Path $f) -and ([regex]::IsMatch([IO.File]::ReadAllText($f), $chk.pattern))) { throw "$rel still points at the source app - NOT safe to run setup. Tell Claude." }
      }
    }
    foreach ($chk in $app.mustContain) {
      foreach ($rel in $chk.files) {
        $f = Join-Path $dst $rel
        if (-not (Test-Path $f) -or -not [IO.File]::ReadAllText($f).Contains($chk.text)) { throw "$rel is missing the AmetecsGlobal hook ($($chk.text)) - the app changed. Tell Claude." }
      }
    }
    Say "   OK  $($app.name) synced"
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
