# Queue Recovery Runbook

Operational procedures for the document-generation queue
(`rams_documents`, `om_manuals`, `cable_schedules`, `worksheets`).

The underlying Laravel queue driver is **database** (`jobs` table), processed
by `queue:work`. A single running worker is expected per environment.

## Symptoms

| Observed | Likely cause | Command |
|---|---|---|
| Docs stuck in `generating` > 5 min | Worker not consuming jobs | `queue:health-check` |
| `jobs` table growing, `attempts=0` | Worker absent or stalled | `queue:recover` |
| User clicks regenerate, nothing happens | Same as above | same |

## Commands

### Health check (read-only)

```
php artisan queue:health-check          # human-readable table
php artisan queue:health-check --json   # single-line JSON (machine)
```

Exit codes (deterministic, stable for schedulers):

| Code | Meaning | Action |
|---|---|---|
| 0 | Healthy | none |
| 1 | Unhealthy (warn-threshold breached or stalled generating) | investigate / run recovery |
| 2 | Critical (pending jobs + no worker, or crit threshold breached) | run recovery now |

Thresholds:

- `PENDING_AGE_WARN_S = 300` — oldest pending job older than 5 min → exit 1
- `PENDING_AGE_CRIT_S = 900` — oldest pending job older than 15 min → exit 2
- `HEARTBEAT_STALE_S  = 120` — heartbeat file older than 2 min = stalled
- `GENERATING_STUCK_S = 900` — doc in `generating` status older than 15 min = stuck

### Recovery (drains queue, CLI-only)

```
php artisan queue:recover --dry-run     # show plan without acting
php artisan queue:recover               # act
```

Behaviour:

1. Acquires a `queue-recover` cache lock (10-min TTL). A second concurrent
   invocation exits with `EXIT_LOCKED=3` — no retries. Cron's next tick is
   the retry loop.
2. Invokes `queue:health-check`. If exit is 0, does nothing.
3. Otherwise broadcasts `queue:restart` so any currently-running worker
   finishes its current job and exits on its next poll.
4. Runs `queue:work --stop-when-empty --tries=2 --timeout=300 --sleep=3` to
   drain pending jobs in-process. Exits 0 when queue is empty or stop signal
   received.

Does **not** spawn a new persistent worker — that is the orchestrator's job
(systemd / Windows service / cron loop).

## Windows Task Scheduler — periodic recovery

Register a task that runs every 5 minutes. Replace the path fragments with
values appropriate to your host.

### Option A — `schtasks.exe` one-liner (recommended)

Simpler, no duration-ceiling gotchas, works on every Windows version:

```powershell
schtasks.exe /Create /TN "RAMS Queue Recover" /SC MINUTE /MO 5 /TR "cmd /c cd /d \"C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com\" && \"C:\Users\sonny.tanda\.config\herd\bin\php.bat\" artisan queue:recover" /F
```

The `cmd /c cd /d ...` prefix sets the working directory since `schtasks.exe`
has no working-directory flag. `/F` forces overwrite, so re-running the line
is safe.

### Option B — PowerShell `Register-ScheduledTask`

If you prefer the PowerShell cmdlet, use a bounded duration. Do NOT pass
`[TimeSpan]::MaxValue` — Task Scheduler rejects it with
`The task XML contains a value which is incorrectly formatted or out of range`.
Use a long bounded duration instead (9999 days ≈ 27 years is effectively forever):

```powershell
$action   = New-ScheduledTaskAction `
    -Execute 'C:\Users\sonny.tanda\.config\herd\bin\php.bat' `
    -Argument 'artisan queue:recover' `
    -WorkingDirectory 'C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com'

$trigger  = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes 5) `
    -RepetitionDuration (New-TimeSpan -Days 9999)

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName 'RAMS Queue Recover (5m)' `
    -Action $action -Trigger $trigger -Settings $settings `
    -Description 'Drains the RAMS document-generation queue if stalled.'
```

The 5-minute cadence matches `PENDING_AGE_WARN_S` — worst-case user-visible
stall becomes pending-age + 5 min.

## Linux cron equivalent

```
# Every 5 minutes, recover if unhealthy. stdout/stderr routed to laravel.log.
*/5 * * * * cd /var/www/rams && /usr/bin/php artisan queue:recover >> storage/logs/laravel.log 2>&1
```

## Observability signals

- `storage/worker-heartbeat` — UNIX epoch written by the running worker on
  every loop iteration and on every job processed (`Looping` + `JobProcessed`
  events). Missing / stale beyond 120s = stalled.
- `storage/logs/worker.log` — stdout from `queue:work`. Used as a secondary
  freshness signal when heartbeat is absent (first 120s after worker start).
- `storage/logs/laravel.log` — structured log entries from the health /
  recovery commands, tagged `QueueRecoverCommand:` / `WorkerMonitorService:`.

## Manual escalation

If `queue:recover` is itself failing (e.g. DB unavailable, lock stuck):

1. Check cache lock: `php artisan cache:forget queue-recover` (clears the key
   on the default cache store).
2. Inspect `failed_jobs` table — retryable failures stay there until an
   operator runs `queue:retry all`.
3. Inspect `jobs` table `attempts` column — jobs with `attempts >= 2` were
   tried and exceeded the configured tries; they should have migrated to
   `failed_jobs` automatically.
4. Last-resort drain: `php artisan queue:flush` removes all failed jobs
   (destructive, use only after auditing).
