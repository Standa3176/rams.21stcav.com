<#
    Phase 46 gate runner — the ONE way any 46-xx plan runs a test command.

    WHY THIS FILE EXISTS (two traps, both previously paid for):

      1. `php` is NOT on the Bash tool's PATH on this machine. A piped
         `php ... | tail` exits 0 while executing nothing, which fakes a green
         gate. Every invocation below uses the explicit Herd binary.

      2. The Bash tool strips `$` out of `powershell -Command "..."`. Anything
         needing `$env:USERPROFILE` — which is every one of these — must live in
         a .ps1 file and be invoked with -File. That is this file.

    USAGE (no `$` on the command line, so the Bash tool cannot mangle it):

      powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Filter VisitLifecycleTest
      powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Path tests/Feature/Cockpit
      powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Baseline
      powershell -ExecutionPolicy Bypass -File .planning/phases/46-visit-lifecycle/gate-46.ps1 -Hashes

    -Baseline is the D-06 behaviour-preservation gate from 45-BASELINE.md, kept
    CHARACTER-IDENTICAL here so the enumerated subset cannot drift. The gate is
    `>= 159 passed AND 0 failed` — NEVER an equality against 161. Two tests
    self-skip on this machine for a missing ext-imagick and are not defects.

    NEVER run `artisan migrate:fresh --env=testing`. There is no .env.testing;
    it falls back to .env, where DB_DATABASE is commented out, and it would wipe
    database/database.sqlite — live local dev data. The suite needs no database
    preparation: phpunit.xml uses sqlite :memory:.
#>
param(
    [string] $Filter,
    [string] $Path,
    [switch] $Baseline,
    [switch] $Hashes
)

$ErrorActionPreference = 'Stop'
Set-Location "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com"
$php = "$env:USERPROFILE\.config\herd\bin\php84\php.exe"

if ($Hashes) {
    Get-FileHash -Algorithm SHA256 `
        resources/views/layouts/app.blade.php, `
        resources/css/app.css, `
        tailwind.config.js | Format-List Path, Hash
    Write-Host ""
    Write-Host "Expected (45-BASELINE.md, working-tree bytes at 4abd2b24 - compare like with like):"
    Write-Host "  layouts/app.blade.php  9ED63C4C754F33832E12BB07A2C515AAC82AB656C5C9A1D35AED0174A7FF0557"
    Write-Host "  resources/css/app.css  EDAD1982303B9FABF86A4B791BBF16436104251277CA79DBAEFB5603C8BE2133"
    Write-Host "  tailwind.config.js     73BB8AD6B7CDF4DC0B11C51661E3DBC7A274CABBCC226D1FF41B5E50938E74BB"
    Write-Host "A MISMATCH IS A STOP, never a hash to refresh."
    exit $LASTEXITCODE
}

if ($Baseline) {
    & $php artisan test `
        tests/Unit/InstallTaskGeneratorServiceTest.php `
        tests/Feature/InstallTasks `
        tests/Feature/FieldView `
        tests/Feature/Commissioning `
        tests/Unit/Services/CommissioningItemGeneratorTest.php `
        tests/Unit/Services/CommissioningPdfServiceTest.php `
        tests/Unit/Services/CommissioningServiceTest.php `
        tests/Unit/Services/CommissioningSyncServiceTest.php `
        tests/Unit/Models/CommissioningItemTest.php `
        tests/Unit/Models/CommissioningSignoffTest.php `
        tests/Feature/Projects/ProjectDeliverableAutoFlipTest.php `
        tests/Feature/Authorization/SharedWorkspaceFieldOpsAccessTest.php
    exit $LASTEXITCODE
}

if ($Path)   { & $php artisan test $Path;              exit $LASTEXITCODE }
if ($Filter) { & $php artisan test --filter=$Filter;   exit $LASTEXITCODE }

& $php artisan test tests/Feature/Cockpit tests/Unit/Cockpit
exit $LASTEXITCODE
