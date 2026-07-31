# Build an uploadable release ZIP.
#
# The distributed archive must contain vendor/, because the target hosts have
# no Composer. It must NOT contain config.php, tests, or the tooling: config
# because it holds secrets, the rest because it is dead weight on a live site.
#
# Run "composer install --no-dev" first.
#
#   powershell -ExecutionPolicy Bypass -File tools\build-release.ps1
#
# Kept deliberately ASCII-only: Windows PowerShell 5.1 reads .ps1 as ANSI
# unless the file has a BOM, and a stray em dash becomes a parse error.

$ErrorActionPreference = 'Stop'

$root = Split-Path $PSScriptRoot -Parent
$dist = Join-Path $root 'dist'
$stage = Join-Path $dist 'video-portal'

$php = $env:PORTAL_PHP
if (-not $php) { $php = (Get-Command php -ErrorAction SilentlyContinue).Source }
if (-not $php) {
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
           Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) { Write-Error "No php.exe found. Set PORTAL_PHP." }

# Several PHP builds can be on PATH, and the Windows Store alias in particular
# resolves to one with no php.ini and therefore no zip extension. Packaging
# then fails with a message about the extension rather than about which PHP was
# picked, so name it up front.
$modules = & $php -m
if ($modules -notcontains 'zip') {
    Write-Error "The PHP at '$php' has no zip extension, so it cannot package a release. Set PORTAL_PHP to one that does."
}
Write-Host "Using PHP: $php" -ForegroundColor DarkGray

if (-not (Test-Path (Join-Path $root 'vendor\autoload.php'))) {
    Write-Error "vendor/ is missing. Run 'composer install --no-dev' first."
}

Write-Host "Staging..." -ForegroundColor Cyan

if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
New-Item -ItemType Directory -Force -Path $stage | Out-Null

# Everything the running application needs, and nothing else.
$include = @(
    'core', 'public', 'themes', 'plugins', 'storage', 'vendor',
    'composer.json', '.htaccess', 'README.md'
)

foreach ($item in $include) {
    $source = Join-Path $root $item
    if (Test-Path $source) {
        Copy-Item $source -Destination $stage -Recurse -Force
    }
}

# Never ship a config, and never ship a lock file from a previous attempt.
foreach ($unwanted in @('config.php', 'public\install.php.installed')) {
    $path = Join-Path $stage $unwanted
    if (Test-Path $path) { Remove-Item $path -Force }
}

# Runtime directories must exist and be empty.
foreach ($dir in @('storage\cache', 'storage\logs', 'storage\tmp', 'public\uploads')) {
    $path = Join-Path $stage $dir
    if (Test-Path $path) {
        Get-ChildItem $path -Recurse -Force -File |
            Where-Object { $_.Name -ne '.htaccess' -and $_.Name -ne '.gitkeep' } |
            Remove-Item -Force
    } else {
        New-Item -ItemType Directory -Force -Path $path | Out-Null
    }
}

# Dev dependencies are excluded by running "composer install --no-dev" BEFORE
# this script, not by deleting vendor subdirectories. Composer generates a
# classmap and a static autoloader that still reference whatever was installed;
# removing the folders by hand leaves the autoloader requiring files that are
# gone, and the application fatals on its first require.
$installed = Join-Path $stage 'vendor\composer\installed.json'
if (-not (Test-Path $installed)) {
    Write-Error "Staged vendor/ has no composer metadata. Run: composer install --no-dev"
}

$meta = Get-Content $installed -Raw | ConvertFrom-Json

if ($meta.dev) {
    Write-Error "Staged vendor/ was installed WITH dev dependencies. Run: composer install --no-dev"
}

# composer install --no-dev rewrites the autoloader but leaves the old dev
# package folders on disk. They are now unreferenced, so pruning them is safe
# and drops roughly 3 MB from the archive. The set is computed from Composer's
# own metadata rather than a hand-written list, so a new dependency is never
# accidentally deleted.
$keep = New-Object System.Collections.Generic.HashSet[string]
foreach ($package in $meta.packages) {
    $vendorName = ($package.name -split '/')[0]
    [void]$keep.Add($vendorName.ToLower())
}
[void]$keep.Add('composer')

$pruned = 0
Get-ChildItem (Join-Path $stage 'vendor') -Directory | ForEach-Object {
    if (-not $keep.Contains($_.Name.ToLower())) {
        Remove-Item $_.FullName -Recurse -Force
        $pruned++
    }
}

if ($pruned -gt 0) {
    Write-Host "  Pruned $pruned unreferenced vendor folder(s) left behind by --no-dev."
}

# Empty directories are noise in an archive and, worse, they are exactly the
# entries that arrive with awkward permissions. The runtime directories are
# kept because the app needs them to exist; they carry a .htaccess or .gitkeep
# and so are never empty.
$removedEmpty = 0
do {
    $empty = Get-ChildItem $stage -Directory -Recurse |
        Where-Object { (Get-ChildItem $_.FullName -Force | Measure-Object).Count -eq 0 }
    foreach ($dir in $empty) {
        Remove-Item $dir.FullName -Force
        $removedEmpty++
    }
} while ($empty.Count -gt 0)

if ($removedEmpty -gt 0) {
    Write-Host "  Removed $removedEmpty empty director(y|ies)."
}

Write-Host "Verifying staged tree parses..." -ForegroundColor Cyan

$failed = 0
Get-ChildItem $stage -Filter *.php -Recurse |
    Where-Object { $_.FullName -notmatch '\\vendor\\' } |
    ForEach-Object {
        & $php -l $_.FullName *> $null
        if ($LASTEXITCODE -ne 0) {
            $failed++
            Write-Host "  FAIL $($_.FullName.Replace($stage,''))" -ForegroundColor Red
        }
    }

if ($failed -gt 0) { Write-Error "$failed file(s) in the staged tree do not parse." }

Write-Host "Checking .htaccess files..." -ForegroundColor Cyan

# A directive that is illegal in .htaccess context makes Apache abort EVERY
# request in that directory. The site is completely dead, and the only clue is
# a line in a log the user may not know how to reach. None of this shows up in
# php -l or in any test, so it is checked here.
#
# Found the hard way: a <Directory> block in public/.htaccess took down a real
# DreamHost deployment on the first request.
$problems = @()

Get-ChildItem $stage -Filter '.htaccess' -Recurse -Force | ForEach-Object {
    $label = $_.FullName.Replace($stage, '')
    $lines = @(Get-Content $_.FullName)
    $guardDepth = 0

    for ($i = 0; $i -lt $lines.Count; $i++) {
        $line = $lines[$i].Trim()

        if ($line -match '^<IfModule') { $guardDepth++ }
        if ($line -match '^</IfModule>') { $guardDepth-- }

        # Server-config-only containers. Always fatal in .htaccess.
        if ($line -match '^<(Directory|DirectoryMatch|VirtualHost|Location|LocationMatch)') {
            $problems += "$label line $($i+1): $line  [server config only]"
        }

        # PHP directives outside a guard break every non-mod_php host, which is
        # most shared hosting now that FastCGI and FPM are the norm.
        if (($line -match '^php_flag') -or ($line -match '^php_value') -or
            ($line -match '^php_admin_flag') -or ($line -match '^php_admin_value')) {
            if ($guardDepth -le 0) {
                $problems += "$label line $($i+1): $line  [needs an IfModule guard]"
            }
        }
    }
}

if ($problems.Count -gt 0) {
    foreach ($problem in $problems) { Write-Host "  $problem" -ForegroundColor Red }
    Write-Error "$($problems.Count) .htaccess problem(s) would break the site on a real host."
}

Write-Host "  All .htaccess directives are valid in that context."

# Sanity checks on what a fresh install actually needs.
$mustExist = @(
    'public\index.php', 'public\install.php', 'public\.htaccess',
    'core\bootstrap.php', 'core\migrations\0001_core.sql',
    'themes\default\theme.json', 'themes\default\assets\theme.css',
    'vendor\autoload.php'
)
foreach ($required in $mustExist) {
    if (-not (Test-Path (Join-Path $stage $required))) {
        Write-Error "Staged tree is missing $required"
    }
}

$mustNotExist = @('config.php', 'tests', 'tools', '.git', 'phpunit.xml.dist')
foreach ($forbidden in $mustNotExist) {
    if (Test-Path (Join-Path $stage $forbidden)) {
        Write-Error "Staged tree wrongly contains $forbidden"
    }
}

Write-Host "Compressing..." -ForegroundColor Cyan

# Read the version by parsing bootstrap.php, not by executing it. Executing it
# loads the root autoloader, which makes the build depend on whatever state
# vendor/ happens to be in — and the build is frequently run right after a
# --no-dev install has churned exactly that. The version is a literal in a
# define(); a regex is both sufficient and immune.
$bootstrapText = Get-Content (Join-Path $root 'core\bootstrap.php') -Raw
if ($bootstrapText -notmatch "define\('PORTAL_VERSION',\s*'([^']+)'\)") {
    Write-Error "Could not read PORTAL_VERSION from core/bootstrap.php"
}
$version = $Matches[1]

$zip = Join-Path $dist "video-portal-$version.zip"

# NOT Compress-Archive. That cmdlet stores no Unix mode, so extracting on Linux
# produces directories without the write bit and the person who just uploaded
# their site cannot delete what they extracted. tools/package.php writes an
# explicit mode on every entry and verifies them by reading the archive back.
& $php (Join-Path $PSScriptRoot 'package.php') $stage $zip
if ($LASTEXITCODE -ne 0) {
    Write-Error "Packaging failed."
}

$sizeMb = [math]::Round((Get-Item $zip).Length / 1MB, 1)
$fileCount = (Get-ChildItem $stage -Recurse -File).Count

Write-Host ""
Write-Host "Built $zip" -ForegroundColor Green
Write-Host "  $fileCount files, $sizeMb MB"
Write-Host ""
Write-Host "Upload the CONTENTS of this archive to your host, then open /install.php"
