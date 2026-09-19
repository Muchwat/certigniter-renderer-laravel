# Changelog

All notable changes to this package are documented here.

## [Unreleased]

### Added

- Barcodes now support the same content sources as QR codes: a `barcode`
  element with `qrType: 'dynamic'` encodes its `variableName` recipient
  column, and one with `qrType: 'verification'` encodes a value the host
  application supplies via `qrCodeOverrides` (which now accepts barcode
  element IDs too). New `DesignElement::isCode()`/`isVerificationCode()`/
  `isDynamicCode()` cover both types; `isQrAuthenticationLink()`/
  `isDynamicQr()` stay QR-only. New `CertificateProject::verificationCodeElementIds()`
  lists every verification QR code and barcode. `elementCatalog()`'s barcode
  `details` now include `qrType`, `isVerificationCode` and `variableName`.
- `DesignElement::isAiGenerated()`, and `aiGenerated`/`aiModel`/`aiGeneratedAt`/
  `aiPromptPreview` in `elementCatalog()`'s per-image `details`, surfacing the
  attribution Design Studio's AI background tool now stamps on the image
  element it creates or replaces (`properties.aiGenerated`/`aiModel`/
  `aiPrompt`/`aiGeneratedAt`). `imageReplacementHint()` now also treats
  `aiGenerated` as authoritative for the `background` classification, ahead of
  the existing 90%-of-page-size fallback - a background the issuer has since
  resized down no longer falls through to `logo`/`signature`/`image`.

### Fixed

- Dynamic QR codes never resolved: both Design Studio editors write the
  column into `data` as `{{ name }}` (with spaces), which the exact-match
  token substitution didn't match, so the literal token was encoded.
  `RecipientMerge` now resolves a dynamic code's `variableName` directly
  (case-insensitive, trimmed, like text), and `{{token}}`/`<token>`
  substitution ignores whitespace just inside the delimiters.
  `variableNames()` no longer reports those tokens with padding spaces.
- A dynamic code whose column is missing from the recipient is now skipped
  with a warning instead of encoding the unresolved token.

## [3.0.0] - 2026-09-15

### Added

- `DesignElement::isDynamicQr()`, mirroring `isQrAuthenticationLink()`, for a
  QR element whose Design Studio "Content source" is "Dynamic value" - bound
  to one recipient/CSV column via `properties.variableName`. It renders
  through the existing `{{token}}`/`<token>` substitution on `data` (which
  both Design Studio editors mirror from `variableName` on every edit), so
  no change was needed to `RecipientMerge` or `CertificateProject::variableNames()`.
  `CertificateRenderer` now also skips a dynamic QR with no column ever set,
  with a warning, the same way it already did for an unconfigured
  verification link.

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

- The QR "Content source" wire value `qrType: 'authentication'` is renamed
  to `'verification'` (matching the Design Studio's "Verification link"
  label). `'authentication'` is still accepted everywhere as a legacy,
  fully-equivalent alias - `isQrAuthenticationLink()`'s name and behavior
  are unchanged, older `.igniter` files/projects keep rendering identically.

- **A `.igniter` file is a ZIP package, and it is self-contained.**
  `parseIgniter()` reads that and only that; the earlier form - a single
  encrypted JSON blob with every binary inline as base64 - is gone, along
  with the code that read it. Every font and image a certificate uses now
  travels as a compressed member of the archive's `assets/` tree.

- **This package ships no fonts.** `resources/fonts` is deleted (~2.8 MB).
  `FontRegistrar` registers what the file carries and nothing else.

  Previously this package bundled the same six families Certigniter did, and
  Certigniter left their bytes out of exports on the assumption the rendering
  server had matching copies. That made a file's fidelity depend on the
  consumer's inventory rather than on the file, and it failed quietly: the
  moment the two lists drifted, a project rendered in the fallback font with
  the file still perfectly valid. It had already happened - the web Studio
  had added IBM Plex Mono, which no plugin shipped. Moving fonts into the ZIP
  as binary members also cut what embedding costs: the two families of a
  typical project are ~0.57 MB compressed against ~2.47 MB of base64 in the
  old envelope, and they no longer sit inside the AES stream that has to be
  decrypted in full before the first element can be drawn.

### Removed

- Reading the pre-ZIP `.igniter` form. `CertificateRenderer::parseIgniter()`
  no longer falls back to decrypting a bare envelope, and
  `IgniterPackage::isPackage()` - which only existed to choose between the
  two - is gone. `Support\Encryption` stays: it protects `manifest.json`
  inside the archive, and is still the interop point with the Design Studio's
  `EncryptionUtil`.

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
  numbered, matching Design Studio's own element-naming logic
  (`ElementLabelUtil`): a signature image now reads `"Signature 1"`,
  `"Signature 2"`, ... instead of `"Image 1"`, `"Image 2"`, ... - the
  numbering is now per resolved name, not per raw element type, so a
  signature image no longer shares its counter with an unrelated plain
  image; an unrecognized element type reads `"Element 1"`, `"Element 2"`,
  ... A text element still previews its own content (now also
  `"[Empty Text]"` for an empty one, rather than falling through to a
  number), and a dynamic-text placeholder still previews its variable name.
  **Named elements are unaffected** - `properties['name']` still wins over
  every default, so this only changes the computed label for elements a
  designer never named. If your integration parses or displays default
  labels for unnamed elements (rather than only showing/relying on names
  you or the designer set), expect their exact text to change; the field's
  shape (`array{id, type, label, ...}`) and every other key are unchanged.
  Elements with an image path but no name previously showed the image's
  filename (e.g. `"my-logo-2023"`) as their label - this is superseded by
  the role/type-based name for consistency with Design Studio, which never
  shows filenames on canvas.

## [2.0.1] - 2026-09-02

No functional changes - re-tagged to mark the current `main` HEAD (an
already-merged, content-identical commit on top of v2.0.0) as a release
point.

## [2.0.0] - 2026-09-02

### Added

- `qrCodeOverrides` parameter on `renderIgniterToPdf()`/`renderProjectToPdf()`,
  letting a host application supply a QR code's payload at issue time (e.g. a
  per-recipient verification URL) - the only way to give a project's
  "Authentication link" QR element any data, and also usable to override an
  ordinary "Custom value" QR element.
- A standalone `tests/Unit` PHPUnit suite (`vendor/bin/phpunit`, no Laravel
  app required) covering every class that doesn't need a booted container -
  `phpunit/phpunit` was previously a declared dev dependency with nothing to
  run. See the README's "Testing" section for what's covered here versus in
  the consuming Laravel app's integration suite.
- `CertificateProject::$dateFormat`, parsed from a project's new
  `date_format` key (a Dart/ICU-style pattern like `'MMM d, yyyy'`, the
  same syntax Design Studio's new date-format picker uses) - defaults to
  `'MMM d, yyyy'` for every existing `.igniter` file, which lacks the key
  entirely. This package doesn't format any recipient value itself
  (`RecipientMerge` stays a pure pass-through of whatever string a caller
  supplies), so pair it with the new `Support\DateFormatting` helper below
  when building a recipient map from a real date rather than an
  already-formatted string.
- `Support\DateFormatting`, an opt-in helper translating a project's
  `dateFormat` into PHP's `date()` syntax
  (`DateFormatting::format($issuedAt, $project->dateFormat)`), so a host
  application's own date values can stay visually consistent with
  whatever format the certificate's designer picked, instead of each
  caller defaulting to its own format (an HTML `<input type="date">`
  always produces ISO `yyyy-MM-dd`, for instance) and producing a
  certificate where the same field looks different depending on how it
  was issued.

### Changed

- Extracted `certificate.blade.php`'s text- and image-element CSS computation
  (bold detection, shadow/gradient/decoration CSS, content-alignment CSS,
  bottom-border CSS, image `contain`/`fill` fitting, mask CSS) out of inline
  `@php` blocks into two new internal classes, `Support\TextElementStyle` and
  `Support\ImageElementLayout`, mirroring the existing `ShapeRenderer`
  pattern. This is a pure move, not a rewrite: the formulas are unchanged and
  output was verified byte-for-byte identical, both against a fixture
  covering every branch and against a real production `.igniter` file,
  before and after. Purely internal - no public API change.

### Removed

- `CertificateProject::topLevelElements()` - unused internally (superseded
  by `GroupComposer::resolve()`, which does its own group-child filtering)
  and not referenced anywhere in this repository's own usage. It was public
  but undocumented in this README's "Public API" section; removing it is
  technically a breaking change for any external caller that had started
  depending on it directly, hence the major-version-eligible classification
  even though nothing in this monorepo used it.

### Fixed

- `Certigniter` facade's `@method` docblock was missing the `$qrCodeOverrides`
  parameter added above, so IDE autocompletion for facade calls omitted it.
- A text element's `bottomBorderEnabled` underline (and the text itself, when
  centered) rendered slightly off-center in the PDF versus the Design
  Studio/bulk-issuance pipeline. Root cause: dompdf mis-centers a `display:
  table` box whose own `left: 50%; transform: translateX(-50%)` centering is
  combined with `padding-left`/`padding-right` on that same box - the
  padding is counted toward the shrink-to-fit width used to position the
  box, but isn't painted as an inset, so the box lands off-center by
  roughly the padding amount. The underline is now a sibling
  `position: absolute` box with negative `left`/`right` offsets instead of
  padding on the centered box, which doesn't participate in that box's
  shrink-to-fit measurement at all and sidesteps the bug. Verified against
  real dompdf output (rasterized, not just reasoned about) both in isolation
  and against a real production `.igniter` file.
- A text element's vertical centering drifted from the Design
  Studio/Flutter-generated PDF for fonts with an unusual ascent/descent
  split - most visibly blackletter and script display faces used for
  titles/names, off by several points at large sizes. Root cause:
  `baselineCorrectionPt` (compensating for dompdf's CSS line-box centering
  differing from `package:pdf`'s tight-ink-bbox centering) was a single flat
  fraction of font size, implicitly tuned for fonts with an ascent/descent
  split resembling Roboto's - it doesn't hold for a font whose split differs
  meaningfully. `FontRegistrar` now parses each registered font's real
  ascent/descent from its own TTF `hhea` table and scales the correction
  accordingly. Verified against a real production `.igniter` file (a
  Strathmore CIPIT template using Old English Text MT for its title and
  recipient-name elements): measured via `pdftotext -bbox` against the
  Flutter-rendered reference, the title's vertical offset dropped from
  5.46pt to 1.26pt and the recipient name's from 3.76pt to 0.87pt, with
  zero change to already-correct body text in a standard font (Roboto/
  AlbertSans).
- A text element's `bottomBorderEnabled` underline could sit several points
  below the text even with `bottomBorderGap: 0`, most visibly on a large
  display font. Root cause: the underline was a sibling `<div>` inside
  `.text-content` (a `display:table` box, for the shrink-to-fit centering
  described above) - dompdf wraps *any* other child of a table box in its
  own anonymous table row regardless of that child's `position:absolute`
  status, which roughly doubles the table's rendered height and pushes a
  sibling underline far below the text (confirmed by rendering an isolated
  reproduction with a tinted background and measuring its real pixel
  height, not just reasoned about). The underline is now `padding-left`/
  `padding-right`/`padding-bottom`/`border-bottom` on an inline `<span>`
  that wraps the text itself instead of a separate sibling element, which
  sidesteps the anonymous-row bug entirely and - unlike padding on
  `.text-content` itself - doesn't feed into its shrink-to-fit *width*
  measurement, so the horizontal-centering bug fixed above doesn't
  reappear either. Verified against the same real production `.igniter`
  file.

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

- Convert Certigniter's 96-DPI canvas typography to 72-DPI PDF points so text
  matches the Design Studio instead of rendering approximately 1.33× larger.
- Normalize asymmetric rounded-rectangle corners like Flutter.
- Preserve image fit and alignment in Dompdf output.
- Compose group transforms and opacity consistently.
