# Syntax-check every PHP file in the project.
#
# `php -l` is the cheapest possible feedback loop and catches the whole class of
# mistakes that only show up as a blank page on a live host. Run it before every
# commit.
#
#   pwsh tools\lint.ps1

$ErrorActionPreference = 'Stop'

$php = $env:PORTAL_PHP
if (-not $php) {
    $php = (Get-Command php -ErrorAction SilentlyContinue).Source
}
if (-not $php) {
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
           Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) {
    Write-Error "No php.exe found. Set PORTAL_PHP to its full path."
}

$root = Split-Path $PSScriptRoot -Parent
$failed = @()
$checked = 0

Get-ChildItem $root -Filter *.php -Recurse |
    Where-Object { $_.FullName -notmatch '\\vendor\\' } |
    ForEach-Object {
        $checked++
        $output = & $php -l $_.FullName 2>&1 | Out-String
        if ($LASTEXITCODE -ne 0) {
            $failed += [pscustomobject]@{
                File  = $_.FullName.Replace($root, '')
                Error = $output.Trim()
            }
        }
    }

Write-Host "Checked $checked file(s)."

if ($failed.Count -gt 0) {
    Write-Host ""
    foreach ($f in $failed) {
        Write-Host "FAIL $($f.File)" -ForegroundColor Red
        Write-Host "     $($f.Error)"
    }
    Write-Host ""
    Write-Host "$($failed.Count) file(s) failed." -ForegroundColor Red
    exit 1
}

Write-Host "All files parse cleanly." -ForegroundColor Green
