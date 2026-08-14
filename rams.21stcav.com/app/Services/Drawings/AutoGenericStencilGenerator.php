<?php

namespace App\Services\Drawings;

/**
 * Phase 21 Plan 01 — Tier 1 placeholder mxGraph stencil builder.
 *
 * Emits a visually basic <shape> document for an uncatalogued part_number so
 * Phase 23's renderer always has SOMETHING to draw — even on day 1 before
 * any engineer curation. Phase 24's curation UI promotes auto-generated
 * stencils to engineer-curated ones in-place.
 *
 * Visual spec (CONTEXT.md D-04):
 *   - 220 x 140 outer rounded rectangle, FAFAF6 fill, 1B7A7A stroke
 *     (matches v1.3 brand palette + the spike's 5 stencils)
 *   - 30 px-tall teal header bar with manufacturer text in white 12pt bold
 *   - Body: model (or display_name) in teal 11pt
 *   - Body: part_number in italic grey 9pt
 *   - "Tier 1 placeholder" annotation at the bottom in 7pt grey so engineers
 *     know on sight which devices need promoting
 *   - NO port rails — Phase 24 adds them
 *
 * Threat-model T-21.01-01: every interpolated user-supplied value is XML-
 * escaped via htmlspecialchars(ENT_XML1 | ENT_QUOTES). Mirrors the protection
 * in DrawIoSpikeBuilderService::xml() — equipment names from QuoteWerks are
 * untrusted (they're AI-extracted from PDFs).
 *
 * Determinism: the same hints array produces byte-identical output across
 * calls — no random IDs, no timestamps, no environment-dependent values.
 * This matters because the cache service uses the output as the firstOrCreate
 * payload; deterministic output means re-running the cache for the same
 * part_number on a fresh DB produces identical mxgraph_xml.
 *
 * Phase 24 Plan 01 Task 3 (D-05) extends build()/emitShape() to accept an
 * optional `$hints['ports']` key (shaped exactly like
 * CategoryPortTemplateResolver::resolve()'s output — label/side/
 * connector_type/signal_type/direction/sort_order/port_id) and, when
 * present, emits:
 *   - a <connections> block of named mxGraph constraints (one per port,
 *     coordinate-mapped from `side`) so Phase 23's CableRouter has
 *     something to terminate cables on (stencilHasConstraints() is a
 *     `<constraint` substring check);
 *   - PROVISIONAL visual rail ticks + muted port labels in <foreground>,
 *     styled dashed/60%-alpha to mark them template-derived rather than
 *     engineer-verified — this SUPERSEDES 21 D-04's "no port rails" for the
 *     stub-with-template-ports case (the bare zero-port placeholder remains
 *     unchanged for portless stubs).
 *
 * The exact grammar for the provisional rail's state elements (`dashed`,
 * `strokealpha`) is verified directly against the vendored draw.io/mxGraph
 * parser this project ships — NOT the seed pack, which contains zero
 * `dashed`/`strokealpha` precedent:
 *   - public/vendor/drawio/mxgraph/src/shape/mxStencil.js:893-896 — the
 *     `<dashed>` element reads attribute `dashed` (NOT `value`); `"1"` on,
 *     `"0"` off.
 *   - public/vendor/drawio/mxgraph/src/shape/mxStencil.js:948-951 (+
 *     docblock :90-91) — the `<strokealpha>` element reads attribute `alpha`
 *     as a 0.0-1.0 FRACTION (never a 0-100 percentage), and is GLOBAL alpha
 *     in this vendored build (identical handler to `alpha`/`fillalpha`) —
 *     it mutes fills/text/lines alike until explicitly reset, which is why
 *     the reset pair at the end of the rail block is mandatory, not tidy-up.
 * When `$hints['ports']` is absent/empty, output is byte-identical to the
 * pre-Phase-24 committed placeholder (criterion 6 — no regression).
 *
 * @see app/Services/Drawings/DeviceStencilCacheService.php
 * @see app/Services/Drawings/DrawIoSpikeBuilderService.php — XSS escape pattern
 * @see app/Services/Drawings/CategoryPortTemplateResolver.php — $hints['ports'] producer
 * @see app/Services/Drawings/CableRouter.php — stencilHasConstraints() consumer contract
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-04)
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-05)
 */
class AutoGenericStencilGenerator
{
    private const DEFAULT_WIDTH = 220;

    private const DEFAULT_HEIGHT = 140;

    private const HEADER_FILL = '#1B7A7A';

    private const BODY_FILL = '#FAFAF6';

    private const HEADER_TEXT_COLOUR = '#FFFFFF';

    private const BODY_TEXT_COLOUR = '#1B7A7A';

    private const PART_TEXT_COLOUR = '#666666';

    /** D-05 provisional rail/label colour — UI-SPEC Component Inventory point 5. */
    private const PROVISIONAL_RAIL_COLOUR = '#94A3B8';

    /** Rail-tick length in px, mirrors neat-bar-pro.json's 0->8 / 232->240 tick geometry. */
    private const RAIL_TICK_LENGTH = 8;

    /**
     * Build the Tier 1 placeholder payload (D-05: with provisional rails
     * when `$hints['ports']` is supplied).
     *
     * @param  array{manufacturer?:?string, model?:?string, name?:?string, part_number?:?string, ports?:array}  $hints
     * @return array{mxgraph_xml:string, default_width:int, default_height:int, display_name:string}
     */
    public function build(array $hints): array
    {
        $manufacturer = $this->stringify($hints['manufacturer'] ?? null);
        $model        = $this->stringify($hints['model'] ?? null);
        $name         = $this->stringify($hints['name'] ?? null);
        $partNumber   = $this->stringify($hints['part_number'] ?? null);
        $ports        = is_array($hints['ports'] ?? null) ? $hints['ports'] : [];

        $displayName = $this->resolveDisplayName($name, $manufacturer, $model, $partNumber);

        $mxgraphXml = $this->emitShape($manufacturer, $model, $displayName, $partNumber, $ports);

        return [
            'mxgraph_xml'    => $mxgraphXml,
            'default_width'  => self::DEFAULT_WIDTH,
            'default_height' => self::DEFAULT_HEIGHT,
            'display_name'   => $displayName,
        ];
    }

    /**
     * Resolve the display name with the documented fallback ladder:
     *   name → manufacturer + ' ' + model → part_number → 'Unknown Device'
     */
    private function resolveDisplayName(string $name, string $manufacturer, string $model, string $partNumber): string
    {
        if ($name !== '') {
            return $name;
        }

        $combined = trim($manufacturer.' '.$model);
        if ($combined !== '') {
            return $combined;
        }

        if ($partNumber !== '') {
            return $partNumber;
        }

        return 'Unknown Device';
    }

    /**
     * Emit the mxGraph <shape> document.
     *
     * Layout coordinates assume 220 x 140 outer dimensions (matches the
     * stencil pack's default_size). Coordinates inside the <foreground>
     * are in shape-local units mapped through h/w on the stencil tag.
     *
     * @param  array<int, array{label?:string, side?:string, connector_type?:string, port_id?:string}>  $ports
     */
    private function emitShape(string $manufacturer, string $model, string $displayName, string $partNumber, array $ports = []): string
    {
        $headerText = $manufacturer !== '' ? $manufacturer : 'Generic';
        // Body line 1 prefers model; falls back to display_name when model
        // wasn't supplied so the card never renders blank text.
        $bodyText = $model !== '' ? $model : $displayName;

        $headerTextSafe  = $this->xml($headerText);
        $bodyTextSafe    = $this->xml($bodyText);
        $partNumberSafe  = $this->xml($partNumber);
        $displayNameSafe = $this->xml($displayName);
        $stencilName     = $this->xml('auto-'.($partNumber !== '' ? $partNumber : 'unknown'));

        $headerFill      = $this->xml(self::HEADER_FILL);
        $bodyFill        = $this->xml(self::BODY_FILL);
        $headerTextColor = $this->xml(self::HEADER_TEXT_COLOUR);
        $bodyTextColor   = $this->xml(self::BODY_TEXT_COLOUR);
        $partTextColor   = $this->xml(self::PART_TEXT_COLOUR);

        // Display-name fallback under the part_number when the part_number
        // is absent (so the card always carries some identifying text).
        $partLineXml = $partNumber !== ''
            ? sprintf(
                '<fontstyle style="2"/><fontsize size="9"/><fontcolor color="%s"/><text str="%s" x="110" y="105" align="center"/>',
                $partTextColor,
                $partNumberSafe
            )
            : sprintf(
                '<fontstyle style="2"/><fontsize size="9"/><fontcolor color="%s"/><text str="%s" x="110" y="105" align="center"/>',
                $partTextColor,
                $displayNameSafe
            );

        // D-05: <connections> + provisional rail/label block are ONLY
        // emitted when a resolved port template was supplied. Both resolve
        // to '' for the zero-port case, which — by construction of the
        // sprintf format string below — keeps this byte-identical to the
        // pre-Phase-24 committed output (criterion 6, no regression).
        $portLayout      = $ports !== [] ? $this->resolvePortLayout($ports) : [];
        $connectionsXml  = $portLayout !== [] ? $this->buildConnections($portLayout) : '';
        $provisionalRail = $portLayout !== [] ? $this->buildProvisionalRail($portLayout) : '';

        return sprintf(
            '<shape name="%s" h="140" w="220" aspect="variable" strokewidth="inherit">'
                .'%s' // D-05 <connections> — named mxGraph constraints, empty when portless
                .'<background>'
                    .'<fillcolor color="%s"/>'
                    .'<strokecolor color="%s"/>'
                    .'<roundrect x="0" y="0" w="220" h="140" arcsize="6"/>'
                    .'<fillstroke/>'
                .'</background>'
                .'<foreground>'
                    // Header bar
                    .'<fillcolor color="%s"/>'
                    .'<rect x="0" y="0" w="220" h="30"/>'
                    .'<fill/>'
                    // Header text (manufacturer)
                    .'<fontstyle style="1"/>'
                    .'<fontsize size="12"/>'
                    .'<fontcolor color="%s"/>'
                    .'<text str="%s" x="110" y="20" align="center"/>'
                    // Body text (model or display_name)
                    .'<fontstyle style="0"/>'
                    .'<fontsize size="11"/>'
                    .'<fontcolor color="%s"/>'
                    .'<text str="%s" x="110" y="65" align="center"/>'
                    // Part number / display name fallback
                    .'%s'
                    // Tier 1 annotation
                    .'<fontstyle style="0"/>'
                    .'<fontsize size="7"/>'
                    .'<fontcolor color="%s"/>'
                    .'<text str="Tier 1 placeholder" x="110" y="130" align="center"/>'
                    // D-05 provisional port rails + muted labels — VERY LAST
                    // addition, strictly after every existing primitive.
                    // strokealpha is GLOBAL alpha in the vendored engine
                    // (mxStencil.js:940-951), so anything emitted before this
                    // point renders unaffected; only this block is muted, and
                    // its own mandatory reset (see buildProvisionalRail())
                    // prevents the mute leaking into whatever the caller
                    // composes around this <shape> next. Empty string when
                    // portless.
                    .'%s'
                .'</foreground>'
            .'</shape>',
            $stencilName,
            $connectionsXml,
            $bodyFill,
            $headerFill,
            $headerFill,
            $headerTextColor,
            $headerTextSafe,
            $bodyTextColor,
            $bodyTextSafe,
            $partLineXml,
            $partTextColor,
            $provisionalRail
        );
    }

    /**
     * Resolve each port's layout geometry once — shared by both
     * buildConnections() (named constraint fraction coords) and
     * buildProvisionalRail() (pixel tick + label coords) so the two never
     * drift apart from independently-computed spread values.
     *
     * `spread` is computed PURELY for this XML's own visual layout as
     * `(index_within_side + 1) / (count_on_that_side + 1)` — NEVER written
     * back to device_ports.x_pct/y_pct (D-01: Phase 23's renderer computes
     * position independently when null).
     *
     * @param  array<int, array{label?:string, side?:string, connector_type?:string, port_id?:string}>  $ports
     * @return array<int, array{port_id:string, label:string, side:string, constraint_x:string, constraint_y:string, tick:array{x1:string,y1:string,x2:string,y2:string}, label_pos:array{x:string,y:string,align:string}}>
     */
    private function resolvePortLayout(array $ports): array
    {
        $countBySide = [];
        foreach ($ports as $port) {
            $side = $this->normaliseSide($port['side'] ?? null);
            $countBySide[$side] = ($countBySide[$side] ?? 0) + 1;
        }

        $seenBySide = [];
        $layout = [];

        foreach ($ports as $port) {
            $side = $this->normaliseSide($port['side'] ?? null);
            $seenBySide[$side] = ($seenBySide[$side] ?? 0) + 1;
            $spread = $seenBySide[$side] / ($countBySide[$side] + 1);

            $geometry = $this->sideGeometry($side, $spread);

            $layout[] = [
                'port_id'      => (string) ($port['port_id'] ?? ''),
                'label'        => (string) ($port['label'] ?? ''),
                'side'         => $side,
                'constraint_x' => $geometry['constraint_x'],
                'constraint_y' => $geometry['constraint_y'],
                'tick'         => $geometry['tick'],
                'label_pos'    => $geometry['label_pos'],
            ];
        }

        return $layout;
    }

    private function normaliseSide(?string $side): string
    {
        return in_array($side, ['left', 'right', 'top', 'bottom'], true) ? $side : 'left';
    }

    /**
     * Per-side pixel + fraction geometry for one port at a given 0..1 spread
     * position along its edge. Tick lines are RAIL_TICK_LENGTH px, mirroring
     * neat-bar-pro.json's own 0->8 / 232->240 tick geometry.
     *
     * @return array{constraint_x:string, constraint_y:string, tick:array{x1:string,y1:string,x2:string,y2:string}, label_pos:array{x:string,y:string,align:string}}
     */
    private function sideGeometry(string $side, float $spread): array
    {
        $tick   = self::RAIL_TICK_LENGTH;
        $width  = self::DEFAULT_WIDTH;
        $height = self::DEFAULT_HEIGHT;

        return match ($side) {
            'right' => [
                'constraint_x' => '1',
                'constraint_y' => $this->fraction($spread),
                'tick' => [
                    'x1' => $this->pixel($width - $tick),
                    'y1' => $this->pixel($spread * $height),
                    'x2' => $this->pixel($width),
                    'y2' => $this->pixel($spread * $height),
                ],
                'label_pos' => [
                    'x'     => $this->pixel($width - $tick - 4),
                    'y'     => $this->pixel($spread * $height),
                    'align' => 'end',
                ],
            ],
            'top' => [
                'constraint_x' => $this->fraction($spread),
                'constraint_y' => '0',
                'tick' => [
                    'x1' => $this->pixel($spread * $width),
                    'y1' => $this->pixel(0),
                    'x2' => $this->pixel($spread * $width),
                    'y2' => $this->pixel($tick),
                ],
                'label_pos' => [
                    'x'     => $this->pixel($spread * $width),
                    'y'     => $this->pixel($tick + 8),
                    'align' => 'center',
                ],
            ],
            'bottom' => [
                'constraint_x' => $this->fraction($spread),
                'constraint_y' => '1',
                'tick' => [
                    'x1' => $this->pixel($spread * $width),
                    'y1' => $this->pixel($height - $tick),
                    'x2' => $this->pixel($spread * $width),
                    'y2' => $this->pixel($height),
                ],
                'label_pos' => [
                    'x'     => $this->pixel($spread * $width),
                    'y'     => $this->pixel($height - $tick - 8),
                    'align' => 'center',
                ],
            ],
            default => [ // left
                'constraint_x' => '0',
                'constraint_y' => $this->fraction($spread),
                'tick' => [
                    'x1' => $this->pixel(0),
                    'y1' => $this->pixel($spread * $height),
                    'x2' => $this->pixel($tick),
                    'y2' => $this->pixel($spread * $height),
                ],
                'label_pos' => [
                    'x'     => $this->pixel($tick + 4),
                    'y'     => $this->pixel($spread * $height),
                    'align' => 'start',
                ],
            ],
        };
    }

    /**
     * Emit the <connections> block — one named <constraint> per port,
     * consumed by CableRouter::stencilHasConstraints() as a `<constraint`
     * substring check and by cable termination as an exact `name` match
     * against device_ports.port_id (RESEARCH.md Pitfall 2).
     *
     * @param  array<int, array{port_id:string, constraint_x:string, constraint_y:string}>  $layout
     */
    private function buildConnections(array $layout): string
    {
        $constraints = '';
        foreach ($layout as $port) {
            $constraints .= sprintf(
                '<constraint x="%s" y="%s" perimeter="0" name="%s"/>',
                $port['constraint_x'],
                $port['constraint_y'],
                $this->xml($port['port_id'])
            );
        }

        return '<connections>'.$constraints.'</connections>';
    }

    /**
     * Emit the D-05 provisional rail-tick + muted port-label block, in the
     * EXACT sequence mandated by the vendored mxStencil.js grammar (verified
     * against public/vendor/drawio/mxgraph/src/shape/mxStencil.js, NOT the
     * seed pack — see this class's docblock):
     *
     *   1. <dashed dashed="1"/>                       — attribute is `dashed`, not `value`
     *   2. <strokealpha alpha="0.6"/>                  — 0.0-1.0 fraction, GLOBAL alpha
     *   3. <strokecolor color="#94A3B8"/>
     *   4. N bare <line x1 y1 x2 y2/> — no other attributes, one per port
     *   5. <stroke/>                                   — commits the batched rail ticks
     *   6. per port: <fontcolor .../><text .../>        — while the mute is still active
     *   7. <dashed dashed="0"/><strokealpha alpha="1"/> — MANDATORY reset, not tidiness
     *
     * @param  array<int, array{label:string, tick:array{x1:string,y1:string,x2:string,y2:string}, label_pos:array{x:string,y:string,align:string}}>  $layout
     */
    private function buildProvisionalRail(array $layout): string
    {
        $railColour = $this->xml(self::PROVISIONAL_RAIL_COLOUR);

        $lines = '';
        foreach ($layout as $port) {
            $lines .= sprintf(
                '<line x1="%s" y1="%s" x2="%s" y2="%s"/>',
                $port['tick']['x1'],
                $port['tick']['y1'],
                $port['tick']['x2'],
                $port['tick']['y2']
            );
        }

        $labels = '';
        foreach ($layout as $port) {
            $labels .= sprintf(
                '<fontcolor color="%s"/><text str="%s" x="%s" y="%s" align="%s"/>',
                $railColour,
                $this->xml($port['label']),
                $port['label_pos']['x'],
                $port['label_pos']['y'],
                $this->xml($port['label_pos']['align'])
            );
        }

        return '<dashed dashed="1"/>'
            .'<strokealpha alpha="0.6"/>'
            .sprintf('<strokecolor color="%s"/>', $railColour)
            .$lines
            .'<stroke/>'
            .$labels
            .'<dashed dashed="0"/>'
            .'<strokealpha alpha="1"/>';
    }

    /**
     * Format a 0..1 fraction coordinate for mxGraph <constraint> attributes
     * — trimmed to avoid float noise while staying deterministic across
     * calls (RESEARCH.md Pitfall 3: no random/time-based values anywhere).
     */
    private function fraction(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Round a shape-local pixel coordinate to the nearest integer string.
     */
    private function pixel(float $value): string
    {
        return (string) (int) round($value);
    }

    /**
     * Coerce hint values to trimmed strings (handles null / non-string inputs).
     */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * Escape user-supplied values for safe XML attribute / text-node placement.
     * Mirrors DrawIoSpikeBuilderService::xml() — equipment metadata from
     * QuoteWerks PDFs is untrusted input (T-21.01-01).
     */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
