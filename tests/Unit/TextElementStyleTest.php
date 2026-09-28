<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\CertificateRenderer;
use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\FontRegistrar;
use Certigniter\CertificateRenderer\Support\TextElementStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextElementStyleTest extends TestCase
{
    private function fonts(): FontRegistrar
    {
        return new FontRegistrar;
    }

    /** @param array<string, mixed> $properties */
    private function element(array $properties): DesignElement
    {
        return new DesignElement(id: 'e', type: 'text', x: 0, y: 0, width: 100, height: 20, properties: $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function describe(array $properties): array
    {
        return TextElementStyle::describe($this->element($properties), 'css-hex', 'mm', $this->fonts());
    }

    public function test_font_size_converts_96_dpi_pixels_to_72_dpi_points(): void
    {
        $style = $this->describe(['fontSize' => 16.0]);

        $this->assertSame(16.0 * CertificateRenderer::CANVAS_PX_TO_PDF_PT, $style['fontSizePt']);
        $this->assertSame(12.0, $style['fontSizePt']);
    }

    public function test_bold_is_detected_from_the_word_bold_case_insensitively(): void
    {
        $this->assertSame(700, $this->describe(['fontWeight' => 'Bold'])['fontWeight']);
        $this->assertSame(700, $this->describe(['fontWeight' => 'bold'])['fontWeight']);
    }

    public function test_bold_is_detected_from_a_numeric_weight_of_600_or_more(): void
    {
        $this->assertSame(700, $this->describe(['fontWeight' => '700'])['fontWeight']);
        $this->assertSame(700, $this->describe(['fontWeight' => 'w600'])['fontWeight']);
        $this->assertSame(400, $this->describe(['fontWeight' => '500'])['fontWeight']);
        $this->assertSame(400, $this->describe(['fontWeight' => 'normal'])['fontWeight']);
    }

    public function test_font_style_is_italic_only_for_the_literal_value_italic(): void
    {
        $this->assertSame('italic', $this->describe(['fontStyle' => 'italic'])['fontStyle']);
        $this->assertSame('normal', $this->describe(['fontStyle' => 'oblique'])['fontStyle']);
        $this->assertSame('normal', $this->describe([])['fontStyle']);
    }

    public function test_text_align_falls_back_to_left_for_an_invalid_value(): void
    {
        $this->assertSame('right', $this->describe(['textAlign' => 'right'])['textAlign']);
        $this->assertSame('left', $this->describe(['textAlign' => 'not-a-real-alignment'])['textAlign']);
        $this->assertSame('left', $this->describe([])['textAlign']);
    }

    public function test_underline_and_strikethrough_are_mutually_exclusive_with_underline_winning(): void
    {
        $this->assertSame('text-decoration: underline;', $this->describe(['underline' => true])['decorationCss']);
        $this->assertSame('text-decoration: line-through;', $this->describe(['strikethrough' => true])['decorationCss']);
        $this->assertSame('text-decoration: underline;', $this->describe(['underline' => true, 'strikethrough' => true])['decorationCss']);
        $this->assertSame('', $this->describe([])['decorationCss']);
    }

    public function test_there_is_no_shadow_unless_shadow_is_an_array(): void
    {
        $this->assertSame([], $this->describe(['shadow' => 'not-an-array'])['shadowLayers']);
        $this->assertSame([], $this->describe([])['shadowLayers']);
    }

    public function test_an_unblurred_shadow_is_one_copy_moved_by_its_offset_in_css_pixels(): void
    {
        $layers = $this->describe(['shadow' => ['color' => '#112233', 'offsetX' => 3.0, 'offsetY' => -6.0, 'blur' => 0]])['shadowLayers'];

        $this->assertCount(1, $layers);
        $this->assertEqualsWithDelta(3 * 25.4 / 96, $layers[0]['dx'], 1e-9);
        $this->assertEqualsWithDelta(-6 * 25.4 / 96, $layers[0]['dy'], 1e-9);
        $this->assertSame('rgba(17, 34, 51, 1)', $layers[0]['color']);
    }

    public function test_a_blurred_shadow_is_spread_over_faint_copies_in_the_default_colour(): void
    {
        $layers = $this->describe(['shadow' => ['offsetX' => 0, 'offsetY' => 0, 'blur' => 8.0]])['shadowLayers'];

        $this->assertCount(48, $layers);
        $this->assertSame(sprintf('rgba(0, 0, 0, %s)', round(1 - (1 - round(128 / 255, 4)) ** (1 / 48), 4)), $layers[0]['color']);
    }

    public function test_gradient_first_stop_overrides_the_flat_color_fallback(): void
    {
        $style = $this->describe(['color' => '#000000', 'gradient' => ['colors' => ['#ff00ff', '#00ffff']]]);

        $this->assertSame('#ff00ff', $style['color']);
    }

    public function test_an_empty_gradient_does_not_override_the_flat_color(): void
    {
        $style = $this->describe(['color' => '#000000', 'gradient' => ['colors' => []]]);

        $this->assertSame('#000000', $style['color']);
    }

    /** @return array<string, array{string, string}> */
    public static function contentPositionProvider(): array
    {
        return [
            'topLeft' => ['topLeft', 'left: 0; top: 0;'],
            'topCenter' => ['topCenter', 'left: 50%; top: 0; transform: translateX(-50%);'],
            'topRight' => ['topRight', 'right: 0; top: 0;'],
            'centerLeft' => ['centerLeft', 'left: 0; top: 50%; transform: translateY(-50%);'],
            'center' => ['center', 'left: 50%; top: 50%; transform: translateX(-50%) translateY(-50%);'],
            'centerRight' => ['centerRight', 'right: 0; top: 50%; transform: translateY(-50%);'],
            'bottomLeft' => ['bottomLeft', 'left: 0; bottom: 0;'],
            'bottomCenter' => ['bottomCenter', 'left: 50%; bottom: 0; transform: translateX(-50%);'],
            'bottomRight' => ['bottomRight', 'right: 0; bottom: 0;'],
        ];
    }

    #[DataProvider('contentPositionProvider')]
    public function test_every_content_alignment_produces_its_css(string $alignment, string $expected): void
    {
        $style = $this->describe(['contentAlignment' => $alignment]);

        $this->assertSame($expected, $style['contentPositionCss']);
    }

    public function test_bottom_border_span_css_is_empty_when_disabled(): void
    {
        $this->assertSame('', $this->describe(['bottomBorderEnabled' => false])['bottomBorderSpanCss']);
        $this->assertSame('', $this->describe([])['bottomBorderSpanCss']);
    }

    public function test_bottom_border_span_css_uses_saved_values_when_enabled(): void
    {
        $style = $this->describe([
            'bottomBorderEnabled' => true,
            'bottomBorderGap' => 2.5,
            'bottomBorderWidth' => 0.6,
            'bottomBorderLeftPadding' => 5.0,
            'bottomBorderRightPadding' => 3.0,
            'bottomBorderColor' => '#2255AA',
        ]);

        // padding + border-bottom on the inline <span> wrapping the text
        // itself (not a separate sibling element) - see the docblock in
        // TextElementStyle for why: dompdf wraps any other child of the
        // centered/shrink-to-fit `display:table` text box in its own
        // anonymous table row, which roughly doubles that box's rendered
        // height and pushes a sibling underline far below the text.
        $this->assertSame(
            'padding-left: 5mm; padding-right: 3mm; padding-bottom: 2.5mm; border-bottom: 0.6mm solid #2255aa;',
            $style['bottomBorderSpanCss'],
        );
    }

    public function test_bottom_border_width_and_gap_are_floored_at_their_minimums(): void
    {
        $style = $this->describe([
            'bottomBorderEnabled' => true,
            'bottomBorderGap' => -5.0,
            'bottomBorderWidth' => 0.0,
        ]);

        $this->assertStringContainsString('padding-bottom: 0mm;', $style['bottomBorderSpanCss']);
        $this->assertStringContainsString('border-bottom: 0.1mm solid', $style['bottomBorderSpanCss']);
    }

    public function test_overflow_css_is_set_only_for_fixed_size_text_resize_mode(): void
    {
        $this->assertSame('overflow: hidden;', $this->describe(['textResizeMode' => 'fixedSize'])['overflowCss']);
        $this->assertSame('', $this->describe(['textResizeMode' => 'autoFit'])['overflowCss']);
        $this->assertSame('', $this->describe([])['overflowCss']);
    }

    public function test_baseline_correction_falls_back_to_the_reference_ratio_when_the_font_has_no_cached_metrics(): void
    {
        // $this->fonts() never calls registerAll(), so FontRegistrar has no
        // parsed ascent/descent for the resolved family to scale by - see
        // FontRegistrarTest for the metrics-driven case.
        $style = $this->describe(['fontSize' => 20.0]);

        $this->assertEqualsWithDelta($style['fontSizePt'] * 0.0875, $style['baselineCorrectionPt'], 1e-9);
    }

    public function test_straight_text_has_no_curved_glyphs(): void
    {
        $element = $this->element(['text' => 'ABCD']);

        $this->assertSame([], TextElementStyle::curvedGlyphBoxes($element, TextElementStyle::describe($element, 'css-hex', 'mm', $this->fonts()), $this->fonts()));
    }

    public function test_curved_text_is_one_upright_line_box_per_glyph_turned_onto_the_circle(): void
    {
        $element = $this->element(['text' => "AB\nCD", 'fontSize' => 16.0, 'lineHeight' => 1.0, 'curveRadius' => 30]);
        $style = TextElementStyle::describe($element, 'css-hex', 'mm', $this->fonts());
        $boxes = TextElementStyle::curvedGlyphBoxes($element, $style, $this->fonts());

        // The line break reads as a space; unmeasured (no Dompdf), each glyph is half an em.
        $this->assertSame(['A', 'B', ' ', 'C', 'D'], array_column($boxes, 'text'));
        $advance = 6 * 25.4 / 72;
        foreach ($boxes as $box) {
            $this->assertEqualsWithDelta($advance, $box['width'], 1e-9);
            $this->assertEqualsWithDelta(12 * 25.4 / 72, $box['height'], 1e-9);
        }
        // Symmetric about the apex: the middle glyph stands straight up, the ends turn equally.
        $this->assertEqualsWithDelta(0.0, $boxes[2]['rotation'], 1e-9);
        $this->assertEqualsWithDelta(-$boxes[0]['rotation'], $boxes[4]['rotation'], 1e-9);
        $this->assertEqualsWithDelta(rad2deg(-2 * $advance / 30), $boxes[0]['rotation'], 1e-9);
        $this->assertLessThan(0, $boxes[0]['rotation']);
    }
}
