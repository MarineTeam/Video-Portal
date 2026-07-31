# Build an uploadable release ZIP.
#
# The distributed archive must contain vendor/, because the target hosts have
# no Composer. It must NOT contain config.php, tests, or the tooling — config
# because it holds secrets, the rest because it is dead weight on a live site.
#
#   pwsh tools\build-release.ps1

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

# vendor/ is the whole point of shipping a ZIP — refuse to build without it.
if (-not (Test-Path (Join-Path $root 'vendor\autoload.php'))) {
    Write-Error "vendor/ is missing. Run 'composer install --no-dev' first."
}

Write-Host "Staging..." -ForegroundColor Cyan

if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
New-Item -ItemType Directory -Force -Path $stage | Out-Null

# Everything the running application needs, and nothing else.
$include = @(
    'core',
    'public',
    'themes',
    'plugins',
    'storage',
    'vendor',
    'composer.json',
    '.htaccess',
    'README.md'
)

foreach ($item in $include) {
    $source = Join-Path $root $item
    if (Test-Path $source) {
        Copy-Item $source -Destination $stage -Recurse -Force
    }
}

# Belt and braces: never ship a config, and never ship a stale lock file left
# by a previous install attempt.
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

# Dev packages are excluded by running `composer install --no-dev` before this
# script, NOT by deleting directories here. Composer generates a classmap and
# a static autoloader that still reference whatever was installed; removing the
# folders by hand leaves the autoloader requiring files that no longer exist,
# and the whole application fatals on its first require. Learned the hard way.
if (Select-String -Path (Join-Path $stage 'vendor\composer\installed.json') `
                  -Pattern '"name": "phpunit/phpunit"' -Quiet -ErrorAction SilentlyContinue) {
    Write-Error @"
The staged vendor/ still contains dev dependencies.
Run this first, then re-run the build:
    composer install --no-dev
"@
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

# Sanity checks on what a fresh install actually needs.
$mustExist = @(
    'public\index.php',
    'public\install.php',
    'public\.htaccess',
    'core\bootstrap.php',
    'core\migrations\0001_core.sql',
    'themes\default\theme.json',
    'themes\default\assets\theme.css',
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

$version = (& $php -r "require '$($root -replace '\\','/')/core/bootstrap.php'; echo PORTAL_VERSION;")
$zip = Join-Path $dist "video-portal-$version.zip"

Compress-Archive -Path (Join-Path $stage '*') -DestinationPath $zip -Force

$sizeMb = [math]::Round((Get-Item $zip).Length / 1MB, 1)
$fileCount = (Get-ChildItem $stage -Recurse -File).Count

Write-Host ""
Write-Host "Built $zip" -ForegroundColor Green
Write-Host "  $fileCount files, $sizeMb MB"
Write-Host ""
Write-Host "Upload the CONTENTS of this archive to your host, then open /install.php"
