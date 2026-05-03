# Drawings Queue Runbook

Phase 20 (CRIT-03) — operational procedure for the dedicated `drawings`
queue worker that processes `BuildBoundPdfJob` and any future drawing-export
jobs (ZIP bundle async builds, etc.).

> Companion document: [queue-recovery.md](../runbook/queue-recovery.md) covers
> the default queue (RAMS / O&M / Worksheet / Cable Schedule). This runbook
> covers ONLY the new `drawings` lane.

## Why a separate drawings queue?

Per [CONTEXT.md production-hardening-non-negotiables](../../.planning/phases/20-drawing-export-pipeline-o-m-integration/20-CONTEXT.md):

- **CRIT-03**: Browsershot bound-PDF builds can take 90s+ on full projects
  (5+ drawings × per-drawing render + FPDI page concat). Running on the
  default queue means a single bound PDF can starve every other doc job
  (RAMS notification emails, O&M handover builds, worksheet generation).
- **Isolation**: Bound PDFs cannot starve customer-facing doc generation.
- **Memory**: Each Chrome render holds 200-400 MB RSS. The dedicated worker
  caps at `--memory=512` and cycles every 10 jobs (`--max-jobs=10`) to clear
  Chrome fork-leakage before it compounds.

## Worker process

The dedicated worker is invoked with the following flags. Hosting on the
existing `stcav` user (CWP / AlmaLinux 8):

```bash
php /home/stcav/rams.21stcav.com/artisan queue:work \
    --queue=drawings \
    --max-jobs=10 \
    --memory=512 \
    --timeout=600 \
    --tries=2
```

Flag rationale:

| Flag | Value | Reason |
|------|-------|--------|
| `--queue=drawings` | `drawings` | Pin to the new connection (config/queue.php). |
| `--max-jobs=10` | `10` | Recycle worker before Chrome leaks compound. |
| `--memory=512` | `512` MiB | Cap RSS so OOM-kills fire BEFORE the bound PDF garbles. |
| `--timeout=600` | 10 min | Match `retry_after=600` in config/queue.php drawings connection. |
| `--tries=2` | `2` | Single retry for transient failures; `failed()` hook then alerts admins (NOTF-04). |

### Supervisor config snippet

Mirrors the pattern of the existing default queue worker config. Adjust
the path to your project root:

```ini
[program:rams-queue-drawings]
process_name=%(program_name)s_%(process_num)02d
command=php /home/stcav/rams.21stcav.com/artisan queue:work --queue=drawings --max-jobs=10 --memory=512 --timeout=600 --tries=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=stcav
numprocs=1
redirect_stderr=true
stdout_logfile=/home/stcav/storage/logs/queue-drawings.log
stopwaitsecs=620
```

> `numprocs=1` enforces concurrency=1 at the supervisor level. The
> `WithoutOverlapping` middleware on `BuildBoundPdfJob` already prevents
> double-fire per-project (`bound-pdf-{projectId}` key, releaseAfter 60s)
> but a second worker process would still let two DIFFERENT projects'
> bound PDFs concurrently fight for Chrome RAM. Keep numprocs=1.

### Restart procedure

```bash
sudo supervisorctl restart rams-queue-drawings
sudo supervisorctl status rams-queue-drawings   # confirm RUNNING
tail -f /home/stcav/storage/logs/queue-drawings.log
```

## Chrome upgrade procedure (CRIT-04)

The `chrome-headless-shell` version is pinned in `.env.example` via
`CHROME_HEADLESS_SHELL_VERSION` (current: `147.0.7727.57`). Bumps
must follow this procedure to avoid silent prod-vs-dev font / SVG
divergence:

1. Update `CHROME_HEADLESS_SHELL_VERSION` in `.env.example` and the
   live `.env` on production.
2. Download the new chrome-headless-shell binary into a versioned
   directory: `/home/stcav/chrome-headless-shell-<version>/`
3. Deploy the application (push, composer install, etc.).
4. **Before** repointing the symlink, smoke-test the NEW binary:
   ```bash
   CHROME_PATH=/home/stcav/chrome-headless-shell-<new-version>/chrome \
       php artisan pdf:smoke-test --drawings
   ```
   Expect exit 0 with output mentioning BOTH "schematic" AND "rack" with
   non-zero byte sizes. If either fails, do NOT proceed with the symlink.
5. Repoint the symlink atomically:
   ```bash
   ln -sfn /home/stcav/chrome-headless-shell-<new-version>/chrome /home/stcav/chrome
   ```
6. Re-run `php artisan pdf:smoke-test --drawings` to confirm the new
   binary is live behind `CHROME_PATH=/home/stcav/chrome`.
7. Restart the drawings queue worker so any cached Chrome paths reset:
   `sudo supervisorctl restart rams-queue-drawings`.
8. Keep the previous version's directory on disk for 24h as a rollback.

## License audit gate

Phase 20 (MOD-01) — add to deploy preflight, BEFORE every `php artisan
migrate --force`:

```bash
php artisan drawings:audit-licenses
echo "exit code: $?"
```

Expected: `exit code: 0` and "License audit OK" output. If exit code is
1, **pause deploy** and investigate the offender table in the output. The
audit allowlists pre-existing GPL/LGPL deps documented in the command's
`$preExistingAllowlist` (mpdf, dompdf, smalot/pdfparser, etc. — predate
Phase 20 and are out of scope per Plan 20-01 SUMMARY).

For a stricter pass that ALSO flags LGPL:

```bash
php artisan drawings:audit-licenses --strict
```

This is informational — strict mode would currently fail because of the
pre-existing LGPL deps; it's documented here for the future migration
away from dompdf/mpdf to Browsershot-only.

## Fonts setup

CRIT-04 mitigation. The three drawing Blade views
(`pdf.drawings.schematic`, `pdf.drawings.rack`, `pdf.drawings.bound-cover`)
declare `@font-face` for Liberation Sans + DejaVu Sans backed by woff2
files at `/fonts/`. The directory exists in git via
`public/fonts/.gitkeep`; the actual binaries do **not** ship in git
(they're 100KB+ each).

### First-deploy step (per environment)

Copy the woff2 binaries from a known-good location:

```bash
# Production source — adjust to your environment
cp /home/stcav/fonts/liberation-sans-regular.woff2 \
   /home/stcav/rams.21stcav.com/public/fonts/

cp /home/stcav/fonts/liberation-sans-bold.woff2 \
   /home/stcav/rams.21stcav.com/public/fonts/

cp /home/stcav/fonts/dejavu-sans-regular.woff2 \
   /home/stcav/rams.21stcav.com/public/fonts/
```

### Confirm

```bash
ls public/fonts/
# Expect: .gitkeep, liberation-sans-regular.woff2, liberation-sans-bold.woff2, dejavu-sans-regular.woff2
```

### What happens if a font is missing?

`@font-face` declarations use `font-display: block` so Browsershot waits
for the font load. If the woff2 URL 404s, Chromium silently falls back to
the next font in the SVG `font-family` chain
(`Arial, Helvetica, 'Liberation Sans', 'DejaVu Sans', sans-serif`). The
PDF still renders cleanly — it's a graceful degradation, not a hard fail.
The smoke test will still exit 0.

## Smoke test gate

`php artisan pdf:smoke-test --drawings` is the single source of truth for
"is the drawings render path alive?". Run it after every:

- chrome-headless-shell upgrade (CRIT-04)
- composer install (in case Browsershot's PHP wrapper bumps)
- font binary swap
- `disable-dev-shm-usage` flag verification (Phase 20 P02 Task 3 keeps the
  flag locked in PdfRenderService — `grep -c "disable-dev-shm-usage"` must
  return ≥ 2 across `fromBlade` + `fromBladeAsPng`).

Expected output:

```
Schematic smoke (placeholder fixture): wrote /home/stcav/.../pdf-smoke-drawing.pdf
Schematic smoke: OK (12345 bytes at /home/stcav/.../pdf-smoke-drawing.pdf)
Rack smoke (placeholder fixture): wrote /home/stcav/.../pdf-smoke-drawing-rack.pdf
Rack smoke: OK (12345 bytes at /home/stcav/.../pdf-smoke-drawing-rack.pdf)
Drawings smoke summary: schematic=ok rack=ok
```

Exit 0 = ship-ready. Exit 1 = pause and investigate.

## Where bound PDFs land

Per H-07 + Plan 20-01: bound PDFs write through `DocumentArtifactStorage`
(`TYPE_DRAWING` = `documents/drawings/`). Filename pattern:

```
documents/drawings/bound-{projectId}-v{N}-{ulid}.pdf
documents/drawings/bundle-{projectId}-v{N}-{ulid}.zip
```

Never construct paths by hand — always use the service.

---

*Phase 20 Plan 02 Task 2 — Drawings queue worker + Chrome upgrade procedure
+ license audit gate + fonts setup. Mirrors the structure of
`docs/runbook/queue-recovery.md` (default queue lane).*
