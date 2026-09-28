<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

/**
 * Converts the format's pixel-valued properties (QR `padding`, the barcode
 * caption, arrowheads and blur sizes, all in 96-DPI CSS pixels) into a
 * project's own unit.
 */
class Units
{
    /** CSS pixels (1/96 in) in one of `$unit`. */
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
