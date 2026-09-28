<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Support\PathShapeGeometry;
use PHPUnit\Framework\TestCase;

/**
 * Same inputs and expected paths as the web Studio's shapePaths.test.js
 * (certigniter-saas), so the port provably draws what the editor draws.
 */
class PathShapeGeometryTest extends TestCase
{
    private const SQUARE = [['d' => 'M 0 0 L 1 0 L 1 1 L 0 1 Z', 'color' => 0]];

    /**
     * @param  list<array<string, mixed>>  $parts
     * @param  list<string>  $colors
     * @return array{0: string, 1: array{color: string, rule: string, subpaths: list<array{start: array{float, float}, segments: list<array{type: string, points: list<float>}>, closed: bool}>}}
     */
    private function path(array $parts, float $width, float $height, float $stroke = 0.0, float $radius = 0.0, array $colors = ['#123456']): array
    {
        $outline = PathShapeGeometry::outlines($colors, $parts, $width, $height, $stroke, $radius)[0];

        return [PathShapeGeometry::toSvgPath($outline['subpaths']), $outline];
    }

    public function test_stretches_the_unit_outline_to_the_box_inset_by_half_the_stroke(): void
    {
        [$d, $outline] = $this->path(self::SQUARE, 100, 50, 4);

        $this->assertSame('M 2 2 L 98 2 L 98 48 L 2 48 Z', $d);
        $this->assertSame('#123456', $outline['color']);
        $this->assertSame('nonzero', $outline['rule']);
    }

    public function test_rounds_every_sharp_corner(): void
    {
        [$d] = $this->path(self::SQUARE, 100, 100, 0, 10);

        $this->assertSame(4, substr_count($d, 'C'));
        $this->assertStringStartsWith('M 10 0 L 90 0 C', $d);
    }

    public function test_never_cuts_more_than_half_a_side(): void
    {
        [$d] = $this->path(self::SQUARE, 10, 10, 0, 50);

        $this->assertStringStartsWith('M 5 0 L 5 0 C', $d);
    }

    public function test_rounds_a_corner_between_two_curves(): void
    {
        $lens = [['d' => 'M 0 0.5 C 0.3 0 0.7 0 1 0.5 C 0.7 1 0.3 1 0 0.5 Z', 'color' => 0]];
        [$plain] = $this->path($lens, 100, 100);
        [$rounded] = $this->path($lens, 100, 100, 0, 5);

        $this->assertSame(substr_count($plain, 'C') + 2, substr_count($rounded, 'C'));
    }

    public function test_keeps_evenodd_and_falls_back_to_black_for_a_missing_colour(): void
    {
        [, $outline] = $this->path([['d' => 'M 0 0 L 1 1 Z', 'color' => 3, 'rule' => 'evenodd']], 10, 10, colors: []);

        $this->assertSame('#000000', $outline['color']);
        $this->assertSame('evenodd', $outline['rule']);
    }

    public function test_ignores_malformed_input(): void
    {
        $this->assertSame([], PathShapeGeometry::outlines([], ['nonsense'], 10, 10));
        $this->assertSame([], PathShapeGeometry::parse('<script>'));
    }
}
