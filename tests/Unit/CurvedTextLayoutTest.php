<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\CurvedTextLayout;
use PHPUnit\Framework\TestCase;

/**
 * Fixed cases with known expected numbers: "ABCD", every character 10 wide,
 * on a circle of radius 50 in a 60 x 30 box with 12-high line boxes.
 */
class CurvedTextLayoutTest extends TestCase
{
    /** @return list<array{0: string, 1: float, 2: float, 3: float}> */
    private function place(float $radius): array
    {
        $glyphs = CurvedTextLayout::glyphs('ABCD', fn (string $prefix): float => mb_strlen($prefix) * 10.0);

        return array_map(
            fn (array $glyph): array => [$glyph['text'], $glyph['x'], $glyph['y'], $glyph['angle']],
            CurvedTextLayout::place($glyphs, $radius, 12, 60, 30),
        );
    }

    /**
     * @param  list<array{0: string, 1: float, 2: float, 3: float}>  $actual
     * @param  list<array{0: string, 1: float, 2: float, 3: float}>  $expected
     */
    private function assertPlaced(array $actual, array $expected): void
    {
        $this->assertCount(count($expected), $actual);
        foreach ($expected as $index => [$text, $x, $y, $angle]) {
            $this->assertSame($text, $actual[$index][0]);
            $this->assertEqualsWithDelta($x, $actual[$index][1], 1e-5);
            $this->assertEqualsWithDelta($y, $actual[$index][2], 1e-5);
            $this->assertEqualsWithDelta($angle, $actual[$index][3], 1e-6);
        }
    }

    public function test_an_arch_stands_the_glyphs_on_the_outside_of_the_circle(): void
    {
        $this->assertPlaced($this->place(50), [
            ['A', 15.22399, 8.233176, -0.3],
            ['B', 25.008329, 6.249792, -0.1],
            ['C', 34.991671, 6.249792, 0.1],
            ['D', 44.77601, 8.233176, 0.3],
        ]);
    }

    public function test_a_dip_hangs_them_inside_the_circle(): void
    {
        $this->assertPlaced($this->place(-50), [
            ['A', 15.22399, 21.766824, 0.3],
            ['B', 25.008329, 23.750208, 0.1],
            ['C', 34.991671, 23.750208, -0.1],
            ['D', 44.77601, 21.766824, -0.3],
        ]);
    }

    public function test_glyphs_are_measured_as_prefixes_so_kerning_is_kept(): void
    {
        $kerned = ['A' => 10.0, 'AV' => 17.0, 'AVA' => 27.0];

        $this->assertSame([
            ['text' => 'A', 'start' => 0.0, 'end' => 10.0],
            ['text' => 'V', 'start' => 10.0, 'end' => 17.0],
            ['text' => 'A', 'start' => 17.0, 'end' => 27.0],
        ], CurvedTextLayout::glyphs('AVA', fn (string $prefix): float => $kerned[$prefix]));
    }

    public function test_line_breaks_read_as_spaces(): void
    {
        $this->assertSame('Certificate of Merit', CurvedTextLayout::line("Certificate\nof  \r\n Merit"));
    }

    public function test_the_radius_is_read_from_the_element_and_anything_else_is_straight(): void
    {
        $element = fn (array $properties): DesignElement => new DesignElement(id: 't', type: 'text', x: 0, y: 0, width: 10, height: 10, properties: $properties);

        $this->assertSame(-40.0, CurvedTextLayout::radius($element(['curveRadius' => -40])));
        $this->assertSame(0.0, CurvedTextLayout::radius($element(['curveRadius' => null])));
        $this->assertSame(0.0, CurvedTextLayout::radius($element([])));
    }
}
