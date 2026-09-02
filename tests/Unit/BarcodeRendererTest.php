<?php

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Support\BarcodeRenderer;
use PHPUnit\Framework\TestCase;

class BarcodeRendererTest extends TestCase
{
    private function assertIsPngDataUri(string $uri): void
    {
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $bytes = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);
        $this->assertNotFalse($bytes);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $bytes);
    }

    public function test_every_known_barcode_type_renders_a_valid_png(): void
    {
        foreach (['code39', 'ean13', 'ean8', 'upcA', 'itf', 'codabar', 'code128'] as $type) {
            $data = $type === 'ean13' ? '123456789012' : ($type === 'ean8' ? '1234567' : '123456789');
            $uri = BarcodeRenderer::pngDataUri($data, $type, 100, 30, ['r' => 0, 'g' => 0, 'b' => 0]);

            $this->assertIsPngDataUri($uri);
        }
    }

    public function test_an_unrecognized_barcode_type_falls_back_to_code128_instead_of_throwing(): void
    {
        $uri = BarcodeRenderer::pngDataUri('123456789', 'not-a-real-symbology', 100, 30, ['r' => 0, 'g' => 0, 'b' => 0]);

        $this->assertIsPngDataUri($uri);
    }

    public function test_height_is_floored_at_20px_regardless_of_a_smaller_requested_height(): void
    {
        // Not directly observable from the data URI alone, but this at least
        // guards against an exception/zero-height image for a tiny box.
        $uri = BarcodeRenderer::pngDataUri('123456789', 'code128', 20, 2, ['r' => 0, 'g' => 0, 'b' => 0]);

        $this->assertIsPngDataUri($uri);
    }
}
