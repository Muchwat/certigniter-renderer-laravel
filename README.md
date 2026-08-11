# Certigniter Certificate Renderer for Laravel

Render encrypted Certigniter `.igniter` certificate templates as PDFs in a
Laravel application—without Flutter, the desktop application, or a browser.

The package can:

- decrypt and inspect uploaded `.igniter` files;
- discover recipient fields before issuing;
- render individual or bulk certificates;
- replace foreign logos, signatures, and other images at issue time;
- render text, images, shapes, QR codes, barcodes, masks, mirrors, groups, and
  embedded fonts;
- report non-fatal rendering problems through a warnings API.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick start](#quick-start)
- [Inspecting a template](#inspecting-a-template)
- [Recipient data](#recipient-data)
- [Replacing logos and signatures](#replacing-logos-and-signatures)
- [Bulk issuance](#bulk-issuance)
- [Warnings and error handling](#warnings-and-error-handling)
- [Public API](#public-api)
- [Rendering compatibility](#rendering-compatibility)
- [Security and production guidance](#security-and-production-guidance)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)

## Requirements

- PHP 8.2 or newer
- Laravel 11 or 12
- A writable system temporary directory for Dompdf's font cache
- The PHP extensions required by Dompdf, Simple QR Code, and the selected
  image formats

## Installation

The package is not on Packagist yet. Install it from GitHub with a Composer
VCS repository:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Muchwat/certigniter-certificate-renderer.git"
        }
    ],
    "require": {
        "certigniter/laravel-certificate-renderer": "dev-main"
    }
}
```

```bash
composer update certigniter/laravel-certificate-renderer
```

For local package development, use a path repository instead:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/certigniter/laravel-certificate-renderer",
            "options": { "symlink": true }
        }
    ]
}
```

Laravel package discovery registers the service provider and `Certigniter`
facade automatically.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=certigniter-config
```

Set the shared Certigniter encryption key in `.env`:

```env
CERTIGNITER_ENCRYPTION_KEY=your-32-byte-shared-key
```

This is not Laravel's `APP_KEY`. It must exactly match the `encryptionKey`
used by the Certigniter application that created the file. A wrong key causes
decryption to fail before parsing or rendering begins.

The published configuration also controls:

- bundled font-family mappings;
- the fallback font;
- composition of parent-group rotation and opacity.

Views can be published only when a project genuinely needs to customize the
renderer markup:

```bash
php artisan vendor:publish --tag=certigniter-views
```

Prefer the package view unless you are prepared to maintain rendering parity
when new element properties are added.

## Quick start

Inject `CertificateRenderer`, read the encrypted upload, and return the PDF:

```php
use Certigniter\CertificateRenderer\CertificateRenderer;
use Illuminate\Http\Request;

final class CertificateController
{
    public function render(Request $request, CertificateRenderer $renderer)
    {
        $request->validate([
            'template' => ['required', 'file'],
        ]);

        $encrypted = $request->file('template')->get();
        $pdf = $renderer->renderIgniterToPdf($encrypted);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificate.pdf"',
        ]);
    }
}
```

The facade provides the same methods:

```php
use Certigniter\CertificateRenderer\Facades\Certigniter;

$pdf = Certigniter::renderIgniterToPdf($encrypted);
```

## Inspecting a template

Parse once before rendering when the web application needs to build a form,
CSV template, or image-replacement screen:

```php
$project = $renderer->parseIgniter($encrypted);

$metadata = [
    'id' => $project->id,
    'title' => $project->title,
    'width' => $project->width,
    'height' => $project->height,
    'unit' => $project->unit,
    'recipient_fields' => $project->variableNames(),
    'elements' => $project->elementCatalog(),
    'replaceable_images' => $project->getElementIds('image'),
];
```

Element IDs are stable within the template. Store or submit those IDs when
the issuer chooses which logo or signature to replace.

`elementCatalog()` and its intuitive alias `getElementIds()` return metadata,
not just bare IDs, so a developer can build a useful selection screen:

```php
[
    [
        'id' => 'signature-uuid',
        'type' => 'image',
        'label' => 'Director signature',
        'visible' => true,
        'replaceable' => true,
        'parentGroupId' => null,
        'position' => [
            'x' => 24.0,
            'y' => 165.0,
            'width' => 42.0,
            'height' => 14.0,
            'unit' => 'mm',
        ],
        'details' => [
            'hasEmbeddedData' => false,
            'hasLocalPath' => true,
            'originalFilename' => 'director-signature.png',
            'fit' => 'contain',
            'maskShape' => 'none',
            'replacementHint' => 'signature',
        ],
    ],
]
```

The replacement hint is one of `background`, `logo`, `signature`, or `image`.
The catalog also provides relevant summaries for text, variable text, shapes,
QR codes, barcodes, and groups. It intentionally excludes embedded base64 font
and image payloads, making it safe and lightweight to return as JSON.

`parseIgniter()` returns a typed `Data\CertificateProject`; it does not render
a PDF or mutate the uploaded file.

## Recipient data

Pass one flat associative array for one certificate:

```php
$recipient = [
    'Recipient Name' => 'Ada Lovelace',
    'Certificate ID' => 'CERT-0001',
    'Issue Date' => '2026-08-11',
];

$pdf = $renderer->renderIgniterToPdf(
    $encrypted,
    recipient: $recipient,
);
```

There are two merge mechanisms because that is how Certigniter stores fields:

| Template element | Stored form | Matching behavior |
|---|---|---|
| Variable text | `variableName: "Recipient Name"` | Case-insensitive and trimmed |
| QR/barcode data | `{{Certificate ID}}` or `<Certificate ID>` | Exact token replacement |

Use `$project->variableNames()` to obtain the distinct fields required by
both mechanisms in template order.

When no recipient is supplied, variable text renders empty and QR/barcode
tokens remain unresolved. That is normally suitable only for structural
template previews.

## Replacing logos and signatures

Templates created elsewhere may contain the wrong branding or a local path
such as `/Users/designer/Desktop/signature.png`. Server-side code cannot read
that foreign path. Supply replacement bytes keyed by the image element ID:

```php
$request->validate([
    'logo' => ['nullable', 'image', 'max:10240'],
    'signature' => ['nullable', 'image', 'max:10240'],
]);

$imageOverrides = array_filter([
    'logo-element-id' => $request->file('logo')
        ? base64_encode($request->file('logo')->get())
        : null,
    'signature-element-id' => $request->file('signature')
        ? base64_encode($request->file('signature')->get())
        : null,
]);

$pdf = $renderer->renderIgniterToPdf(
    $encrypted,
    recipient: $recipient,
    imageOverrides: $imageOverrides,
);
```

Raw base64 or a `data:image/...;base64,...` URI is accepted. The renderer:

1. finds the image by element ID;
2. clones that render element;
3. replaces `imageData` and ignores its original local path;
4. preserves position, size, fit, alignment, masks, opacity, rotation, and
   mirroring;
5. leaves the parsed project and uploaded `.igniter` file unchanged.

Unknown IDs and IDs belonging to non-image elements are ignored. Validate
upload MIME type and size in the host Laravel application before encoding.

## Bulk issuance

For bulk work, decrypt and parse once, then reuse the project and image map:

```php
$project = $renderer->parseIgniter($encrypted);
$imageOverrides = [
    'logo-element-id' => base64_encode($request->file('logo')->get()),
];

foreach ($recipients as $index => $recipient) {
    $pdf = $renderer->renderProjectToPdf(
        $project,
        recipient: $recipient,
        imageOverrides: $imageOverrides,
    );

    $zip->addFromString("certificate-{$index}.pdf", $pdf);

    foreach ($renderer->warnings() as $warning) {
        logger()->warning($warning, ['row' => $index]);
    }
}
```

Parsing once avoids repeatedly decrypting and decoding the same template.
Each `renderProjectToPdf()` call creates an independent PDF and resets the
warning list.

For large batches, process work in a queue, place limits on template/image
uploads, and write PDFs incrementally rather than retaining every PDF in RAM.

## Warnings and error handling

Invalid encryption, malformed JSON, or an unrecoverable rendering failure
throws an exception. Catch it at the request or queue-job boundary:

```php
try {
    $pdf = $renderer->renderIgniterToPdf($encrypted, $recipient);
} catch (Throwable $error) {
    report($error);

    return response()->json([
        'message' => 'The certificate could not be rendered.',
    ], 422);
}
```

Recoverable element failures do not abort the certificate. Inspect warnings
after each render:

```php
foreach ($renderer->warnings() as $warning) {
    logger()->warning('Certificate element skipped', [
        'warning' => $warning,
    ]);
}
```

Typical warnings include:

- an image contains only a path from another computer and no replacement was
  provided;
- a QR/barcode token was not resolved;
- barcode data is invalid for its selected symbology.

## Public API

### `renderIgniterToPdf()`

```php
renderIgniterToPdf(
    string $encryptedIgniterContent,
    ?array $recipient = null,
    ?string $encryptionKey = null,
    array $imageOverrides = [],
): string
```

Convenience entry point that decrypts, parses, merges, overrides images, and
returns PDF bytes. Pass a per-request encryption key only when intentionally
supporting files from a different trusted key domain.

### `parseIgniter()`

```php
parseIgniter(
    string $encryptedIgniterContent,
    ?string $encryptionKey = null,
): Data\CertificateProject
```

Decrypts and parses without rendering.

### `renderProjectToPdf()`

```php
renderProjectToPdf(
    Data\CertificateProject $project,
    ?array $recipient = null,
    array $imageOverrides = [],
): string
```

Preferred rendering method after a project has already been inspected.

### `warnings()`

```php
warnings(): array
```

Returns non-fatal warnings from the most recent render call.

### Project inspection helpers

```php
$project->variableNames(): array;
$project->elementCatalog(?string $type = null): array;
$project->getElementIds(?string $type = null): array;
```

`getElementIds()` is an alias of `elementCatalog()` and returns the same rich
metadata. Use `getElementIds('image')` for a logo/signature replacement UI.

## Rendering compatibility

| Feature | Support |
|---|---|
| Current CSS and legacy Flutter color formats | Yes |
| Static and variable text | Yes |
| Font family, weight, style, alignment | Yes |
| Line height and letter spacing | Yes |
| Underline, strike-through, shadow | Yes |
| Text bottom borders | Yes |
| Rectangle and four-sided polygon shapes | Yes |
| Per-corner rounded rectangles | Yes |
| Images with contain/fill and content alignment | Yes |
| Circle and rounded-rectangle image masks | Yes |
| Horizontal and vertical element mirroring | Yes |
| QR codes | Yes |
| Code39, EAN-13, EAN-8, UPC-A, ITF, Codabar, Code128 | Yes |
| Group rotation and opacity composition | Yes, configurable |
| Embedded project fonts | Yes |
| Bundled Certigniter font families | Yes |

Typography values are converted from Certigniter's 96-DPI canvas pixels to
72-DPI PDF points. This prevents the approximately 1.33× text enlargement
seen in older exporters and keeps titles aligned with the Design Studio.

Current projects may contain `embedded_fonts`; these are registered for the
render. For projects that only store a font-family name, the package uses its
bundled Playfair Display, Cormorant Garamond, Cinzel, Roboto, and Montserrat
files, then falls back to Inter for unavailable system fonts.

### Known limitations

- Dompdf approximates some CSS rotation behavior.
- Dompdf cannot faithfully reproduce gradient-filled text, so the first
  gradient stop is used as a flat fallback color.
- A path-only image from another computer cannot render unless the host app
  supplies an image override.
- The project model is currently single-page/single-sided.

## Security and production guidance

- Treat `CERTIGNITER_ENCRYPTION_KEY` as a shared secret. Do not commit it.
- Validate uploaded file size and MIME type before reading it into memory.
- Do not trust original image paths from uploaded templates. Remote access is
  disabled and Dompdf is restricted to package font/cache directories.
- Authorize who may render, inspect, or replace certificate assets.
- Escape user-facing metadata when displaying project titles or element names.
- Use queues and execution limits for bulk issuance.
- Record warnings and issuance failures for auditability.
- Use unique, sanitized filenames when creating ZIP archives.

## Troubleshooting

### The `.igniter` file cannot be decrypted

Confirm `CERTIGNITER_ENCRYPTION_KEY` matches the Certigniter application that
created the file. Clear Laravel's cached configuration after changing `.env`:

```bash
php artisan config:clear
```

### A logo or signature is missing

Inspect the image element. If it has `path` but no `imageData`, upload a
replacement and pass it in `imageOverrides` using that element's ID.

### Text is a different size from the editor

Update to the latest package revision. Current versions convert canvas pixels
to PDF points. Also verify the template's font is embedded or included in
`config/certigniter.php`.

### A barcode is skipped

Ensure all merge tokens were supplied and the resulting value is valid for
the selected barcode format. Read `warnings()` for the element ID and cause.

### A custom font falls back to Inter

The server needs actual font bytes. Use a template with `embedded_fonts`, add
the licensed font files to a maintained package customization, or choose a
bundled family.

## Testing

In this repository, the package is exercised through the Laravel host app's
Pest integration suite, including a real encrypted fixture and tests for
single rendering, bulk rendering, image overrides, typography, shapes, masks,
mirrors, fonts, QR codes, and barcodes:

```bash
php artisan test tests/Feature/Certigniter
```

Run the host endpoint tests as well when changing upload or controller logic:

```bash
php artisan test tests/Feature/CertificateControllerTest.php
```

## License

MIT. See [LICENSE](LICENSE).
