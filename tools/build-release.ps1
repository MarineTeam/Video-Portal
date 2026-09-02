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

# Install production dependencies here rather than trusting whoever ran the
# script to have done it. The failure this prevents is subtle: a vendor/ left
# over from a dev install has an autoloader referencing dev packages, and any
# attempt to reconcile that by hand ships an archive that fatals on its first
# require. Composer owns vendor/; let it own vendor/.
$composer = $env:PORTAL_COMPOSER
if (-not $composer) {
    $candidate = Join-Path $env:LOCALAPPDATA 'Composer\composer.phar'
    if (Test-Path $candidate) { $composer = $candidate }
}
if (-not $composer) {
    $composer = (Get-Command composer -ErrorAction SilentlyContinue).Source
}

if ($composer) {
    Write-Host "Installing production dependencies..." -ForegroundColor Cyan

    Push-Location $root
    try {
        # Retried once: OneDrive and Windows Search hold handles on files inside
        # vendor/ while they index them, and Composer's removal step fails with
        # "Could not delete". It is transient, and a second attempt a moment
        # later almost always succeeds.
        foreach ($attempt in 1..2) {
            if ($composer -like '*.phar') {
                & $php $composer install --no-dev --optimize-autoloader --no-interaction
            } else {
                & $composer install --no-dev --optimize-autoloader --no-interaction
            }

            if ($LASTEXITCODE -eq 0) { break }

            if ($attempt -eq 1) {
                Write-Host "  Retrying (a file was locked)..." -ForegroundColor Yellow
                Start-Sleep -Seconds 3
            } else {
                Write-Error "composer install failed. If a file is locked, pause OneDrive syncing and retry."
            }
        }
    } finally {
        Pop-Location
    }
} else {
    Write-Warning "Composer not found. Using vendor/ as-is; run 'composer install --no-dev --optimize-autoloader' yourself."
}

if (-not (Test-Path (Join-Path $root 'vendor\autoload.php'))) {
    Write-Error "vendor/ is missing. Run 'composer install --no-dev --optimize-autoloader' first."
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
    Write-Error "Staged vendor/ was installed WITH dev dependencies. Run: composer install --no-dev --optimize-autoloader"
}

# Nothing is pruned from vendor/ by hand any more.
#
# An earlier version deleted folders that installed.json no longer listed,
# reasoning they were leftovers from a --no-dev install. They were - but
# Composer's generated autoloader still referenced them, because
# autoload_files.php and autoload_static.php are only rewritten when Composer
# itself regenerates them. The shipped archive then fataled on its very first
# require, on a live host, before a single line of application code ran:
#
#   Failed opening required '.../myclabs/deep-copy/src/DeepCopy/deep_copy.php'
#
# vendor/ is Composer's to manage. The correct way to exclude dev packages is
# to let Composer do it and regenerate the autoloader to match, which the boot
# check below now proves actually happened.

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

Write-Host "Booting the staged tree..." -ForegroundColor Cyan

# php -l checks each file in isolation and never follows a require. It cannot
# see a Composer autoloader pointing at a package that is not there, which is
# exactly the failure that reached a live host: every file parsed cleanly and
# the archive fataled on its first require.
#
# So: actually load it. bootstrap.php requires vendor/autoload.php, which
# executes autoload_real.php and every "files" entry - the precise code that
# broke.
$bootProbe = Join-Path $env:TEMP "portal-boot-check-$(Get-Random).php"
@"
<?php
require '$(($stage -replace '\\','/'))/core/bootstrap.php';
if (!PORTAL_HAS_VENDOR) { fwrite(STDERR, "vendor/autoload.php was not found\n"); exit(1); }
foreach ([
    'Portal\\Config', 'Portal\\Db', 'Portal\\App',
    'Portal\\Install\\Installer', 'Portal\\Themes\\ThemeManager',
    'Firebase\\JWT\\JWT', 'PHPMailer\\PHPMailer\\PHPMailer',
] as `$class) {
    if (!class_exists(`$class)) { fwrite(STDERR, "missing class: `$class\n"); exit(1); }
}
echo 'boot ok';
"@ | Set-Content $bootProbe -Encoding UTF8

$bootOutput = & $php $bootProbe 2>&1 | Out-String
$bootCode = $LASTEXITCODE
Remove-Item $bootProbe -Force -ErrorAction SilentlyContinue

if ($bootCode -ne 0 -or $bootOutput -notmatch 'boot ok') {
    Write-Host $bootOutput.Trim() -ForegroundColor Red
    Write-Error "The staged tree does not boot. It would fatal on the first request."
}

Write-Host "  Autoloader loads and every expected class resolves."

Write-Host "Resolving every class..." -ForegroundColor Cyan

# The boot check above loads a handful of representative classes. This loads
# ALL of them, which is what surfaces an inheritance error in a class no test
# happens to instantiate - the failure mode that put a fatal on /admin.
& $php (Join-Path $PSScriptRoot 'load-all.php')
if ($LASTEXITCODE -ne 0) {
    Write-Error "Not every class resolves. The release would fatal on the routes that use them."
}

Write-Host "Resolving every class REFERENCE..." -ForegroundColor Cyan

# The step above loads each class. This one reads inside the method bodies.
#
# A class named without an import resolves against the CURRENT namespace, so
# `DownloadPolicy::allows()` written in a controller means
# Portal\Controllers\DownloadPolicy - which does not exist, and which PHP does
# not complain about until that line runs. php -l parses without resolving;
# load-all.php resolves the class without executing it. Both pass.
#
# Worse for instanceof: a missing import there is not an error at all. The
# comparison is silently always false and the code takes its fallback path
# forever.
#
# Run against the SOURCE rather than the stage, because the stage has no tests
# directory and the check wants the whole tree.
& $php (Join-Path $PSScriptRoot 'check-imports.php')
if ($LASTEXITCODE -ne 0) {
    Write-Error "A class reference does not resolve. It is a fatal the moment that line runs."
}

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
