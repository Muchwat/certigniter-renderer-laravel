<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\CertificateRenderer;
use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Computes every CSS value the Blade view needs to render a text-like
 * element (`text`/`placeholder_text`/`dynamic_text`): bold detection,
 * shadow and gradient fallback, content alignment and the bottom border.
 * Kept out of certificate.blade.php so each branch is unit-testable
 * without rendering a PDF.
 */
class TextElementStyle
{
    /** @return array{fontFamily: string, fontSizePt: float, fontWeight: int, fontStyle: string, color: string, textAlign: string, lineHeightPt: float, letterSpacing: float, decorationCss: string, shadowLayers: list<array{dx: float, dy: float, color: string}>, overflowCss: string, contentPositionCss: string, baselineCorrectionPt: float, bottomBorderSpanCss: string} */
    public static function describe(DesignElement $element, string $colorFormat, string $unit, FontRegistrar $fonts): array
    {
        $fontFamily = $fonts->resolveFamily($element->property('fontFamily'));

        // Typography is stored in CSS pixels (96 DPI); PDF typography uses
        // points (72 DPI).
        $fontSizePt = (float) $element->property('fontSize', 14.0) * CertificateRenderer::CANVAS_PX_TO_PDF_PT;

        // Dompdf's line box places the glyph baseline lower than the glyphs'
        // visual centre by a fraction of the font size that depends on the
        // font's own ascent/descent split - see
        // FontRegistrar::baselineCorrectionRatio() for why this isn't a
        // flat constant. Compensate inside the element box so the text sits
        // where it was placed.
        $baselineCorrectionPt = $fontSizePt * $fonts->baselineCorrectionRatio($fontFamily);

        $color = self::color($element, $colorFormat);

        $textAlign = $element->property('textAlign', 'left');
        $textAlign = in_array($textAlign, ['left', 'center', 'right', 'justify'], true) ? $textAlign : 'left';

        return [
            'fontFamily' => $fontFamily,
            'fontSizePt' => $fontSizePt,
            'fontWeight' => self::isBold($element) ? 700 : 400,
            'fontStyle' => $element->property('fontStyle', 'normal') === 'italic' ? 'italic' : 'normal',
            'color' => $color,
            'textAlign' => $textAlign,
            'lineHeightPt' => $fontSizePt * max(0, (float) $element->property('lineHeight', 1.2)),
            'letterSpacing' => (float) $element->property('letterSpacing', 0) * CertificateRenderer::CANVAS_PX_TO_PDF_PT,
            'decorationCss' => self::decorationCss($element),
            'shadowLayers' => self::shadowLayers($element, $colorFormat, $unit),
            'overflowCss' => $element->property('textResizeMode') === 'fixedSize' ? 'overflow: hidden;' : '',
            'contentPositionCss' => self::contentPositionCss($element),
            'baselineCorrectionPt' => $baselineCorrectionPt,
            'bottomBorderSpanCss' => self::bottomBorderSpanCss($element, $colorFormat, $unit, $color),
        ];
    }

    private static function isBold(DesignElement $element): bool
    {
        $rawWeight = (string) $element->property('fontWeight', 'normal');

        return str_contains(strtolower($rawWeight), 'bold')
            || (is_numeric(str_replace('w', '', $rawWeight)) && (int) str_replace('w', '', $rawWeight) >= 600);
    }

    private static function color(DesignElement $element, string $colorFormat): string
    {
        $color = ColorConverter::toCss($element->property('color'), $colorFormat, '#000000');

        // Text gradients have no faithful dompdf equivalent (no
        // background-clip:text support) - fall back to the gradient's first
        // stop as a flat color rather than emitting CSS that silently
        // renders nothing.
        $gradient = $element->property('gradient');
        if (is_array($gradient) && ! empty($gradient['colors'][0])) {
            $color = ColorConverter::toCss($gradient['colors'][0], $colorFormat, $color);
        }

        return $color;
    }

    private static function decorationCss(DesignElement $element): string
    {
        if ($element->property('underline', false)) {
            return 'text-decoration: underline;';
        }
        if ($element->property('strikethrough', false)) {
            return 'text-decoration: line-through;';
        }

        return '';
    }

    /**
     * A text shadow is drawn as copies of the text behind it (dompdf
     * ignores CSS text-shadow): moved by its offset and spread over its
     * blur (ShadowBlur), all in CSS pixels - a blur of b is a Gaussian
     * of standard deviation b / 2.
     *
     * @return list<array{dx: float, dy: float, color: string}>
     */
    private static function shadowLayers(DesignElement $element, string $colorFormat, string $unit): array
    {
        $shadow = $element->property('shadow');
        if (! is_array($shadow)) {
            return [];
        }

        $pixelsPerUnit = Units::pixelsPer($unit);
        $shadowRgba = ColorConverter::toRgba($shadow['color'] ?? null, $colorFormat, '#00000080');
        $offsets = ShadowBlur::offsets(max(0.0, (float) ($shadow['blur'] ?? 0)) / 2 / $pixelsPerUnit);
        $alpha = ShadowBlur::copyAlpha($shadowRgba['a'], count($offsets));

        $layers = [];
        foreach ($offsets as [$ox, $oy]) {
            $layers[] = [
                'dx' => (float) ($shadow['offsetX'] ?? 0) / $pixelsPerUnit + $ox,
                'dy' => (float) ($shadow['offsetY'] ?? 0) / $pixelsPerUnit + $oy,
                'color' => sprintf('rgba(%d, %d, %d, %s)', $shadowRgba['r'], $shadowRgba['g'], $shadowRgba['b'], round($alpha, 4)),
            ];
        }

        return $layers;
    }

    private static function contentPositionCss(DesignElement $element): string
    {
        [$contentX, $contentY] = $element->contentAlignmentFactors();
        $css = $contentX === 0.0
            ? 'left: 0;'
            : ($contentX === 1.0 ? 'right: 0;' : 'left: 50%;');
        $css .= $contentY === 0.0
            ? ' top: 0;'
            : ($contentY === 1.0 ? ' bottom: 0;' : ' top: 50%;');

        $transforms = [];
        if ($contentX === 0.5) {
            $transforms[] = 'translateX(-50%)';
        }
        if ($contentY === 0.5) {
            $transforms[] = 'translateY(-50%)';
        }

        return $transforms ? $css.' transform: '.implode(' ', $transforms).';' : $css;
    }

    /**
     * padding-left/right/bottom + border-bottom on an inline <span>
     * that wraps the text itself, not a separate sibling element
     * inside `.text-content`: dompdf wraps any *other* child of a
     * `display:table` box (`.text-content`, for the shrink-to-fit
     * centering) in its own anonymous table row regardless of that
     * child's position:absolute status, which silently roughly doubles
     * the table's rendered height and pushes a sibling underline far
     * below the text (verified by rendering an isolated reproduction
     * with a tinted background and measuring its real pixel height, not
     * just reasoned about). Padding/border on an inline element that's
     * already part of the table's own text content sidesteps that
     * entirely, and - unlike padding on `.text-content` itself - doesn't
     * feed into its shrink-to-fit *width* measurement either, so the
     * horizontal-centering bug this class already works around doesn't
     * reappear.
     */
    private static function bottomBorderSpanCss(DesignElement $element, string $colorFormat, string $unit, string $textColor): string
    {
        if (! (bool) $element->property('bottomBorderEnabled', false)) {
            return '';
        }

        return sprintf(
            'padding-left: %s%s; padding-right: %s%s; padding-bottom: %s%s; border-bottom: %s%s solid %s;',
            max(0, (float) $element->property('bottomBorderLeftPadding', 0)), $unit,
            max(0, (float) $element->property('bottomBorderRightPadding', 0)), $unit,
            max(0, (float) $element->property('bottomBorderGap', 1.5)), $unit,
            max(0.1, (float) $element->property('bottomBorderWidth', 0.4)), $unit,
            ColorConverter::toCss($element->property('bottomBorderColor', $element->property('color')), $colorFormat, $textColor),
        );
    }

    /**
     * Text on a curve (CurvedTextLayout): one box per glyph, in mm inside the
     * element, each a line box that CSS turns about its centre onto the
     * circle. Glyphs are measured by Dompdf itself, so each box is exactly as
     * wide as the advance Dompdf will set in it. Straight text gets `[]`.
     *
     * The same baseline correction straight text gets (see describe()) moves
     * each glyph up along its own upright, not the page's.
     *
     * @param  array{fontFamily: string, fontSizePt: float, fontWeight: int, lineHeightPt: float, letterSpacing: float, baselineCorrectionPt: float}  $style  describe()'s result
     * @return list<array{text: string, left: float, top: float, width: float, height: float, rotation: float}>
     */
    public static function curvedGlyphBoxes(DesignElement $element, array $style, FontRegistrar $fonts): array
    {
        $radius = CurvedTextLayout::radius($element);
        if ($radius === 0.0) {
            return [];
        }

        $ptToMm = 25.4 / 72;
        $lineHeight = $style['lineHeightPt'] * $ptToMm;
        $measure = fn (string $prefix): float => $fonts->textWidthPt(
            $style['fontFamily'], $style['fontWeight'] >= 700, $style['fontSizePt'], $style['letterSpacing'], $prefix,
        ) * $ptToMm;
        $glyphs = CurvedTextLayout::place(
            CurvedTextLayout::glyphs(CurvedTextLayout::line((string) $element->property('text', '')), $measure),
            $radius, $lineHeight, $element->width, $element->height,
        );
        $correction = $style['baselineCorrectionPt'] * $ptToMm;

        return array_map(fn (array $glyph): array => [
            'text' => $glyph['text'],
            'left' => $glyph['x'] + $correction * sin($glyph['angle']) - ($glyph['end'] - $glyph['start']) / 2,
            'top' => $glyph['y'] - $correction * cos($glyph['angle']) - $lineHeight / 2,
            'width' => $glyph['end'] - $glyph['start'],
            'height' => $lineHeight,
            'rotation' => rad2deg($glyph['angle']),
        ], $glyphs);
    }
}
