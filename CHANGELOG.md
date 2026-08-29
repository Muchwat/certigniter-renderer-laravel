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
