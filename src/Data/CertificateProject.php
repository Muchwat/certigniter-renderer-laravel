<?php

declare(strict_types=1);

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
        public string $dateFormat = 'MMM d, yyyy',
    ) {}

    /** @param array<string, mixed> $data */
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
            // Absent on files predating this field - matches Design
            // Studio's own de-facto default before the picker existed.
            dateFormat: (string) ($data['date_format'] ?? 'MMM d, yyyy'),
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
     * @param  array<string, mixed>  $data
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

    /** @return DesignElement[] */
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
     * A safe, metadata-only catalog for building developer tools and asset
     * replacement forms. Embedded image/font bytes are intentionally omitted.
     *
     * @return array<int, array{
     *   id: string,
     *   type: string,
     *   label: string,
     *   visible: bool,
     *   replaceable: bool,
     *   parentGroupId: ?string,
     *   position: array{x: float, y: float, width: float, height: float, unit: string},
     *   details: array<string, mixed>
     * }>
     */
    public function elementCatalog(?string $type = null): array
    {
        $parentGroups = [];
        foreach ($this->elements as $element) {
            if (! $element->isGroup()) {
                continue;
            }
            foreach ($element->childrenIds ?? [] as $childId) {
                $parentGroups[$childId] = $element->id;
            }
        }

        $labels = $this->elementLabels();

        $catalog = [];
        foreach ($this->elements as $element) {
            if ($type !== null && $element->type !== $type) {
                continue;
            }

            $catalog[] = [
                'id' => $element->id,
                'type' => $element->type,
                'label' => $labels[$element->id] ?? $element->type,
                'visible' => $element->isVisible(),
                'replaceable' => $element->type === 'image',
                'parentGroupId' => $parentGroups[$element->id] ?? null,
                'position' => [
                    'x' => $element->x,
                    'y' => $element->y,
                    'width' => $element->width,
                    'height' => $element->height,
                    'unit' => $this->unit,
                ],
                'details' => $this->elementDetails($element),
            ];
        }

        return $catalog;
    }

    /**
     * Intuitive alias for integrations that start by asking for element IDs.
     * Returns the full catalog rather than bare IDs so callers know what each
     * ID represents. Pass a type such as `image` to filter the result.
     *
     * @see self::elementCatalog()
     *
     * @return array<int, array<string, mixed>>
     */
    public function getElementIds(?string $type = null): array
    {
        return $this->elementCatalog($type);
    }

    /**
     * Known `placeholderRole` values (see Design Studio's ElementIconUtil,
     * the other place this same set is enumerated) mapped to a human name.
     * Checked before the plain type-based fallback below, so e.g. a
     * signature image (type `image`, role `signature_image`) reads as
     * "Signature", not "Image".
     */
    private const ROLE_BASE_NAMES = [
        'static_text' => 'Text',
        'recipient_name' => 'Recipient Name',
        'description' => 'Description',
        'issuer' => 'Issuer',
        'image' => 'Image',
        'logo' => 'Logo', // Legacy role - see ElementIconUtil's comment.
        'qrcode' => 'QR Code',
        'barcode' => 'Barcode',
        'dynamic_text' => 'Variable Text',
        'issue_date' => 'Issue Date',
        'expiry_date' => 'Expiry Date',
        'signatory_name' => 'Signatory Name',
        'signature_image' => 'Signature',
        'signatory_title' => 'Signatory Title',
        'serial_number' => 'Serial Number',
    ];

    private const TYPE_BASE_NAMES = [
        'text' => 'Text',
        'placeholder_text' => 'Variable Text',
        'image' => 'Image',
        'qrcode' => 'QR Code',
        'barcode' => 'Barcode',
        'group' => 'Group',
        'shape' => 'Shape',
    ];

    /**
     * Every element's display label, keyed by id, computed in one pass over
     * the whole project - mirrors Design Studio's own ElementLabelUtil
     * (lib/utils/designer/element_label_util.dart) so a catalog built here
     * reads the same as what the designer saw while building the template:
     * a custom `properties['name']` wins; otherwise a known role/type gets
     * a name that means something ("Signature", "Barcode", ...) instead of
     * a raw type string, numbered from the first occurrence ("Signature
     * 1", "Signature 2", ...) so repeats stay distinguishable. Anything
     * unrecognized falls back to "Element 1", "Element 2", ...
     *
     * @return array<string, string>
     */
    private function elementLabels(): array
    {
        $baseNameCounts = [];
        $labels = [];

        foreach ($this->elements as $element) {
            $name = trim((string) $element->property('name', ''));
            if ($name !== '') {
                $labels[$element->id] = $name;

                continue;
            }

            $preview = $this->elementContentPreview($element);
            if ($preview !== null) {
                $labels[$element->id] = $preview;

                continue;
            }

            $baseName = $this->elementBaseName($element);
            $occurrence = ($baseNameCounts[$baseName] ?? 0) + 1;
            $baseNameCounts[$baseName] = $occurrence;
            $labels[$element->id] = "{$baseName} {$occurrence}";
        }

        return $labels;
    }

    /**
     * The element's base name before any disambiguating number - a known
     * role or type maps to a human name; anything unrecognized falls back
     * to "Element".
     */
    private function elementBaseName(DesignElement $element): string
    {
        $role = trim((string) $element->property('placeholderRole', ''));
        if ($role !== '' && isset(self::ROLE_BASE_NAMES[$role])) {
            return self::ROLE_BASE_NAMES[$role];
        }

        if (isset(self::TYPE_BASE_NAMES[$element->type])) {
            return self::TYPE_BASE_NAMES[$element->type];
        }

        return $element->type === '' ? 'Element' : ucfirst(str_replace('_', ' ', $element->type));
    }

    /**
     * Content-based label for a text-ish element with no custom name: a
     * preview of what it actually contains, so an unlabeled title reads as
     * its own words rather than a generic "Text 1". Returns null when
     * there's no usable content to preview, so the caller falls through to
     * the role/type-based base name instead.
     */
    private function elementContentPreview(DesignElement $element): ?string
    {
        if ($element->type === 'text') {
            $text = trim((string) $element->property('text', ''));

            return $text === '' ? '[Empty Text]' : $this->shortPreview($text);
        }

        if ($element->property('placeholderRole') === 'dynamic_text') {
            $variable = trim((string) $element->property('variableName', ''));
            if ($variable !== '') {
                return implode(' ', array_map(
                    fn (string $word) => $word === '' ? '' : ucfirst($word),
                    explode(' ', str_replace('_', ' ', $variable)),
                ));
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function elementDetails(DesignElement $element): array
    {
        return match ($element->type) {
            'image' => [
                'hasEmbeddedData' => is_string($element->property('imageData'))
                    && $element->property('imageData') !== '',
                'hasLocalPath' => is_string($element->property('path'))
                    && $element->property('path') !== '',
                'originalFilename' => $element->property('path')
                    ? basename((string) $element->property('path'))
                    : null,
                'fit' => $element->property('fit', 'contain'),
                'maskShape' => $element->property('maskShape', 'none'),
                'aiGenerated' => $element->isAiGenerated(),
                'aiModel' => $element->property('aiModel'),
                'aiGeneratedAt' => $element->property('aiGeneratedAt'),
                'aiPromptPreview' => is_string($element->property('aiPrompt')) && $element->property('aiPrompt') !== ''
                    ? $this->shortPreview((string) $element->property('aiPrompt'))
                    : null,
                'replacementHint' => $this->imageReplacementHint($element),
            ],
            'text', 'placeholder_text', 'dynamic_text' => [
                'isVariable' => (string) $element->property('variableName', '') !== '',
                'variableName' => $element->property('variableName'),
                'textPreview' => $this->shortPreview((string) $element->property('text', '')),
                'fontFamily' => $element->property('fontFamily'),
            ],
            'shape' => [
                'shapeType' => $element->property('shapeType', 'rectangle'),
            ],
            'qrcode' => [
                'qrType' => $element->property('qrType', 'custom'),
                'isAuthenticationLink' => $element->isQrAuthenticationLink(),
                'isVerificationCode' => $element->isVerificationCode(),
                'variableName' => $element->isDynamicCode() ? $element->property('variableName') : null,
                'dataPreview' => $this->shortPreview((string) $element->property('data', '')),
            ],
            'barcode' => [
                'qrType' => $element->property('qrType', 'custom'),
                'isVerificationCode' => $element->isVerificationCode(),
                'variableName' => $element->isDynamicCode() ? $element->property('variableName') : null,
                'dataPreview' => $this->shortPreview((string) $element->property('data', '')),
                'barcodeType' => $element->property('barcodeType', 'code128'),
            ],
            'group' => [
                'childIds' => $element->childrenIds ?? [],
            ],
            default => [],
        };
    }

    private function imageReplacementHint(DesignElement $element): string
    {
        // The AI-generated tag is authoritative where present - it survives a
        // background the user has since resized below the 90% fallback
        // threshold, which a size-only check would otherwise misclassify.
        if ($element->isAiGenerated() || ($element->width >= $this->width * 0.9 && $element->height >= $this->height * 0.9)) {
            return 'background';
        }

        $description = strtolower(implode(' ', [
            (string) $element->property('name', ''),
            (string) $element->property('path', ''),
        ]));
        if (str_contains($description, 'sign')) {
            return 'signature';
        }
        if (str_contains($description, 'logo') || str_contains($description, 'brand')) {
            return 'logo';
        }

        return 'image';
    }

    private function shortPreview(string $value, int $limit = 80): string
    {
        $singleLine = trim((string) preg_replace('/\s+/', ' ', $value));
        if (function_exists('mb_strlen') && mb_strlen($singleLine) > $limit) {
            return mb_substr($singleLine, 0, $limit - 1).'…';
        }
        if (strlen($singleLine) > $limit) {
            return substr($singleLine, 0, $limit - 3).'...';
        }

        return $singleLine;
    }

    /**
     * Every recipient-record column this project actually needs to fully
     * resolve - i.e. what a caller's CSV/form needs a column for. Combines
     * both of RecipientMerge's mechanisms: `variableName` on text-like
     * elements and "Dynamic value" qrcode/barcode elements (whole-field
     * replacement), and `{{token}}`/`<token>` found inside other
     * qrcode/barcode `data` strings (inline substitution). "Verification
     * link" codes contribute nothing - their value comes from
     * `qrCodeOverrides`, not a recipient column.
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

            if (! $element->isCode() || $element->isVerificationCode()) {
                continue;
            }

            $variableName = trim((string) $element->property('variableName', ''));

            if ($element->isDynamicCode() && $variableName !== '') {
                $names[$variableName] = true;

                continue;
            }

            $data = (string) $element->property('data', '');

            if ($data !== '' && preg_match_all('/\{\{([^{}]+)\}\}|<([^<>]+)>/', $data, $matches)) {
                foreach ([...$matches[1], ...$matches[2]] as $token) {
                    if (trim($token) !== '') {
                        $names[trim($token)] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }

    /**
     * The element ID of this project's "Authentication link" QR code, if
     * it has one (the Design Studio allows at most one per project). Null
     * when the project has no such element. Use this to key the
     * `$qrCodeOverrides` array passed to CertificateRenderer without the
     * caller needing to already know the element's ID.
     */
    public function authenticationQrElementId(): ?string
    {
        foreach ($this->elements as $element) {
            if ($element->isQrAuthenticationLink()) {
                return $element->id;
            }
        }

        return null;
    }

    /**
     * Element IDs of every "Verification link" QR code AND barcode in this
     * project, in canvas order. A project can carry one of each (e.g. a QR
     * encoding the verification URL beside a barcode encoding the same
     * code), so a host application should key the same per-recipient value
     * into `$qrCodeOverrides` for every ID returned here.
     *
     * @return string[]
     */
    public function verificationCodeElementIds(): array
    {
        $ids = [];

        foreach ($this->elements as $element) {
            if ($element->isVerificationCode()) {
                $ids[] = $element->id;
            }
        }

        return $ids;
    }
}
