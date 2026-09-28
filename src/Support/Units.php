<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

/**
 * Converts the Studio's pixel-valued properties (QR `padding`, the barcode
 * caption, arrowheads and blur sizes, all 96-DPI CSS px in canvasRendering.js)
 * into a project's own unit.
 */
class Units
{
    /** Studio px (1/96 in) in one of `$unit`. */
    public static function pixelsPer(string $unit): float
    {
        return match ($unit) {
            'cm' => 96 / 2.54,
            'in' => 96.0,
            'pt' => 96 / 72,
            'px' => 1.0,
            default => 96 / 25.4,
        };
    }
}
