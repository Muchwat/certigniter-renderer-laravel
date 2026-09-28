<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\ImageElementLayout;
use PHPUnit\Framework\TestCase;

class ImageElementLayoutTest extends TestCase
{
    /** @param array<string, mixed> $properties */
    private function element(float $width, float $height, array $properties = []): DesignElement
    {
        return new DesignElement(id: 'e', type: 'image', x: 0, y: 0, width: $width, height: $height, properties: $properties);
    }

    public function test_fill_stretches_to_the_full_box_regardless_of_aspect_ratio(): void
    {
        $fitted = ImageElementLayout::fit($this->element(40, 20, ['fit' => 'fill']), 4.0);

        $this->assertSame(['width' => 40.0, 'height' => 20.0, 'left' => 0.0, 'top' => 0.0], $fitted);
    }

    public function test_contain_with_no_aspect_ratio_falls_back_to_the_full_box(): void
    {
        $fitted = ImageElementLayout::fit($this->element(40, 20, ['fit' => 'contain']), null);

        $this->assertSame(['width' => 40.0, 'height' => 20.0, 'left' => 0.0, 'top' => 0.0], $fitted);
    }

    public function test_contain_shrinks_width_when_the_image_is_wider_than_the_box(): void
    {
        // 40x20 box (2:1), image ratio 4:1 - wider than the box, so width
        // stays at 40 and height shrinks to keep the image's own ratio.
        $fitted = ImageElementLayout::fit($this->element(40, 20, ['fit' => 'contain']), 4.0);

        $this->assertSame(40.0, $fitted['width']);
        $this->assertSame(10.0, $fitted['height']);
    }

    public function test_contain_shrinks_height_when_the_image_is_taller_than_the_box(): void
    {
        // 40x20 box (2:1), image ratio 1:4 (0.25) - taller than the box, so
        // height stays at 20 and width shrinks.
        $fitted = ImageElementLayout::fit($this->element(40, 20, ['fit' => 'contain']), 0.25);

        $this->assertSame(20.0, $fitted['height']);
        $this->assertSame(5.0, $fitted['width']);
    }

    public function test_contain_centers_the_fitted_image_by_default(): void
    {
        $fitted = ImageElementLayout::fit($this->element(40, 20, ['fit' => 'contain']), 4.0);

        // width=40 (no horizontal slack), height=10 leaves 10mm of vertical
        // slack split evenly top/bottom by the default 'center' alignment.
        $this->assertSame(0.0, $fitted['left']);
        $this->assertSame(5.0, $fitted['top']);
    }

    public function test_contain_honors_a_non_center_content_alignment(): void
    {
        $fitted = ImageElementLayout::fit(
            $this->element(40, 20, ['fit' => 'contain', 'contentAlignment' => 'topLeft']),
            4.0,
        );

        $this->assertSame(0.0, $fitted['left']);
        $this->assertSame(0.0, $fitted['top']);
    }

    public function test_fit_defaults_to_contain_for_any_value_other_than_fill(): void
    {
        $fitted = ImageElementLayout::fit($this->element(40, 20, ['fit' => 'something-else']), 4.0);

        $this->assertSame(10.0, $fitted['height'], 'contain behavior expected, not a fill stretch');
    }

    /** @return array{shape: 'circle'|'roundedRectangle', left: float, top: float, width: float, height: float, radius: float} */
    private function maskOf(DesignElement $element): array
    {
        $mask = ImageElementLayout::mask($element);
        $this->assertNotNull($mask);

        return $mask;
    }

    /** @param array<string, mixed> $properties */
    private function sized(float $width, float $height, array $properties = []): DesignElement
    {
        return new DesignElement(id: 'e', type: 'image', x: 0, y: 0, width: $width, height: $height, properties: $properties);
    }

    public function test_an_image_has_no_mask_by_default_or_when_it_is_switched_off(): void
    {
        $this->assertNull(ImageElementLayout::mask($this->sized(40, 20)));
        $this->assertNull(ImageElementLayout::mask($this->sized(40, 20, ['maskShape' => 'none'])));
        $this->assertNull(ImageElementLayout::mask($this->sized(40, 20, ['maskShape' => 'circle', 'maskEnabled' => false])));
    }

    public function test_a_circle_mask_is_a_true_circle_of_the_shorter_side_even_in_a_wide_box(): void
    {
        $mask = $this->maskOf($this->sized(40, 20, ['maskShape' => 'circle']));

        $this->assertSame(['shape' => 'circle', 'left' => 10.0, 'top' => 0.0, 'width' => 20.0, 'height' => 20.0, 'radius' => 10.0], $mask);
    }

    public function test_a_circle_mask_shrinks_by_its_radius_factor_clamped_to_its_range(): void
    {
        $this->assertEqualsWithDelta(5.0, $this->maskOf($this->sized(40, 20, ['maskShape' => 'circle', 'maskRadiusFactor' => 0.5]))['radius'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $this->maskOf($this->sized(40, 20, ['maskShape' => 'circle', 'maskRadiusFactor' => 0.0]))['radius'], 1e-9);
    }

    public function test_a_rounded_rectangle_mask_is_centred_at_its_width_and_height_factors(): void
    {
        $mask = $this->maskOf($this->sized(40, 20, [
            'maskShape' => 'roundedRectangle', 'maskWidthFactor' => 0.5, 'maskHeightFactor' => 0.8, 'maskCornerRadius' => 6.0,
        ]));

        $this->assertEqualsWithDelta(10.0, $mask['left'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $mask['top'], 1e-9);
        $this->assertEqualsWithDelta(20.0, $mask['width'], 1e-9);
        $this->assertEqualsWithDelta(16.0, $mask['height'], 1e-9);
        $this->assertEqualsWithDelta(6.0, $mask['radius'], 1e-9);
    }

    public function test_a_rounded_rectangle_mask_corner_defaults_to_4_is_floored_at_0_and_capped_at_half_a_side(): void
    {
        $this->assertSame(4.0, $this->maskOf($this->sized(40, 20, ['maskShape' => 'roundedRectangle']))['radius']);
        $this->assertSame(0.0, $this->maskOf($this->sized(40, 20, ['maskShape' => 'roundedRectangle', 'maskCornerRadius' => -3.0]))['radius']);
        $this->assertSame(10.0, $this->maskOf($this->sized(40, 20, ['maskShape' => 'roundedRectangle', 'maskCornerRadius' => 30.0]))['radius']);
    }

    public function test_cover_fills_the_box_and_lets_the_rest_of_the_picture_run_past_it(): void
    {
        // A 4:1 picture in a 2:1 box: cover fits its height and overhangs left and right, centred.
        $fitted = ImageElementLayout::fit($this->sized(40, 20, ['fit' => 'cover']), 4.0);

        $this->assertSame(['width' => 80.0, 'height' => 20.0, 'left' => -20.0, 'top' => 0.0], $fitted);
    }

    public function test_an_element_without_a_complete_crop_has_none(): void
    {
        $this->assertNull(ImageElementLayout::crop($this->element(40, 20)));
        $this->assertNull(ImageElementLayout::crop($this->element(40, 20, ['cropX' => 0.1, 'cropY' => 0, 'cropWidth' => 0.5])));
        $this->assertNull(ImageElementLayout::crop($this->element(40, 20, ['cropX' => null, 'cropY' => null, 'cropWidth' => null, 'cropHeight' => null])));
        $this->assertNull(ImageElementLayout::crop($this->element(40, 20, ['cropX' => 0, 'cropY' => 0, 'cropWidth' => 1, 'cropHeight' => 1])));
    }

    public function test_an_out_of_range_crop_is_pulled_back_inside_the_picture(): void
    {
        $crop = ImageElementLayout::crop($this->element(40, 20, ['cropX' => 0.9, 'cropY' => -1, 'cropWidth' => 0.5, 'cropHeight' => 2]));

        $this->assertSame(['x' => 0.5, 'y' => 0.0, 'width' => 0.5, 'height' => 1.0], $crop);
    }

    public function test_contain_letterboxes_a_cropped_image_by_the_shape_of_the_crop(): void
    {
        // A 2:1 picture cropped to its right half is a square, so in a 40x20
        // box it is 20x20 and centred - not 40x20 as the whole picture would be.
        $element = $this->element(40, 20, ['fit' => 'contain', 'cropX' => 0.5, 'cropY' => 0, 'cropWidth' => 0.5, 'cropHeight' => 1]);

        $this->assertSame(['width' => 20.0, 'height' => 20.0, 'left' => 10.0, 'top' => 0.0], ImageElementLayout::fit($element, 2.0));
    }

    public function test_crop_placement_offsets_the_whole_picture_so_only_the_crop_shows(): void
    {
        $element = $this->element(40, 20, ['fit' => 'fill', 'cropX' => 0.25, 'cropY' => 0.5, 'cropWidth' => 0.5, 'cropHeight' => 0.5]);
        $fitted = ImageElementLayout::fit($element, 2.0);

        $this->assertSame(['width' => 80.0, 'height' => 40.0, 'left' => -20.0, 'top' => -20.0], ImageElementLayout::cropPlacement($element, $fitted));
    }

    public function test_an_uncropped_image_has_no_crop_placement(): void
    {
        $element = $this->element(40, 20, ['fit' => 'fill']);

        $this->assertNull(ImageElementLayout::cropPlacement($element, ImageElementLayout::fit($element, 2.0)));
    }
}
