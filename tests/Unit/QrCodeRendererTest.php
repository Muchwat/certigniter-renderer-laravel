<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\QrCodeRenderer;
use Certigniter\CertificateRenderer\Support\Units;
use PHPUnit\Framework\TestCase;

/**
 * Placement: a background square of the box's shorter side, centred, with
 * `padding` CSS pixels of quiet zone.
 */
class QrCodeRendererTest extends TestCase
{
    private const BLACK = ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 1.0];

    private const WHITE = ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0];

    /** @param  array<string, mixed>  $properties */
    private function qr(float $width, float $height, array $properties = []): DesignElement
    {
        return DesignElement::fromArray([
            'id' => 'qr', 'type' => 'qrcode', 'x' => 0, 'y' => 0, 'width' => $width, 'height' => $height,
            'properties' => $properties,
        ]);
    }

    public function test_the_error_correction_level_uses_the_format_indicator_bits(): void
    {
        // ISO/IEC 18004 format indicator: L=1, M=0, Q=3, H=2 - not alphabetical.
        $this->assertSame('L', QrCodeRenderer::levelLetter(1));
        $this->assertSame('M', QrCodeRenderer::levelLetter(0));
        $this->assertSame('Q', QrCodeRenderer::levelLetter(3));
        $this->assertSame('H', QrCodeRenderer::levelLetter(2));
        $this->assertSame('H', QrCodeRenderer::levelLetter('h'));
        $this->assertSame('L', QrCodeRenderer::levelLetter(null));
    }

    public function test_the_code_is_a_centred_square_with_the_default_two_pixel_quiet_zone(): void
    {
        $mm = Units::pixelsPer('mm');
        $svg = QrCodeRenderer::svg($this->qr(30, 20), 'certigniter_placeholder', self::BLACK, self::WHITE, $mm);

        $this->assertStringContainsString('<rect x="5" y="0" width="20" height="20" fill="#ffffff"', $svg);
        $padding = 2 / $mm;
        // certigniter_placeholder at level L is a version 2 symbol, 25 modules across.
        $module = (20 - 2 * $padding) / 25;
        $this->assertStringContainsString(sprintf('translate(%s,%s) scale(%s)', 5 + $padding, $padding, $module), $svg);
    }

    public function test_round_modules_and_eyes_are_drawn_as_circles(): void
    {
        $square = QrCodeRenderer::svg($this->qr(20, 20), 'certigniter_placeholder', self::BLACK, self::WHITE, Units::pixelsPer('mm'));
        $round = QrCodeRenderer::svg(
            $this->qr(20, 20, ['dataModuleShape' => 'circle', 'eyeShape' => 'circle']),
            'certigniter_placeholder', self::BLACK, self::WHITE, Units::pixelsPer('mm'),
        );

        $this->assertStringNotContainsString('<circle', $square);
        $this->assertStringContainsString('<circle cx="3.5" cy="3.5" r="3" fill="none" stroke-width="1"', $round);
        $this->assertStringContainsString('r="0.5"', $round);
        $this->assertStringNotContainsString('<path', $round);
    }
}
