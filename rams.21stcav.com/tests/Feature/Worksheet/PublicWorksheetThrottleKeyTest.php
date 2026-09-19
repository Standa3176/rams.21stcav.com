<?php

namespace Tests\Feature\Worksheet;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quick task 260919-fq8 — per-token throttle keys for public worksheet routes.
 *
 * Proves the actual point of the task: two different worksheet tokens do NOT
 * share a rate-limit bucket, even when every request comes from the same
 * test-client IP (the only variable that changes between tokenA and tokenB
 * requests here is the token itself — this is deliberate, see Test 1).
 *
 * Also proves every named limiter registered in AppServiceProvider::boot()
 * actually resolves via a REAL HTTP request. `route:list` cannot catch a
 * route referencing an unregistered limiter — only a request that runs the
 * `throttle:{name}` middleware surfaces that failure (a 500).
 */
class PublicWorksheetThrottleKeyTest extends TestCase
{
    use RefreshDatabase;

    // Matches worksheet-sign's Limit::perMinute(30) in AppServiceProvider.
    // Kept as a named constant (not re-derived from the container) so this
    // test fails loudly and obviously if the limiter's cap ever drifts from
    // what this test assumes, rather than silently degrading into a no-op.
    private const SIGN_LIMIT_PER_MINUTE = 30;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeWorksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Throttle Key Test Project',
            'project_ref'  => 'Q-THROTTLE-1',
            'client_name'  => 'Throttle Test Client',
            'site_address' => '1 Throttle Street, London',
            'status'       => Worksheet::STATUS_DRAFT,
            'generated_data' => [
                'project' => [
                    'name'             => 'Throttle Key Test Project',
                    'client_name'      => 'Throttle Test Client',
                    'site_address'     => '1 Throttle Street, London',
                    'quote_reference'  => 'Q-THROTTLE-1',
                ],
                'rooms' => [],
            ],
        ]);
    }

    private function pngFixturePath(): string
    {
        $path = base_path('tests/Fixtures/1x1.png');
        if (! file_exists($path)) {
            $im = imagecreatetruecolor(1, 1);
            imagesavealpha($im, true);
            $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
            imagefill($im, 0, 0, $transparent);
            imagepng($im, $path);
            imagedestroy($im);
        }

        return $path;
    }

    private function pngBase64(): string
    {
        return base64_encode(file_get_contents($this->pngFixturePath()));
    }

    private function signPayload(string $clientName): array
    {
        return [
            'client_name'          => $clientName,
            'signature_image'      => 'data:image/png;base64,' . $this->pngBase64(),
            'happy_with_work'      => '1',
            'signed_with_comments' => '0',
            'comments'             => null,
        ];
    }

    // ── Test 1 (load-bearing): bucket isolation across tokens ────────────────

    public function test_sign_throttle_bucket_is_isolated_per_worksheet_token(): void
    {
        $worksheetA = $this->makeWorksheet();
        $worksheetB = $this->makeWorksheet();

        $this->assertNotSame($worksheetA->access_token, $worksheetB->access_token);

        $lastStatus = null;

        // Exhaust tokenA's bucket: SIGN_LIMIT_PER_MINUTE allowed + 1 over.
        // All requests come from the test client's default IP — the ONLY
        // variable across requests is the worksheet token, which is exactly
        // the thing this plan changed the limiter key to.
        for ($i = 0; $i < self::SIGN_LIMIT_PER_MINUTE + 1; $i++) {
            $response = $this->post(
                route('public-worksheet.sign', ['token' => $worksheetA->access_token]),
                $this->signPayload('Client A Attempt ' . $i),
            );
            $lastStatus = $response->getStatusCode();
        }

        $this->assertSame(
            429,
            $lastStatus,
            'Expected tokenA\'s bucket to be exhausted (429) after '
                . (self::SIGN_LIMIT_PER_MINUTE + 1) . ' requests to worksheet-sign.',
        );

        // TokenB must NOT be 429 from the same IP — proves the bucket key is
        // the token, not the IP, and tokenA's exhaustion did not leak.
        $responseB = $this->post(
            route('public-worksheet.sign', ['token' => $worksheetB->access_token]),
            $this->signPayload('Client B'),
        );

        $this->assertNotSame(
            429,
            $responseB->getStatusCode(),
            'TokenB must have its own independent throttle bucket — it should not '
                . 'inherit tokenA\'s exhausted bucket just because both requests came '
                . 'from the same test-client IP.',
        );
    }

    // ── Test 2: registration smoke test — every named limiter resolves ───────

    public function test_all_named_worksheet_limiters_resolve_without_a_server_error(): void
    {
        $worksheet = $this->makeWorksheet();
        $token     = $worksheet->access_token;

        // worksheet-photo-write (delete branch — nonexistent photo id is a
        // clean way to exercise the route without needing a stored photo;
        // the assertion is "not 500", not a specific success status).
        $photoWriteResponse = $this->delete(
            route('public-worksheet.photos.delete', ['token' => $token, 'photo' => 999999]),
        );
        $this->assertNotSame(500, $photoWriteResponse->getStatusCode(),
            'worksheet-photo-write limiter must resolve without a server error.');

        // worksheet-label-photo-upload — empty payload fails validation
        // (422), which is fine; a 500 here would mean the limiter itself
        // isn't registered.
        $labelUploadResponse = $this->post(
            route('public-worksheet.label-photo.upload', ['token' => $token]),
            [],
        );
        $this->assertNotSame(500, $labelUploadResponse->getStatusCode(),
            'worksheet-label-photo-upload limiter must resolve without a server error.');

        // worksheet-status-write — files.serve with a nonexistent file id.
        $statusWriteResponse = $this->get(
            route('public-worksheet.files.serve', ['token' => $token, 'file' => 999999]),
        );
        $this->assertNotSame(500, $statusWriteResponse->getStatusCode(),
            'worksheet-status-write limiter must resolve without a server error.');

        // worksheet-survey-photo-read — nonexistent survey photo id.
        $surveyPhotoReadResponse = $this->get(
            route('public-worksheet.survey-photos.serve', ['token' => $token, 'photo' => 999999]),
        );
        $this->assertNotSame(500, $surveyPhotoReadResponse->getStatusCode(),
            'worksheet-survey-photo-read limiter must resolve without a server error.');
    }

    // ── Test 3: fallback key — invalid/expired token still resolves cleanly ──

    public function test_throttled_route_with_unknown_token_still_resolves_without_server_error(): void
    {
        // No worksheet exists for this token. resolveWorksheet() 404s inside
        // the controller, but that only fires AFTER the throttle:worksheet-sign
        // middleware has already run and resolved its key from the route's
        // {token} parameter — which is always a non-empty string segment for
        // these routes (the URL requires it), so the `?: $request->ip()`
        // fallback is not expected to engage here. This test documents the
        // OBSERVED behaviour rather than assuming it.
        $unknownToken = (string) Str::uuid();

        $response = $this->post(
            route('public-worksheet.sign', ['token' => $unknownToken]),
            $this->signPayload('Nobody'),
        );

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'An unknown token should 404 via resolveWorksheet(), not 500 — proving the '
                . 'throttle middleware resolved its key and let the request through to '
                . 'the controller.',
        );
    }
}
