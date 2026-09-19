<?php

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\DesignElement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DesignElementTest extends TestCase
{
    public function test_is_visible_defaults_true_when_the_key_is_absent(): void
    {
        $element = new DesignElement(id: 'e', type: 'text', x: 0, y: 0, width: 1, height: 1);

        $this->assertTrue($element->isVisible());
    }

    public function test_is_visible_is_false_only_when_explicitly_set_false(): void
    {
        $hidden = new DesignElement(id: 'e', type: 'text', x: 0, y: 0, width: 1, height: 1, properties: ['isVisible' => false]);
        $shown = new DesignElement(id: 'e', type: 'text', x: 0, y: 0, width: 1, height: 1, properties: ['isVisible' => true]);

        $this->assertFalse($hidden->isVisible());
        $this->assertTrue($shown->isVisible());
    }

    public function test_is_ai_generated_defaults_false_and_is_true_only_when_explicitly_set(): void
    {
        $untagged = new DesignElement(id: 'e', type: 'image', x: 0, y: 0, width: 1, height: 1);
        $tagged = new DesignElement(id: 'e', type: 'image', x: 0, y: 0, width: 1, height: 1, properties: ['aiGenerated' => true]);

        $this->assertFalse($untagged->isAiGenerated());
        $this->assertTrue($tagged->isAiGenerated());
    }

    public function test_is_text_like_matches_all_three_text_variants(): void
    {
        foreach (['text', 'placeholder_text', 'dynamic_text'] as $type) {
            $element = new DesignElement(id: 'e', type: $type, x: 0, y: 0, width: 1, height: 1);
            $this->assertTrue($element->isTextLike(), "expected {$type} to be text-like");
        }

        $this->assertFalse((new DesignElement(id: 'e', type: 'image', x: 0, y: 0, width: 1, height: 1))->isTextLike());
    }

    public function test_is_qr_authentication_link_requires_both_type_and_qr_type(): void
    {
        $verification = new DesignElement(id: 'e', type: 'qrcode', x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => 'verification']);
        $authLink = new DesignElement(id: 'e', type: 'qrcode', x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => 'authentication']);
        $custom = new DesignElement(id: 'e', type: 'qrcode', x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => 'custom']);
        $dynamic = new DesignElement(id: 'e', type: 'qrcode', x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => 'dynamic']);
        $barcodeWithSameProperty = new DesignElement(id: 'e', type: 'barcode', x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => 'verification']);

        $this->assertTrue($verification->isQrAuthenticationLink());
        $this->assertTrue($authLink->isQrAuthenticationLink(), 'the legacy "authentication" value must keep resolving as verification');
        $this->assertFalse($custom->isQrAuthenticationLink());
        $this->assertFalse($dynamic->isQrAuthenticationLink());
        $this->assertFalse($barcodeWithSameProperty->isQrAuthenticationLink());
    }

    public function test_is_dynamic_qr_requires_both_type_and_qr_type(): void
    {
        $dynamic = new DesignElement(id: 'e', type: 'qrcode', x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => 'dynamic']);
        $custom = new DesignElement(id: 'e', type: 'qrcode', x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => 'custom']);
        $verification = new DesignElement(id: 'e', type: 'qrcode', x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => 'verification']);
        $barcodeWithSameProperty = new DesignElement(id: 'e', type: 'barcode', x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => 'dynamic']);

        $this->assertTrue($dynamic->isDynamicQr());
        $this->assertFalse($custom->isDynamicQr());
        $this->assertFalse($verification->isDynamicQr());
        $this->assertFalse($barcodeWithSameProperty->isDynamicQr());
    }

    public function test_verification_and_dynamic_content_sources_apply_to_barcodes_too(): void
    {
        $make = fn (string $type, string $qrType) => new DesignElement(id: 'e', type: $type, x: 0, y: 0, width: 1, height: 1, properties: ['qrType' => $qrType]);

        $this->assertTrue($make('barcode', 'verification')->isVerificationCode());
        $this->assertTrue($make('barcode', 'authentication')->isVerificationCode());
        $this->assertTrue($make('qrcode', 'verification')->isVerificationCode());
        $this->assertFalse($make('barcode', 'custom')->isVerificationCode());
        $this->assertFalse($make('text', 'verification')->isVerificationCode());

        $this->assertTrue($make('barcode', 'dynamic')->isDynamicCode());
        $this->assertTrue($make('qrcode', 'dynamic')->isDynamicCode());
        $this->assertFalse($make('barcode', 'verification')->isDynamicCode());
        $this->assertFalse($make('image', 'dynamic')->isDynamicCode());

        $this->assertFalse((new DesignElement(id: 'e', type: 'barcode', x: 0, y: 0, width: 1, height: 1))->isVerificationCode(), 'a barcode with no qrType is static');
    }

    public function test_content_alignment_defaults_to_text_align_for_text_like_elements_without_a_saved_alignment(): void
    {
        $left = new DesignElement(id: 'e', type: 'text', x: 0, y: 0, width: 1, height: 1, properties: ['textAlign' => 'left']);
        $center = new DesignElement(id: 'e', type: 'text', x: 0, y: 0, width: 1, height: 1, properties: ['textAlign' => 'center']);
        $right = new DesignElement(id: 'e', type: 'text', x: 0, y: 0, width: 1, height: 1, properties: ['textAlign' => 'right']);

        $this->assertSame('centerLeft', $left->contentAlignment());
        $this->assertSame('center', $center->contentAlignment());
        $this->assertSame('centerRight', $right->contentAlignment());
    }

    public function test_content_alignment_defaults_to_center_for_non_text_elements(): void
    {
        $image = new DesignElement(id: 'e', type: 'image', x: 0, y: 0, width: 1, height: 1);

        $this->assertSame('center', $image->contentAlignment());
    }

    public function test_a_saved_content_alignment_wins_over_the_text_align_derived_default(): void
    {
        $element = new DesignElement(
            id: 'e', type: 'text', x: 0, y: 0, width: 1, height: 1,
            properties: ['textAlign' => 'left', 'contentAlignment' => 'bottomRight'],
        );

        $this->assertSame('bottomRight', $element->contentAlignment());
        $this->assertSame([1.0, 1.0], $element->contentAlignmentFactors());
    }

    public static function alignmentFactorProvider(): array
    {
        return [
            'topLeft' => ['topLeft', [0.0, 0.0]],
            'topCenter' => ['topCenter', [0.5, 0.0]],
            'topRight' => ['topRight', [1.0, 0.0]],
            'centerLeft' => ['centerLeft', [0.0, 0.5]],
            'center' => ['center', [0.5, 0.5]],
            'centerRight' => ['centerRight', [1.0, 0.5]],
            'bottomLeft' => ['bottomLeft', [0.0, 1.0]],
            'bottomCenter' => ['bottomCenter', [0.5, 1.0]],
            'bottomRight' => ['bottomRight', [1.0, 1.0]],
        ];
    }

    #[DataProvider('alignmentFactorProvider')]
    public function test_every_content_alignment_maps_to_its_box_factors(string $alignment, array $expected): void
    {
        $element = new DesignElement(
            id: 'e', type: 'image', x: 0, y: 0, width: 1, height: 1,
            properties: ['contentAlignment' => $alignment],
        );

        $this->assertSame($expected, $element->contentAlignmentFactors());
    }

    public function test_center_x_and_y_are_the_midpoint_of_the_box(): void
    {
        $element = new DesignElement(id: 'e', type: 'shape', x: 10, y: 20, width: 30, height: 40);

        $this->assertSame(25.0, $element->centerX());
        $this->assertSame(40.0, $element->centerY());
    }

    public function test_with_effective_transform_clones_rather_than_mutating_the_original(): void
    {
        $original = new DesignElement(id: 'e', type: 'shape', x: 0, y: 0, width: 10, height: 10, properties: ['rotation' => 5.0]);

        $composed = $original->withEffectiveTransform(x: 1.0, y: 2.0, rotation: 45.0, opacity: 0.5);

        $this->assertSame(0.0, $original->x);
        $this->assertSame(5.0, $original->rotation());
        $this->assertSame(1.0, $composed->x);
        $this->assertSame(2.0, $composed->y);
        $this->assertSame(45.0, $composed->rotation());
        $this->assertSame(0.5, $composed->opacity());
        $this->assertNotSame($original, $composed);
    }

    public function test_from_array_falls_back_defaults_for_missing_fields(): void
    {
        $element = DesignElement::fromArray(['type' => 'text']);

        $this->assertSame('', $element->id);
        $this->assertSame(0.0, $element->x);
        $this->assertSame([], $element->properties);
        $this->assertNull($element->childrenIds);
    }
}
