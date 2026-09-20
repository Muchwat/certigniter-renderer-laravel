<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Stringable;

/**
 * Certigniter's `errorCorrectionLevel` is stored as the Dart `qr` package's
 * numeric QrErrorCorrectLevel (0=L, 1=M, 2=Q, 3=H) - map it to the letter
 * simple-qrcode/bacon-qr-code expect. `eyeShape`/`dataModuleShape` (custom
 * QR eye/module shapes) have no equivalent in simple-qrcode and are
 * dropped - Certigniter's own bulk-CSV export drops them too (canvas-only
 * cosmetic), so this isn't a new gap.
 */
class QrCodeRenderer
{
    private const ERROR_CORRECTION_LEVELS = ['L', 'M', 'Q', 'H'];

    /**
     * @param  array{r: int, g: int, b: int}  $foreground
     * @param  array{r: int, g: int, b: int}  $background
     */
    public static function svgDataUri(
        string $data,
        int $sizePx,
        array $foreground,
        array $background,
        int $errorCorrectionLevel = 0,
    ): string {
        $level = self::ERROR_CORRECTION_LEVELS[$errorCorrectionLevel] ?? 'L';

        // generate() is declared as returning an HtmlString-alike, a
        // string, or null - it only ever returns markup here, but say so
        // rather than casting something that might be an object.
        $svg = QrCode::format('svg')
            ->size(max(50, min(1000, $sizePx)))
            ->margin(0)
            ->errorCorrection($level)
            ->color($foreground['r'], $foreground['g'], $foreground['b'])
            ->backgroundColor($background['r'], $background['g'], $background['b'])
            ->generate($data);

        return 'data:image/svg+xml;base64,'.base64_encode($svg instanceof Stringable || is_string($svg) ? (string) $svg : '');
    }
}
