<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\BarcodeRenderer;
use Certigniter\CertificateRenderer\Support\Units;
use PHPUnit\Framework\TestCase;

/**
 * Layout mirrors canvasRendering.js's drawBarcode(): bars stretched across
 * the whole box, a caption of min(10px, height / 3) along the bottom with
 * fontSize * 1.2 + 2px kept free for it, and none of that when `showText`
 * is false.
 */
class BarcodeRendererTest extends TestCase
{
    private const BLACK = ['r' => 0, 'g' => 0, 'b' => 0, 'a' => 1.0];

    private const WHITE = ['r' => 255, 'g' => 255, 'b' => 255, 'a' => 1.0];

    /** @param  array<string, mixed>  $properties */
    private function barcode(float $width, float $height, array $properties = []): DesignElement
    {
        return DesignElement::fromArray([
            'id' => 'code', 'type' => 'barcode', 'x' => 0, 'y' => 0, 'width' => $width, 'height' => $height,
            'properties' => $properties + ['barcodeType' => 'code128'],
        ]);
    }

    public function test_the_caption_takes_the_studios_share_of_the_box(): void
    {
        $mm = Units::pixelsPer('mm');
        $layout = BarcodeRenderer::layout($this->barcode(50, 15), '123456789', $mm);

        // 15 mm is ~56.7px high, so the font is capped at 10px.
        $this->assertEqualsWithDelta(10 / $mm, $layout['fontSize'], 1e-9);
        $this->assertEqualsWithDelta(15 - (10 * 1.2 + 2) / $mm, $layout['barHeight'], 1e-9);
        $this->assertSame('123456789', $layout['text']);
        $this->assertTrue($layout['showText']);
    }

    public function test_a_short_box_shrinks_the_caption_to_a_third_of_its_height(): void
    {
        $mm = Units::pixelsPer('mm');
        $layout = BarcodeRenderer::layout($this->barcode(50, 6), '123456789', $mm);

        $this->assertEqualsWithDelta(2.0, $layout['fontSize'], 1e-9);
    }

    public function test_hiding_the_text_gives_the_bars_the_whole_height(): void
    {
        $layout = BarcodeRenderer::layout($this->barcode(50, 15, ['showText' => false]), '123456789', Units::pixelsPer('mm'));

        $this->assertFalse($layout['showText']);
        $this->assertSame(15.0, $layout['barHeight']);
    }

    public function test_the_bars_run_edge_to_edge_over_the_background(): void
    {
        $element = $this->barcode(40, 10, ['showText' => false]);
        $layout = BarcodeRenderer::layout($element, 'X1Y', Units::pixelsPer('mm'));
        $svg = BarcodeRenderer::svg($element, $layout, self::BLACK, self::WHITE);

        $this->assertStringContainsString('<rect x="0" y="0" width="40" height="10" fill="#ffffff"', $svg);
        // Code 128 starts and ends on a bar, so the first run starts at 0 and the last ends at the full width.
        $this->assertStringContainsString('M 0 0 L', $svg);
        $this->assertMatchesRegularExpression('/L 40 0 L 40 10 L [0-9.]+ 10 Z"/', $svg);
    }

    public function test_an_empty_barcode_draws_the_studios_sample_for_its_type(): void
    {
        $this->assertSame('4006381333931', BarcodeRenderer::sampleData('ean13'));
        $this->assertSame('123456789', BarcodeRenderer::sampleData('something-else'));
    }
}
