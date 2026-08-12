# Changelog

All notable changes to this package are documented here.

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
