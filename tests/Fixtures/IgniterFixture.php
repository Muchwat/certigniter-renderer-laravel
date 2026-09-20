<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Fixtures;

use Certigniter\CertificateRenderer\Support\Encryption;
use RuntimeException;
use ZipArchive;

/**
 * Builds `.igniter` packages in memory, the same way Certigniter writes
 * one: an AES-encrypted `manifest.json` plus the raw image/font bytes it
 * references under `assets/`.
 *
 * Fixtures are generated rather than committed as binaries so a test reads
 * as the project it is about, and so the suite exercises the *current*
 * container format instead of a snapshot of it that nobody re-exports.
 */
final class IgniterFixture
{
    /** Matches the package's own out-of-the-box config default. */
    public const KEY = 'nA9bC5eD1fG7hJ3kL2pQ8rT6vW0yZ4xU';

    /** A 1x1 opaque PNG - the smallest thing the image pipeline will accept as real bytes. */
    private const PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var array<string, string> archive path => raw bytes */
    private array $assets = [];

    /** @var array<int, array<string, mixed>> */
    private array $elements = [];

    /** @var array<string, array<string, mixed>> */
    private array $embeddedFonts = [];

    private function __construct(
        private string $title = 'Integration Certificate',
        private float $width = 297.0,
        private float $height = 210.0,
        private string $unit = 'mm',
    ) {}

    public static function make(
        string $title = 'Integration Certificate',
        float $width = 297.0,
        float $height = 210.0,
        string $unit = 'mm',
    ): self {
        return new self($title, $width, $height, $unit);
    }

    /** @param array<string, mixed> $properties */
    public function withText(string $id, string $text, array $properties = []): self
    {
        $this->elements[] = [
            'id' => $id,
            'type' => 'text',
            'x' => 20.0,
            'y' => 20.0 + 15.0 * count($this->elements),
            'width' => $this->width - 40.0,
            'height' => 14.0,
            'properties' => array_merge([
                'text' => $text,
                'fontSize' => 24.0,
                'color' => '#1a1a1a',
                'textAlign' => 'center',
                'isVisible' => true,
                'opacity' => 1.0,
            ], $properties),
        ];

        return $this;
    }

    /** A text element whose value comes from a recipient record column. */
    public function withRecipientField(string $id, string $variableName): self
    {
        return $this->withText($id, '', [
            'variableName' => $variableName,
            'placeholderRole' => 'dynamic_text',
            'fontSize' => 32.0,
        ]);
    }

    /** An image element carrying its bytes as an `assets/images/...` reference. */
    public function withImage(string $id, ?string $bytes = null): self
    {
        $bytes ??= self::pngBytes();
        $path = 'assets/images/'.hash('sha256', $bytes).'.png';
        $this->assets[$path] = $bytes;

        $this->elements[] = [
            'id' => $id,
            'type' => 'image',
            'x' => 20.0,
            'y' => 120.0,
            'width' => 40.0,
            'height' => 40.0,
            'properties' => [
                'imageData' => ['asset_path' => $path],
                'fit' => 'contain',
                'isVisible' => true,
                'opacity' => 1.0,
            ],
        ];

        return $this;
    }

    public function withShape(string $id, string $shapeType = 'rectangle'): self
    {
        $this->elements[] = [
            'id' => $id,
            'type' => 'shape',
            'x' => 10.0,
            'y' => 10.0,
            'width' => $this->width - 20.0,
            'height' => $this->height - 20.0,
            'properties' => [
                'shapeType' => $shapeType,
                'fillColor' => '#ffffff',
                'strokeColor' => '#c8a951',
                'strokeWidth' => 2.0,
                'isVisible' => true,
                'opacity' => 1.0,
            ],
        ];

        return $this;
    }

    /**
     * A QR element. Leaving $data empty and passing role `qrcode` with
     * qrType `verification` produces the "Verification link" shape, whose
     * payload only ever arrives through $qrCodeOverrides.
     */
    public function withQrCode(string $id, string $data = '', string $qrType = 'custom'): self
    {
        $this->elements[] = [
            'id' => $id,
            'type' => 'qrcode',
            'x' => $this->width - 60.0,
            'y' => 120.0,
            'width' => 40.0,
            'height' => 40.0,
            'properties' => [
                'data' => $data,
                'qrType' => $qrType,
                'errorCorrectionLevel' => 0,
                'foregroundColor' => '#000000',
                'backgroundColor' => '#ffffff',
                'isVisible' => true,
                'opacity' => 1.0,
            ],
        ];

        return $this;
    }

    public function withBarcode(string $id, string $data = '', string $qrType = 'custom'): self
    {
        $this->elements[] = [
            'id' => $id,
            'type' => 'barcode',
            'x' => 20.0,
            'y' => 170.0,
            'width' => 60.0,
            'height' => 20.0,
            'properties' => [
                'data' => $data,
                'qrType' => $qrType,
                'barcodeType' => 'code128',
                'foregroundColor' => '#000000',
                'backgroundColor' => '#ffffff',
                'isVisible' => true,
                'opacity' => 1.0,
            ],
        ];

        return $this;
    }

    /**
     * Embed a real font, carried in the archive exactly as Certigniter
     * carries one, so a render exercises FontRegistrar's registration path
     * rather than only its fallback.
     */
    public function withEmbeddedFont(string $family, ?string $bytes = null): self
    {
        $bytes ??= self::fontBytes();
        $path = 'assets/fonts/'.hash('sha256', $bytes).'.ttf';
        $this->assets[$path] = $bytes;
        $this->embeddedFonts[$family] = ['normal' => ['asset_path' => $path]];

        return $this;
    }

    /** Raw `.igniter` bytes. */
    public function bytes(string $key = self::KEY): string
    {
        return self::pack($this->manifest(), $this->assets, $key);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'format' => 'igniter',
            'version' => 1,
            'project' => [
                'id' => 'integration-project',
                'title' => $this->title,
                'size' => ['width' => $this->width, 'height' => $this->height, 'unit' => $this->unit],
                'color_format' => 'css-hex',
                'date_format' => 'MMM d, yyyy',
                'elements' => $this->elements,
                'embedded_fonts' => $this->embeddedFonts,
            ],
        ];
    }

    /**
     * Assemble an arbitrary manifest + asset set into a ZIP container.
     * Exposed so a test can build a deliberately malformed package.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $assets  archive path => raw bytes
     */
    public static function pack(array $manifest, array $assets = [], string $key = self::KEY): string
    {
        $path = tempnam(sys_get_temp_dir(), 'igniter-fixture-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the fixture.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the fixture archive for writing.');
            }

            // JSON_PRESERVE_ZERO_FRACTION matches how Certigniter writes a
            // manifest, so page dimensions stay floats rather than
            // collapsing to ints on the way through the fixture.
            $json = json_encode($manifest, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
            $zip->addFromString('manifest.json', Encryption::encrypt((string) $json, $key));

            foreach ($assets as $archivePath => $bytes) {
                $zip->addFromString($archivePath, $bytes);
            }

            $zip->close();

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Could not read back the fixture archive.');
            }

            return $contents;
        } finally {
            @unlink($path);
        }
    }

    public static function pngBytes(): string
    {
        return (string) base64_decode(self::PIXEL_PNG_BASE64, true);
    }

    /**
     * Real TTF bytes, borrowed from Dompdf's own bundled DejaVu Sans under
     * a different family name. Using a font that is genuinely parseable
     * (rather than random bytes) is the point: FontRegistrar reads the
     * `head`/`hhea` tables for its baseline correction.
     */
    public static function fontBytes(): string
    {
        $candidates = [
            __DIR__.'/../../vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf',
            __DIR__.'/../../../../vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return (string) file_get_contents($candidate);
            }
        }

        throw new RuntimeException('Could not locate Dompdf\'s bundled DejaVuSans.ttf to build a font fixture.');
    }
}
