<?php

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\CertificateRenderer;
use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Computes every CSS value the Blade view needs to render a text-like
 * element (`text`/`placeholder_text`/`dynamic_text`), extracted verbatim
 * from what used to be an inline `@php` block in certificate.blade.php so
 * this branchy formatting logic (bold detection, shadow/gradient fallback,
 * content-alignment CSS, bottom-border CSS) is unit-testable on its own,
 * matching the existing `ShapeRenderer` pattern. Moved, not rewritten - see
 * this package's test suite for characterization coverage of every branch.
 */
class TextElementStyle
{
    /** @return array{fontFamily: string, fontSizePt: float, fontWeight: int, fontStyle: string, color: string, textAlign: string, lineHeightPt: float, letterSpacing: float, decorationCss: string, shadowCss: string, overflowCss: string, contentPositionCss: string, baselineCorrectionPt: float, bottomBorderCss: string} */
    public static function describe(DesignElement $element, string $colorFormat, string $unit, FontRegistrar $fonts): array
    {
        $fontFamily = $fonts->resolveFamily($element->property('fontFamily'));
        $rawWeight = (string) $element->property('fontWeight', 'normal');
        $isBold = str_contains(strtolower($rawWeight), 'bold')
            || (is_numeric(str_replace('w', '', $rawWeight)) && (int) str_replace('w', '', $rawWeight) >= 600);

        // Studio typography is stored as Flutter logical pixels (96 DPI);
        // CSS PDF typography uses points (72 DPI).
        $fontSizePt = (float) $element->property('fontSize', 14.0) * CertificateRenderer::CANVAS_PX_TO_PDF_PT;

        // Dompdf's tableless line box places the glyph baseline lower than
        // package:pdf by a stable fraction of the font size. Compensate
        // inside the element box so both renderers share the same visual
        // vertical center.
        $baselineCorrectionPt = $fontSizePt * 0.0875;

        $color = ColorConverter::toCss($element->property('color'), $colorFormat, '#000000');

        $textAlign = $element->property('textAlign', 'left');
        $textAlign = in_array($textAlign, ['left', 'center', 'right', 'justify'], true) ? $textAlign : 'left';

        $lineHeight = (float) $element->property('lineHeight', 1.2);
        $lineHeightPt = $fontSizePt * max(0, $lineHeight);

        $letterSpacing = (float) $element->property('letterSpacing', 0) * CertificateRenderer::CANVAS_PX_TO_PDF_PT;

        $decorations = [];
        if ($element->property('underline', false)) {
            $decorations[] = 'underline';
        } elseif ($element->property('strikethrough', false)) {
            $decorations[] = 'line-through';
        }
        $decorationCss = $decorations ? 'text-decoration: '.implode(' ', $decorations).';' : '';

        $shadow = $element->property('shadow');
        $shadowCss = '';
        if (is_array($shadow)) {
            $shadowColor = ColorConverter::toCss($shadow['color'] ?? null, $colorFormat, '#00000080');
            $shadowCss = sprintf(
                'text-shadow: %s%s %s%s %s%s %s;',
                $shadow['offsetX'] ?? 0, $unit, $shadow['offsetY'] ?? 0, $unit, $shadow['blur'] ?? 0, $unit, $shadowColor,
            );
        }

        // Text gradients have no faithful dompdf equivalent (no
        // background-clip:text support) - fall back to the gradient's first
        // stop as a flat color rather than emitting CSS that silently
        // renders nothing.
        $gradient = $element->property('gradient');
        if (is_array($gradient) && ! empty($gradient['colors'][0])) {
            $color = ColorConverter::toCss($gradient['colors'][0], $colorFormat, $color);
        }

        [$contentX, $contentY] = $element->contentAlignmentFactors();
        $contentPositionCss = $contentX === 0.0
            ? 'left: 0;'
            : ($contentX === 1.0 ? 'right: 0;' : 'left: 50%;');
        $contentPositionCss .= $contentY === 0.0
            ? ' top: 0;'
            : ($contentY === 1.0 ? ' bottom: 0;' : ' top: 50%;');
        $contentTransforms = [];
        if ($contentX === 0.5) {
            $contentTransforms[] = 'translateX(-50%)';
        }
        if ($contentY === 0.5) {
            $contentTransforms[] = 'translateY(-50%)';
        }
        if ($contentTransforms) {
            $contentPositionCss .= ' transform: '.implode(' ', $contentTransforms).';';
        }

        $overflowCss = $element->property('textResizeMode') === 'fixedSize' ? 'overflow: hidden;' : '';

        $bottomBorderEnabled = (bool) $element->property('bottomBorderEnabled', false);
        $bottomBorderCss = '';
        if ($bottomBorderEnabled) {
            $bottomBorderGap = max(0, (float) $element->property('bottomBorderGap', 1.5));
            $bottomBorderWidth = max(0.1, (float) $element->property('bottomBorderWidth', 0.4));
            $bottomBorderLeftPadding = max(0, (float) $element->property('bottomBorderLeftPadding', 0));
            $bottomBorderRightPadding = max(0, (float) $element->property('bottomBorderRightPadding', 0));
            $bottomBorderColor = ColorConverter::toCss(
                $element->property('bottomBorderColor', $element->property('color')), $colorFormat, $color,
            );
            $bottomBorderCss = sprintf(
                ' padding-left: %s%s; padding-right: %s%s; padding-bottom: %s%s; border-bottom: %s%s solid %s;',
                $bottomBorderLeftPadding, $unit,
                $bottomBorderRightPadding, $unit,
                $bottomBorderGap, $unit,
                $bottomBorderWidth, $unit,
                $bottomBorderColor,
            );
        }

        return [
            'fontFamily' => $fontFamily,
            'fontSizePt' => $fontSizePt,
            'fontWeight' => $isBold ? 700 : 400,
            'fontStyle' => $element->property('fontStyle', 'normal') === 'italic' ? 'italic' : 'normal',
            'color' => $color,
            'textAlign' => $textAlign,
            'lineHeightPt' => $lineHeightPt,
            'letterSpacing' => $letterSpacing,
            'decorationCss' => $decorationCss,
            'shadowCss' => $shadowCss,
            'overflowCss' => $overflowCss,
            'contentPositionCss' => $contentPositionCss,
            'baselineCorrectionPt' => $baselineCorrectionPt,
            'bottomBorderCss' => $bottomBorderCss,
        ];
    }
}
