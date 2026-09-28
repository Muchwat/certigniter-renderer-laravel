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
            'shadowEnabled' => true, 'shadowOffsetX' => 3.0, 'shadowOffsetY' => 5.0, 'shadowBlur' => 0,
        ]), 'css-hex');

        $this->assertSame(43.0, $rendered['width']);
        $this->assertSame(25.0, $rendered['height']);
        $this->assertSame(0.0, $rendered['offsetX']);
        $this->assertSame(0.0, $rendered['offsetY']);
    }

    public function test_an_up_left_shadow_widens_the_box_and_shifts_the_anchor_negative(): void
    {
        $rendered = ShapeRenderer::render($this->rect([
            'shadowEnabled' => true, 'shadowOffsetX' => -3.0, 'shadowOffsetY' => -5.0, 'shadowBlur' => 0,
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

    public function test_a_triangle_keeps_its_three_points_instead_of_falling_back_to_a_box(): void
    {
        $svg = $this->svgOf($this->rect([
            'shapeType' => 'polygon',
            'points' => [['x' => 0.5, 'y' => 0.0], ['x' => 1.0, 'y' => 1.0], ['x' => 0.0, 'y' => 1.0]],
        ]));

        $this->assertStringContainsString('<polygon points="20,0 40,20 0,20" />', $svg);
    }

    public function test_a_star_keeps_every_point(): void
    {
        $points = array_map(fn (int $index): array => ['x' => $index / 9, 'y' => $index % 2], range(0, 9));

        $svg = $this->svgOf($this->rect(['shapeType' => 'polygon', 'points' => $points]));

        $this->assertSame(1, preg_match('/<polygon points="([^"]+)"/', $svg, $match));
        $this->assertCount(10, explode(' ', $match[1] ?? ''));
    }

    public function test_fewer_than_three_polygon_points_fall_back_to_the_box(): void
    {
        $svg = $this->svgOf($this->rect([
            'shapeType' => 'polygon',
            'points' => [['x' => 0.0, 'y' => 0.0], ['x' => 1.0, 'y' => 1.0]],
        ]));

        $this->assertStringContainsString('<polygon points="0,0 40,0 40,20 0,20" />', $svg);
    }

    public function test_an_ellipse_is_inset_by_half_its_stroke_like_the_studio_canvas(): void
    {
        $svg = $this->svgOf($this->rect(['shapeType' => 'ellipse', 'strokeWidth' => 2, 'strokeAlign' => 'outside']));

        $this->assertStringContainsString('<ellipse cx="20" cy="10" rx="19" ry="9" />', $svg);
        $this->assertStringNotContainsString('<rect', $svg);
    }

    public function test_an_inside_border_is_drawn_on_the_inset_path_it_fills(): void
    {
        $svg = $this->svgOf($this->rect(['strokeWidth' => 2]));

        $this->assertStringContainsString('<rect x="1" y="1" width="38" height="18" rx="0" />', $svg);
        $this->assertStringContainsString('viewBox="0 0 40 20"', $svg);
    }

    public function test_an_outside_border_fills_the_box_and_strokes_around_it(): void
    {
        $rendered = ShapeRenderer::render($this->rect(['strokeWidth' => 2, 'strokeAlign' => 'outside']), 'css-hex');
        $svg = base64_decode(substr($rendered['src'], strlen('data:image/svg+xml;base64,')));

        $this->assertStringContainsString('stroke="none"><rect x="0" y="0" width="40" height="20" rx="0" />', $svg);
        $this->assertStringContainsString('fill="none" stroke="#000000" stroke-width="2"', $svg);
        $this->assertStringContainsString('<rect x="-1" y="-1" width="42" height="22" rx="0" />', $svg);
        // Padded by a full border width so the stroke outside the box is not clipped.
        $this->assertSame([44.0, 24.0, -2.0, -2.0], [$rendered['width'], $rendered['height'], $rendered['offsetX'], $rendered['offsetY']]);
    }

    public function test_a_centred_border_sits_on_the_box_edge(): void
    {
        $rendered = ShapeRenderer::render($this->rect(['strokeWidth' => 2, 'strokeAlign' => 'center']), 'css-hex');
        $svg = base64_decode(substr($rendered['src'], strlen('data:image/svg+xml;base64,')));

        $this->assertSame(2, substr_count($svg, '<rect x="0" y="0" width="40" height="20" rx="0" />'));
        $this->assertSame([42.0, 22.0, -1.0, -1.0], [$rendered['width'], $rendered['height'], $rendered['offsetX'], $rendered['offsetY']]);
    }

    public function test_an_outside_border_grows_rounded_corners_and_keeps_square_ones_square(): void
    {
        $svg = $this->svgOf($this->rect(['strokeWidth' => 2, 'strokeAlign' => 'outside', 'cornerRadius' => 4]));

        $this->assertStringContainsString('<rect x="-1" y="-1" width="42" height="22" rx="5" />', $svg);
        $this->assertStringContainsString('<rect x="0" y="0" width="40" height="20" rx="4" />', $svg);
    }

    public function test_an_unknown_stroke_position_is_inside(): void
    {
        $this->assertSame('inside', ShapeRenderer::strokeAlign($this->rect(['strokeAlign' => 'sideways'])));
    }

    public function test_a_library_shape_draws_each_part_in_its_own_colour_with_round_joins(): void
    {
        $svg = $this->svgOf($this->rect([
            'shapeType' => 'path',
            'pathColors' => ['#6464DC', '#FFFFFF'],
            'pathParts' => [
                ['d' => 'M 0 0 L 1 0 L 1 1 L 0 1 Z', 'color' => 0],
                ['d' => 'M 0.25 0.25 L 0.75 0.25 L 0.75 0.75 Z', 'color' => 1, 'rule' => 'evenodd'],
            ],
            'strokeWidth' => 2.0,
            'strokeColor' => '#000000',
        ]));

        $this->assertStringContainsString('<path d="M 1 1 L 39 1 L 39 19 L 1 19 Z" fill="#6464dc" fill-rule="nonzero" stroke="#000000"', $svg);
        $this->assertStringContainsString('fill="#ffffff" fill-rule="evenodd"', $svg);
        $this->assertStringContainsString('stroke-linejoin="round"', $svg);
    }

    public function test_a_library_shape_leaves_out_parts_hidden_in_the_studio_layers_panel(): void
    {
        $svg = $this->svgOf($this->rect([
            'shapeType' => 'path',
            'pathColors' => ['#6464DC', '#FFFFFF'],
            'pathParts' => [
                ['d' => 'M 0 0 L 1 0 L 1 1 L 0 1 Z', 'color' => 0, 'hidden' => true],
                ['d' => 'M 0.25 0.25 L 0.75 0.25 L 0.75 0.75 Z', 'color' => 1, 'locked' => true],
            ],
            'borderEnabled' => false,
        ]));

        $this->assertStringNotContainsString('fill="#6464dc"', $svg);
        $this->assertStringContainsString('fill="#ffffff"', $svg);
    }

    public function test_a_library_shape_leaves_out_parts_inside_a_hidden_vector_group(): void
    {
        $svg = $this->svgOf($this->rect([
            'shapeType' => 'path',
            'pathColors' => ['#6464DC', '#FFFFFF', '#FF0000'],
            'pathParts' => [
                ['d' => 'M 0 0 L 1 0 L 1 1 L 0 1 Z', 'color' => 0, 'group' => 'crown'],
                ['d' => 'M 0.25 0.25 L 0.75 0.25 L 0.75 0.75 Z', 'color' => 1],
                ['d' => 'M 0 0 L 0.5 0 L 0.5 0.5 Z', 'color' => 2, 'group' => 'loop'],
            ],
            'pathGroups' => [
                ['id' => 'badge', 'hidden' => true],
                ['id' => 'crown', 'parent' => 'badge'],
                ['id' => 'loop', 'parent' => 'loop'],
            ],
            'borderEnabled' => false,
        ]));

        $this->assertStringNotContainsString('fill="#6464dc"', $svg);
        $this->assertStringContainsString('fill="#ffffff"', $svg);
        $this->assertStringContainsString('fill="#ff0000"', $svg);
    }

    public function test_a_library_shape_with_fill_and_border_off_paints_nothing(): void
    {
        $svg = $this->svgOf($this->rect([
            'shapeType' => 'path',
            'pathColors' => ['#6464DC'],
            'pathParts' => [['d' => 'M 0 0 L 1 0 L 1 1 Z', 'color' => 0]],
            'fillEnabled' => false,
            'borderEnabled' => false,
        ]));

        $this->assertStringContainsString('fill="none"', $svg);
        $this->assertStringNotContainsString('stroke=', $svg);
    }

    public function test_a_blurred_shadow_is_spread_over_faint_copies_and_padded_for_their_reach(): void
    {
        $rendered = ShapeRenderer::render($this->rect([
            'shadowEnabled' => true, 'shadowOffsetX' => 0, 'shadowOffsetY' => 0, 'shadowBlur' => 4,
        ]), 'css-hex');
        $svg = (string) base64_decode(substr($rendered['src'], strlen('data:image/svg+xml;base64,')));

        // The Studio's blur is a Gaussian with a standard deviation of shadowBlur / 2.
        $reach = 2 * sqrt(-2 * log(1 / 8));
        $this->assertEqualsWithDelta(40 + 2 * $reach, $rendered['width'], 1e-9);
        $this->assertEqualsWithDelta(-$reach, $rendered['offsetX'], 1e-9);
        // 48 copies, plus the group that places the shape inside its padding.
        $this->assertSame(49, substr_count($svg, '<g transform="translate('));
        // Stacked, the 48 copies reach the default shadow colour's own 40%.
        $this->assertStringContainsString(sprintf('fill="#000000" fill-opacity="%s"', 1 - 0.6 ** (1 / 48)), $svg);
    }

    public function test_a_shadow_without_a_colour_is_the_studios_translucent_black(): void
    {
        $svg = $this->svgOf($this->rect(['shadowEnabled' => true, 'shadowBlur' => 0]));

        $this->assertStringContainsString('fill="#000000" fill-opacity="0.4"', $svg);
    }

    public function test_a_gradient_fill_is_drawn_as_bands_running_between_its_colours(): void
    {
        $svg = $this->svgOf($this->rect([
            'fillColor' => '#123456', 'borderEnabled' => false,
            'gradient' => ['colors' => ['#ff0000', '#0000ff'], 'angle' => 0],
        ]));

        // Angle 0 runs from the right edge (first colour) to the left edge
        // (last colour): 40 mm at 0.25 mm a band is 160 bands, each sampled at its middle.
        preg_match_all('/<polygon points="([^"]+)" fill="#([0-9a-f]{6})"/', $svg, $bands, PREG_SET_ORDER);
        $this->assertCount(160, $bands);
        $this->assertSame('fe0001', $bands[0][2]);
        // The first band hugs the right edge, reaching half a band into its neighbour so no seam shows.
        $this->assertSame('39.625,0 40,0 40,20 39.625,20', $bands[0][1]);
        $this->assertSame('0100fe', $bands[159][2]);
        $this->assertStringNotContainsString('#123456', $svg);
    }

    public function test_a_single_colour_gradient_fills_flat_in_that_colour(): void
    {
        $svg = $this->svgOf($this->rect(['fillColor' => '#123456', 'gradient' => ['colors' => ['#abcdef']]]));

        $this->assertStringContainsString('fill="#abcdef"', $svg);
        $this->assertStringNotContainsString('<polygon', $svg);
    }

    public function test_a_line_is_an_open_stroke_from_bottom_left_to_top_right_and_never_filled(): void
    {
        $svg = $this->svgOf(new DesignElement(
            id: 'l', type: 'shape', x: 0, y: 0, width: 40, height: 20,
            properties: ['shapeType' => 'line', 'strokeWidth' => 1.0, 'fillColor' => '#ff0000'],
        ));

        $this->assertStringContainsString('<path d="M 0.5 19.5 L 39.5 0.5" fill="none"', $svg);
        $this->assertStringNotContainsString('#ff0000', $svg);
        $this->assertStringNotContainsString('<polygon', $svg);
    }

    public function test_an_arrow_gets_a_head_at_its_end_and_optionally_its_start(): void
    {
        $arrow = fn (array $properties): string => $this->svgOf(new DesignElement(
            id: 'a', type: 'shape', x: 0, y: 0, width: 40, height: 20,
            properties: $properties + ['shapeType' => 'arrow', 'strokeWidth' => 1.0, 'points' => [['x' => 0, 'y' => 0.5], ['x' => 1, 'y' => 0.5]]],
        ));

        $end = $arrow([]);
        $this->assertStringContainsString('<path d="M 0 10 L 40 10"', $end);
        // max(4 x 1 mm, 8px) = 4 mm long, 0.48 x 4 mm either side of the shaft.
        $this->assertStringContainsString('<polygon points="40,10 36,11.92 36,8.08"', $end);
        $this->assertSame(2, substr_count($arrow(['arrowStart' => true]), '<polygon'));
        $this->assertSame(0, substr_count($arrow(['arrowEnd' => false]), '<polygon'));
    }

    public function test_an_elbow_connector_steps_across_at_half_its_width(): void
    {
        $svg = $this->svgOf(new DesignElement(
            id: 'e', type: 'shape', x: 0, y: 0, width: 40, height: 20,
            properties: ['shapeType' => 'line', 'connectorStyle' => 'elbow', 'strokeWidth' => 2.0],
        ));

        $this->assertStringContainsString('<path d="M 1 19 L 20 19 L 20 1 L 39 1"', $svg);
    }

    public function test_a_connector_without_a_stroke_draws_nothing(): void
    {
        $svg = $this->svgOf(new DesignElement(
            id: 'n', type: 'shape', x: 0, y: 0, width: 40, height: 20,
            properties: ['shapeType' => 'arrow', 'borderEnabled' => false],
        ));

        $this->assertStringNotContainsString('<path', $svg);
        $this->assertStringNotContainsString('<polygon', $svg);
    }
}
