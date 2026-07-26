<?php

namespace App\Support\Rams;

use InvalidArgumentException;

/**
 * Immutable, typed accessor for the shared RAMS design tokens.
 *
 * Wraps `config('rams_theme')` so that both renderers read from the same
 * source of truth (see phase 260726-rf3-rams-render-unification). Every
 * lookup is bounded — unknown keys throw InvalidArgumentException rather
 * than silently returning null, so a typo in a Blade template or DOCX
 * builder fails loudly at render time instead of shipping a blank cell.
 *
 * Usage:
 *
 *   $theme = app(\App\Support\Rams\RamsTheme::class);
 *   $blue  = $theme->palette('brand_blue');       // '2E74B5' (bare hex)
 *   $font  = $theme->font('body');                // 'Poppins'
 *   $body  = $theme->size('body');                // 10
 *   $mt    = $theme->spacing('section_break_twips'); // 240
 *   $order = $theme->sectionOrder();              // ['cover', 'doc_control', ...]
 *
 * Constructed from `config('rams_theme')` and registered as a singleton
 * in AppServiceProvider::register() so tests that override the config
 * still resolve a single instance per request.
 */
final readonly class RamsTheme
{
    /**
     * @param  array<string, string>  $palette      Bare 6-digit hex, no leading '#'.
     * @param  array<string, string>  $fonts        Font family names.
     * @param  array<string, int>     $sizes        Point sizes.
     * @param  array<string, int>     $spacing      Twip / point spacing values.
     * @param  array<int, string>     $sectionOrder Canonical section slug order.
     */
    public function __construct(
        private array $palette,
        private array $fonts,
        private array $sizes,
        private array $spacing,
        private array $sectionOrder,
    ) {}

    /**
     * Build a RamsTheme instance from `config('rams_theme')`.
     *
     * Factory kept static (not a constructor default) so tests can inject
     * a custom config array without touching the global config repository.
     *
     * @param  array<string, mixed>  $config  Shape must match config/rams_theme.php.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            palette:      (array) ($config['palette']       ?? []),
            fonts:        (array) ($config['fonts']         ?? []),
            sizes:        (array) ($config['sizes']         ?? []),
            spacing:      (array) ($config['spacing']       ?? []),
            sectionOrder: array_values((array) ($config['section_order'] ?? [])),
        );
    }

    /**
     * Palette lookup — returns bare 6-digit uppercase hex (no '#' prefix).
     *
     * @throws InvalidArgumentException When $key is not defined in config('rams_theme.palette').
     */
    public function palette(string $key): string
    {
        if (! array_key_exists($key, $this->palette)) {
            throw new InvalidArgumentException(
                "RamsTheme: unknown palette key '{$key}'. "
                ."Available: ".implode(', ', array_keys($this->palette))
            );
        }

        return (string) $this->palette[$key];
    }

    /**
     * Font family lookup.
     *
     * @throws InvalidArgumentException When $key is not defined in config('rams_theme.fonts').
     */
    public function font(string $key): string
    {
        if (! array_key_exists($key, $this->fonts)) {
            throw new InvalidArgumentException(
                "RamsTheme: unknown font key '{$key}'. "
                ."Available: ".implode(', ', array_keys($this->fonts))
            );
        }

        return (string) $this->fonts[$key];
    }

    /**
     * Point-size lookup (integer pt).
     *
     * @throws InvalidArgumentException When $key is not defined in config('rams_theme.sizes').
     */
    public function size(string $key): int
    {
        if (! array_key_exists($key, $this->sizes)) {
            throw new InvalidArgumentException(
                "RamsTheme: unknown size key '{$key}'. "
                ."Available: ".implode(', ', array_keys($this->sizes))
            );
        }

        return (int) $this->sizes[$key];
    }

    /**
     * Spacing lookup (twips for PhpWord, or points where suffixed `_pt`).
     *
     * @throws InvalidArgumentException When $key is not defined in config('rams_theme.spacing').
     */
    public function spacing(string $key): int
    {
        if (! array_key_exists($key, $this->spacing)) {
            throw new InvalidArgumentException(
                "RamsTheme: unknown spacing key '{$key}'. "
                ."Available: ".implode(', ', array_keys($this->spacing))
            );
        }

        return (int) $this->spacing[$key];
    }

    /**
     * Canonical section slug order (16 slugs, matching Sections/ DTOs).
     *
     * @return array<int, string>
     */
    public function sectionOrder(): array
    {
        return $this->sectionOrder;
    }

    /**
     * Emit a `<style>:root { --palette-key: #HEX; ... }</style>` block for
     * inclusion in the PDF blade `<head>` so every hex colour in the
     * template resolves via `var(--...)` from a single source of truth.
     *
     * Consumed by `resources/views/pdf/rams-v2.blade.php` (phase 260726-rf3
     * Plan 03) — the legacy `pdf.rams` blade keeps its inline hex constants
     * until Plan 03's kill switch is flipped globally in Plan 05.
     *
     * Also exposes font + size tokens so the blade can bind
     * `body { font-family: var(--font-body), var(--font-fallback); }` etc.
     *
     * Returns the raw `<style>...</style>` markup (already-escaped, safe to
     * emit via `{!! ... !!}`) so the blade does not need to compose the
     * declaration list itself.
     */
    public function paletteCss(): string
    {
        $lines = [':root {'];

        foreach ($this->palette as $key => $hex) {
            $var = '--palette-' . str_replace('_', '-', $key);
            $lines[] = "    {$var}: #{$hex};";
        }

        foreach ($this->fonts as $key => $family) {
            $var = '--font-' . str_replace('_', '-', $key);
            $lines[] = "    {$var}: {$family};";
        }

        foreach ($this->sizes as $key => $pt) {
            $var = '--size-' . str_replace('_', '-', $key);
            $lines[] = "    {$var}: {$pt}pt;";
        }

        $lines[] = '}';

        return '<style>' . implode("\n", $lines) . '</style>';
    }

    /**
     * Introspection — expose the whole token tree for snapshot tests.
     *
     * @return array{palette: array<string, string>, fonts: array<string, string>, sizes: array<string, int>, spacing: array<string, int>, section_order: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'palette'       => $this->palette,
            'fonts'         => $this->fonts,
            'sizes'         => $this->sizes,
            'spacing'       => $this->spacing,
            'section_order' => $this->sectionOrder,
        ];
    }
}
