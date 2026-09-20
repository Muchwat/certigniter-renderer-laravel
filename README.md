# Certigniter Certificate Renderer for Laravel

Render encrypted Certigniter `.igniter` certificate templates as PDFs in a
Laravel application—without Flutter, the desktop application, or a browser.

The package can:

- open and inspect uploaded `.igniter` files;
- discover recipient fields before issuing;
- render individual or bulk certificates;
- replace foreign logos, signatures, and other images at issue time;
- assign application-generated QR code and barcode values (e.g. unique
  verification links or codes) at issue time, or bind either to a recipient
  column;
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
- [Dynamic QR code and barcode values](#dynamic-qr-code-and-barcode-values)
- [Replacing logos and signatures](#replacing-logos-and-signatures)
- [Bulk issuance](#bulk-issuance)
- [Warnings and error handling](#warnings-and-error-handling)
- [Public API](#public-api)
- [Rendering compatibility](#rendering-compatibility)
- [Security and production guidance](#security-and-production-guidance)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)

## Requirements

- PHP 8.2 or newer (8.3+ on Laravel 13)
- Laravel 12 or 13 - both are covered by CI on every push, across the
  lowest and highest dependency set each constraint allows
- The `zip` PHP extension (`ext-zip`) - a `.igniter` file is a ZIP container
- A writable system temporary directory for Dompdf's font cache
- The PHP extensions required by Dompdf, Simple QR Code, and the selected
  image formats
- A `gs` (Ghostscript) executable on `PATH` (e.g. `brew install ghostscript` /
  `apt-get install ghostscript`), but only if you call `capture()` - no
  PHP extension required, it's shelled out to directly

## Installation

```bash
composer require certigniter/laravel-certificate-renderer
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

- composition of parent-group rotation and opacity;
- the Ghostscript binary used by `capture()`.

There is nothing to configure for fonts. This package ships none: every family
a certificate uses travels inside the `.igniter` file (see
[Fonts](#fonts)).

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
            'aiGenerated' => false,
            'aiModel' => null,
            'aiGeneratedAt' => null,
            'aiPromptPreview' => null,
            'replacementHint' => 'signature',
        ],
    ],
]
```

The replacement hint is one of `background`, `logo`, `signature`, or `image`.
`aiGenerated`/`aiModel`/`aiGeneratedAt`/`aiPromptPreview` are set on an image
Certigniter's Design Studio generated with its AI background tool -
`aiGenerated` alone is enough to classify it as `background`, even if it's
since been resized below the 90% area fallback the hint otherwise uses.
`aiPromptPreview` is the same 80-character truncation `textPreview`/
`dataPreview` use elsewhere in this catalog. The catalog also provides
relevant summaries for text, variable text, shapes, QR codes, barcodes, and
groups. It intentionally excludes embedded base64 font and image payloads,
making it safe and lightweight to return as JSON.

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

Certigniter stores fields in two forms, so there are two merge mechanisms:

| Template element | Stored form | Matching behavior |
|---|---|---|
| Variable text | `variableName: "Recipient Name"` | Case-insensitive and trimmed |
| "Dynamic value" QR code/barcode | `qrType: "dynamic"`, `variableName: "Certificate ID"` | Case-insensitive and trimmed, replaces the whole value |
| Other QR/barcode data | `{{Certificate ID}}` or `<Certificate ID>` | Exact (case-sensitive) token replacement; spaces inside the delimiters are ignored |

Use `$project->variableNames()` to obtain the distinct fields required by
both mechanisms in template order.

When no recipient is supplied, variable text renders empty, QR/barcode
tokens remain unresolved, and a "Dynamic value" barcode is skipped with a
warning. That is normally suitable only for structural
template previews.

### Consistent date formatting

`$recipient` values are substituted exactly as given - `'Issue Date' =>
'2026-08-11'` and `'Issue Date' => 'Aug 11, 2026'` are both valid, and this
package has no opinion on which. If your own values come from a real date
(rather than an already-formatted string, e.g. from a CSV import or an
HTML `<input type="date">`, which always yields ISO `yyyy-MM-dd`), format
them with `$project->dateFormat` first so every certificate for a project
shows dates the same way its designer chose in Design Studio's date-format
picker, regardless of how each certificate was issued:

```php
use Certigniter\CertificateRenderer\Support\DateFormatting;

$project = $renderer->parseIgniter($encrypted);

$recipient = [
    'Recipient Name' => 'Ada Lovelace',
    'Issue Date' => DateFormatting::format($issuedAt, $project->dateFormat),
];
```

`$project->dateFormat` is a Dart/ICU-style pattern (e.g. `'MMM d, yyyy'`) -
the same syntax the picker itself uses - and defaults to `'MMM d, yyyy'`
for any `.igniter` file saved before this field existed.
`DateFormatting::toPhpFormat()` and `::format()` translate a small, fixed
set of patterns (exactly the ones the picker offers) into PHP's `date()`
syntax; an unrecognized pattern falls back to the default rather than
guessing at a translation.

## Dynamic QR code and barcode values

A QR code or barcode element's Design Studio "Content source" (`qrType`)
is one of:

- **Custom value** (`'custom'`) - a static string, optionally containing
  `{{token}}`/`<token>` placeholders resolved from `recipient` like any
  other QR/barcode data (see above); or
- **Dynamic value** (`'dynamic'`) - bound to exactly one recipient/CSV
  column, named in `properties.variableName` (and mirrored into `data` as
  `{{ variableName }}` for older consumers) - the whole payload is replaced
  by that column's value, matched case-insensitively and trimmed like a text
  element's `variableName`; a recipient with no value for it skips the code
  with a warning (not to be confused with this section's own "dynamic QR code
  values" - the `qrCodeOverrides` mechanism below - which is a different,
  host-application-driven kind of dynamic content); or
- **Verification link** (`'verification'` - `'authentication'` is a legacy
  value older projects may still carry, and is accepted identically) - the
  element intentionally stores no `data` at all. Its real payload doesn't
  exist until the certificate is issued, so it must be supplied by your
  application at render time - typically a unique verification URL such as
  `https://you.example.com/verify/{id}`.

**Barcodes support the same three content sources.** A `barcode` element
carries the same `qrType`/`variableName` properties, so a barcode can encode
a per-recipient column (`'dynamic'`) or an application-supplied verification
code (`'verification'`) exactly like a QR code. Pick a symbology that can
encode the value - Code 128 handles any ASCII verification URL or code;
EAN/UPC/ITF only accept digits, and a value they reject is skipped with a
warning.

Use `$project->verificationCodeElementIds()` to find every verification QR
code and barcode in a parsed project (a template may have e.g. one of each),
then pass the value you generated in `qrCodeOverrides`, keyed by element ID.
`authenticationQrElementId()` still returns just the QR one:

```php
$project = $renderer->parseIgniter($encrypted);
$verificationUrl = route('certificate.verify', ['id' => $certificateId]);

$qrCodeOverrides = array_fill_keys($project->verificationCodeElementIds(), $verificationUrl);

$pdf = $renderer->renderProjectToPdf(
    $project,
    recipient: $recipient,
    qrCodeOverrides: $qrCodeOverrides,
);
```

`qrCodeOverrides` accepts any qrcode or barcode element ID, not only a verification
link - an explicit override always wins over both a static `data` value and
`{{token}}`/`<token>` substitution, so it also works as a direct escape
hatch for a value your application computed rather than one that came from
a recipient record. If a verification-link code has no override
supplied for it, it is skipped with a warning (see below) rather than
encoding an empty or placeholder string into the certificate.

For bulk issuance, each recipient's link is normally unique per row - build
the override map fresh inside the loop, e.g. from a `Verification URL`
column already present in that row's data, or by minting one from your own
application state per iteration.

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

For bulk work, parse once, then reuse the project and image map:

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

Parsing once avoids repeatedly unpacking and decoding the same template.
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
- barcode data is invalid for its selected symbology;
- a "Verification link" QR code or barcode had no value supplied for it in
  `qrCodeOverrides`;
- a "Dynamic value" QR code or barcode had no data column set, or the
  recipient had no value for that column.

## Public API

### `renderIgniterToPdf()`

```php
renderIgniterToPdf(
    string $igniterContents,
    ?array $recipient = null,
    ?string $encryptionKey = null,
    array $imageOverrides = [],
    array $qrCodeOverrides = [],
): string
```

Convenience entry point that unpacks, parses, merges, overrides images and
QR code/barcode values, and returns PDF bytes. `$qrCodeOverrides` is keyed
by QR code or barcode element ID. Pass a per-request encryption key only
when intentionally supporting files from a different trusted key domain.

### `parseIgniter()`

```php
parseIgniter(
    string $igniterContents,
    ?string $encryptionKey = null,
): Data\CertificateProject
```

Unpacks and parses without rendering.

### `renderProjectToPdf()`

```php
renderProjectToPdf(
    Data\CertificateProject $project,
    ?array $recipient = null,
    array $imageOverrides = [],
    array $qrCodeOverrides = [],
): string
```

Preferred rendering method after a project has already been inspected.

### `capture()`

```php
capture(
    string $igniterContents,
    ?array $recipient = null,
    ?string $encryptionKey = null,
    array $imageOverrides = [],
    array $qrCodeOverrides = [],
    ?string $outputPath = null,
    int $resolution = 150,
): string
```

Renders straight to a PNG preview and writes it to `$outputPath` (a temp file
when omitted), returning the path written. This is the method a host app
calls at the moment a user installs/imports an `.igniter` file, to get a
thumbnail to display for it. Shells out to a `gs` (Ghostscript) binary
directly - no `imagick` PHP extension involved - configurable via
`certigniter.ghostscript_binary` / `CERTIGNITER_GHOSTSCRIPT_BINARY` (see
[Requirements](#requirements)); throws a `RuntimeException` if the binary is
missing or fails.

Two things worth knowing before wiring this into an install/import flow:

- **A missing Ghostscript binary shouldn't be fatal to your own install
  step.** If you call `capture()` from a Composer script or similar
  onboarding hook, catch the exception and treat it as non-fatal - Composer
  aborts the entire `install`/`update` on any non-zero script exit, so a
  teammate or CI runner without Ghostscript would otherwise be unable to
  install your app at all. This package's own `certificate:snapshot`
  Artisan command in the parent app does exactly that (warns, still exits
  `0`).
- **A real-world `.igniter` file with path-only images will produce an
  incomplete thumbnail, not an error.** Per the `imageData`-vs-`path`
  distinction described under [Replacing logos and
  signatures](#replacing-logos-and-signatures), any image element that only
  has a local `path` (common for files exported before a project embeds its
  assets) is silently skipped - `capture()` still returns a PNG, it's just
  missing that background/logo. Always check `warnings()` right after
  calling `capture()` and surface it (e.g. "this certificate's preview may
  be missing some images") rather than assuming a returned path means a
  complete render.

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
$project->authenticationQrElementId(): ?string;
$project->verificationCodeElementIds(): array;
```

`getElementIds()` is an alias of `elementCatalog()` and returns the same rich
metadata. Use `getElementIds('image')` for a logo/signature replacement UI.
`authenticationQrElementId()` returns the element ID of the project's
"Verification link" QR code, or `null` if it has none;
`verificationCodeElementIds()` returns every verification QR code and
barcode - see [Dynamic QR code and barcode values](#dynamic-qr-code-and-barcode-values).

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
| Dynamic and verification-link QR codes and barcodes | Yes |
| Code39, EAN-13, EAN-8, UPC-A, ITF, Codabar, Code128 | Yes |
| Group rotation and opacity composition | Yes, configurable |
| Fonts carried inside the `.igniter` | Yes |

Typography values are converted from Certigniter's 96-DPI canvas pixels to
72-DPI PDF points. This prevents the approximately 1.33× text enlargement
seen in older exporters and keeps titles aligned with the Design Studio.

### Fonts

A `.igniter` file is self-contained: every font family it uses travels inside
it, as raw members of the archive's `assets/fonts/` tree. This package ships no
font files of its own and registers exactly what the file carries.

That is a change from earlier versions, which bundled Playfair Display,
Cormorant Garamond, Cinzel, Roboto, Montserrat, and Inter, and relied on
Certigniter leaving those families out of exports. That made a file's fidelity
depend on the renderer's inventory rather than on the file, and it failed
silently when the two drifted apart - a valid file simply rendered in the
wrong typeface. It also made this package about 2.8 MB heavier for every
install, most of which no given certificate needed.

A family the file names but carries no bytes for renders in Dompdf's own
built-in DejaVu Sans. Re-save such a project from Certigniter so its fonts
travel with it.

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
  disabled and Dompdf is restricted to the font cache directory.
- Uploaded `.igniter` packages are read without ever being extracted to disk.
  The reader enforces per-file and total size limits and rejects unknown or
  duplicate archive paths, symlinks, ZIP-level encryption, unsupported
  compression, and checksum mismatches.
- Authorize who may render, inspect, or replace certificate assets.
- Escape user-facing metadata when displaying project titles or element names.
- Use queues and execution limits for bulk issuance.
- Record warnings and issuance failures for auditability.
- Use unique, sanitized filenames when creating ZIP archives.

## Troubleshooting

### The `.igniter` file cannot be opened

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
the selected barcode format. A "Dynamic value" barcode also needs its
`variableName` column in the recipient data, and a "Verification link"
barcode needs a value in `qrCodeOverrides`. EAN-13, EAN-8, UPC-A and ITF only
encode digits; use Code 128 for a verification URL. Read `warnings()` for the
element ID and cause.

### A font falls back to DejaVu Sans

The file carries no bytes for that family, and the package ships no fonts to
fill the gap. Re-save the project from Certigniter so the family is embedded;
the renderer then uses those bytes directly. There is no server-side font
directory to install into.

### An upload is rejected as "not a ZIP container"

A `.igniter` is a ZIP package. A file that starts with anything else is not
one - most likely it was produced by a build that predates the format, and
needs re-exporting from Certigniter.

## Testing

Two suites, both run by CI on every push across Laravel 12 and 13:

```bash
composer test               # both suites
composer test:unit          # tests/Unit
composer test:integration   # tests/Integration
composer analyse            # PHPStan, level 8
```

**`tests/Unit`** covers every class that works without a booted Laravel
application - parsing, decryption, geometry, colors, merging. It runs
standalone after `composer install`, which is what a `composer require`
install outside the Certigniter monorepo gets to verify its install.

**`tests/Integration`** boots a real Laravel application with
[Testbench][testbench], which discovers this package's service provider
through the same `extra.laravel` metadata a host app's package discovery
uses. It covers what only exists inside a framework - config merging and
publishing, the container binding and facade, the `certigniter::` view
namespace, and the facade root `QrCodeRenderer` needs - and renders
`.igniter` fixtures all the way to real PDF bytes, asserting on what landed
on the page: the text that was drawn, the page box, whether the project's
own font travelled into the file.

Fixtures are built in memory by `tests/Fixtures/IgniterFixture`, not
committed as binaries, so a test reads as the project it is about and the
suite exercises the *current* container format rather than a snapshot of it
that nobody re-exports.

The host Laravel app in this repository keeps its own Pest suite against a
real encrypted fixture, covering the app's upload and controller logic on
top of the package:

```bash
php artisan test tests/Feature/Certigniter
php artisan test tests/Feature/CertificateControllerTest.php
```

[testbench]: https://packages.tools/testbench/

## License

MIT. See [LICENSE](LICENSE).
