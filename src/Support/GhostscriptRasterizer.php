<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use RuntimeException;

/**
 * Rasterizes the first page of a PDF to PNG by shelling out to a
 * Ghostscript binary directly (an array command, so no shell
 * interpolation/escaping is involved) rather than going through the
 * `imagick` extension, since a bare `gs` binary is a lighter system
 * requirement than compiling/enabling a PHP extension.
 */
class GhostscriptRasterizer
{
    public function __construct(private readonly string $binary = 'gs') {}

    /** @return string the path the PNG was written to */
    public function firstPageToPng(string $pdfBytes, string $outputPath, int $resolution): string
    {
        if (! function_exists('proc_open')) {
            throw new RuntimeException('The PHP proc_open() function is disabled - required to shell out to Ghostscript for certificate thumbnails.');
        }

        $directory = dirname($outputPath);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create the thumbnail directory: {$directory}");
        }

        $tempPdfPath = tempnam(sys_get_temp_dir(), 'certigniter-thumb-src-').'.pdf';
        file_put_contents($tempPdfPath, $pdfBytes);

        try {
            $this->run($tempPdfPath, $outputPath, $resolution);
        } finally {
            @unlink($tempPdfPath);
        }

        return $outputPath;
    }

    private function run(string $pdfPath, string $outputPath, int $resolution): void
    {
        // Suppressed deliberately: a missing binary makes proc_open() raise
        // an E_WARNING ("posix_spawn() failed: No such file or directory")
        // that a booted Laravel app's error handler promotes to an
        // ErrorException before the $process === false check below ever
        // runs, leaking that raw OS-level message instead of the clear one
        // below. The `@` keeps behavior identical whether or not a Laravel
        // error handler is installed (this package also runs standalone).
        $process = @proc_open(
            [
                $this->binary,
                '-q',
                '-dSAFER',
                '-dBATCH',
                '-dNOPAUSE',
                // Only the first page is rasterized, so a multi-page PDF
                // isn't fully rendered just to preview it.
                '-dFirstPage=1',
                '-dLastPage=1',
                '-dTextAlphaBits=4',
                '-dGraphicsAlphaBits=4',
                '-sDEVICE=pngalpha',
                "-r{$resolution}",
                "-sOutputFile={$outputPath}",
                $pdfPath,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if ($process === false) {
            throw new RuntimeException(
                "Unable to start the Ghostscript process ('{$this->binary}') - is Ghostscript "
                .'installed and on PATH? (e.g. `brew install ghostscript` / `apt-get install ghostscript`). '
                .'Set certigniter.ghostscript_binary / CERTIGNITER_GHOSTSCRIPT_BINARY if it uses a different name or path.'
            );
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || ! is_file($outputPath)) {
            throw new RuntimeException(sprintf(
                "Ghostscript ('%s') failed to rasterize the certificate to PNG (exit %d): %s",
                $this->binary,
                $exitCode,
                trim($stderr."\n".$stdout) ?: 'no output',
            ));
        }
    }
}
