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
 * @see app/Services/Drawings/DeviceStencilCacheService.php
 * @see app/Services/Drawings/DrawIoSpikeBuilderService.php — XSS escape pattern
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-04)
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

    /**
     * Build the Tier 1 placeholder payload.
     *
     * @param  array{manufacturer?:?string, model?:?string, name?:?string, part_number?:?string}  $hints
     * @return array{mxgraph_xml:string, default_width:int, default_height:int, display_name:string}
     */
    public function build(array $hints): array
    {
        $manufacturer = $this->stringify($hints['manufacturer'] ?? null);
        $model        = $this->stringify($hints['model'] ?? null);
        $name         = $this->stringify($hints['name'] ?? null);
        $partNumber   = $this->stringify($hints['part_number'] ?? null);

        $displayName = $this->resolveDisplayName($name, $manufacturer, $model, $partNumber);

        $mxgraphXml = $this->emitShape($manufacturer, $model, $displayName, $partNumber);

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
     */
    private function emitShape(string $manufacturer, string $model, string $displayName, string $partNumber): string
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

        // No <connections> element by design (D-04: Tier 1 has no port rails).
        return sprintf(
            '<shape name="%s" h="140" w="220" aspect="variable" strokewidth="inherit">'
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
                .'</foreground>'
            .'</shape>',
            $stencilName,
            $bodyFill,
            $headerFill,
            $headerFill,
            $headerTextColor,
            $headerTextSafe,
            $bodyTextColor,
            $bodyTextSafe,
            $partLineXml,
            $partTextColor
        );
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
