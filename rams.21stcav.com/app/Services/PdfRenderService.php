<?php

namespace App\Services;

use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

/**
 * Single Browsershot wrapper used by every PDF pipeline (RAMS, O&M,
 * Site Survey). Replaces the legacy dompdf calls that previously lived
 * inline inside PdfService and SurveyPdfService.
 *
 * On production (AlmaLinux 8) full Chrome 147 fails with crashpad, so we
 * point Browsershot at chrome-headless-shell via the /home/stcav/chrome
 * symlink. The path can be overridden in non-prod via CHROME_PATH.
 *
 * Chromium is invoked with --no-sandbox, --disable-dev-shm-usage, and
 * --disable-setuid-sandbox so it runs reliably under the stcav user
 * inside CWP without requiring root.
 *
 * Running headers/footers (the equivalent of dompdf's `position: fixed`
 * trick that worked there but doesn't repeat in Chromium) are passed as
 * separate HTML strings via the $options array — Chromium repeats those
 * automatically on every page and supports `<span class="pageNumber">` /
 * `<span class="totalPages">` placeholders.
 */
class PdfRenderService
{
    /**
     * Render a Blade view to PDF.
     *
     * @param  string  $view  Blade view name (e.g. 'pdf.rams').
     * @param  array  $data  View data passed to view($view, $data).
     * @param  string|null  $writeToPath  When provided, PDF is saved to this absolute
     *                                    path and the path is returned. When null,
     *                                    raw PDF bytes are returned instead.
     * @param  array  $options  Optional render overrides:
     *                          - 'headerHtml' (string) — repeats on every page;
     *                          may contain <span class="pageNumber"></span>
     *                          and <span class="totalPages"></span>.
     *                          - 'footerHtml' (string) — repeats on every page.
     *                          - 'marginTop' (int|float, mm) — overrides default 10.
     *                          Bump up when supplying a headerHtml so the body
     *                          doesn't bleed into the running header.
     *                          - 'marginBottom' (int|float, mm) — overrides default 10.
     *                          Bump up when supplying a footerHtml.
     *                          - 'marginLeft' / 'marginRight' (int|float, mm) — defaults 10.
     *                          - 'waitForJs' (bool, default false) — when true,
     *                          Browsershot waits for window.__drawingReady === true.
     *                          Used by Phase 17 schematic edit-override and Phase 19
     *                          Konva renders. The Blade view is responsible for setting
     *                          window.__drawingReady = true after client-side rendering.
     *                          Default false keeps every existing call site (RAMS / O&M /
     *                          Site Survey) byte-for-byte identical.
     * @return string Absolute file path (when $writeToPath is provided) or raw
     *                PDF bytes (when null).
     *
     * @throws \RuntimeException When the Blade view cannot be located.
     */
    public function fromBlade(
        string $view,
        array $data,
        ?string $writeToPath = null,
        array $options = [],
    ): string {
        if (! View::exists($view)) {
            throw new \RuntimeException(
                'PDF template missing: resources/views/'.str_replace('.', '/', $view).'.blade.php'
            );
        }

        // Pre-flight the puppeteer dependency BEFORE invoking Browsershot so we
        // give operators an actionable message instead of a 4KB Node stack
        // trace pasted into the flash message. Browsershot's browser.cjs
        // does `require('puppeteer')` — if node_modules/puppeteer is absent
        // (the classic "post-deploy forgot npm install" case) Chromium never
        // starts and the exception surfaces as "Cannot find module 'puppeteer'".
        self::assertPuppeteerInstalled();

        $html = view($view, $data)->render();

        $marginTop = $options['marginTop'] ?? 10;
        $marginRight = $options['marginRight'] ?? 10;
        $marginBottom = $options['marginBottom'] ?? 10;
        $marginLeft = $options['marginLeft'] ?? 10;

        $shot = Browsershot::html($html)
            ->noSandbox()
            ->addChromiumArguments([
                'disable-dev-shm-usage' => null,
                'disable-setuid-sandbox' => null,
            ])
            ->format('A4')
            ->showBackground()
            ->emulateMedia('print')
            ->margins($marginTop, $marginRight, $marginBottom, $marginLeft)
            // Photo-heavy PDFs (Mini O&M, Survey Client Report) base64-inline
            // images, which produces large HTML payloads. Puppeteer's default
            // protocol timeout (30s) and process timeout aren't enough — bump
            // both. Override per-call via options['timeoutSeconds'] /
            // options['protocolTimeoutMs'].
            ->timeout($options['timeoutSeconds'] ?? 180)
            ->setOption('protocolTimeout', $options['protocolTimeoutMs'] ?? 180000);

        $chromePath = (string) env('CHROME_PATH', '/home/stcav/chrome');
        if ($chromePath !== '' && is_file($chromePath)) {
            $shot->setChromePath($chromePath);
        }
        // Otherwise: Browsershot/puppeteer will use the bundled Chromium from
        // node_modules/puppeteer's download cache. Prod sets CHROME_PATH (or
        // ships the binary at /home/stcav/chrome); dev falls back to bundled.

        // Running header/footer repeated on every page (Chromium-native).
        // Whichever is supplied is enabled — the other side stays blank.
        if (! empty($options['headerHtml']) || ! empty($options['footerHtml'])) {
            $shot->showBrowserHeaderAndFooter();
            // Default both to a blank span so the side that wasn't supplied
            // doesn't fall back to Chromium's date/title defaults.
            $shot->headerHtml($options['headerHtml'] ?? '<span></span>');
            $shot->footerHtml($options['footerHtml'] ?? '<span></span>');
        } else {
            $shot->hideBrowserHeaderAndFooter();
        }

        // Phase 17 / Phase 19 — opt-in: wait for client-side rendering to flag
        // completion before snapshotting. The Blade view sets
        //   window.__drawingReady = true
        // after Konva (or any other JS render) has settled.
        if (! empty($options['waitForJs'])) {
            $shot->waitUntilNetworkIdle()
                ->waitForFunction('window.__drawingReady === true');
        }

        if ($writeToPath !== null) {
            $dir = dirname($writeToPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $shot->savePdf($writeToPath);

            return $writeToPath;
        }

        return $shot->pdf();
    }

    /**
     * Render a Blade view to PNG via Browsershot screenshot — uses the SAME
     * Browsershot construction (chrome path, no-sandbox, --disable-dev-shm-usage,
     * --disable-setuid-sandbox) as fromBlade(). Phase 17 schematics, Phase 19
     * floor plans, and Phase 20 thumbnails ALL go through this method so any
     * future hardening (CRIT-03 chrome flags, dedicated queue) lands in one
     * place rather than being duplicated per renderer.
     *
     * @param  string  $view  Blade view name.
     * @param  array  $data  View data.
     * @param  string|null  $writeToPath  Absolute output path; if null, raw PNG bytes returned.
     * @param  array  $options  Optional:
     *                          - 'waitForJs' (bool, default false) — same semantics as fromBlade.
     *                          - 'widthPx'   (int, default 1920)  — Browsershot windowSize width.
     *                          - 'heightPx'  (int, default null)  — when null, computed as
     *                          widthPx * 0.707 (A4 portrait
     *                          aspect; pass explicit value
     *                          for thumbnails, e.g. widthPx=400).
     * @return string Absolute file path (when $writeToPath provided) or raw PNG bytes.
     *
     * @throws \RuntimeException When the Blade view cannot be located.
     */
    public function fromBladeAsPng(
        string $view,
        array $data,
        ?string $writeToPath = null,
        array $options = [],
    ): string {
        if (! View::exists($view)) {
            throw new \RuntimeException(
                'PNG template missing: resources/views/'.str_replace('.', '/', $view).'.blade.php'
            );
        }

        // Same pre-flight as fromBlade() — the PNG path also hits Chromium.
        self::assertPuppeteerInstalled();

        $html = view($view, $data)->render();
        $widthPx = (int) ($options['widthPx'] ?? 1920);
        $heightPx = (int) ($options['heightPx'] ?? intval($widthPx * 0.707));

        $shot = Browsershot::html($html)
            ->noSandbox()
            ->addChromiumArguments([
                'disable-dev-shm-usage' => null,
                'disable-setuid-sandbox' => null,
            ])
            ->showBackground()
            ->emulateMedia('print')
            ->windowSize($widthPx, $heightPx);

        $chromePath = (string) env('CHROME_PATH', '/home/stcav/chrome');
        if ($chromePath !== '' && is_file($chromePath)) {
            $shot->setChromePath($chromePath);
        }
        // Otherwise: bundled Chromium from puppeteer (same fallback as fromBlade).

        if (! empty($options['waitForJs'])) {
            $shot->waitUntilNetworkIdle()
                ->waitForFunction('window.__drawingReady === true');
        }

        if ($writeToPath !== null) {
            $dir = dirname($writeToPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            // Browsershot infers PNG format from the .png extension.
            $shot->save($writeToPath);

            return $writeToPath;
        }

        // Raw PNG bytes — Browsershot::screenshot() returns binary PNG.
        return $shot->screenshot();
    }

    /**
     * Verify node_modules/puppeteer is installed before invoking Browsershot.
     *
     * Browsershot's bin/browser.cjs script literally does `require('puppeteer')`,
     * so if the package isn't in node_modules the shell command exits 1 with a
     * "MODULE_NOT_FOUND" trace. We surface a friendlier operator-facing message
     * pointing at the actual fix — running `npm install` in the project root.
     *
     * The check is a fast filesystem stat, negligible overhead compared to the
     * Chromium spawn that follows. Skips silently in tests so unit test suites
     * that never actually spawn Chromium (they use fakes / mock the service)
     * don't need a full node_modules install in the CI worker.
     *
     * @throws \RuntimeException When puppeteer's package.json is missing from
     *                          the resolved node_modules path.
     */
    private static function assertPuppeteerInstalled(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $candidatePath = base_path('node_modules/puppeteer/package.json');
        if (is_file($candidatePath)) {
            return;
        }

        throw new \RuntimeException(
            'PDF generation is offline: the puppeteer npm module is missing on this server. '
            . 'Fix: SSH to the server and run `cd ' . base_path() . ' && npm install --omit=dev` '
            . '(as the app user). This installs the JavaScript client Browsershot uses to drive '
            . 'the headless Chromium binary at $CHROME_PATH. Retry the download once npm install '
            . 'finishes. See docs: package.json declares puppeteer as a runtime dependency.'
        );
    }
}
