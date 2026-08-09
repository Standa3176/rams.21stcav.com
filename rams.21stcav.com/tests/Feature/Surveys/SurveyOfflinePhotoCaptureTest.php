<?php

namespace Tests\Feature\Surveys;

use Tests\TestCase;

/**
 * Static-source guard for quick task 260809-so1 — Survey offline-first photo
 * capture ported from the Worksheet via a shared inline Blade partial.
 *
 * Before: surveys/show.blade.php uploadPhoto() posted the raw File and swallowed
 * every error — offline/flaky-4G photos were silently lost and iOS HEIC was
 * rejected by the server validator + vision OCR.
 *
 * After: a shared partial (resources/views/partials/_engineer-offline-capture.blade.php)
 * exposes window.OfflineQueue + convertToJpegBlob(Safe); the Survey re-encodes to
 * JPEG then persists to IndexedDB on offline/failure and auto-retries on reconnect.
 *
 * These are source-shape assertions (the module is client-side JS with no server
 * entry point to exercise), mirroring the repo's existing view-parity test style.
 *
 * @see resources/views/partials/_engineer-offline-capture.blade.php
 * @see resources/views/surveys/show.blade.php
 * @see resources/views/worksheets/public-show.blade.php (source — must stay unchanged)
 */
class SurveyOfflinePhotoCaptureTest extends TestCase
{
    private function partial(): string
    {
        return file_get_contents(resource_path('views/partials/_engineer-offline-capture.blade.php'));
    }

    private function survey(): string
    {
        return file_get_contents(resource_path('views/surveys/show.blade.php'));
    }

    private function worksheet(): string
    {
        return file_get_contents(resource_path('views/worksheets/public-show.blade.php'));
    }

    // (a) The shared partial exists and exposes the expected globals + a DISTINCT DB.

    public function test_partial_exposes_offline_queue_and_heic_helpers(): void
    {
        $partial = $this->partial();

        $this->assertStringContainsString('window.OfflineQueue', $partial);
        $this->assertStringContainsString('window.convertToJpegBlob', $partial);
        $this->assertStringContainsString('window.convertToJpegBlobSafe', $partial);
        $this->assertStringContainsString('window.mountOfflineChip', $partial);
    }

    public function test_partial_uses_a_distinct_indexeddb_from_the_worksheet(): void
    {
        $partial = $this->partial();

        // Own DB name so the two queues never surface each other's rows.
        $this->assertStringContainsString("'engineer-capture'", $partial);
        $this->assertStringNotContainsString("'engineer-worksheet'", $partial);

        // Generalised record carries its own endpoint (not a hardcoded path).
        $this->assertStringContainsString('row.endpoint', $partial);

        // Terminal-vs-retryable classification is present.
        $this->assertStringContainsString('413', $partial);
        $this->assertStringContainsString('415', $partial);
        $this->assertStringContainsString('422', $partial);
    }

    // (b) The Survey includes the partial and routes uploads through the queue,
    //     while preserving the exact Alpine success push shape.

    public function test_survey_includes_the_shared_partial(): void
    {
        $this->assertStringContainsString(
            "@include('partials._engineer-offline-capture')",
            $this->survey(),
        );
    }

    public function test_survey_upload_photo_uses_heic_reencode_and_offline_queue(): void
    {
        $survey = $this->survey();

        $this->assertStringContainsString('convertToJpegBlobSafe', $survey);
        $this->assertStringContainsString('window.OfflineQueue.enqueue', $survey);

        // Endpoint, field name and CSRF header are unchanged.
        $this->assertStringContainsString("'/survey/' + this.token + '/rooms/' + roomId + '/photos'", $survey);
        $this->assertStringContainsString("formData.append('photo',", $survey);
        $this->assertStringContainsString("formData.append('category', category)", $survey);
    }

    public function test_survey_preserves_the_alpine_photos_push_success_shape(): void
    {
        $survey = $this->survey();

        // The canonical success push must survive the rewrite intact.
        $this->assertStringContainsString('this.rooms[this.currentRoomIdx].photos.push({', $survey);
        $this->assertStringContainsString('type:      res.category ?? category', $survey);
        $this->assertStringContainsString("caption:   res.caption ?? ''", $survey);
        $this->assertStringContainsString("file_path: res.url ?? ''", $survey);
    }

    // (c) The Worksheet is untouched — its own queue/HEIC + DB name still present.

    public function test_worksheet_offline_machinery_is_unchanged(): void
    {
        $worksheet = $this->worksheet();

        $this->assertStringContainsString("'engineer-worksheet'", $worksheet);
        $this->assertStringContainsString('OfflineQueue', $worksheet);
        $this->assertStringContainsString('convertToJpegBlob', $worksheet);
    }
}
