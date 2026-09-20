<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\ShapeRenderer;
use PHPUnit\Framework\TestCase;

class ShapeRendererTest extends TestCase
{
    private function svgOf(DesignElement $element): string
    {
        $rendered = ShapeRenderer::render($element, 'css-hex');

        return base64_decode(substr($rendered['src'], strlen('data:image/svg+xml;base64,')));
    }

    /** @param array<string, mixed> $properties */
    private function rect(array $properties): DesignElement
    {
        return new DesignElement(id: 's', type: 'shape', x: 0, y: 0, width: 40, height: 20, properties: $properties);
    }

    public function test_corners_are_sharp_miter_not_rounded(): void
    {
        $svg = $this->svgOf($this->rect([]));

        $this->assertStringContainsString('stroke-linejoin="miter"', $svg);
    }

    public function test_fill_enabled_false_forces_fill_none_even_with_a_fill_color(): void
    {
        $svg = $this->svgOf($this->rect(['fillEnabled' => false, 'fillColor' => '#FF0000']));

        $this->assertStringContainsString('fill="none"', $svg);
    }

    public function test_fill_enabled_defaults_to_true_when_absent(): void
    {
        $svg = $this->svgOf($this->rect(['fillColor' => '#123456']));

        $this->assertStringContainsString('fill="#123456"', $svg);
    }

    public function test_border_enabled_false_drops_the_stroke_even_with_a_stroke_width(): void
    {
        $svg = $this->svgOf($this->rect(['borderEnabled' => false, 'strokeWidth' => 2.0]));

        $this->assertStringContainsString('stroke="none"', $svg);
        $this->assertStringContainsString('stroke-width="0"', $svg);
    }

    public function test_dashed_and_dotted_border_styles_emit_matching_dasharray(): void
    {
        $dashed = $this->svgOf($this->rect(['borderStyle' => 'dashed', 'strokeWidth' => 2.0]));
        $dotted = $this->svgOf($this->rect(['borderStyle' => 'dotted', 'strokeWidth' => 2.0]));
        $solid = $this->svgOf($this->rect(['borderStyle' => 'solid', 'strokeWidth' => 2.0]));

        $this->assertStringContainsString('stroke-dasharray="5 3.6"', $dashed);
        $this->assertStringContainsString('stroke-dasharray="0.01 4.4"', $dotted);
        $this->assertStringContainsString('stroke-linecap="round"', $dotted);
        $this->assertStringNotContainsString('stroke-dasharray', $solid);
        $this->assertStringContainsString('stroke-linecap="butt"', $solid);
    }

    public function test_a_uniform_corner_radius_emits_the_simple_rect_shortcut(): void
    {
        $svg = $this->svgOf($this->rect(['cornerRadius' => 4.0]));

        $this->assertStringContainsString('<rect ', $svg);
        $this->assertStringNotContainsString('<path', $svg);
    }

    public function test_differing_per_corner_radii_build_a_hand_rolled_path(): void
    {
        $svg = $this->svgOf($this->rect(['cornerRadiusTopLeft' => 6.0, 'cornerRadiusBottomRight' => 2.0]));

        $this->assertStringContainsString('<path ', $svg);
        $this->assertStringNotContainsString('<rect ', $svg);
    }

    public function test_per_corner_radius_falls_back_to_the_legacy_uniform_corner_radius(): void
    {
        $svg = $this->svgOf($this->rect(['cornerRadius' => 4.0]));

        $this->assertMatchesRegularExpression('/rx="4"/', $svg);
    }

    public function test_oversized_corners_clamp_independently_without_affecting_the_others(): void
    {
        // Matches the Studio editor's own per-corner clamp (the web Studio's
        // normalizedCornerRadii and Flutter's _ShapePainter._cornerRadius):
        // each corner is capped at half the shorter side on its own, rather
        // than scaling all four down together when one doesn't fit.
        $element = new DesignElement(
            id: 's', type: 'shape', x: 0, y: 0, width: 36.5, height: 36.5,
            properties: [
                'cornerRadiusTopLeft' => 7.4,
                'cornerRadiusTopRight' => 30.0,
                'cornerRadiusBottomRight' => 30.0,
                'cornerRadiusBottomLeft' => 30.0,
            ],
        );

        $method = new \ReflectionMethod(ShapeRenderer::class, 'cornerRadii');
        [$tl, $tr, $br, $bl] = $method->invoke(null, $element, 36.5, 36.5);

        $this->assertEqualsWithDelta(7.4, $tl, 1e-6);
        $this->assertEqualsWithDelta(18.25, $tr, 1e-6);
        $this->assertEqualsWithDelta(18.25, $br, 1e-6);
        $this->assertEqualsWithDelta(18.25, $bl, 1e-6);
    }

    public function test_no_shadow_leaves_the_box_at_the_elements_own_bounds(): void
    {
        $rendered = ShapeRenderer::render($this->rect([]), 'css-hex');

        $this->assertSame(40.0, $rendered['width']);
        $this->assertSame(20.0, $rendered['height']);
        $this->assertSame(0.0, $rendered['offsetX']);
        $this->assertSame(0.0, $rendered['offsetY']);
    }

    public function test_a_down_right_shadow_widens_the_box_without_shifting_the_anchor(): void
    {
        $rendered = ShapeRenderer::render($this->rect([
            'shadowEnabled' => true, 'shadowOffsetX' => 3.0, 'shadowOffsetY' => 5.0,
        ]), 'css-hex');

        $this->assertSame(43.0, $rendered['width']);
        $this->assertSame(25.0, $rendered['height']);
        $this->assertSame(0.0, $rendered['offsetX']);
        $this->assertSame(0.0, $rendered['offsetY']);
    }

    public function test_an_up_left_shadow_widens_the_box_and_shifts_the_anchor_negative(): void
    {
        $rendered = ShapeRenderer::render($this->rect([
            'shadowEnabled' => true, 'shadowOffsetX' => -3.0, 'shadowOffsetY' => -5.0,
        ]), 'css-hex');

        $this->assertSame(43.0, $rendered['width']);
        $this->assertSame(25.0, $rendered['height']);
        $this->assertSame(-3.0, $rendered['offsetX']);
        $this->assertSame(-5.0, $rendered['offsetY']);
    }

    public function test_polygon_points_are_scaled_to_the_element_box_and_clamped_to_0_1(): void
    {
        $element = new DesignElement(
            id: 's', type: 'shape', x: 0, y: 0, width: 40, height: 20,
            properties: [
                'shapeType' => 'polygon',
                'points' => [
                    ['x' => -1.0, 'y' => 0.0], ['x' => 1.0, 'y' => 0.0],
                    ['x' => 1.0, 'y' => 2.0], ['x' => 0.0, 'y' => 1.0],
                ],
            ],
        );

        $svg = $this->svgOf($element);

        $this->assertStringContainsString('<polygon points="0,0 40,0 40,20 0,20" />', $svg);
    }
}
