<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 261015-mcp — "engineers could not use the survey engineer link at all"
 * (reported 2026-10-14).
 *
 * ROOT CAUSE. The public survey engineer routes carried bare `throttle:N,1`
 * middleware. Laravel keys an unauthenticated throttle on the CLIENT IP, and
 * these are public token routes with no auth — so every engineer behind one
 * address shares ONE bucket: site guest wifi, or mobile carrier NAT where a
 * whole region shares an IP. One engineer's autosave burst 429s a completely
 * different engineer's survey.
 *
 * This is not a new discovery in this codebase. The identical defect was fixed
 * for the WORKSHEET link in quick task 260919-fq8, after a 2026-09-18 incident
 * reported as "signature didn't work". That fix left seven named per-token
 * limiters in AppServiceProvider, all `worksheet-*`. The survey link was never
 * converted. This test closes that half-done job.
 *
 * WHY IT SURFACED ON 14 OCT. Quick task 261011-lvf repaired 81 dead $watch
 * registrations, so the per-room debounced autosave — which had never actually
 * fired — now fires roughly every 600 ms of typing pause. One engineer alone
 * emits 20-40 step-save calls a minute; two on one IP blow straight past 60.
 * 261011-tax then made a 429 present as a persistent "Needs a connection" even
 * on full signal, which reads to an engineer as "the link is broken".
 *
 * ⚠️ WHAT THIS FILE DELIBERATELY DOES NOT DO. It does not assert that a limiter
 * NAMED `survey-step-save` exists and stop there. Such a test passes on a
 * limiter keyed the WRONG way — keyed on IP, or on nothing — and would have
 * proved exactly nothing about the reported outage. Test 1 below asserts the
 * BEHAVIOUR that was broken: two different survey tokens, from the SAME client
 * IP, must not share a bucket. It fails on the pre-fix code.
 */
class SurveyEngineerThrottleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real survey with a real token and two rooms, reachable on the public
     * engineer link. Returns the token.
     */
    private function makeSurvey(string $label): string
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => $label,
            'site_address' => "1 {$label} Road, Leeds",
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => $label,
            'site_address' => "1 {$label} Road, Leeds",
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (['Board Room', 'Huddle 1'] as $i => $name) {
            $survey->rooms()->create([
                'room_name'  => $name,
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        return $token;
    }

    /**
     * One autosave call on the engineer link — the exact path the outage was
     * reported on. Step 0 is the site-level engineer-feedback save.
     */
    private function stepSave(string $token)
    {
        return $this->postJson(route('survey.step.save', ['token' => $token]), [
            'room_index' => 0,
            'step'       => 0,
            'data'       => ['engineer_feedback_site' => []],
        ]);
    }

    /**
     * Every public survey engineer route — i.e. every route whose URI is the
     * `survey/{token}` link. Written as a scan, not a hard-coded list, so a
     * route added tomorrow is covered without touching this file.
     *
     * @return array<int, \Illuminate\Routing\Route>
     */
    private function surveyEngineerRoutes(): array
    {
        $routes = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();
            if ($uri === 'survey/{token}' || str_starts_with($uri, 'survey/{token}/')) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    // -- 0. NON-VACUITY ------------------------------------------------------

    public function test_the_engineer_autosave_route_really_is_reachable_and_throttled(): void
    {
        $token = $this->makeSurvey('Non Vacuity');

        $this->stepSave($token)->assertOk();

        $routes = array_values(array_filter(
            $this->surveyEngineerRoutes(),
            fn ($r) => $r->getName() === 'survey.step.save'
        ));

        $this->assertCount(
            1,
            $routes,
            'The survey.step.save route is gone — every assertion in this file would be vacuous.'
        );

        $throttles = array_values(array_filter(
            $routes[0]->gatherMiddleware(),
            fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')
        ));

        $this->assertNotEmpty(
            $throttles,
            'survey.step.save carries no throttle at all. These are PUBLIC, UNAUTHENTICATED '
            .'endpoints — removing the throttle is never the fix for a 429.'
        );
    }

    // -- 1. THE DEFECT: TWO TOKENS MUST NOT SHARE A BUCKET -------------------

    /**
     * ⚠️ THIS IS THE REPORTED OUTAGE.
     *
     * Two engineers, two different surveys, one shared IP (site wifi / carrier
     * NAT). Engineer A autosaves until A is rate limited. Engineer B — who has
     * barely touched their own survey — must still be able to save.
     *
     * Pre-fix this FAILS: the bare `throttle:60,1` keys on the IP, so A's 60th
     * save closes the bucket on B as well, and B's next keystroke 429s. With a
     * per-token key, A exhausting A's own budget is A's problem alone.
     *
     * Note what is NOT asserted: no specific budget number, and no limiter
     * name. The loop drains whatever budget the route is configured with and
     * only then looks at B, so this stays a statement about bucket ISOLATION
     * and survives any future re-tuning of the numbers.
     */
    public function test_one_engineers_autosave_burst_does_not_rate_limit_another_survey_on_the_same_ip(): void
    {
        $tokenA = $this->makeSurvey('Engineer A Survey');
        $tokenB = $this->makeSurvey('Engineer B Survey');

        // Engineer B is working normally and their link is fine right now.
        $this->stepSave($tokenB)->assertOk();

        // Engineer A types fast across several rooms until A's own budget is
        // spent. Hard-capped so a misconfigured unlimited bucket fails loudly
        // instead of hanging the suite.
        $attempts = 0;
        $limited  = false;

        while ($attempts < 1000) {
            $attempts++;
            if ($this->stepSave($tokenA)->getStatusCode() === 429) {
                $limited = true;
                break;
            }
        }

        $this->assertTrue(
            $limited,
            "Survey A was never rate limited in {$attempts} autosaves. These routes are public "
            .'and unauthenticated — an unlimited bucket is not an acceptable fix for this outage.'
        );

        // The burst must have drained the budget the route's OWN limiter
        // declares — not some other bucket it is accidentally sharing, and not
        // a smaller one left behind by stale route caching. Compared against
        // the resolved limiter rather than a hard-coded number, so re-tuning
        // the budget does not need this test edited.
        $declared = $this->declaredBudgetFor('survey.step.save', $tokenA);

        $this->assertSame(
            $declared + 1,
            $attempts,
            "Survey A was rate limited after {$attempts} autosaves, but the limiter on "
            ."survey.step.save declares a budget of {$declared}/min. The route is not actually "
            .'drawing on the limiter it names.'
        );

        // ── The assertion the outage is about ────────────────────────────────
        // Same test client, therefore the same client IP as engineer A.
        $this->assertSame(
            200,
            $this->stepSave($tokenB)->getStatusCode(),
            'ENGINEER B WAS 429d BY ENGINEER A. Survey A exhausting its own autosave budget '
            ."after {$attempts} calls closed the bucket on a DIFFERENT survey from the same IP. "
            .'That is the 2026-10-14 outage: a bare `throttle:N,1` on a public token route keys '
            .'on the client IP, so every engineer behind one site wifi or carrier NAT shares one '
            .'bucket. The limiter must be keyed on the {token} route parameter, exactly as the '
            .'`worksheet-*` limiters in AppServiceProvider already are.'
        );
    }

    // -- 2. D-02: THE TOKENLESS FALLBACK IS A BUCKET, NOT A BLANK KEY --------

    /**
     * The `?: $request->ip()` fallback in every worksheet limiter is
     * load-bearing, and the comment in AppServiceProvider says why: without it
     * a request that reaches one of these routes carrying no token resolves to
     * an EMPTY STRING key — i.e. one shared bucket, for every such request,
     * from every client. The survey limiters must carry the same fallback.
     *
     * Asserted on the resolved Limit, not on the source text, so a limiter that
     * merely contains the right characters but computes the wrong key fails.
     */
    public function test_a_request_with_no_token_still_lands_in_a_real_bucket(): void
    {
        $names = $this->namedLimitersOnSurveyRoutes();

        $this->assertNotEmpty(
            $names,
            'No survey engineer route uses a named limiter, so there is no limiter to resolve. '
            .'See test 1 — this is the same missing fix.'
        );

        foreach ($names as $name) {
            $resolver = RateLimiter::limiter($name);

            $this->assertNotNull($resolver, "Route middleware names limiter `{$name}`, but nothing registers it.");

            // A request that reaches the route with no {token} bound at all.
            $request = Request::create('/survey//step-save', 'POST');
            $request->server->set('REMOTE_ADDR', '203.0.113.77');

            $limit = $resolver($request);
            $limit = is_array($limit) ? ($limit[0] ?? null) : $limit;

            $this->assertInstanceOf(
                Limit::class,
                $limit,
                "Limiter `{$name}` did not resolve to a Limit for a tokenless request."
            );

            $this->assertNotSame(
                INF,
                (float) $limit->maxAttempts,
                "Limiter `{$name}` is unlimited for a tokenless request. These routes are public."
            );

            $this->assertNotSame(
                '',
                (string) $limit->key,
                "Limiter `{$name}` resolves a tokenless request to an EMPTY-STRING key — one "
                .'shared bucket for every such request, from every client on the internet. The '
                .'`?: $request->ip()` fallback is missing.'
            );

            $this->assertSame(
                '203.0.113.77',
                (string) $limit->key,
                "Limiter `{$name}` must fall back to the client IP when there is no token, as "
                .'every `worksheet-*` limiter does.'
            );
        }
    }

    // -- 3. NO SURVEY ROUTE MAY CARRY A BARE throttle:N,1 --------------------

    /**
     * Written as a scan over the live route table rather than a checklist, so a
     * route added tomorrow with a bare `throttle:30,1` fails this test on the
     * day it is added, not after the next outage.
     */
    public function test_no_survey_engineer_route_carries_an_ip_keyed_bare_throttle(): void
    {
        $routes = $this->surveyEngineerRoutes();

        $this->assertNotEmpty($routes, 'No survey engineer routes found — this test would be vacuous.');

        $offenders = [];

        foreach ($routes as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }

                $arg = substr($middleware, strlen('throttle:'));

                // `throttle:60,1` — a bare numeric budget, keyed on client IP.
                if (preg_match('/^\d+\s*,\s*\d+$/', $arg) === 1) {
                    $offenders[] = ($route->getName() ?: $route->uri())." => {$middleware}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These public survey engineer routes are throttled PER CLIENT IP, so every engineer "
            ."behind one site wifi or carrier NAT shares a single bucket:\n  "
            .implode("\n  ", $offenders)
            ."\nThat is the 2026-10-14 outage. Each must use a named per-token limiter, as the "
            .'`worksheet-*` routes already do. Do NOT fix this by removing the throttle.'
        );
    }

    /**
     * The per-minute budget the named route's own limiter resolves to for a
     * given token.
     */
    private function declaredBudgetFor(string $routeName, string $token): int
    {
        $route = RouteFacade::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} is gone.");

        $name = null;
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                $arg = substr($middleware, strlen('throttle:'));
                if (preg_match('/^\d+\s*,\s*\d+$/', $arg) !== 1) {
                    $name = $arg;
                }
            }
        }

        $this->assertNotNull($name, "Route {$routeName} carries no NAMED throttle limiter.");

        $request = Request::create("/survey/{$token}/step-save", 'POST');
        $request->setRouteResolver(fn () => (clone $route)->bind($request));

        $limit = RateLimiter::limiter($name)($request);
        $limit = is_array($limit) ? ($limit[0] ?? null) : $limit;

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(
            $token,
            (string) $limit->key,
            "Limiter `{$name}` is not keyed on the {token} route parameter."
        );

        return (int) $limit->maxAttempts;
    }

    /**
     * The named limiters actually referenced by the survey engineer routes.
     *
     * @return array<int, string>
     */
    private function namedLimitersOnSurveyRoutes(): array
    {
        $names = [];

        foreach ($this->surveyEngineerRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }

                $arg = substr($middleware, strlen('throttle:'));

                if (preg_match('/^\d+\s*,\s*\d+$/', $arg) !== 1) {
                    $names[$arg] = true;
                }
            }
        }

        return array_keys($names);
    }
}
