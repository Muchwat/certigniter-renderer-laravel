# Changelog

All notable changes to this package are documented here.

## [3.1.0] - 2026-09-28

### Added

- **Lines and arrows.** `shapeType: 'line'` and `'arrow'` are drawn by
  `ConnectorGeometry`: an open stroke between two stored `points`, or
  bottom-left to top-right (`connectorStyle: 'elbow'` steps across at half
  the width), with filled arrowheads (`arrowStart`, `arrowEnd`, default on
  for arrows) max(4 x stroke, 8px) long. They are never filled. They used to
  render as filled rectangles.
- **Gradient shape fills.** A rectangle, ellipse or polygon's `gradient`
  ({colors, angle}) is painted with evenly spaced stops along the gradient
  line. Dompdf has no SVG gradients or clip paths, so the fill is thin flat
  bands, each the shape's outline clipped to its strip (`GradientBands`), at
  most 0.25 mm apart. Gradients used to fill flat in `fillColor`.
- **Blurred shadows.** `shadowBlur` (a Gaussian of shadowBlur / 2, default
  3 mm) and a text `shadow.blur` are drawn as faint copies spread over the
  same Gaussian (`ShadowBlur`), since PDF cannot blur vectors.
- **A built-in QR encoder.** `QrEncoder` encodes QR codes deterministically,
  choosing the cheapest mix of segment modes, so the same value always gives
  the same symbol module for module. The code is a centred square of the
  box's shorter side with `padding` (CSS pixels, default 2) of quiet zone, in
  `backgroundColor`, with round data modules (`dataModuleShape`) and eyes
  (`eyeShape`) when asked.
- **A built-in barcode encoder.** `BarcodeEncoding` encodes Code 128 (with
  automatic code-set switching), Code 39, EAN-13, EAN-8, UPC-A, ITF and
  Codabar. The bars fill the box's width over `backgroundColor`, with the
  caption along the bottom unless `showText` is false.
- `tests/Fixtures/studio-codes.json`: reference QR and barcode symbols that
  `StudioCodeParityTest` holds both encoders to, module for module.
- **Image masks and `cover`.** A circle mask is a true circle of the box's
  shorter side (it was an ellipse in a non-square box), scaled by
  `maskRadiusFactor`; a rounded rectangle is scaled by `maskWidthFactor`/
  `maskHeightFactor`; `maskEnabled: false` turns a mask off. `fit: 'cover'`
  is supported, and a picture is always clipped to its box.
- **Text on a curve.** A text element's `curveRadius` (the signed radius, in
  mm, of the circle its line follows: positive arches it over the top,
  negative bends it along the bottom) runs its text on one line round that
  circle (`CurvedTextLayout`). The Blade view sets each glyph in its own line
  box, measured by Dompdf itself (`FontRegistrar::textWidthPt()`) and turned
  onto the circle with a CSS rotation
  (`TextElementStyle::curvedGlyphBoxes()`). A curve carries no underline,
  strikethrough or bottom border. Straight text is unchanged.
- **Library shapes (`shapeType: 'path'`).** Vector shapes stored as
  `pathParts` (outlines in absolute M/L/C/Z over the 0-1 box, each pointing
  at one `pathColors` entry). `PathShapeGeometry` stretches them to the
  element box, inset by half the stroke, and rounds every sharp corner by
  `cornerRadius`. `ShapeRenderer` fills and strokes each part in paint order
  with round joins. Hidden parts and hidden vector groups are left out.
- **Stroke position for rectangles.** A shape's `strokeAlign` (`inside`, the
  default, `center` or `outside`) sets where its border sits against its
  box. Inside draws exactly as before. Center and outside fill the box and
  stroke a separate outline on or around it; an outside border's rounded
  corners grow by half a stroke so they stay concentric.
- **Non-destructive image crops.** An `image` element's `cropX`/`cropY`/
  `cropWidth`/`cropHeight` (fractions 0-1 of the original picture) are
  honoured via `ImageElementLayout::crop()`/`cropPlacement()`: the Blade view
  draws the whole picture inside a box that clips it to the crop. `contain`
  letterboxes by the crop's shape. Elements without a crop render exactly as
  before.
- **Barcode content sources.** Barcodes now support the same content sources
  as QR codes: a `barcode` element with `qrType: 'dynamic'` encodes its
  `variableName` recipient column, and one with `qrType: 'verification'`
  encodes a value your application supplies via `qrCodeOverrides` (which now
  accepts barcode element IDs too). New `DesignElement::isCode()`/
  `isVerificationCode()`/`isDynamicCode()` cover both types;
  `isQrAuthenticationLink()`/`isDynamicQr()` stay QR-only. New
  `CertificateProject::verificationCodeElementIds()` lists every
  verification QR code and barcode. `elementCatalog()`'s barcode `details`
  now include `qrType`, `isVerificationCode` and `variableName`.
- **AI-generated image metadata.** `DesignElement::isAiGenerated()`, and
  `aiGenerated`/`aiModel`/`aiGeneratedAt`/`aiPromptPreview` in
  `elementCatalog()`'s per-image `details`, surface the attribution stored
  on an AI-generated image (`properties.aiGenerated`/`aiModel`/`aiPrompt`/
  `aiGeneratedAt`). `imageReplacementHint()` treats `aiGenerated` as
  authoritative for the `background` classification, ahead of the
  90%-of-page-size fallback, so a background that has since been resized
  down no longer falls through to `logo`/`signature`/`image`.
- **An integration suite that boots a real Laravel application.**
  `tests/Integration` uses [Testbench][testbench] to boot an app that
  discovers this package the same way a host app does, and renders
  `.igniter` fixtures all the way to PDF bytes - asserting on the text that
  was drawn, the page box, and whether the project's own font travelled into
  the file, rather than on the length of an opaque blob. It covers the parts
  that only exist inside a framework: config merging and publishing, the
  container binding and facade, and the `certigniter::` view namespace.
  Fixtures are generated by `tests/Fixtures/IgniterFixture` rather than
  committed as binaries, so the suite exercises the current container format
  instead of a snapshot of it.
- **CI** (`.github/workflows/ci.yml`): both suites across Laravel 12 and 13
  on PHP 8.2-8.4, at both the highest and the lowest dependency set each
  constraint allows, plus PHPStan, `composer validate --strict` and
  `composer audit`, on every push and weekly.
- **PHPStan at level 8** (`phpstan.neon.dist`), with larastan for the
  framework's facades and container, and `declare(strict_types=1)` in every
  `src/` and `tests/` file. It found two real bugs on untrusted input, both
  fixed below.

### Fixed

- A QR code's `errorCorrectionLevel` is read as the ISO/IEC 18004 format
  indicator the file stores (L=1, M=0, Q=3, H=2). It was read as an index
  into L, M, Q, H, so an L code rendered as M, with a different grid.
- A shape shadow with no `shadowColor` is black at 40%. Colour fallbacks are
  now always CSS hex; `#66000000` read as CSS hex was a fully transparent
  red, so the shadow was invisible.
- A text shadow's `offsetX`/`offsetY` are CSS pixels, not mm (they were
  3.78x too far), and an unknown `barcodeType` is skipped with a warning
  instead of silently becoming Code 128.
- An empty static QR code or barcode encodes placeholder content
  (`certigniter_placeholder`, or the symbology's sample value).
- Text shadows are drawn at all: Dompdf ignores CSS `text-shadow`, so the
  shadow is copies of the text behind it.
- A `.igniter` whose project contains invalid UTF-8 crashed with a
  `TypeError` instead of a clear rejection: `json_encode()` returns `false`
  for such a project and the result was passed straight to `strlen()`. Since
  the project comes from an uploaded file, this was reachable from outside.
- `Encryption::encrypt()` declared `string` but returned `json_encode()`'s
  result, which can be `false`. It now fails with a message instead.
- A pre-3.0 `.igniter` larger than 512 bytes - which is every real one - was
  reported as "not a .igniter file" rather than as the older format, because
  the detector tried to JSON-decode a truncated prefix of it. It now matches
  the envelope's opening key.
- Dynamic QR codes never resolved: the format writes the column into `data`
  as `{{ name }}` (with spaces), which the exact-match token substitution
  didn't match, so the literal token was encoded. `RecipientMerge` now
  resolves a dynamic code's `variableName` directly (case-insensitive,
  trimmed, like text), and `{{token}}`/`<token>` substitution ignores
  whitespace just inside the delimiters. `variableNames()` no longer reports
  those tokens with padding spaces.
- A dynamic code whose column is missing from the recipient is now skipped
  with a warning instead of encoding the unresolved token.

### Changed

- **Error messages now say what to do.** Every rejection in `IgniterPackage`
  names what the file actually is and what the person holding it should do
  next, because that person is usually a certificate admin rather than the
  developer who will read the stack trace. "Not a valid .igniter file:
  expected a ZIP container, got something else" is now, for the file that
  most often turns up in an upload field, "This is a pre-3.0 .igniter file
  (a single encrypted blob rather than a package). Open it in Certigniter
  Design Studio and export it again". An empty upload, a saved web page, a
  repacked archive, a file in a newer format, a full disk and a missing
  `ext-zip` each say what happened and what fixes it.
- **Laravel 11 support dropped; Laravel 13 added.** `illuminate/support` is
  now `^12.0|^13.0`. Laravel 11 is past its security-fix window, and every
  11.x release is flagged by Composer's advisory database, so a default
  `composer install` will not resolve it. Laravel 13 is now tested in CI.

### Removed

- The `simplesoftwareio/simple-qrcode` and `picqer/php-barcode-generator`
  dependencies (and, with them, `bacon/bacon-qr-code` and `dasprid/enum`).
  QR codes and barcodes are encoded by `QrEncoder` and `BarcodeEncoding`.

[testbench]: https://packages.tools/testbench/

## [3.0.0] - 2026-09-15

### Added

- `DesignElement::isDynamicQr()`, mirroring `isQrAuthenticationLink()`, for a
  QR element whose content source is "Dynamic value" - bound to one
  recipient column via `properties.variableName`. It renders through the
  existing `{{token}}`/`<token>` substitution on `data`, which the format
  mirrors from `variableName`, so no change was needed to `RecipientMerge`
  or `CertificateProject::variableNames()`. `CertificateRenderer` now also
  skips a dynamic QR with no column set, with a warning, the same way it
  already did for an unconfigured verification link.
- `Support\IgniterPackage`, which reads the `.igniter` format: a ZIP container
  holding an AES-encrypted `manifest.json` plus a raw `assets/` tree of the
  images and fonts it references. It resolves each `{"asset_path": ...}`
  reference in the manifest back to the base64 the rest of the package
  expects, and rejects anything outside the documented layout - unknown or
  duplicate member paths, symlinks, ZIP-level encryption, unsupported
  compression, CRC mismatches, and per-file/total size limits. It never
  extracts to disk, so Zip Slip does not apply. Requires `ext-zip`, now
  declared in `composer.json`.

### Changed

- The QR content-source value `qrType: 'authentication'` is renamed to
  `'verification'` ("Verification link"). `'authentication'` is still
  accepted everywhere as a legacy, fully equivalent alias -
  `isQrAuthenticationLink()`'s name and behavior are unchanged, and older
  `.igniter` files keep rendering identically.
- **A `.igniter` file is a ZIP package, and it is self-contained.**
  `parseIgniter()` reads that and only that; the earlier form - a single
  encrypted JSON blob with every binary inline as base64 - is gone, along
  with the code that read it. Every font and image a certificate uses now
  travels as a compressed member of the archive's `assets/` tree.
- **This package ships no fonts.** `resources/fonts` is deleted (~2.8 MB).
  `FontRegistrar` registers what the file carries and nothing else.

  Previously this package bundled six font families, and `.igniter` files
  left those families' bytes out on the assumption that the rendering server
  had matching copies. That made a file's fidelity depend on the server's
  inventory rather than on the file, and it failed quietly: the moment the
  two lists drifted, a project rendered in the fallback font with the file
  still perfectly valid. Moving fonts into the ZIP as binary members also cut
  what embedding costs: the two families of a typical project are ~0.57 MB
  compressed against ~2.47 MB of base64 in the old envelope, and they no
  longer sit inside the AES stream that has to be decrypted in full before
  the first element can be drawn.

### Removed

- Reading the pre-ZIP `.igniter` form. `CertificateRenderer::parseIgniter()`
  no longer falls back to decrypting a bare envelope, and
  `IgniterPackage::isPackage()` - which only existed to choose between the
  two - is gone. `Support\Encryption` stays: it protects `manifest.json`
  inside the archive.
- The bundled font files and every setting that pointed at them.
  `config('certigniter.fonts')` and `config('certigniter.fallback_font')` are
  gone, and `CertificateRenderer`'s `$fonts`, `$fallbackFontRelativePath` and
  `$fontsBasePath` constructor arguments with them.
  `FontRegistrar` now takes just `$embeddedFonts` and `$fontCachePath`.

  **Upgrading:** re-export your templates from Certigniter. A project that
  names a family it carries no bytes for renders in Dompdf's own bundled
  DejaVu Sans (`FontRegistrar::FALLBACK_FAMILY`).

## [2.1.0] - 2026-09-02

### Changed

- `elementCatalog()`/`getElementIds()`'s default label for an element with
  no custom `properties['name']` is now role/type-aware and consistently
  numbered: a signature image now reads `"Signature 1"`, `"Signature 2"`,
  ... instead of `"Image 1"`, `"Image 2"`, ... - the numbering is now per
  resolved name, not per raw element type, so a signature image no longer
  shares its counter with an unrelated plain image; an unrecognized element
  type reads `"Element 1"`, `"Element 2"`, ... A text element still
  previews its own content (now also `"[Empty Text]"` for an empty one,
  rather than falling through to a number), and a dynamic-text placeholder
  still previews its variable name. **Named elements are unaffected** -
  `properties['name']` still wins over every default, so this only changes
  the computed label for elements a designer never named. If your
  integration parses or displays default labels for unnamed elements, expect
  their exact text to change; the field's shape (`array{id, type, label,
  ...}`) and every other key are unchanged. Elements with an image path but
  no name previously showed the image's filename (e.g. `"my-logo-2023"`) as
  their label; they now get the role/type-based name.

## [2.0.1] - 2026-09-02

No functional changes - re-tagged to mark the current `main` HEAD (an
already-merged, content-identical commit on top of v2.0.0) as a release
point.

## [2.0.0] - 2026-09-02

### Added

- `qrCodeOverrides` parameter on `renderIgniterToPdf()`/`renderProjectToPdf()`,
  letting your application supply a QR code's payload at issue time (e.g. a
  per-recipient verification URL) - the only way to give a project's
  "Authentication link" QR element any data, and also usable to override an
  ordinary "Custom value" QR element.
- A standalone `tests/Unit` PHPUnit suite (`vendor/bin/phpunit`, no Laravel
  app required) covering every class that doesn't need a booted container -
  `phpunit/phpunit` was previously a declared dev dependency with nothing to
  run.
- `CertificateProject::$dateFormat`, parsed from a project's new
  `date_format` key (an ICU date pattern like `'MMM d, yyyy'`) - defaults to
  `'MMM d, yyyy'` for every existing `.igniter` file, which lacks the key
  entirely. This package doesn't format any recipient value itself
  (`RecipientMerge` stays a pure pass-through of whatever string a caller
  supplies), so pair it with the new `Support\DateFormatting` helper below
  when building a recipient map from a real date rather than an
  already-formatted string.
- `Support\DateFormatting`, an opt-in helper translating a project's
  `dateFormat` into PHP's `date()` syntax
  (`DateFormatting::format($issuedAt, $project->dateFormat)`), so your
  application's own date values stay consistent with the format the
  certificate's designer picked, instead of each caller defaulting to its own
  format (an HTML `<input type="date">` always produces ISO `yyyy-MM-dd`, for
  instance) and producing certificates where the same field looks different
  depending on how it was issued.

### Changed

- Extracted `certificate.blade.php`'s text- and image-element CSS computation
  (bold detection, shadow/gradient/decoration CSS, content-alignment CSS,
  bottom-border CSS, image `contain`/`fill` fitting, mask CSS) out of inline
  `@php` blocks into two new internal classes, `Support\TextElementStyle` and
  `Support\ImageElementLayout`, mirroring the existing `ShapeRenderer`
  pattern. This is a pure move, not a rewrite: the formulas are unchanged and
  output was verified byte-for-byte identical against a fixture covering
  every branch. Purely internal - no public API change.

### Removed

- `CertificateProject::topLevelElements()` - unused internally (superseded
  by `GroupComposer::resolve()`, which does its own group-child filtering).
  It was public but undocumented; removing it is technically a breaking
  change for any external caller that had started depending on it directly,
  hence the major version.

### Fixed

- `Certigniter` facade's `@method` docblock was missing the `$qrCodeOverrides`
  parameter added above, so IDE autocompletion for facade calls omitted it.
- A text element's `bottomBorderEnabled` underline (and the text itself, when
  centered) rendered slightly off-center. Root cause: dompdf mis-centers a
  `display: table` box whose own `left: 50%; transform: translateX(-50%)`
  centering is combined with `padding-left`/`padding-right` on that same box
  - the padding is counted toward the shrink-to-fit width used to position
  the box, but isn't painted as an inset, so the box lands off-center by
  roughly the padding amount. The underline is now a sibling
  `position: absolute` box with negative `left`/`right` offsets instead of
  padding on the centered box, which doesn't participate in that box's
  shrink-to-fit measurement at all and sidesteps the bug.
- A text element's vertical centering drifted for fonts with an unusual
  ascent/descent split - most visibly blackletter and script display faces
  used for titles and names, off by several points at large sizes. Root
  cause: `baselineCorrectionPt` (compensating for dompdf's CSS line-box
  centering) was a single flat fraction of font size, implicitly tuned for
  fonts with an ascent/descent split resembling Roboto's. `FontRegistrar` now
  parses each registered font's real ascent/descent from its own TTF `hhea`
  table and scales the correction accordingly. For Old English Text MT,
  measured with `pdftotext -bbox`, a title's vertical offset dropped from
  5.46pt to 1.26pt and a recipient name's from 3.76pt to 0.87pt, with no
  change to body text in a standard font.
- A text element's `bottomBorderEnabled` underline could sit several points
  below the text even with `bottomBorderGap: 0`, most visibly on a large
  display font. Root cause: the underline was a sibling `<div>` inside
  `.text-content` (a `display:table` box, for the shrink-to-fit centering
  described above) - dompdf wraps *any* other child of a table box in its
  own anonymous table row regardless of that child's `position:absolute`
  status, which roughly doubles the table's rendered height and pushes a
  sibling underline far below the text. The underline is now
  `padding-left`/`padding-right`/`padding-bottom`/`border-bottom` on an
  inline `<span>` that wraps the text itself, which sidesteps the
  anonymous-row bug entirely and - unlike padding on `.text-content` itself
  - doesn't feed into its shrink-to-fit *width* measurement, so the
  horizontal-centering bug fixed above doesn't reappear either.

## [1.0.0] - 2026-08-12

### Added

- Issue-time image overrides keyed by stable element ID, allowing Laravel
  applications to replace logos, signatures, and path-only foreign images
  without modifying the `.igniter` template.
- Metadata-only `elementCatalog()` and `getElementIds()` inspection helpers
  with element types, labels, geometry, grouping, and image replacement hints.
- Circle and rounded-rectangle image masks.
- Horizontal and vertical mirroring for renderable elements.
- Embedded project-font registration.
- Complete developer documentation for inspection, single rendering, bulk
  issuance, asset replacement, warnings, security, and troubleshooting.

### Fixed

- Convert 96-DPI CSS-pixel typography to 72-DPI PDF points, so text is set at
  its designed size instead of approximately 1.33× larger.
- Normalize asymmetric rounded-rectangle corner radii.
- Preserve image fit and alignment in Dompdf output.
- Compose group transforms and opacity consistently.
