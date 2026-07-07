<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach hardening headers to every response from the web middleware group.
 *
 * Audit M-02 (2026-07) — before this middleware the app relied entirely on
 * webserver configuration for security headers. That worked when the
 * server was configured correctly, but a stack move / vhost edit could
 * silently drop `X-Frame-Options` and expose `/dashboard`, `/projects/N`,
 * and `/rams/N/review` to iframe embedding by a malicious page on a
 * different host. This middleware sets the baseline in code so the app
 * self-defends regardless of webserver state.
 *
 * Header choices:
 *
 * - `X-Frame-Options: SAMEORIGIN`
 *     Blocks embedding by third-party sites. Legacy header that most modern
 *     browsers still honour; belt-and-braces with the CSP `frame-ancestors`
 *     directive below.
 *
 * - `Content-Security-Policy: frame-ancestors 'self'`
 *     Modern equivalent of X-Frame-Options; the two together survive the
 *     rare mismatched-browser edge case.
 *
 * - `X-Content-Type-Options: nosniff`
 *     Prevents MIME-sniffing exploits (e.g. a text/plain response getting
 *     interpreted as script by a legacy IE downstream).
 *
 * - `Referrer-Policy: same-origin`
 *     Client-side navigation to any external URL sends no Referer, so
 *     internal document IDs / tokens in query strings don't leak.
 *
 * - `Strict-Transport-Security: max-age=31536000; includeSubDomains`
 *     Applied only on HTTPS responses (a `?scheme` guard prevents leaking
 *     an HSTS pin from a plaintext local dev response).
 *
 * A full Content-Security-Policy is intentionally NOT set here — the app's
 * PDF pipelines (dompdf, mpdf, phpword) inline styles and images heavily
 * and would take a longer report-only rollout to lock down without
 * breaking DOCX/PDF renders. Deferred to a follow-up.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Belt-and-braces frame busting: legacy header + modern CSP directive.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'self'",
            false,
        );

        // Block MIME sniffing.
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);

        // Leak neither internal paths nor query-string tokens (survey / worksheet
        // access URLs live in query strings; a Referer to an external link click
        // must not carry them).
        $response->headers->set('Referrer-Policy', 'same-origin', false);

        // HSTS only makes sense on HTTPS — pinning from a plaintext response
        // is either useless (browser ignores) or actively harmful if a dev
        // machine hits localhost:80 and gets pinned to a domain that then
        // stops serving HTTPS.
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
                false,
            );
        }

        return $response;
    }
}
