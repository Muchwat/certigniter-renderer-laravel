# Changelog

All notable changes to this package are documented here.

## [Unreleased]

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
