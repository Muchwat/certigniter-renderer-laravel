<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Tests\Integration;

use RuntimeException;

/**
 * A just-enough reader for the PDFs these tests render, so an assertion can
 * be about what actually landed on the page rather than about the byte
 * length of an opaque blob.
 *
 * It is not a general PDF parser and does not try to be - it inflates the
 * page content stream, pulls the text-showing operators out of it, and
 * reads the page box. That is the whole surface the renderer is
 * responsible for.
 */
final class RenderedPdf
{
    private function __construct(private readonly string $bytes) {}

    public static function from(string $bytes): self
    {
        return new self($bytes);
    }

    public function isStructurallyValid(): bool
    {
        return str_starts_with($this->bytes, '%PDF-') && str_contains($this->bytes, '%%EOF');
    }

    public function pageCount(): int
    {
        return (int) preg_match_all('#/Type\s*/Page[^s]#', $this->bytes);
    }

    /**
     * The page box in PDF points, as [x1, y1, x2, y2].
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function mediaBox(): array
    {
        if (preg_match('/MediaBox\s*\[([^\]]*)\]/', $this->bytes, $match) !== 1) {
            throw new RuntimeException('The rendered PDF has no MediaBox.');
        }

        $numbers = array_map('floatval', preg_split('/\s+/', trim($match[1])) ?: []);

        if (count($numbers) !== 4) {
            throw new RuntimeException('The rendered PDF has a malformed MediaBox.');
        }

        return [$numbers[0], $numbers[1], $numbers[2], $numbers[3]];
    }

    public function widthInPoints(): float
    {
        [$x1, , $x2] = $this->mediaBox();

        return round($x2 - $x1, 3);
    }

    public function heightInPoints(): float
    {
        [, $y1, , $y2] = $this->mediaBox();

        return round($y2 - $y1, 3);
    }

    /**
     * Every text-showing operator's string, decoded back to UTF-8. Dompdf
     * writes text against a subset TrueType font as UTF-16BE code units,
     * which is why this has to decode rather than just match ASCII.
     */
    public function text(): string
    {
        $content = $this->contentStream();

        preg_match_all('/\[\(((?:\\\\.|[^)\\\\])*)\)\]\s*TJ/s', $content, $matches);

        $runs = array_map(
            fn (string $run): string => (string) mb_convert_encoding(stripcslashes($run), 'UTF-8', 'UTF-16BE'),
            $matches[1],
        );

        return implode("\n", $runs);
    }

    public function containsText(string $needle): bool
    {
        return str_contains($this->text(), $needle);
    }

    /** How many image XObjects the page draws - one per rendered raster image. */
    public function drawnImageCount(): int
    {
        return (int) preg_match_all('#/I\d+\s+Do#', $this->contentStream());
    }

    /** How many vector path segments the page draws - QR codes and barcodes arrive as paths, not images. */
    public function drawnPathSegmentCount(): int
    {
        return (int) preg_match_all('/^\s*[\d.-]+\s+[\d.-]+\s+l\s*$/m', $this->contentStream());
    }

    /** True when the PDF carries an embedded TrueType font program, i.e. a project font travelled into the output. */
    public function hasEmbeddedFontProgram(): bool
    {
        return str_contains($this->bytes, '/FontFile2');
    }

    /** The inflated page content stream - the drawing operators themselves. */
    public function contentStream(): string
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $this->bytes, $matches);

        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            if (is_string($inflated) && str_contains($inflated, ' re W n')) {
                return $inflated;
            }
        }

        throw new RuntimeException('Could not find an inflatable page content stream in the rendered PDF.');
    }
}
