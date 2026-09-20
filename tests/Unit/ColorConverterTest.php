<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Support\ColorConverter;
use PHPUnit\Framework\TestCase;

class ColorConverterTest extends TestCase
{
    public function test_opaque_six_digit_hex_is_read_the_same_regardless_of_color_format(): void
    {
        $cssHex = ColorConverter::toRgba('#112233', 'css-hex');
        $legacy = ColorConverter::toRgba('#112233', 'argb');

        $expected = ['r' => 0x11, 'g' => 0x22, 'b' => 0x33, 'a' => 1.0];
        $this->assertSame($expected, $cssHex);
        $this->assertSame($expected, $legacy);
    }

    public function test_eight_digit_css_hex_is_rrggbbaa_alpha_last(): void
    {
        $rgba = ColorConverter::toRgba('#1122337F', 'css-hex');

        $this->assertSame(0x11, $rgba['r']);
        $this->assertSame(0x22, $rgba['g']);
        $this->assertSame(0x33, $rgba['b']);
        $this->assertEqualsWithDelta(0x7F / 255, $rgba['a'], 0.0001);
    }

    public function test_eight_digit_legacy_hex_is_aarrggbb_alpha_first(): void
    {
        $rgba = ColorConverter::toRgba('#7F112233', 'argb');

        $this->assertSame(0x11, $rgba['r']);
        $this->assertSame(0x22, $rgba['g']);
        $this->assertSame(0x33, $rgba['b']);
        $this->assertEqualsWithDelta(0x7F / 255, $rgba['a'], 0.0001);
    }

    public function test_the_same_eight_hex_digits_mean_different_colors_depending_on_color_format(): void
    {
        $cssHex = ColorConverter::toRgba('#1122337F', 'css-hex');
        $legacy = ColorConverter::toRgba('#1122337F', 'argb');

        $this->assertNotSame($cssHex, $legacy);
    }

    public function test_short_three_digit_hex_expands(): void
    {
        $rgba = ColorConverter::toRgba('#abc', 'css-hex');

        $this->assertSame(['r' => 0xAA, 'g' => 0xBB, 'b' => 0xCC, 'a' => 1.0], $rgba);
    }

    public function test_null_or_empty_falls_back_to_the_given_fallback_color(): void
    {
        $rgba = ColorConverter::toRgba(null, 'css-hex', '#654321');

        $this->assertSame(['r' => 0x65, 'g' => 0x43, 'b' => 0x21, 'a' => 1.0], $rgba);
    }

    public function test_invalid_hex_falls_back_too(): void
    {
        $rgba = ColorConverter::toRgba('not-a-color', 'css-hex', '#654321');

        $this->assertSame(['r' => 0x65, 'g' => 0x43, 'b' => 0x21, 'a' => 1.0], $rgba);
    }

    public function test_to_css_renders_opaque_colors_as_hex_and_translucent_as_rgba(): void
    {
        $this->assertSame('#112233', ColorConverter::toCss('#112233', 'css-hex'));
        // 0x99 / 255 = 0.6 exactly, so the rounded alpha renders cleanly.
        $this->assertSame('rgba(17, 34, 51, 0.6)', ColorConverter::toCss('#11223399', 'css-hex'));
    }
}
