<?php

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Support\Encryption;
use Certigniter\CertificateRenderer\Support\IgniterPackage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class IgniterPackageTest extends TestCase
{
    private const KEY = 'nA9bC5eD1fG7hJ3kL2pQ8rT6vW0yZ4xU';

    /** @var string[] */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        $this->temporaryFiles = [];
        parent::tearDown();
    }

    /**
     * Builds a package the same way Certigniter writes one: an encrypted
     * manifest plus raw asset members.
     *
     * @param  array<string, mixed>  $project
     * @param  array<string, string>  $assets  archive path => raw bytes
     */
    private function package(array $project, array $assets = [], ?array $manifestOverride = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'igniter-test-').'.igniter';
        $this->temporaryFiles[] = $path;

        $manifest = $manifestOverride ?? ['format' => 'igniter', 'version' => 1, 'project' => $project];

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        // JSON_PRESERVE_ZERO_FRACTION matches how Certigniter writes a
        // manifest, so page dimensions stay floats rather than collapsing
        // to ints on the way through the fixture.
        $zip->addFromString('manifest.json', Encryption::encrypt(
            json_encode($manifest, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES),
            self::KEY,
        ));
        foreach ($assets as $archivePath => $bytes) {
            $zip->addFromString($archivePath, $bytes);
        }
        $this->assertTrue($zip->close());

        return file_get_contents($path);
    }

    private function minimalProject(array $overrides = []): array
    {
        return array_merge([
            'id' => 'project-1',
            'title' => 'Certificate',
            'size' => ['width' => 297.0, 'height' => 210.0, 'unit' => 'mm'],
            'elements' => [],
        ], $overrides);
    }

    public function test_decode_returns_the_project_from_an_encrypted_manifest(): void
    {
        $project = IgniterPackage::decode($this->package($this->minimalProject()), self::KEY);

        $this->assertSame('project-1', $project['id']);
        $this->assertSame('Certificate', $project['title']);
        $this->assertSame(297.0, $project['size']['width']);
    }

    public function test_decode_resolves_an_image_asset_reference_back_to_base64(): void
    {
        $bytes = random_bytes(64);
        $path = 'assets/images/'.hash('sha256', $bytes).'.png';

        $project = IgniterPackage::decode($this->package(
            $this->minimalProject(['elements' => [[
                'id' => 'image-1',
                'type' => 'image',
                'properties' => ['imageData' => ['asset_path' => $path]],
            ]]]),
            [$path => $bytes],
        ), self::KEY);

        $this->assertSame(base64_encode($bytes), $project['elements'][0]['properties']['imageData']);
    }

    public function test_decode_resolves_embedded_font_references_back_to_base64(): void
    {
        $regular = random_bytes(128);
        $bold = random_bytes(128);
        $regularPath = 'assets/fonts/'.hash('sha256', $regular).'.ttf';
        $boldPath = 'assets/fonts/'.hash('sha256', $bold).'.ttf';

        $project = IgniterPackage::decode($this->package(
            $this->minimalProject(['embedded_fonts' => [
                'Old English Text MT' => [
                    'normal' => ['asset_path' => $regularPath],
                    'bold' => ['asset_path' => $boldPath],
                ],
            ]]),
            [$regularPath => $regular, $boldPath => $bold],
        ), self::KEY);

        $this->assertSame(base64_encode($regular), $project['embedded_fonts']['Old English Text MT']['normal']);
        $this->assertSame(base64_encode($bold), $project['embedded_fonts']['Old English Text MT']['bold']);
    }

    /** Desktop projects saved before backgrounds became real elements still carry these. */
    public function test_decode_resolves_a_legacy_background_image_reference(): void
    {
        $bytes = random_bytes(32);
        $path = 'assets/images/'.hash('sha256', $bytes).'.png';

        $project = IgniterPackage::decode($this->package(
            $this->minimalProject(['background' => ['image_data' => ['asset_path' => $path]]]),
            [$path => $bytes],
        ), self::KEY);

        $this->assertSame(base64_encode($bytes), $project['background']['image_data']);
    }

    public function test_decode_leaves_null_and_empty_binary_fields_untouched(): void
    {
        $project = IgniterPackage::decode($this->package(
            $this->minimalProject(['elements' => [[
                'id' => 'image-1',
                'type' => 'image',
                'properties' => ['imageData' => null],
            ], [
                'id' => 'image-2',
                'type' => 'image',
                'properties' => ['imageData' => ''],
            ]]]),
        ), self::KEY);

        $this->assertNull($project['elements'][0]['properties']['imageData']);
        $this->assertSame('', $project['elements'][1]['properties']['imageData']);
    }

    /** A bare encrypted JSON blob is not a .igniter - only the ZIP container is. */
    public function test_decode_rejects_a_file_that_is_not_a_zip(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected a ZIP container');

        IgniterPackage::decode(Encryption::encrypt('{"id":"x"}', self::KEY), self::KEY);
    }

    public function test_decode_rejects_an_empty_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected a ZIP container');

        IgniterPackage::decode('', self::KEY);
    }

    public function test_decode_rejects_a_package_with_no_manifest(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'igniter-test-').'.igniter';
        $this->temporaryFiles[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('assets/images/abc.png', 'not an image');
        $zip->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no manifest.json');

        IgniterPackage::decode(file_get_contents($path), self::KEY);
    }

    public function test_decode_rejects_an_unsupported_manifest_version(): void
    {
        $contents = $this->package([], [], ['format' => 'igniter', 'version' => 2, 'project' => $this->minimalProject()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported .igniter package version');

        IgniterPackage::decode($contents, self::KEY);
    }

    public function test_decode_rejects_a_manifest_that_carries_no_project(): void
    {
        $contents = $this->package([], [], ['format' => 'igniter', 'version' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not contain a project');

        IgniterPackage::decode($contents, self::KEY);
    }

    public function test_decode_rejects_the_wrong_encryption_key(): void
    {
        $this->expectException(RuntimeException::class);

        IgniterPackage::decode($this->package($this->minimalProject()), str_repeat('z', 32));
    }

    /** A reference the archive has no member for must fail loudly, not silently render blank. */
    public function test_decode_rejects_a_reference_to_a_missing_asset(): void
    {
        $contents = $this->package($this->minimalProject(['elements' => [[
            'id' => 'image-1',
            'type' => 'image',
            'properties' => ['imageData' => ['asset_path' => 'assets/images/deadbeef.png']],
        ]]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing or unsafe asset reference');

        IgniterPackage::decode($contents, self::KEY);
    }

    public function test_decode_rejects_a_traversing_asset_reference(): void
    {
        $contents = $this->package($this->minimalProject(['elements' => [[
            'id' => 'image-1',
            'type' => 'image',
            'properties' => ['imageData' => ['asset_path' => 'assets/images/../../../../etc/passwd']],
        ]]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing or unsafe asset reference');

        IgniterPackage::decode($contents, self::KEY);
    }

    public function test_decode_rejects_an_archive_member_outside_the_documented_layout(): void
    {
        $contents = $this->package($this->minimalProject(), ['../escape.txt' => 'nope']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe or duplicate path');

        IgniterPackage::decode($contents, self::KEY);
    }

    public function test_decode_accepts_the_directory_entries_a_zip_writer_may_emit(): void
    {
        $bytes = random_bytes(16);
        $path = 'assets/images/'.hash('sha256', $bytes).'.png';

        $zipPath = tempnam(sys_get_temp_dir(), 'igniter-test-').'.igniter';
        $this->temporaryFiles[] = $zipPath;
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', Encryption::encrypt(json_encode([
            'format' => 'igniter',
            'version' => 1,
            'project' => $this->minimalProject(['elements' => [[
                'id' => 'image-1',
                'type' => 'image',
                'properties' => ['imageData' => ['asset_path' => $path]],
            ]]]),
        ], JSON_PRESERVE_ZERO_FRACTION), self::KEY));
        $zip->addEmptyDir('assets');
        $zip->addEmptyDir('assets/images');
        $zip->addFromString($path, $bytes);
        $zip->close();

        $project = IgniterPackage::decode(file_get_contents($zipPath), self::KEY);

        $this->assertSame(base64_encode($bytes), $project['elements'][0]['properties']['imageData']);
    }

    public function test_decode_rejects_a_file_larger_than_the_supported_size(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the supported size');

        IgniterPackage::decode(IgniterPackage::MAGIC.str_repeat('x', IgniterPackage::MAX_FILE_BYTES), self::KEY);
    }
}
