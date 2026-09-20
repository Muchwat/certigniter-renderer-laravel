<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Maps Certigniter's `barcodeType` values (design_element.dart's symbology
 * switch) to picqer/php-barcode-generator's type constants. Certigniter's
 * own bulk-CSV export ignores barcodeType entirely and always renders
 * Code128 (a bug, not a spec) - this renders the symbology the project
 * actually asked for.
 */
class BarcodeRenderer
{
    private const TYPE_MAP = [
        'code39' => BarcodeGenerator::TYPE_CODE_39,
        'ean13' => BarcodeGenerator::TYPE_EAN_13,
        'ean8' => BarcodeGenerator::TYPE_EAN_8,
        'upcA' => BarcodeGenerator::TYPE_UPC_A,
        'itf' => BarcodeGenerator::TYPE_INTERLEAVED_2_5,
        'codabar' => BarcodeGenerator::TYPE_CODABAR,
        'code128' => BarcodeGenerator::TYPE_CODE_128,
    ];

    /** @param array{r: int, g: int, b: int} $foreground */
    public static function pngDataUri(
        string $data,
        string $barcodeType,
        int $widthPx,
        int $heightPx,
        array $foreground,
    ): string {
        $type = self::TYPE_MAP[$barcodeType] ?? BarcodeGenerator::TYPE_CODE_128;

        $generator = new BarcodeGeneratorPNG;
        $color = [$foreground['r'], $foreground['g'], $foreground['b']];

        // widthFactor is per-bar-element pixel width, not a total-image
        // width - approximate one from the box's pixel width and a rough
        // encoded-length guess so wider elements get proportionally
        // chunkier bars instead of a fixed size regardless of box size.
        $widthFactor = max(1, (int) round($widthPx / max(50, strlen($data) * 11)));
        $height = max(20, $heightPx);

        $png = $generator->getBarcode($data, $type, $widthFactor, $height, $color);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
