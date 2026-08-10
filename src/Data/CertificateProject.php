<?php

namespace Certigniter\CertificateRenderer\Data;

/**
 * A decoded (decrypted, JSON-parsed) .igniter project. Mirrors
 * lib/models/designer/certificate_project.dart's JSON shape - see this
 * package's README for the full field reference this was built against.
 */
class CertificateProject
{
    /**
     * @param  DesignElement[]  $elements
     * @param  array<string, array{normal?: string, bold?: string}>  $embeddedFonts
     */
    public function __construct(
        public string $id,
        public string $title,
        public float $width,
        public float $height,
        public string $unit,
        public array $elements,
        public string $colorFormat = 'css-hex',
        public array $embeddedFonts = [],
    ) {}

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
            embeddedFonts: self::embeddedFontsFromArray($data['embedded_fonts'] ?? []),
        );

        $project->elements = self::withMigratedBackground($project->elements, $data, $project->width, $project->height);

        return $project;
    }

    /** @return array<string, array{normal?: string, bold?: string}> */
    private static function embeddedFontsFromArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $fonts = [];
        foreach ($value as $family => $variants) {
            if (! is_string($family) || $family === '' || ! is_array($variants)) {
                continue;
            }
            $valid = [];
            foreach (['normal', 'bold'] as $weight) {
                if (isset($variants[$weight]) && is_string($variants[$weight]) && $variants[$weight] !== '') {
                    $valid[$weight] = $variants[$weight];
                }
            }
            if ($valid !== []) {
                $fonts[$family] = $valid;
            }
        }

        return $fonts;
    }

    /**
     * Certigniter migrates a legacy top-level `background` object into a
     * synthetic full-bleed `image` element at render/load time rather than
     * rewriting the file on disk (certificate_project.dart's
     * migrateLegacyBackgroundToImageElement) - so both forms exist in the
     * wild indefinitely. Do the same here so the rest of the renderer only
     * ever has to deal with a flat element list.
     *
     * @param  DesignElement[]  $elements
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
            fn (DesignElement $e) => ! isset($childIds[$e->id]),
        ));
    }

    public function children(DesignElement $group): array
    {
        if (! $group->childrenIds) {
            return [];
        }

        $wanted = array_flip($group->childrenIds);

        return array_values(array_filter(
            $this->elements,
            fn (DesignElement $e) => isset($wanted[$e->id]),
        ));
    }

    /**
     * Every recipient-record column this project actually needs to fully
     * resolve - i.e. what a caller's CSV/form needs a column for. Combines
     * both of RecipientMerge's mechanisms: `variableName` on text-like
     * elements (whole-field replacement) and `{{token}}`/`<token>` found
     * inside qrcode/barcode `data` strings (inline substitution).
     *
     * @return string[] distinct names, in first-seen order
     */
    public function variableNames(): array
    {
        $names = [];

        foreach ($this->elements as $element) {
            if ($element->isTextLike()) {
                $variableName = $element->property('variableName');

                if (is_string($variableName) && $variableName !== '') {
                    $names[$variableName] = true;
                }

                continue;
            }

            if (in_array($element->type, ['qrcode', 'barcode'], true)) {
                $data = (string) $element->property('data', '');

                if ($data !== '' && preg_match_all('/\{\{([^{}]+)\}\}|<([^<>]+)>/', $data, $matches)) {
                    foreach ([...$matches[1], ...$matches[2]] as $token) {
                        if ($token !== '') {
                            $names[$token] = true;
                        }
                    }
                }
            }
        }

        return array_keys($names);
    }
}
