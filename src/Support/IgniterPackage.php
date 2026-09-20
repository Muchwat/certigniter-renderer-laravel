<?php

declare(strict_types=1);

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
            throw new RuntimeException(sprintf(
                'This .igniter file is %.1f MB, over the %d MB this package will open. Re-export it from '
                .'Certigniter Design Studio - a file this size usually means a very large background image '
                .'that Design Studio will downscale on export.',
                strlen($contents) / 1048576,
                self::MAX_FILE_BYTES / 1048576,
            ));
        }

        if (! str_starts_with($contents, self::MAGIC)) {
            throw new RuntimeException(self::notAPackageMessage($contents));
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'Reading a .igniter file requires PHP\'s zip extension (ext-zip), which is not enabled on this '
                .'server. Install it (e.g. `apt-get install php-zip`, `pecl install zip`, or enable '
                .'extension=zip in php.ini) and restart PHP-FPM.'
            );
        }

        return self::withArchive($contents, function (ZipArchive $archive) use ($encryptionKey): array {
            $entries = self::inspectEntries($archive);

            if (! isset($entries['manifest.json'])) {
                throw new RuntimeException(
                    'This .igniter file is a ZIP archive but has no manifest.json in its root, so it is not a '
                    .'certificate package. Re-export the certificate from Certigniter Design Studio, and check '
                    .'that nothing (a zip tool, an archive manager) has repacked the file since.'
                );
            }

            $manifest = self::decodeManifest(self::readEntry($archive, $entries['manifest.json']), $encryptionKey);

            if (($manifest['format'] ?? null) !== 'igniter' || ($manifest['version'] ?? null) !== 1) {
                throw new RuntimeException(sprintf(
                    'This .igniter file is format "%s" version %s; this package reads format "igniter" version 1. '
                    .'It was written by a newer Certigniter Design Studio than this server understands - upgrade '
                    .'certigniter/laravel-certificate-renderer (`composer update certigniter/laravel-certificate-renderer`), '
                    .'or re-export the certificate from a matching Design Studio version.',
                    is_scalar($manifest['format'] ?? null) ? (string) $manifest['format'] : 'unknown',
                    is_scalar($manifest['version'] ?? null) ? (string) $manifest['version'] : 'unknown',
                ));
            }

            if (! is_array($manifest['project'] ?? null)) {
                throw new RuntimeException(
                    'This .igniter file decrypted correctly but carries no certificate design. It was most likely '
                    .'exported from Design Studio before the design finished saving - re-export it.'
                );
            }

            // A project that cannot be re-encoded (invalid UTF-8 survives
            // json_decode into strings that json_encode then rejects) has no
            // measurable size, so budget it at its whole allowance rather
            // than letting strlen(false) blow up on an uploaded file.
            $encodedProject = json_encode($manifest['project']);
            $budget = $encodedProject === false ? self::MAX_EXPANDED_BYTES : strlen($encodedProject);

            return self::mapAssets($manifest['project'], function (mixed $value, string $kind) use ($archive, $entries, &$budget): mixed {
                if ($value === null || $value === '') {
                    return $value;
                }

                $noun = $kind === 'fonts' ? 'font' : 'image';

                if (! is_array($value) || ! is_string($value['asset_path'] ?? null)) {
                    throw new RuntimeException(
                        "This .igniter file's manifest points at a {$noun} in a form this package does not "
                        .'understand. The file has been modified or repacked since Design Studio wrote it - '
                        .'re-export the certificate.'
                    );
                }

                $path = $value['asset_path'];
                if (! self::isAssetPath($path, $kind) || ! isset($entries[$path])) {
                    throw new RuntimeException(
                        "This .igniter file references a {$noun} ({$path}) that is missing from the archive "
                        .'or sits outside the assets/ tree. The file is incomplete or has been repacked - '
                        .'re-export the certificate from Design Studio.'
                    );
                }

                // Repeated references to one asset each cost their expansion
                // again, so a project cannot cite a large asset thousands of
                // times to blow up memory downstream.
                $budget += 4 * (int) ceil($entries[$path]['size'] / 3);
                if ($budget > self::MAX_EXPANDED_BYTES) {
                    throw new RuntimeException(sprintf(
                        'This certificate expands to more than %d MB of images and fonts once unpacked, more than '
                        .'this package will hold in memory. Re-export it from Design Studio with fewer or smaller '
                        .'images.',
                        self::MAX_EXPANDED_BYTES / 1048576,
                    ));
                }

                return base64_encode(self::readEntry($archive, $entries[$path]));
            });
        });
    }

    /**
     * Say what this file looks like instead of a package, and what to do
     * about it. "Expected a ZIP container, got something else" is accurate
     * and useless: the person holding the file is usually a certificate
     * admin, not the developer who will read the stack trace. The three
     * cases below cover what actually turns up in an upload field.
     */
    private static function notAPackageMessage(string $contents): string
    {
        if ($contents === '') {
            return 'The uploaded .igniter file is empty - the upload did not complete. Try again, and check the '
                .'server\'s upload_max_filesize/post_max_size if larger files keep arriving empty.';
        }

        // Before 3.0 a .igniter was the bare AES envelope itself, with every
        // font and image inline as base64. Those files still exist in
        // people\'s downloads folders and are the single most likely thing
        // to be uploaded here by mistake. Matched on the envelope's opening
        // key rather than by decoding it: a real one is megabytes of inline
        // base64, and the point is to name the format, not to read it.
        if (preg_match('/\\A\\s*\\{\\s*"(iv|value)"\\s*:\\s*"/', substr($contents, 0, 512)) === 1) {
            return 'This is a pre-3.0 .igniter file (a single encrypted blob rather than a package). Open it in '
                .'Certigniter Design Studio and export it again - the current format carries its fonts and '
                .'images alongside the design, and this package only reads that form.';
        }

        if (preg_match('/\A\s*(<!doctype|<html|\{|\[)/i', $contents) === 1) {
            return 'This is not a .igniter file - it looks like text or a web page (an error response saved to '
                .'disk, most likely) rather than a certificate package. Download or export the certificate '
                .'again and upload that file.';
        }

        return 'This is not a .igniter file. A certificate package is a ZIP archive written by Certigniter '
            .'Design Studio; this file is something else. Re-export the certificate from Design Studio and '
            .'upload that.';
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
            throw new RuntimeException(sprintf(
                'This .igniter archive holds %d files; a certificate package has at most %d. This is not a '
                .'certificate export - check that the right file was uploaded.',
                $archive->numFiles,
                self::MAX_ENTRIES,
            ));
        }

        $entries = [];
        $totalBytes = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $stat = $archive->statIndex($index);
            if ($stat === false) {
                throw new RuntimeException(
                    'The .igniter archive\'s file listing could not be read - the upload is truncated or '
                    .'corrupt. Try uploading the file again, or re-export it from Design Studio.'
                );
            }

            $path = $stat['name'];
            $isDirectory = in_array($path, ['assets/', 'assets/images/', 'assets/fonts/'], true);
            $isKnown = $path === 'manifest.json' || self::isAssetPath($path, 'images') || self::isAssetPath($path, 'fonts');

            if (isset($entries[$path]) || (! $isDirectory && ! $isKnown)) {
                throw new RuntimeException(
                    "This .igniter archive contains an unexpected or duplicated member ({$path}). A certificate "
                    .'package holds only manifest.json and an assets/ tree, so this file has been repacked or '
                    .'tampered with since Design Studio wrote it - re-export the certificate and upload that.'
                );
            }

            $archive->getExternalAttributesIndex($index, $operatingSystem, $attributes);
            if ($operatingSystem === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw new RuntimeException(
                    'This .igniter archive contains a symbolic link, which a certificate package never does. '
                    .'The file has been repacked or tampered with - re-export the certificate from Design Studio '
                    .'and upload that instead.'
                );
            }

            $totalBytes += $stat['size'];
            $limit = $path === 'manifest.json' ? self::MAX_MANIFEST_BYTES : self::MAX_FILE_BYTES;
            if ($stat['size'] > $limit || $totalBytes > self::MAX_EXPANDED_BYTES) {
                throw new RuntimeException(sprintf(
                    'This .igniter archive unpacks to more than %d MB, more than this package will open. '
                    .'Re-export it from Design Studio with fewer or smaller images.',
                    self::MAX_EXPANDED_BYTES / 1048576,
                ));
            }

            if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE
                || ! in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new RuntimeException(
                    "The archive member '{$path}' uses password protection or a compression method Design Studio "
                    .'never writes. This file has been repacked by another zip tool - re-export the certificate '
                    .'from Design Studio and upload that instead.'
                );
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
            throw new RuntimeException(
                'A file inside this .igniter package failed its checksum, so the upload is damaged. Upload the '
                .'file again - if it keeps failing, re-export the certificate from Design Studio.'
            );
        }

        return $bytes;
    }

    /** @return array<string, mixed> */
    private static function decodeManifest(string $envelope, string $encryptionKey): array
    {
        $manifest = json_decode(Encryption::decrypt($envelope, $encryptionKey), true);

        if (! is_array($manifest)) {
            throw new RuntimeException(
                'This .igniter file\'s manifest decrypted but is not valid JSON. The encryption key configured '
                .'here (config("certigniter.encryption_key") / CERTIGNITER_ENCRYPTION_KEY) most likely differs '
                .'from the one Design Studio sealed the file with - check that the two match exactly.'
            );
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
            throw new RuntimeException(sprintf(
                'Could not create a temporary file in %s to stage the .igniter package - check that the '
                .'directory exists, is writable by the web server user, and has free space.',
                sys_get_temp_dir(),
            ));
        }

        $archive = new ZipArchive;
        $opened = false;

        try {
            $path = stream_get_meta_data($file)['uri'] ?? null;
            if (! is_string($path) || $path === '') {
                throw new RuntimeException(
                    'PHP gave no filesystem path for the temporary file staging the .igniter package, so the '
                    .'archive cannot be opened. Check that sys_temp_dir points at a real directory.'
                );
            }
            if (fwrite($file, $contents) !== strlen($contents)) {
                throw new RuntimeException(sprintf(
                    'Could not write the .igniter package to a temporary file in %s - the disk is most likely '
                    .'full or the directory is not writable by the web server user.',
                    sys_get_temp_dir(),
                ));
            }

            if ($archive->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException(
                    'This file starts like a ZIP archive but could not be opened as one - it is truncated or '
                    .'damaged, which usually means an interrupted upload. Upload it again, or re-export the '
                    .'certificate from Certigniter Design Studio.'
                );
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
