<?php

namespace App\Support\Cockpit;

use App\Models\Project;

/**
 * ProjectLanding — WHERE A PROJECT OPENS (quick task 260930-cl9, SCOPE.md D-01b).
 *
 * D-01b: "from the project dash, when i click a project, it should show the
 * cockpit". This class is the single place that decides that, so the entry
 * points (the project list rows and the dashboard health rows) all agree and
 * a later change is one edit, not a hunt through Blades.
 *
 * ── IT IS A FALLBACK, NOT A REDIRECT ────────────────────────────────────────
 *
 * `projects.cockpit` 404s whenever COCKPIT_ENABLED is off
 * (`config/cockpit.php:44`, default FALSE, and deliberately so — the cockpit
 * renders an empty spine until `visits:backfill --apply` has run). If the
 * entry points simply pointed at `route('projects.cockpit')`, then every
 * project click on a tree where the flag has not been flipped would land on a
 * 404. config/cockpit.php:19-38 says a gate that starts wrong "teaches
 * everyone to ignore it"; a front door that starts broken is worse.
 *
 * So the URL is chosen at RENDER TIME from the flag:
 *   flag ON  → the cockpit          (the new front door)
 *   flag OFF → the nine-tab page    (today's behaviour, byte-for-byte)
 *
 * This is deliberately NOT done by making `ProjectController@show` render the
 * cockpit. The nine-tab page must keep a STABLE URL, because the admin-only
 * top-menu escape hatch added by the same task points at it, and because
 * `projects/show.blade.php` is linked by 30+ "← Back to project" anchors and
 * links 46 routes. Re-pointing the two entry points leaves all of that alone.
 *
 * NOTHING IS DELETED AND NOTHING IS REDIRECTED. `projects.show` still renders
 * the nine-tab page at `/projects/{id}` in both flag states.
 *
 * READ-ONLY and side-effect-free: it builds a string from a route name.
 */
final class ProjectLanding
{
    /**
     * The URL a project opens at, honouring the cockpit flag.
     *
     * Accepts a model or a bare id so the dashboard (which holds models) and
     * any caller holding only `project_id` can both use it.
     */
    public static function url(Project|int $project): string
    {
        return self::cockpitIsTheFrontDoor()
            ? route('projects.cockpit', $project)
            : route('projects.show', $project);
    }

    /**
     * Whether the cockpit is currently the front door.
     *
     * Reads `config()` rather than `env()` on purpose: a cached config is the
     * live truth, and `env()` returns null once `config:cache` has run.
     */
    public static function cockpitIsTheFrontDoor(): bool
    {
        return (bool) config('cockpit.enabled');
    }
}
