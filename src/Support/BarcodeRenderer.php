<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Lays out a barcode the way the web Studio draws it (canvasRendering.js's
 * `drawBarcode()`): the whole box filled with `backgroundColor`, the
 * symbol's modules (BarcodeEncoding, a port of the JsBarcode encoders the
 * Studio uses) stretched edge to edge across the box, and, unless
 * `showText` is false, the caption centred along the bottom in
 * min(10px, height / 3) Roboto, with fontSize * 1.2 + 2px kept free for it.
 */
class BarcodeRenderer
{
    /** canvasRendering.js BARCODE_SAMPLES: what the Studio draws for a barcode with no data. */
    private const SAMPLES = [
        'code128' => '123456789', 'code39' => 'CODE39', 'ean13' => '4006381333931', 'ean8' => '96385074',
        'upcA' => '036000291452', 'itf' => '1234567890', 'codabar' => 'A123456A',
    ];

    public static function sampleData(string $barcodeType): string
    {
        return self::SAMPLES[$barcodeType] ?? self::SAMPLES['code128'];
    }

    /**
     * @param  float  $pixelsPerUnit  Studio px (1/96 in) in one of the project's units
     * @return array{modules: string, text: string, showText: bool, barHeight: float, fontSize: float}
     *   `barHeight` and `fontSize` are in the project's unit
     */
    public static function layout(DesignElement $element, string $data, float $pixelsPerUnit): array
    {
        $encoding = BarcodeEncoding::encode($data, (string) ($element->property('barcodeType') ?: 'code128'));
        $height = max(0.0, $element->height);
        $showText = $element->property('showText') !== false;
        $fontSize = min(10 / $pixelsPerUnit, $height / 3);
        $textHeight = $showText ? $fontSize * 1.2 + 2 / $pixelsPerUnit : 0.0;

        return [
            'modules' => $encoding['modules'],
            'text' => $encoding['text'],
            'showText' => $showText,
            'barHeight' => max(0.0, $height - $textHeight),
            'fontSize' => $fontSize,
        ];
    }

    /**
     * The background and bars, in the element's own box and unit. The
     * caption is not drawn here: SVG text support in the PDF engines is too
     * thin to trust, so each renderer sets it as ordinary text.
     *
     * @param  array{modules: string, barHeight: float}  $layout
     * @param  array{r: int, g: int, b: int, a: float}  $foreground
     * @param  array{r: int, g: int, b: int, a: float}  $background
     */
    public static function svg(DesignElement $element, array $layout, array $foreground, array $background): string
    {
        $width = max(0.0, $element->width);
        $height = max(0.0, $element->height);
        $modules = $layout['modules'];
        $module = strlen($modules) > 0 ? $width / strlen($modules) : 0.0;

        // Each run of bar modules is one rectangle, so a wide bar has no seams.
        $bars = '';
        preg_match_all('/1+/', $modules, $runs, PREG_OFFSET_CAPTURE);
        foreach ($runs[0] as [$run, $offset]) {
            $x = $offset * $module;
            $right = ($offset + strlen($run)) * $module;
            $bars .= sprintf('M %s 0 L %s 0 L %s %s L %s %s Z ', $x, $right, $right, $layout['barHeight'], $x, $layout['barHeight']);
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$s" height="%2$s" viewBox="0 0 %1$s %2$s">'
            .'<rect x="0" y="0" width="%1$s" height="%2$s" %3$s />%4$s</svg>',
            $width, $height,
            self::paint($background),
            $bars === '' || $layout['barHeight'] <= 0 ? '' : sprintf('<path d="%s" %s />', trim($bars), self::paint($foreground)),
        );
    }

    /** @param  array{r: int, g: int, b: int, a: float}  $rgba */
    private static function paint(array $rgba): string
    {
        return sprintf('fill="#%02x%02x%02x" fill-opacity="%s"', $rgba['r'], $rgba['g'], $rgba['b'], $rgba['a']);
    }
}
