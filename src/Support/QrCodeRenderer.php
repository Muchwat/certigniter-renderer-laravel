<?php

namespace Certigniter\CertificateRenderer\Support;

use SimpleSoftwareIO\QrCode\Facades\QrCode;

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

    public static function pngDataUri(
        string $data,
        int $sizePx,
        array $foreground,
        array $background,
        int $errorCorrectionLevel = 0,
    ): string {
        $level = self::ERROR_CORRECTION_LEVELS[$errorCorrectionLevel] ?? 'L';

        $png = QrCode::format('png')
            ->size(max(50, min(1000, $sizePx)))
            ->margin(1)
            ->errorCorrection($level)
            ->color($foreground['r'], $foreground['g'], $foreground['b'])
            ->backgroundColor($background['r'], $background['g'], $background['b'])
            ->generate($data);

        // generate() returns an Illuminate\Support\HtmlString wrapping the
        // raw PNG bytes when that class is loaded (true in any Laravel
        // app) rather than a plain string - cast explicitly rather than
        // relying on base64_encode()'s implicit Stringable coercion.
        return 'data:image/png;base64,'.base64_encode((string) $png);
    }
}
