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

    # --- build the release commit -------------------------------------------
    Write-Host "Building $Branch..." -ForegroundColor Cyan

    # Built with plumbing against a temporary index, so HEAD and the working
    # tree are never touched. Two reasons:
    #
    #  - Switching branches to build a release is how a commit ends up on the
    #    wrong branch when something fails part-way. That happened twice while
    #    writing this script.
    #  - The release tree is assembled explicitly rather than inherited, so a
    #    file deleted upstream disappears from the release instead of lingering.
    $env:GIT_INDEX_FILE = Join-Path $root '.git\release-index'
    if (Test-Path $env:GIT_INDEX_FILE) { Remove-Item $env:GIT_INDEX_FILE -Force }

    try {
        # Exactly what a deployment needs, named explicitly. Anything not
        # listed - tests, tools, dist, phpunit.xml.dist, and above all
        # config.php - cannot reach the release by accident.
        $include = @(
            'core', 'public', 'themes', 'plugins', 'storage',
            'composer.json', 'README.md', '.htaccess', '.gitattributes', '.gitignore'
        )

        foreach ($path in $include) {
            if (Test-Path (Join-Path $root $path)) {
                git add -A -- $path
            }
        }

        # vendor/ is gitignored on the development branches, so it needs -f.
        git add -f -A -- vendor

        if (@(git ls-files --cached -- config.php).Count -gt 0) {
            Write-Error "config.php reached the release index. Refusing to publish."
        }

        $staged = @(git ls-files --cached)
        $vendorFiles = @($staged | Where-Object { $_ -like 'vendor/*' }).Count

        if ($vendorFiles -eq 0) {
            Write-Error "vendor/ was not staged. Refusing to publish a release without dependencies."
        }

        $tree = (git write-tree).Trim()

        $message = @"
Release from $Source ($sourceCommit)

Deployable tree: application source plus production dependencies.

This branch exists because the target hosts have no Composer, so vendor/ has to
be in the repository for git pull to be a usable deployment. It is gitignored
on the development branches and force-added here.

The tree is assembled from scratch each release, so a file removed upstream
disappears here too. The commit is parented to the previous release, so the
branch history stays linear and deploying really is just git pull - a rebuilt
orphan commit would diverge and force every server to reset instead.

Excludes config.php (secrets, written by the installer), tests, and tooling.
"@

        # Parent to the previous release. This is what keeps `git pull` on a
        # deployed server a fast-forward rather than a divergence.
        $previous = (git rev-parse --verify --quiet "refs/heads/$Branch")

        if ($previous) {
            $previousTree = (git rev-parse "refs/heads/$Branch^{tree}").Trim()
            if ($previousTree -eq $tree) {
                Write-Host "  No change since the last release; nothing to publish." -ForegroundColor Yellow
                $commit = $previous.Trim()
            } else {
                $commit = ($message | git commit-tree $tree -p $previous.Trim()).Trim()
            }
        } else {
            $commit = ($message | git commit-tree $tree).Trim()
        }

        git update-ref "refs/heads/$Branch" $commit
    } finally {
        Remove-Item $env:GIT_INDEX_FILE -Force -ErrorAction SilentlyContinue
        Remove-Item Env:\GIT_INDEX_FILE -ErrorAction SilentlyContinue
    }

    Write-Host ""
    Write-Host "Built $Branch from $Source ($sourceCommit)" -ForegroundColor Green
    Write-Host "  $vendorFiles vendor file(s) included"

    if ($Push) {
        Write-Host ""
        Write-Host "Pushing..." -ForegroundColor Cyan
        # No -f: history is linear now, so a normal push is a fast-forward.
        # If this is ever rejected, something rewrote the branch and that is
        # worth stopping to look at rather than steamrolling.
        git push origin $Branch
    } else {
        Write-Host ""
        Write-Host "Not pushed. To publish:" -ForegroundColor Yellow
        Write-Host "  git push origin $Branch"
    }
} finally {
    # HEAD is never moved by this script, but a failure part-way through an
    # earlier version could leave the repo somewhere unexpected, so this stays
    # as a safety net.
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
