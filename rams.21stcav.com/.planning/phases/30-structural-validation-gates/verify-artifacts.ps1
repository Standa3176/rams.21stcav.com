# Phase 30 - approved-artifact reconciliation verifier (Plan 30-05, Task 3)
#
# Exits non-zero if any Phase 30 approved artifact still carries a stale statement the phase's
# own decisions have since overturned. Exits zero otherwise.
#
# Run from PowerShell only - `php`/`pwsh` invocations through the Bash tool exit 0 without
# executing, per the project memory note on this machine's tooling.

$ErrorActionPreference = "Stop"

$phaseDir = Split-Path -Parent $MyInvocation.MyCommand.Path

$uiSpecPath = Join-Path $phaseDir "30-UI-SPEC.md"
$researchPath = Join-Path $phaseDir "30-RESEARCH.md"
$validationPath = Join-Path $phaseDir "30-VALIDATION.md"

$failures = @()

# 1. 30-UI-SPEC.md must no longer specify GATE-14 as error tier.
if (-not (Test-Path $uiSpecPath)) {
    $failures += "30-UI-SPEC.md does not exist at $uiSpecPath"
} elseif (Select-String -Path $uiSpecPath -Pattern "Error state \(GATE-14\)" -Quiet) {
    $failures += "30-UI-SPEC.md still matches 'Error state (GATE-14)' - GATE-14 must be warn tier"
}

# 2. 30-RESEARCH.md's Open Questions section must be marked resolved.
if (-not (Test-Path $researchPath)) {
    $failures += "30-RESEARCH.md does not exist at $researchPath"
} elseif (-not (Select-String -Path $researchPath -Pattern "Open Questions \(RESOLVED\)" -Quiet)) {
    $failures += "30-RESEARCH.md does not match 'Open Questions (RESOLVED)'"
}

# 3. 30-VALIDATION.md must be signed off as nyquist compliant.
if (-not (Test-Path $validationPath)) {
    $failures += "30-VALIDATION.md does not exist at $validationPath"
} elseif (-not (Select-String -Path $validationPath -Pattern "nyquist_compliant: true" -Quiet)) {
    $failures += "30-VALIDATION.md does not match 'nyquist_compliant: true'"
}

# 4. 30-VALIDATION.md must have no remaining TBD rows.
if ((Test-Path $validationPath) -and (Select-String -Path $validationPath -Pattern "TBD" -Quiet)) {
    $failures += "30-VALIDATION.md still matches 'TBD'"
}

if ($failures.Count -gt 0) {
    foreach ($f in $failures) {
        Write-Error $f
    }
    exit 1
}

Write-Output "OK - all Phase 30 approved artifacts are reconciled"
exit 0
