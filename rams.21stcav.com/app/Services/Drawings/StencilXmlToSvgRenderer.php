<?php

namespace App\Services\Drawings;

use DOMDocument;
use DOMElement;

/**
 * Phase 24 Plan 04 (DRAW-51, D-16) — bounded mxGraph `<shape>` stencil-XML
 * to `<svg>` translator, used ONLY by the admin curation preview endpoint
 * (`DeviceStencilController::preview`). Persists nothing; render-only.
 *
 * Translates the FULL bounded grammar `AutoGenericStencilGenerator::emitShape()`
 * emits (roundrect / rect / line / text / fillcolor / strokecolor / fontcolor /
 * fontsize / fontstyle / strokewidth / dashed / strokealpha / fill /
 * fillstroke / stroke / connections) into equivalent SVG primitives, walking
 * a state machine exactly like the real draw.io/mxGraph engine does — never
 * looking for style attributes directly on `<line>` itself.
 *
 * ── Grammar ground truth (plan-checker correction, verified NOT the seed
 *    pack — the seed pack has zero `dashed`/`strokealpha` precedent) ───────
 *
 *   - `<dashed dashed="1"/>` / `<dashed dashed="0"/>` — attribute is named
 *     `dashed`, NOT `value`. public/vendor/drawio/mxgraph/src/shape/mxStencil.js:895
 *     `canvas.setDashed(node.getAttribute('dashed') == '1')`.
 *   - `<strokealpha alpha="0.6"/>` / `<strokealpha alpha="1"/>` — attribute
 *     is named `alpha`, a 0.0-1.0 FRACTION, never a 0-100 percentage.
 *     mxStencil.js:948-951 + docblock :90-91. `alpha`/`fillalpha`/
 *     `strokealpha` are three identical branches all calling `setAlpha` —
 *     GLOBAL state, so it mutes `<text>` fill-opacity too, not just line
 *     stroke-opacity (mxSvgCanvas2D.js:1137 would clamp an out-of-range
 *     value to fully opaque if it ever leaked through as a raw percentage).
 *
 * Both are tracked here as SCOPED PARSER STATE across sibling elements,
 * exactly like `fillcolor`/`strokecolor` already are — never as an
 * attribute read off `<line>` itself (no such attribute exists in the real
 * grammar, and `AutoGenericStencilGenerator` never emits one).
 *
 * ── Threat model (T-24-09, mitigate) ───────────────────────────────────────
 * The posted `ports` array (label/connector_type text) is client-controlled
 * and flows through `AutoGenericStencilGenerator::xml()`'s
 * `htmlspecialchars(ENT_XML1 | ENT_QUOTES)` escaper before it ever reaches
 * this renderer. This class deliberately does NOT re-escape `<text str="...">`
 * values a second time: `DOMElement::getAttribute()` DOM-decodes the
 * already-escaped value back to its literal string once, and
 * `DOMDocument::createTextNode()` + `saveXML()` re-encodes it once more for
 * the new SVG document — a single safe round-trip, never a double-escape,
 * and never a re-interpretation of escaped markup as live markup.
 *
 * `<connections>` and its `<constraint>` children carry no visual styling
 * (Plan 24-01 Task 3 note) and are skipped entirely — invisible in the SVG.
 *
 * @see app/Services/Drawings/AutoGenericStencilGenerator.php — the ONLY emitter this class parses
 * @see app/Http/Controllers/Admin/DeviceStencilController.php::preview() — sole caller
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-16)
 */
class StencilXmlToSvgRenderer
{
    /**
     * Mirrors AutoGenericStencilGenerator's provisional-rail dash intent —
     * a fixed, non-configurable SVG dasharray. The mxGraph grammar only
     * ever signals dashed on/off (boolean), never a specific pattern, so a
     * single fixed pattern is the correct translation.
     */
    private const RAIL_DASH_PATTERN = '4 3';

    private const DEFAULT_STROKE_WIDTH = '1';

    /**
     * Parser state, reset at the start of every render() call. Mutated in
     * document order as sibling state elements are encountered — mirrors
     * exactly how `fillcolor`/`strokecolor`/`strokewidth` already behave in
     * the real mxGraph engine (set once, applies to every subsequent
     * primitive until changed again).
     *
     * @var array{fillcolor:?string, strokecolor:?string, fontcolor:?string, fontsize:?string, fontstyle:int, strokewidth:string, dashed:bool, alpha:float}
     */
    private array $state;

    /**
     * Translate a bounded mxGraph `<shape>` stencil-XML document into a
     * standalone `<svg>` string. Never persists anything; pure function of
     * its input.
     */
    public function render(string $shapeXml, int $width, int $height): string
    {
        $this->state = [
            'fillcolor'   => null,
            'strokecolor' => null,
            'fontcolor'   => null,
            'fontsize'    => null,
            'fontstyle'   => 0,
            'strokewidth' => self::DEFAULT_STROKE_WIDTH,
            'dashed'      => false,
            'alpha'       => 1.0,
        ];

        $input = new DOMDocument();

        // LIBXML_NONET: forbid network access — no external entity fetches.
        // LIBXML_NOENT: substitute predefined entities.
        // Mirrors SvgSanitizerService's safe-parsing pattern (defence in
        // depth costs nothing here, even though this input is our own
        // generator's output, not untrusted upload).
        $prevInternalErrors = libxml_use_internal_errors(true);
        $loaded = $input->loadXML($shapeXml, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($prevInternalErrors);

        $output = new DOMDocument('1.0', 'UTF-8');

        $svg = $output->createElement('svg');
        $svg->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        $svg->setAttribute('viewBox', sprintf('0 0 %d %d', $width, $height));
        $svg->setAttribute('width', (string) $width);
        $svg->setAttribute('height', (string) $height);
        $output->appendChild($svg);

        if ($loaded && $input->documentElement !== null) {
            foreach (iterator_to_array($input->documentElement->childNodes) as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                $name = strtolower($child->localName ?? $child->nodeName);

                if ($name === 'connections') {
                    // Named mxGraph constraints — invisible markers, never
                    // rendered (Plan 24-01 Task 3 note).
                    continue;
                }

                if ($name === 'background' || $name === 'foreground') {
                    $this->walkContainer($child, $output, $svg);
                }
            }
        }

        return (string) $output->saveXML($svg);
    }

    private function walkContainer(DOMElement $container, DOMDocument $output, DOMElement $svg): void
    {
        foreach (iterator_to_array($container->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $this->applyElement($node, $output, $svg);
        }
    }

    private function applyElement(DOMElement $node, DOMDocument $output, DOMElement $svg): void
    {
        $name = strtolower($node->localName ?? $node->nodeName);

        switch ($name) {
            case 'fillcolor':
                $this->state['fillcolor'] = $node->getAttribute('color');
                break;

            case 'strokecolor':
                $this->state['strokecolor'] = $node->getAttribute('color');
                break;

            case 'fontcolor':
                $this->state['fontcolor'] = $node->getAttribute('color');
                break;

            case 'fontsize':
                $this->state['fontsize'] = $node->getAttribute('size');
                break;

            case 'fontstyle':
                $this->state['fontstyle'] = (int) $node->getAttribute('style');
                break;

            case 'strokewidth':
                $width = $node->getAttribute('width');
                if ($width !== '') {
                    $this->state['strokewidth'] = $width;
                }
                break;

            case 'dashed':
                // Ground truth: mxStencil.js:895 — attribute is `dashed`,
                // NOT `value`. "1" => true, anything else => false.
                $this->state['dashed'] = $node->getAttribute('dashed') === '1';
                break;

            case 'strokealpha':
                // Ground truth: mxStencil.js:948-951 — attribute is `alpha`,
                // a 0.0-1.0 fraction. Reject/ignore any out-of-range
                // (e.g. percentage-scale) value rather than propagating it
                // into SVG opacity output — clamp back to fully opaque
                // instead of silently emitting e.g. stroke-opacity="60".
                $alpha = $node->getAttribute('alpha');
                if ($alpha !== '' && is_numeric($alpha)) {
                    $value = (float) $alpha;
                    $this->state['alpha'] = ($value >= 0.0 && $value <= 1.0) ? $value : 1.0;
                } else {
                    $this->state['alpha'] = 1.0;
                }
                break;

            case 'roundrect':
                $this->emitRect($node, $output, $svg, withStroke: true);
                break;

            case 'rect':
                $this->emitRect($node, $output, $svg, withStroke: false);
                break;

            case 'line':
                $this->emitLine($node, $output, $svg);
                break;

            case 'text':
                $this->emitText($node, $output, $svg);
                break;

            case 'fill':
            case 'fillstroke':
            case 'stroke':
                // Commit markers only. The corresponding primitive
                // (roundrect/rect/line) was already emitted using the
                // CURRENT state at the point it was encountered — mirrors
                // exactly how AutoGenericStencilGenerator sequences its own
                // output (state set BEFORE the primitive, never after).
                break;

            default:
                // Unknown/unsupported element in the bounded grammar —
                // ignored rather than throwing, so a partially-recognised
                // shape still renders best-effort.
                break;
        }
    }

    private function emitRect(DOMElement $node, DOMDocument $output, DOMElement $svg, bool $withStroke): void
    {
        $rect = $output->createElement('rect');
        $rect->setAttribute('x', $node->getAttribute('x'));
        $rect->setAttribute('y', $node->getAttribute('y'));
        $rect->setAttribute('width', $node->getAttribute('w'));
        $rect->setAttribute('height', $node->getAttribute('h'));

        $arcsize = $node->getAttribute('arcsize');
        if ($arcsize !== '') {
            $rect->setAttribute('rx', $arcsize);
        }

        $rect->setAttribute('fill', (string) ($this->state['fillcolor'] ?? 'none'));

        if ($withStroke) {
            $rect->setAttribute('stroke', (string) ($this->state['strokecolor'] ?? 'none'));
        }

        $svg->appendChild($rect);
    }

    /**
     * Translate a bare `<line x1 y1 x2 y2/>` primitive (no attributes
     * beyond these four, per the real grammar). `dashed`/`alpha` are NEVER
     * read from `$node` here — only from parser STATE set by the sibling
     * `<dashed>`/`<strokealpha>` elements that preceded it in document order.
     */
    private function emitLine(DOMElement $node, DOMDocument $output, DOMElement $svg): void
    {
        $line = $output->createElement('line');
        $line->setAttribute('x1', $node->getAttribute('x1'));
        $line->setAttribute('y1', $node->getAttribute('y1'));
        $line->setAttribute('x2', $node->getAttribute('x2'));
        $line->setAttribute('y2', $node->getAttribute('y2'));
        $line->setAttribute('stroke', (string) ($this->state['strokecolor'] ?? '#000000'));
        $line->setAttribute('stroke-width', $this->state['strokewidth']);

        if ($this->state['dashed']) {
            $line->setAttribute('stroke-dasharray', self::RAIL_DASH_PATTERN);
        }

        if ($this->state['alpha'] < 1.0) {
            $line->setAttribute('stroke-opacity', $this->formatAlpha($this->state['alpha']));
        }

        $svg->appendChild($line);
    }

    private function emitText(DOMElement $node, DOMDocument $output, DOMElement $svg): void
    {
        $text = $output->createElement('text');
        $text->setAttribute('x', $node->getAttribute('x'));
        $text->setAttribute('y', $node->getAttribute('y'));
        $text->setAttribute('text-anchor', $this->mapAlign($node->getAttribute('align')));
        $text->setAttribute('font-size', (string) ($this->state['fontsize'] ?? '12'));
        $text->setAttribute('font-weight', ($this->state['fontstyle'] & 1) ? 'bold' : 'normal');

        if ($this->state['fontstyle'] & 2) {
            $text->setAttribute('font-style', 'italic');
        }

        $text->setAttribute('fill', (string) ($this->state['fontcolor'] ?? '#000000'));

        // strokealpha is GLOBAL alpha (mxStencil.js:948-951) — applies to
        // text fill-opacity too, not just line stroke-opacity, so the
        // preview matches production dimming of provisional port labels.
        if ($this->state['alpha'] < 1.0) {
            $text->setAttribute('fill-opacity', $this->formatAlpha($this->state['alpha']));
        }

        // getAttribute() DOM-decodes the already-escaped `str` value back to
        // its literal form once; createTextNode() + saveXML() re-encodes it
        // once more for the new SVG document — a single safe round-trip,
        // never a double-escape (see class docblock, T-24-09).
        $text->appendChild($output->createTextNode($node->getAttribute('str')));

        $svg->appendChild($text);
    }

    /**
     * Map mxGraph's `align` vocabulary to SVG `text-anchor`. The real
     * mxGraph grammar uses left/center/right; AutoGenericStencilGenerator's
     * own port-label geometry already emits SVG-native start/center/end
     * directly (see sideGeometry()) — both vocabularies are accepted so
     * this renderer stays correct against either producer.
     */
    private function mapAlign(string $align): string
    {
        return match ($align) {
            'center' => 'middle',
            'left'   => 'start',
            'right'  => 'end',
            'start', 'end' => $align,
            default  => 'start',
        };
    }

    private function formatAlpha(float $alpha): string
    {
        $formatted = rtrim(rtrim(sprintf('%.2f', $alpha), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
