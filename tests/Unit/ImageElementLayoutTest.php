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

    public function test_mask_css_is_empty_by_default(): void
    {
        $this->assertSame('', ImageElementLayout::maskCss($this->element(40, 20), 'mm'));
        $this->assertSame('', ImageElementLayout::maskCss($this->element(40, 20, ['maskShape' => 'none']), 'mm'));
    }

    public function test_circle_mask_css(): void
    {
        $css = ImageElementLayout::maskCss($this->element(40, 20, ['maskShape' => 'circle']), 'mm');

        $this->assertSame(' overflow: hidden; border-radius: 50%;', $css);
    }

    public function test_rounded_rectangle_mask_css_uses_the_saved_corner_radius(): void
    {
        $css = ImageElementLayout::maskCss(
            $this->element(40, 20, ['maskShape' => 'roundedRectangle', 'maskCornerRadius' => 6.0]),
            'mm',
        );

        $this->assertSame(' overflow: hidden; border-radius: 6mm;', $css);
    }

    public function test_rounded_rectangle_mask_corner_radius_defaults_to_4_and_is_floored_at_0(): void
    {
        $default = ImageElementLayout::maskCss($this->element(40, 20, ['maskShape' => 'roundedRectangle']), 'mm');
        $negative = ImageElementLayout::maskCss(
            $this->element(40, 20, ['maskShape' => 'roundedRectangle', 'maskCornerRadius' => -3.0]),
            'mm',
        );

        $this->assertSame(' overflow: hidden; border-radius: 4mm;', $default);
        $this->assertSame(' overflow: hidden; border-radius: 0mm;', $negative);
    }
}
