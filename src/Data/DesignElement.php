<?php

namespace Certigniter\CertificateRenderer\Data;

/**
 * One element on a Certigniter canvas (text/placeholder_text/image/qrcode/
 * barcode/group). Mirrors lib/models/designer/design_element_data.dart's
 * JSON shape - `properties` is intentionally a loose bag rather than typed
 * fields, since Certigniter itself treats it that way (new keys get added
 * there over time without a schema migration).
 */
class DesignElement
{
    public function __construct(
        public string $id,
        public string $type,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public array $properties = [],
        public ?array $childrenIds = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            type: (string) ($data['type'] ?? ''),
            x: (float) ($data['x'] ?? 0),
            y: (float) ($data['y'] ?? 0),
            width: (float) ($data['width'] ?? 0),
            height: (float) ($data['height'] ?? 0),
            properties: is_array($data['properties'] ?? null) ? $data['properties'] : [],
            childrenIds: isset($data['childrenIds']) && is_array($data['childrenIds'])
                ? array_map('strval', $data['childrenIds'])
                : null,
        );
    }

    public function property(string $key, mixed $default = null): mixed
    {
        return $this->properties[$key] ?? $default;
    }

    public function rotation(): float
    {
        return (float) $this->property('rotation', 0.0);
    }

    public function opacity(): float
    {
        return (float) $this->property('opacity', 1.0);
    }

    public function mirrorHorizontal(): bool
    {
        return $this->property('mirrorHorizontal', false) === true;
    }

    public function mirrorVertical(): bool
    {
        return $this->property('mirrorVertical', false) === true;
    }

    /** Default true: absence of the key means "visible", not "hidden". */
    public function isVisible(): bool
    {
        return $this->property('isVisible', true) !== false;
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    public function isTextLike(): bool
    {
        return in_array($this->type, ['text', 'placeholder_text', 'dynamic_text'], true);
    }

    /**
     * Content alignment saved by Certigniter's Content position control.
     *
     * Older projects do not contain this property. Keep their established
     * behaviour by deriving text's horizontal fallback from paragraph
     * alignment, while images continue to default to the centre of the box.
     */
    public function contentAlignment(): string
    {
        $stored = $this->property('contentAlignment');
        $valid = [
            'topLeft', 'topCenter', 'topRight',
            'centerLeft', 'center', 'centerRight',
            'bottomLeft', 'bottomCenter', 'bottomRight',
        ];

        if (is_string($stored) && in_array($stored, $valid, true)) {
            return $stored;
        }

        if ($this->isTextLike()) {
            return match ($this->property('textAlign', 'left')) {
                'center' => 'center',
                'right' => 'centerRight',
                default => 'centerLeft',
            };
        }

        return 'center';
    }

    /** @return array{0: float, 1: float} Horizontal and vertical factors in the 0..1 range. */
    public function contentAlignmentFactors(): array
    {
        return match ($this->contentAlignment()) {
            'topLeft' => [0.0, 0.0],
            'topCenter' => [0.5, 0.0],
            'topRight' => [1.0, 0.0],
            'centerLeft' => [0.0, 0.5],
            'centerRight' => [1.0, 0.5],
            'bottomLeft' => [0.0, 1.0],
            'bottomCenter' => [0.5, 1.0],
            'bottomRight' => [1.0, 1.0],
            default => [0.5, 0.5],
        };
    }

    public function centerX(): float
    {
        return $this->x + $this->width / 2;
    }

    public function centerY(): float
    {
        return $this->y + $this->height / 2;
    }

    /** A copy with x/y/rotation/opacity overridden - used to apply a group's composed transform onto a child without mutating the original. */
    public function withEffectiveTransform(float $x, float $y, float $rotation, float $opacity): self
    {
        $clone = clone $this;
        $clone->x = $x;
        $clone->y = $y;
        $clone->properties['rotation'] = $rotation;
        $clone->properties['opacity'] = $opacity;

        return $clone;
    }
}
