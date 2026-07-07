<?php

namespace Tests\Unit\Services;

use App\Services\PdfRenderService;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Tests\TestCase;

/**
 * The puppeteer pre-flight guard must:
 *
 *   1. Skip silently under phpunit — most unit tests fake the service or
 *      never invoke rendering; forcing the guard to check node_modules
 *      would require every CI worker to run npm install.
 *   2. Fire a RuntimeException with actionable copy when puppeteer is
 *      genuinely missing. The message must name the fix command
 *      (`npm install`) so an operator reading a flash message can act
 *      without opening the source.
 *
 * The class-level guard is private so we invoke it via reflection.
 */
class PdfRenderServiceGuardTest extends TestCase
{
    public function test_guard_skips_silently_in_test_environment(): void
    {
        // Under phpunit runningUnitTests() is true — the guard early-returns
        // without touching the filesystem. Prove that by calling it and
        // asserting no exception.
        $ref = new \ReflectionClass(PdfRenderService::class);
        $method = $ref->getMethod('assertPuppeteerInstalled');
        $method->setAccessible(true);

        $method->invoke(null);

        $this->assertTrue(true, 'assertPuppeteerInstalled should no-op under runningUnitTests().');
    }

    public function test_guard_message_names_the_fix_command_and_package_source(): void
    {
        // Message contract — flash-message-friendly copy. Operators see this
        // text in the RAMS list page's alert-error strip when they hit the
        // PDF button; it has to include the exact npm command, the app root
        // path, and a hint that package.json owns the dep. If any of these
        // strings drift, this test flags before it ships.
        $ref = new \ReflectionClass(PdfRenderService::class);
        $method = $ref->getMethod('assertPuppeteerInstalled');
        $method->setAccessible(true);

        // Bypass the runningUnitTests short-circuit by extracting the
        // exception's expected wording from the source. This test doesn't
        // execute the guard against a real filesystem — it locks the
        // wording so a future change can't silently swap "npm install"
        // for a less useful hint like "check config".
        $source = file_get_contents($ref->getFileName());

        $this->assertStringContainsString('puppeteer npm module is missing', $source);
        $this->assertStringContainsString('npm install --omit=dev', $source);
        $this->assertStringContainsString('$CHROME_PATH', $source);
        $this->assertStringContainsString('package.json declares puppeteer', $source);
    }
}
