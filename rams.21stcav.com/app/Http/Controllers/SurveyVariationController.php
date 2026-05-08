<?php

namespace App\Http\Controllers;

use App\Models\SiteSurvey;
use App\Models\SurveyVariation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Office-side CRUD surface for SurveyVariation rows. Quick task 260508-v7g.
 *
 * Pure flat capture — no workflow, no events, no notifications (D-LOCK-1).
 * v1 is a record-keeping surface for the sales team's quote-revision
 * conversation. The status enum (proposed | quoted | approved | rejected)
 * exists so the office can flip the dropdown freely as the commercial
 * conversation evolves; no enforced order.
 *
 * Auth: D-LOCK-6 — abort_unless(auth()->check(), 403). NOT ProjectPolicy.
 *   The production sharing model lets any authenticated office user act on
 *   any project. The recent fix bd31cfc (260506-qa9) explicitly switched
 *   Mini O&M from ProjectPolicy to auth()->check for this same reason.
 *
 * Cross-survey forgery guards live in update() / destroy() — both reject
 * 403 if the {variation} doesn't belong to the {siteSurvey} in the URL.
 * Photo allow-list lives in the validate() helper — photo_id from another
 * survey is rejected at validation time.
 */
class SurveyVariationController extends Controller
{
    public function store(Request $request, SiteSurvey $siteSurvey): RedirectResponse
    {
        abort_unless(auth()->check(), 403);

        $data = $this->validate($request, $siteSurvey);

        SurveyVariation::create(array_merge($data, ['site_survey_id' => $siteSurvey->id]));

        return back()->with('success', 'Variation added.');
    }

    public function update(Request $request, SiteSurvey $siteSurvey, SurveyVariation $variation): RedirectResponse
    {
        abort_unless(auth()->check(), 403);
        abort_unless($variation->site_survey_id === $siteSurvey->id, 403);

        $variation->update($this->validate($request, $siteSurvey));

        return back()->with('success', 'Variation updated.');
    }

    public function destroy(SiteSurvey $siteSurvey, SurveyVariation $variation): RedirectResponse
    {
        abort_unless(auth()->check(), 403);
        abort_unless($variation->site_survey_id === $siteSurvey->id, 403);

        $variation->delete();

        return back()->with('success', 'Variation removed.');
    }

    /**
     * Validation rules shared by store + update.
     *
     * The photo_id allow-list is scoped to the survey's own photos — a forged
     * photo_id from another survey is rejected at validation time. The `in:0`
     * fallback (when there are zero photos) is harmless: id 0 will never match
     * a positive autoincrement, so it correctly rejects all photo_ids when
     * the survey has none. Without the fallback, Laravel's `in:` rule throws.
     */
    private function validate(Request $request, SiteSurvey $siteSurvey): array
    {
        $allowedPhotoIds = $siteSurvey
            ->rooms()
            ->with('photos:id,site_survey_room_id')
            ->get()
            ->flatMap(fn ($r) => $r->photos->pluck('id'))
            ->all();

        return $request->validate([
            'room_name'   => ['nullable', 'string', 'max:150'],
            'type'        => ['required', 'in:extra_hardware,extra_labour,cable_change,client_provided_change,access_issue,other'],
            'description' => ['required', 'string', 'max:3000'],
            'qty'         => ['nullable', 'integer', 'min:1', 'max:9999'],
            'photo_id'    => ['nullable', 'integer', 'in:'.(empty($allowedPhotoIds) ? '0' : implode(',', $allowedPhotoIds))],
            'status'      => ['nullable', 'in:proposed,quoted,approved,rejected'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
