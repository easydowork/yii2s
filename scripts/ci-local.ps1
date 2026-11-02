# Local CI runner: mirrors .github/workflows/ci.yml job by job.
#
# Usage:
#   powershell -File scripts/ci-local.ps1            # run all jobs
#   powershell -File scripts/ci-local.ps1 phpunit    # run a single job
#
# The swoole-cli binary location can be overridden with the SWOOLE_CLI
# environment variable, e.g.:
#   $env:SWOOLE_CLI = "D:\swoole-cli-v6.2.2-msys2-x64\bin\swoole-cli.exe"

param(
    [Parameter(Position = 0)]
    [ValidateSet('all', 'phpunit', 'static', 'coding-standard')]
    [string] $Job = 'all'
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

$php = 'D:\EServer-data\childApp\php\php-8.4\php.exe'
$swooleCli = if ($env:SWOOLE_CLI) { $env:SWOOLE_CLI } else { 'D:\swoole-cli-v6.2.2-msys2-x64\bin\swoole-cli.exe' }

$failures = 0

function Invoke-CiStep {
    param([string] $Name, [scriptblock] $Step)

    Write-Host ""
    Write-Host "==> job: $Name" -ForegroundColor Cyan
    & $Step
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FAIL: $Name" -ForegroundColor Red
        $script:failures++
    } else {
        Write-Host "PASS: $Name" -ForegroundColor Green
    }
}

$runPhpunit = {
    if (-not (Test-Path $swooleCli)) {
        Write-Host "swoole-cli not found at $swooleCli; set SWOOLE_CLI env var." -ForegroundColor Yellow
        exit 1
    }
    & $swooleCli vendor/phpunit/phpunit/phpunit --no-coverage
}

$runStatic = {
    & $php vendor/phpstan/phpstan/phpstan analyse --no-progress
}

$runCodingStandard = {
    & $php vendor/friendsofphp/php-cs-fixer/php-cs-fixer check --diff
}

if ($Job -in @('all', 'phpunit'))          { Invoke-CiStep 'phpunit' $runPhpunit }
if ($Job -in @('all', 'static'))           { Invoke-CiStep 'static' $runStatic }
if ($Job -in @('all', 'coding-standard'))  { Invoke-CiStep 'coding-standard' $runCodingStandard }

Write-Host ""
if ($failures -gt 0) {
    Write-Host "$failures job(s) failed." -ForegroundColor Red
    exit 1
}
Write-Host "All jobs passed." -ForegroundColor Green
