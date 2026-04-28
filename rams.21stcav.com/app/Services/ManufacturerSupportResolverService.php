<?php

namespace App\Services;

use App\Models\Manufacturer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resolves UK support details (phone / email / URL / warranty) for a named
 * manufacturer. Phase 2 of the Tier 1 O&M Manual upgrade — NO TBC POLICY.
 *
 * Lookup order:
 *   1. DB hit on `manufacturers.slug` — seeded entries answer most calls.
 *   2. Web lookup against the manufacturer's own contact / support page,
 *      using only an allow-listed primary domain. Any extracted phone /
 *      email is cached back into the DB.
 *   3. If neither yields a record with at least one usable contact channel
 *      (phone OR email OR url), throw RuntimeException — generation aborts.
 *
 * Web lookup is deliberately constrained to known official domains: this
 * avoids the brittleness and trust issues of full search-engine scraping.
 * For new brands not yet seeded, an admin should add them to the seeder
 * (or via a future admin UI) rather than relying on automated discovery.
 */
class ManufacturerSupportResolverService
{
    /** Allow-listed primary domains keyed by slug — only these are fetched. */
    private const DOMAIN_HINTS = [
        'biamp'        => 'biamp.com',
        'logitech'     => 'logi.com',
        'shure'        => 'shure.com',
        'sony'         => 'sony.com',
        'iiyama'       => 'iiyama.com',
        'unicol'       => 'unicol.com',
        'samsung'      => 'samsung.com',
        'lg'           => 'lg.com',
        'barco'        => 'barco.com',
        'crestron'     => 'crestron.com',
        'qsc'          => 'qsc.com',
        'q-sys'        => 'qsys.com',
        'qsys'         => 'qsys.com',
        'sennheiser'   => 'sennheiser.com',
        'yealink'      => 'yealink.com',
        'panasonic'    => 'panasonic.com',
    ];

    /** Candidate paths probed on the manufacturer's domain. */
    private const CONTACT_PATHS = [
        '/uk/support', '/en-gb/support', '/en-gb/contact',
        '/uk/contact', '/contact', '/contact-us', '/support',
    ];

    private const HTTP_TIMEOUT_SECONDS = 5;

    /**
     * @return array{
     *   name: string,
     *   support_phone: string|null,
     *   support_email: string|null,
     *   support_url: string|null,
     *   warranty_years: int|null
     * }
     *
     * @throws RuntimeException When neither DB nor web lookup yields a usable record.
     */
    public function resolve(string $manufacturerName): array
    {
        $name = trim($manufacturerName);
        if ($name === '') {
            throw new RuntimeException('Manufacturer name is required.');
        }

        $record = Manufacturer::findByName($name);
        if ($record !== null && $this->isUsable($record)) {
            return $this->toArray($record);
        }

        $found = $this->webLookup($name);

        if ($record === null) {
            $record = Manufacturer::create([
                'name'           => $name,
                'slug'           => Str::slug($name),
                'support_phone'  => $found['support_phone']  ?? null,
                'support_email'  => $found['support_email']  ?? null,
                'support_url'    => $found['support_url']    ?? null,
                'warranty_years' => $found['warranty_years'] ?? null,
            ]);
        } else {
            // Cache discovered fields without overwriting existing values.
            $record->fill(array_filter([
                'support_phone' => $record->support_phone ?: ($found['support_phone'] ?? null),
                'support_email' => $record->support_email ?: ($found['support_email'] ?? null),
                'support_url'   => $record->support_url   ?: ($found['support_url']   ?? null),
            ], fn ($v) => $v !== null && $v !== ''))->save();
        }

        $record->refresh();

        if (! $this->isUsable($record)) {
            throw new RuntimeException(
                "Could not resolve UK support details for manufacturer '{$name}'. "
                . 'Add an entry to the manufacturers table or update the seeder.'
            );
        }

        return $this->toArray($record);
    }

    /**
     * A record is usable iff it carries at least one contact channel — phone,
     * email, or support URL. Warranty alone isn't enough.
     */
    private function isUsable(Manufacturer $m): bool
    {
        return ! empty($m->support_phone)
            || ! empty($m->support_email)
            || ! empty($m->support_url);
    }

    private function toArray(Manufacturer $m): array
    {
        return [
            'name'           => $m->name,
            'support_phone'  => $m->support_phone,
            'support_email'  => $m->support_email,
            'support_url'    => $m->support_url,
            'warranty_years' => $m->warranty_years,
        ];
    }

    /**
     * Probe the manufacturer's allow-listed domain for a contact / support
     * page. On success, return the first plausible phone / email / URL.
     *
     * @return array{support_phone?:string|null, support_email?:string|null, support_url?:string|null}
     */
    private function webLookup(string $manufacturerName): array
    {
        $slug   = Str::slug($manufacturerName);
        $domain = self::DOMAIN_HINTS[$slug] ?? null;

        if ($domain === null) {
            Log::info('ManufacturerSupportResolver: no domain hint, skipping web lookup', [
                'manufacturer' => $manufacturerName,
            ]);
            return [];
        }

        foreach (self::CONTACT_PATHS as $path) {
            $url = "https://www.{$domain}{$path}";
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; 21CAV-RAMS/1.0)'])
                    ->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $html  = (string) $response->body();
                $email = $this->extractEmail($html, $domain);
                $phone = $this->extractPhone($html);

                if ($email !== null || $phone !== null) {
                    Log::info('ManufacturerSupportResolver: web lookup hit', [
                        'manufacturer' => $manufacturerName,
                        'url'          => $url,
                        'has_email'    => $email !== null,
                        'has_phone'    => $phone !== null,
                    ]);
                    return [
                        'support_phone' => $phone,
                        'support_email' => $email,
                        'support_url'   => $url,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('ManufacturerSupportResolver: web lookup error', [
                    'manufacturer' => $manufacturerName,
                    'url'          => $url,
                    'error'        => $e->getMessage(),
                ]);
                continue;
            }
        }

        return [];
    }

    /**
     * Extract a support@ / info@ / contact@ email — but only when its host
     * matches the allow-listed primary domain (no third-party leakage).
     */
    private function extractEmail(string $html, string $primaryDomain): ?string
    {
        if (preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $html, $matches) === false) {
            return null;
        }
        foreach ($matches[0] as $email) {
            $host = strtolower(substr(strrchr($email, '@'), 1));
            if (str_ends_with($host, strtolower($primaryDomain))) {
                return strtolower($email);
            }
        }
        return null;
    }

    /**
     * Extract a UK-format phone (0xxx, +44, or freephone) — first plausible
     * match. Liberal regex; consumers should treat as best-effort hint.
     */
    private function extractPhone(string $html): ?string
    {
        $patterns = [
            '/\+44[\s\d]{8,14}/',
            '/\b0[1-9]\d{1,4}[\s\d]{4,10}\b/',
            '/\b0800[\s\d]{6,10}\b/',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $html, $m) === 1) {
                return trim($m[0]);
            }
        }
        return null;
    }
}
