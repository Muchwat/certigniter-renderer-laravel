# certigniter/laravel-certificate-renderer

Decrypt, parse, and render [Certigniter](https://github.com/) `.igniter` certificate files as PDFs from any Laravel app — no Flutter runtime required. Built from a from-scratch read of the Certigniter desktop app's serialization + rendering code, so it aims for genuine feature parity rather than covering just the common cases.

## What it does

- Decrypts a `.igniter` file (AES-256-CBC, matching Certigniter's own `encryption_util.dart` byte-for-byte).
- Parses the project JSON into typed PHP objects.
- Composes group rotation/opacity onto their children (Certigniter's own bulk-export pipeline has a known bug where it skips this — this package does it correctly by default).
- Resolves recipient merge fields for bulk issuance (`variableName` on text elements, `{{token}}`/`<token>` on QR/barcode data).
- Renders everything — text (incl. font family/weight/style/align/line-height/letter-spacing/underline/strikethrough/shadow), images, QR codes, and barcodes (all 7 symbologies Certigniter supports, not just Code128) — to a PDF via dompdf.
- Degrades gracefully: an image with no embedded bytes, or a barcode whose data can't be encoded in its symbology, is skipped with a warning instead of failing the whole render.

## Installation

This package isn't published to Packagist yet. Point Composer at it directly:

```jsonc
// composer.json
{
    "repositories": [
        { "type": "path", "url": "packages/certigniter/laravel-certificate-renderer" }
    ],
    "require": {
        "certigniter/laravel-certificate-renderer": "@dev"
    }
}
```

```bash
composer require certigniter/laravel-certificate-renderer:@dev
```

Laravel's package auto-discovery registers the service provider and `Certigniter` facade automatically.

## Configuration

```bash
php artisan vendor:publish --tag=certigniter-config
```

**Set `CERTIGNITER_ENCRYPTION_KEY` in every environment that needs to open real `.igniter` files.** This is a *shared secret*, not a per-app secret like `APP_KEY` — it must match the key configured in the Certigniter desktop app (`lib/config.dart`'s `encryptionKey`) exactly, byte for byte. The package ships with that key as a fallback default so it works out of the box against an unmodified Certigniter build, but don't rely on that in production — if you ever need to rotate it, you'll want it out of source control.

```env
CERTIGNITER_ENCRYPTION_KEY=your-32-byte-shared-key-here
```

## Usage

### Render a single (static) certificate

```php
use Certigniter\CertificateRenderer\Facades\Certigniter;

$pdfBytes = Certigniter::renderIgniterToPdf(file_get_contents($request->file('igniter_file')->getRealPath()));

return response($pdfBytes, 200, ['Content-Type' => 'application/pdf']);
```

Or via dependency injection:

```php
use Certigniter\CertificateRenderer\CertificateRenderer;

public function show(CertificateRenderer $renderer)
{
    $pdf = $renderer->renderIgniterToPdf($encryptedFileContents);
    // ...
}
```

Any `variableName`/`{{token}}` placeholders render literally unresolved (e.g. a text element shows an empty string, a QR/barcode `data` field keeps the literal `{{Recipient Name}}` text) — this is correct behavior for a template preview, not a bug.

### Bulk issuance with a recipient record

```php
$pdf = Certigniter::renderIgniterToPdf($encryptedFileContents, recipient: [
    'Recipient Name' => 'Ada Lovelace',
    'Certificate ID' => 'CERT-0001',
]);
```

`$recipient` is a flat `[column => value]` array — typically one row from a parsed CSV. Matching is case-insensitive and trimmed for `variableName` (whole-field text replacement) and case-sensitive/exact for `{{token}}`/`<token>` (inline substitution in QR/barcode data) — this mirrors Certigniter's own two distinct merge mechanisms exactly; see `Support\RecipientMerge` for the full rules.

### Checking for skipped elements

```php
$pdf = $renderer->renderIgniterToPdf($encrypted);

foreach ($renderer->warnings() as $warning) {
    Log::warning($warning);
}
```

`warnings()` reflects the most recent render call. Common causes:
- An image element only has a local `path` (from the machine that created the project) and no embedded `imageData` — `.igniter` files don't carry image bytes for path-only images, so there's nothing to render server-side. Ask users to re-save/export with images embedded.
- A QR/barcode's `data`, after any recipient substitution, contains characters its format can't encode (frequently an unresolved `{{token}}` left over when no recipient was supplied, or a genuinely invalid symbology/data combination).

### Lower-level access

```php
$project = $renderer->parseIgniter($encrypted); // Data\CertificateProject - decrypted + parsed, not yet rendered
$pdf = $renderer->renderProjectToPdf($project, $recipient); // render an already-parsed project
```

Useful if you want to inspect/validate a project (e.g. list its `variableName`s to build a CSV template) before rendering.

## What's faithfully supported

Built directly from Certigniter's own source (models, `design_element.dart`, `batch_pdf_generator.dart`) — see the extensive doc comments throughout `src/` for exactly which behavior each piece replicates and why. Highlights:

- **Colors**: both the current `css-hex` (`#RRGGBBAA`, alpha last) and legacy Flutter-native (`#AARRGGBB`, alpha first) formats, auto-detected via the project's `color_format` field.
- **Groups**: rotation and opacity are composed onto children using the same trigonometry as Certigniter's live canvas (`design_element.dart`), not the buggy flat-render some already-issued PDFs came from. Set `compose_group_transforms` to `false` in config if you specifically need byte-parity with those.
- **Fonts**: the 5 families Certigniter itself bundles actual font files for (Playfair Display, Cormorant Garamond, Cinzel, Roboto, Montserrat) render identically to the desktop app, because this package bundles the same `.ttf` files. Any other `fontFamily` — a font that only happened to be installed on whichever machine last touched the project — falls back to Inter, exactly like Certigniter's own bulk export does when a font isn't found. **`.igniter` files never embed font bytes**, so this is a hard ceiling, not a bug to work around.
- **Barcodes**: all 7 symbologies (`code39`, `ean13`, `ean8`, `upcA`, `itf`, `codabar`, `code128`) — notably *more* correct than Certigniter's own bulk-CSV export, which currently hardcodes Code128 regardless of the project's `barcodeType`.

## Known limitations (by design, not oversights)

- **Rotation and gradients render through dompdf's CSS engine**, which has partial/approximate support for `transform: rotate()` and no support for `background-clip: text` (needed for a true gradient text fill — this package falls back to the gradient's first color stop as a flat color instead of emitting CSS that would silently render nothing). This was a deliberate trade-off for a zero-extra-server-dependency renderer; pixel-perfect fidelity for heavily rotated/gradient-filled designs would need a raster-compositing renderer (Imagick/GD-based) instead.
- **Path-only images can't be resolved.** `.igniter` files store either embedded `imageData` (base64, portable) or a local file `path` (from the machine that created the project). Only the former works server-side — see "Checking for skipped elements" above.
- **`fontSize` is converted from Certigniter's canvas px (96dpi) to PDF points (72dpi)** for parity with what the Design Studio editor actually shows. Certigniter's own bulk-CSV export has a bug where it skips this conversion (text renders ~1.33× too large in already-issued bulk PDFs) — this package does the conversion correctly, so a certificate rendered here will look right relative to the editor, not necessarily byte-identical to an already-issued bulk PDF.

## Testing

Package logic is exercised via the host app's own Pest suite (`tests/Feature/Certigniter/`), including a real `.igniter` fixture extracted from a production payload (`tests/Fixtures/real-certificate.igniter`) for genuine interop coverage rather than only self-consistent round-trips:

```bash
php artisan test tests/Feature/Certigniter
```
