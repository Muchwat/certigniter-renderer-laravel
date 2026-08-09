<?php

namespace Certigniter\CertificateRenderer\Data;

/**
 * A decoded (decrypted, JSON-parsed) .igniter project. Mirrors
 * lib/models/designer/certificate_project.dart's JSON shape - see this
 * package's README for the full field reference this was built against.
 */
class CertificateProject
{
    /** @param DesignElement[] $elements */
    public function __construct(
        public string $id,
        public string $title,
        public float $width,
        public float $height,
        public string $unit,
        public array $elements,
        public string $colorFormat = 'css-hex',
    ) {
    }

    public static function fromArray(array $data): self
    {
        $size = is_array($data['size'] ?? null) ? $data['size'] : [];

        $elements = array_values(array_map(
            fn (array $e) => DesignElement::fromArray($e),
            array_filter((array) ($data['elements'] ?? []), 'is_array'),
        ));

        $project = new self(
            id: (string) ($data['id'] ?? ''),
            title: (string) ($data['title'] ?? 'Untitled Project'),
            width: (float) ($size['width'] ?? 0),
            height: (float) ($size['height'] ?? 0),
            unit: (string) ($size['unit'] ?? 'mm'),
            elements: $elements,
            // Absent on files predating Certigniter's css-hex migration -
            // ColorConverter treats that as "still Flutter ARGB order".
            colorFormat: (string) ($data['color_format'] ?? 'argb'),
        );

        $project->elements = self::withMigratedBackground($project->elements, $data, $project->width, $project->height);

        return $project;
    }

    /**
     * Certigniter migrates a legacy top-level `background` object into a
     * synthetic full-bleed `image` element at render/load time rather than
     * rewriting the file on disk (certificate_project.dart's
     * migrateLegacyBackgroundToImageElement) - so both forms exist in the
     * wild indefinitely. Do the same here so the rest of the renderer only
     * ever has to deal with a flat element list.
     *
     * @param DesignElement[] $elements
     * @return DesignElement[]
     */
    private static function withMigratedBackground(array $elements, array $data, float $width, float $height): array
    {
        $background = is_array($data['background'] ?? null) ? $data['background'] : [];
        $path = $background['path'] ?? null;
        $imageData = $background['image_data'] ?? $background['imageData'] ?? null;

        if (empty($path) && empty($imageData)) {
            return $elements;
        }

        $backgroundElement = DesignElement::fromArray([
            'id' => 'legacy-background',
            'type' => 'image',
            'x' => 0,
            'y' => 0,
            'width' => $width,
            'height' => $height,
            'properties' => [
                'path' => $path,
                'imageData' => $imageData,
                'fit' => 'fill',
                'opacity' => 1.0,
                'isVisible' => true,
            ],
        ]);

        return [$backgroundElement, ...$elements];
    }

    /** Top-level elements only (no grouped children) - used to drive the main render loop, which handles a group's children itself. */
    public function topLevelElements(): array
    {
        $childIds = [];
        foreach ($this->elements as $element) {
            foreach ($element->childrenIds ?? [] as $childId) {
                $childIds[$childId] = true;
            }
        }

        return array_values(array_filter(
            $this->elements,
            fn (DesignElement $e) => !isset($childIds[$e->id]),
        ));
    }

    public function children(DesignElement $group): array
    {
        if (!$group->childrenIds) {
            return [];
        }

        $wanted = array_flip($group->childrenIds);

        return array_values(array_filter(
            $this->elements,
            fn (DesignElement $e) => isset($wanted[$e->id]),
        ));
    }
}
