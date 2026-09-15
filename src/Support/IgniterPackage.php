<?php

namespace Certigniter\CertificateRenderer\Support;

use Closure;
use RuntimeException;
use ZipArchive;

/**
 * Reads a `.igniter` file.
 *
 * A `.igniter` is a ZIP container:
 *
 *     certificate.igniter
 *     ├── manifest.json     <-- an AES-256-CBC {"iv","value"} envelope wrapping
 *     │                        {format: "igniter", version: 1, project: {...}}
 *     └── assets/
 *         ├── images/<sha256>.<ext>
 *         └── fonts/<sha256>.<ext>
 *
 * Every binary the project needs travels inside `assets/`, so a file is
 * self-contained: this package ships no font or image bytes of its own and
 * does not need to. Inside the manifest each binary field is replaced by a
 * `{"asset_path": "assets/..."}` reference, which decode() resolves back to
 * the base64 string the rest of the package already expects - so
 * CertificateProject, FontRegistrar and the image pipeline see exactly the
 * shape they saw when projects carried their bytes inline.
 *
 * The reader is deliberately strict, because a host application will hand
 * it files uploaded by its own users. It never extracts to disk, never
 * resolves a path or URL out of the project, and rejects anything outside
 * the layout above - see decode() and inspectEntries().
 */
class IgniterPackage
{
    /** Local file header of any ZIP archive. */
    public const MAGIC = "PK\x03\x04";

    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    private const MAX_EXPANDED_BYTES = 64 * 1024 * 1024;

    private const MAX_MANIFEST_BYTES = 8 * 1024 * 1024;

    private const MAX_ENTRIES = 1024;

    /**
     * Unpack a `.igniter` into the plain project array, with every asset
     * reference resolved back to base64 bytes.
     *
     * @return array<string, mixed>
     */
    public static function decode(string $contents, string $encryptionKey): array
    {
        if (strlen($contents) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('The .igniter file exceeds the supported size of 20 MB.');
        }

        if (! str_starts_with($contents, self::MAGIC)) {
            throw new RuntimeException(
                'Not a valid .igniter file: expected a ZIP container, got something else.'
            );
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Reading a .igniter file requires PHP\'s zip extension (ext-zip), which is not installed.'
            );
        }

        return self::withArchive($contents, function (ZipArchive $archive) use ($encryptionKey): array {
            $entries = self::inspectEntries($archive);

            if (! isset($entries['manifest.json'])) {
                throw new RuntimeException('Corrupt .igniter package: no manifest.json in the archive root.');
            }

            $manifest = self::decodeManifest(self::readEntry($archive, $entries['manifest.json']), $encryptionKey);

            if (($manifest['format'] ?? null) !== 'igniter' || ($manifest['version'] ?? null) !== 1) {
                throw new RuntimeException(
                    'Unsupported .igniter package version - this file was written by a newer Certigniter than this package understands.'
                );
            }

            if (! is_array($manifest['project'] ?? null)) {
                throw new RuntimeException('That .igniter file does not contain a project.');
            }

            $budget = strlen(json_encode($manifest['project']));

            return self::mapAssets($manifest['project'], function (mixed $value, string $kind) use ($archive, $entries, &$budget): mixed {
                if ($value === null || $value === '') {
                    return $value;
                }

                if (! is_array($value) || ! is_string($value['asset_path'] ?? null)) {
                    throw new RuntimeException('Invalid asset reference in .igniter package.');
                }

                $path = $value['asset_path'];
                if (! self::isAssetPath($path, $kind) || ! isset($entries[$path])) {
                    throw new RuntimeException("Missing or unsafe asset reference in .igniter package: {$path}");
                }

                // Repeated references to one asset each cost their expansion
                // again, so a project cannot cite a large asset thousands of
                // times to blow up memory downstream.
                $budget += 4 * (int) ceil($entries[$path]['size'] / 3);
                if ($budget > self::MAX_EXPANDED_BYTES) {
                    throw new RuntimeException('The expanded .igniter project is too large.');
                }

                return base64_encode(self::readEntry($archive, $entries[$path]));
            });
        });
    }

    /**
     * Walk only the known binary fields. Deliberately never resolves an
     * arbitrary project path or URL - the legacy `background` fields are
     * here because desktop projects saved before backgrounds became real
     * elements still carry them.
     *
     * @param  array<string, mixed>  $project
     * @param  Closure(mixed, string): mixed  $transform
     * @return array<string, mixed>
     */
    private static function mapAssets(array $project, Closure $transform): array
    {
        if (is_array($project['elements'] ?? null)) {
            foreach ($project['elements'] as &$element) {
                if (is_array($element) && is_array($element['properties'] ?? null) && array_key_exists('imageData', $element['properties'])) {
                    $element['properties']['imageData'] = $transform($element['properties']['imageData'], 'images');
                }
            }
            unset($element);
        }

        if (is_array($project['background'] ?? null)) {
            foreach (['image_data', 'imageData'] as $field) {
                if (array_key_exists($field, $project['background'])) {
                    $project['background'][$field] = $transform($project['background'][$field], 'images');
                }
            }
        }

        if (is_array($project['embedded_fonts'] ?? null)) {
            foreach ($project['embedded_fonts'] as &$variants) {
                if (is_array($variants)) {
                    foreach ($variants as &$value) {
                        $value = $transform($value, 'fonts');
                    }
                    unset($value);
                }
            }
            unset($variants);
        }

        return $project;
    }

    private static function isAssetPath(string $path, string $kind): bool
    {
        return preg_match('#\Aassets/'.$kind.'/[a-zA-Z0-9][a-zA-Z0-9._-]*\z#D', $path) === 1;
    }

    /**
     * Read the archive directory once, rejecting everything that is not part
     * of the documented layout: unexpected or duplicate paths, symlinks,
     * ZIP-level encryption, exotic compression, and any of the size limits.
     *
     * @return array<string, array{index: int, size: int, crc: int}>
     */
    private static function inspectEntries(ZipArchive $archive): array
    {
        if ($archive->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('The .igniter package contains too many entries.');
        }

        $entries = [];
        $totalBytes = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            if ($stat === false) {
                throw new RuntimeException('Could not read the .igniter package directory.');
            }

            $path = $stat['name'];
            $isDirectory = in_array($path, ['assets/', 'assets/images/', 'assets/fonts/'], true);
            $isKnown = $path === 'manifest.json' || self::isAssetPath($path, 'images') || self::isAssetPath($path, 'fonts');

            if (isset($entries[$path]) || (! $isDirectory && ! $isKnown)) {
                throw new RuntimeException("Unsafe or duplicate path in .igniter package: {$path}");
            }

            $archive->getExternalAttributesIndex($index, $operatingSystem, $attributes);
            if ($operatingSystem === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('Symbolic links are not allowed in .igniter packages.');
            }

            $totalBytes += $stat['size'];
            $limit = $path === 'manifest.json' ? self::MAX_MANIFEST_BYTES : self::MAX_FILE_BYTES;
            if ($stat['size'] > $limit || $totalBytes > self::MAX_EXPANDED_BYTES) {
                throw new RuntimeException('The expanded .igniter package is too large.');
            }

            if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE
                || ! in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new RuntimeException('Unsupported compression or ZIP-level encryption in .igniter package.');
            }

            if ($isDirectory) {
                continue;
            }

            $entries[$path] = ['index' => $index, 'size' => $stat['size'], 'crc' => $stat['crc']];
        }

        return $entries;
    }

    /** @param array{index: int, size: int, crc: int} $entry */
    private static function readEntry(ZipArchive $archive, array $entry): string
    {
        // Reading size+1 bytes means a member whose real content is longer
        // than its directory entry claims comes back the wrong length rather
        // than being silently truncated to the expected size.
        $bytes = $archive->getFromIndex($entry['index'], $entry['size'] + 1);

        if ($bytes === false || strlen($bytes) !== $entry['size'] || crc32($bytes) !== $entry['crc']) {
            throw new RuntimeException('Corrupt asset or manifest in .igniter package.');
        }

        return $bytes;
    }

    /** @return array<string, mixed> */
    private static function decodeManifest(string $envelope, string $encryptionKey): array
    {
        $manifest = json_decode(Encryption::decrypt($envelope, $encryptionKey), true);

        if (! is_array($manifest)) {
            throw new RuntimeException('Decrypted .igniter manifest is not valid JSON.');
        }

        return $manifest;
    }

    /**
     * ZipArchive can only work against a seekable file, so the package is
     * staged in a private temporary file that is removed on success and on
     * failure alike. Archive members are read straight out of it - nothing
     * is ever extracted to a path, which is what makes the whole Zip Slip
     * class of bug inapplicable here.
     *
     * @param  Closure(ZipArchive): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private static function withArchive(string $contents, Closure $callback): array
    {
        $file = tmpfile();
        if ($file === false) {
            throw new RuntimeException('Could not create a temporary file to stage the .igniter package.');
        }

        $archive = new ZipArchive;
        $opened = false;

        try {
            $path = stream_get_meta_data($file)['uri'];
            if (fwrite($file, $contents) !== strlen($contents)) {
                throw new RuntimeException('Could not stage the .igniter package.');
            }

            if ($archive->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('Not a valid .igniter ZIP package.');
            }
            $opened = true;

            $result = $callback($archive);

            $opened = false;
            $archive->close();

            return $result;
        } finally {
            if ($opened) {
                $archive->close();
            }
            fclose($file);
        }
    }
}
