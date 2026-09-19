<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Read-only project cockpit (Phase 45, VIS-10)
    |--------------------------------------------------------------------------
    |
    | Master kill-switch for the read-only project cockpit. When false the
    | route 404s (`abort_unless(config('cockpit.enabled'), 404)`, mirroring
    | the SPIKE_SCHEMATIC_ENABLED precedent at config/services.php:40-43 and
    | app/Http/Controllers/SpikeSchematicController.php:19-25) and the page
    | emits no markup and no @push('styles'). The eleven-tab project page
    | stays the default either way.
    |
    | THE DEFAULT IS FALSE, AND THAT IS DELIBERATE — not a copy-paste of a
    | truthy precedent. The cockpit renders against the `visits` table, which
    | is EMPTY until `visits:backfill --apply` has been run. Arming the flag
    | in the same deploy as the code would show every PM an empty spine and
    | reproduce verbatim the failure this repo has already suffered and
    | written down at config/rams_tier1.php:119-134: "content gate defaulting
    | ON is a deploy-order trap". A gate that starts wrong teaches everyone
    | to ignore it.
    |
    | Deploy order — five steps, and the flip is its own decision:
    |   1. ship the code, with COCKPIT_ENABLED absent from the live .env
    |   2. `php artisan visits:backfill`            (dry-run — read the counts)
    |   3. `php artisan visits:backfill --apply`    (populate the table)
    |   4. flip `COCKPIT_ENABLED=true` as a separate one-line .env change
    |   5. `php artisan config:clear`
    |
    | A single global boolean. No per-project, per-user or per-role scoping —
    | 45-CONTEXT.md names a global env flag as the default and every flag
    | precedent in this app matches.
    |
    | tests/Unit/CockpitFlagDefaultTest.php pins this default by reading THIS
    | FILE's literal return value, not config(), so a cached config cannot
    | mask a future flip. If you are here to change `false` to `true`, that
    | test will stop you — and it is right to.
    |
    */
    'enabled' => env('COCKPIT_ENABLED', false),

];
