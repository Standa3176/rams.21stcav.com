<?php

namespace App\Events;

use App\Models\SiteSurvey;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after SurveyService::submitPublic() successfully persists a public
 * survey submission. Carries the SiteSurvey model so any downstream listener
 * (staleness banners, notifications, activity logs, drawings regen) can react
 * without SurveyService needing to know about them.
 *
 * Quick task 260726-fx4 — introduced so all four downstream doc types
 * (RAMS / OmManual / Worksheet / CableSchedule) can consistently detect
 * "the project's underlying survey moved on since I was generated". The event
 * itself does NOT trigger any auto-regeneration; the isStale() methods on
 * each doc model use the survey's submitted_at directly. The event exists
 * so future listeners (e.g. slack notifications, per-role email digests)
 * have a clean subscription point without having to hook the service class.
 */
class SurveySubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SiteSurvey $survey,
    ) {}
}
