<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Encryption key
    |--------------------------------------------------------------------------
    |
    | .igniter files are AES-256-CBC encrypted with a key shared across every
    | Certigniter installation (desktop app + whichever servers render its
    | output). This is a *shared secret*, not a per-app secret like APP_KEY -
    | every party that needs to open .igniter files uses the same value, so
    | it must match the key configured in the Certigniter desktop app
    | (lib/config.dart's `encryptionKey`) exactly, byte for byte.
    |
    | Set CERTIGNITER_ENCRYPTION_KEY in your .env - don't rely on the
    | fallback below in production, it's only here so the package works out
    | of the box against files produced by an unmodified Certigniter build.
    |
    */
    'encryption_key' => env('CERTIGNITER_ENCRYPTION_KEY', 'nA9bC5eD1fG7hJ3kL2pQ8rT6vW0yZ4xU'),

    /*
    |--------------------------------------------------------------------------
    | Fonts
    |--------------------------------------------------------------------------
    |
    | There is nothing to configure here any more, and that is the point.
    |
    | This package used to ship .ttf files for the families Certigniter
    | bundles, and Certigniter left their bytes out of exported files on the
    | assumption that the rendering server had the same copies. That made a
    | file's fidelity depend on the consumer's inventory: the moment the two
    | lists drifted, a project rendered in the fallback font instead -
    | silently, since the file itself was perfectly valid.
    |
    | Certigniter now embeds every font a project uses into the .igniter,
    | where the ZIP container stores it as compressed binary rather than
    | base64 inside the encrypted manifest. So this package ships no fonts,
    | installs ~2.8 MB lighter, and renders a family if and only if the file
    | carries it.
    |
    | A family a project names but carries no bytes for renders in Dompdf's
    | own built-in DejaVu Sans (FontRegistrar::FALLBACK_FAMILY). To render
    | such a project faithfully, re-save it from Certigniter so its fonts
    | travel with it.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Group rotation/opacity composition
    |--------------------------------------------------------------------------
    |
    | A grouped element's rotation/opacity in the .igniter file is relative
    | to its parent group and must be composed with the group's own
    | rotation/opacity to get its true on-canvas appearance (see the
    | package README's "Group composition" section for the exact math and
    | why - Certigniter's own bulk-issuance pipeline has a known bug where
    | it skips this, so files exist in the wild that were exported without
    | it). Leave this true unless you specifically need byte-parity with
    | already-issued PDFs from that buggy pipeline.
    |
    */
    'compose_group_transforms' => true,

    /*
    |--------------------------------------------------------------------------
    | Ghostscript binary
    |--------------------------------------------------------------------------
    |
    | CertificateRenderer::capture() shells out to Ghostscript directly
    | to rasterize a rendered PDF to PNG (no `imagick` PHP extension
    | involved). Set CERTIGNITER_GHOSTSCRIPT_BINARY if `gs` isn't on PATH
    | or you need a specific install (e.g. an absolute path).
    |
    */
    'ghostscript_binary' => env('CERTIGNITER_GHOSTSCRIPT_BINARY', 'gs'),
];
