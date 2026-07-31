# Publish the deployable branch.
#
# The development branches carry source only: vendor/ is gitignored, so a clone
# of them fatals on its first require. The release branch is the same source
# with production dependencies committed, so a host with no Composer can deploy
# by cloning once and running `git pull` thereafter.
#
#   powershell -ExecutionPolicy Bypass -File tools\release-branch.ps1
#   powershell -ExecutionPolicy Bypass -File tools\release-branch.ps1 -Push
#
# Safe to re-run, including after a failure part-way through. The branch is
# rebuilt from scratch every time rather than merged, so it is always exactly
# "current source + current vendor" with no chance of a package removed
# upstream surviving because nothing deleted it.
#
# ASCII-only by necessity: Windows PowerShell 5.1 reads .ps1 as ANSI unless the
# file carries a BOM, and a stray em dash in a comment corrupts on round trip.

param(
    [string] $Source = '',
    [string] $Branch = 'release/1.0',
    [switch] $Push
)

$ErrorActionPreference = 'Stop'

$root = Split-Path $PSScriptRoot -Parent
Push-Location $root

$startingBranch = (git rev-parse --abbrev-ref HEAD).Trim()

try {
    $php = $env:PORTAL_PHP
    if (-not $php) { $php = (Get-Command php -ErrorAction SilentlyContinue).Source }
    if (-not $php) {
        $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
               Select-Object -First 1 -ExpandProperty FullName
    }
    if (-not $php) { Write-Error "No php.exe found. Set PORTAL_PHP." }

    if ($Source -eq '') { $Source = $startingBranch }

    if ($Source -eq $Branch -or $startingBranch -eq 'release-tmp') {
        Write-Error "Run this from a development branch, not from a release branch."
    }

    # Releasing from a dirty tree would publish files that are in no commit, so
    # the branch would not correspond to anything reviewable.
    if ((git status --porcelain | Measure-Object -Line).Lines -ne 0) {
        Write-Error "Working tree is not clean. Commit or stash first."
    }

    Write-Host "Source branch: $Source" -ForegroundColor Cyan
    $sourceCommit = (git rev-parse --short HEAD).Trim()

    # --- production dependencies -------------------------------------------
    Write-Host "Installing production dependencies..." -ForegroundColor Cyan

    $composer = $env:PORTAL_COMPOSER
    if (-not $composer) {
        $candidate = Join-Path $env:LOCALAPPDATA 'Composer\composer.phar'
        if (Test-Path $candidate) { $composer = $candidate }
    }
    if (-not $composer) { $composer = (Get-Command composer -ErrorAction SilentlyContinue).Source }
    if (-not $composer) { Write-Error "Composer not found. Set PORTAL_COMPOSER." }

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
            Write-Error "composer install failed."
        }
    }

    # --- prove it works BEFORE publishing it -------------------------------
    Write-Host "Verifying..." -ForegroundColor Cyan

    & $php (Join-Path $PSScriptRoot 'load-all.php') | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Not every class resolves. Refusing to publish a release that would fatal."
    }
    Write-Host "  Every class resolves."

    $probe = Join-Path $env:TEMP "portal-release-check-$(Get-Random).php"
    @"
<?php
require '$(($root -replace '\\','/'))/core/bootstrap.php';
if (!PORTAL_HAS_VENDOR) { fwrite(STDERR, "vendor missing\n"); exit(1); }
foreach (['Portal\\App', 'Portal\\Install\\Installer', 'Firebase\\JWT\\JWT', 'PHPMailer\\PHPMailer\\PHPMailer'] as `$c) {
    if (!class_exists(`$c)) { fwrite(STDERR, "missing: `$c\n"); exit(1); }
}
echo 'ok';
"@ | Set-Content $probe -Encoding UTF8

    $bootOutput = & $php $probe 2>&1 | Out-String
    $bootCode = $LASTEXITCODE
    Remove-Item $probe -Force -ErrorAction SilentlyContinue

    if ($bootCode -ne 0 -or $bootOutput -notmatch 'ok') {
        Write-Host $bootOutput.Trim() -ForegroundColor Red
        Write-Error "The tree does not boot. Refusing to publish."
    }
    Write-Host "  Dependencies load."

    # --- rebuild the branch -------------------------------------------------
    Write-Host "Building $Branch..." -ForegroundColor Cyan

    # A previous run that failed part-way leaves release-tmp behind, and the
    # orphan checkout below then refuses because the name is taken.
    if (@(git branch --list release-tmp).Count -gt 0) {
        git branch -D release-tmp 2>&1 | Out-Null
    }

    git checkout --orphan release-tmp --quiet
    git reset -q

    # Everything the source branch tracks, plus vendor/. Note the flag
    # placement: git add has no --quiet, and a pathspec follows any options.
    git checkout $Source -- .
    git add -A
    git add -f vendor

    # Unstage what must not ship.
    #
    # ls-files WITHOUT --error-unmatch: that flag writes to stderr for an
    # unmatched path, and PowerShell escalates a native command's stderr into a
    # terminating error. Plain ls-files simply prints nothing.
    foreach ($exclude in @('config.php', 'dist', 'tests', 'phpunit.xml.dist', 'tools')) {
        if (@(git ls-files --cached -- $exclude).Count -gt 0) {
            git rm -r --cached --quiet -f -- $exclude | Out-Null
        }
    }

    # Belt and braces. config.php holds the database password and the
    # encryption keys; publishing it would be the worst thing this script could
    # do, so it is checked explicitly rather than trusted to the loop above.
    if (@(git ls-files --cached -- config.php).Count -gt 0) {
        Write-Error "config.php was staged for the release branch. Refusing to publish."
    }

    $vendorFiles = (git diff --cached --name-only | Select-String '^vendor/' | Measure-Object -Line).Lines
    if ($vendorFiles -eq 0) {
        Write-Error "vendor/ was not staged. Refusing to publish a release without dependencies."
    }

    $message = @"
Release from $Source ($sourceCommit)

Deployable tree: application source plus production dependencies.

This branch exists because the target hosts have no Composer, so vendor/ has to
be in the repository for git pull to be a usable deployment. It is gitignored
on the development branches and force-added here.

Rebuilt from scratch on every release rather than merged, so it is always
exactly the current source and the current dependencies, with no chance of a
package removed upstream surviving because nothing deleted it.

Excludes config.php (secrets, written by the installer), tests, and tooling.
"@

    git commit -q -m $message

    # -M renames release-tmp onto $Branch, replacing any previous release.
    git branch -M $Branch

    Write-Host ""
    Write-Host "Built $Branch from $Source ($sourceCommit)" -ForegroundColor Green
    Write-Host "  $vendorFiles vendor file(s) included"

    if ($Push) {
        Write-Host ""
        Write-Host "Pushing..." -ForegroundColor Cyan
        git push -f origin $Branch
    } else {
        Write-Host ""
        Write-Host "Not pushed. To publish:" -ForegroundColor Yellow
        Write-Host "  git push -f origin $Branch"
    }
} finally {
    # Always return to where we started, whatever happened. Leaving the repo on
    # an orphan branch is how a later commit lands somewhere nobody intended.
    $current = (git rev-parse --abbrev-ref HEAD).Trim()
    if ($current -ne $startingBranch) {
        git checkout -f $startingBranch --quiet 2>&1 | Out-Null
    }
    if (@(git branch --list release-tmp).Count -gt 0) {
        git branch -D release-tmp 2>&1 | Out-Null
    }

    Write-Host ""
    Write-Host "Restoring dev dependencies..." -ForegroundColor DarkGray
    if ($composer) {
        if ($composer -like '*.phar') {
            & $php $composer install --no-interaction --quiet
        } else {
            & $composer install --no-interaction --quiet
        }
    }

    Pop-Location
}
