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
    | Bundled fonts
    |--------------------------------------------------------------------------
    |
    | The font families Certigniter bundles as real .ttf files rather than
    | relying on whatever is installed on the machine doing the rendering.
    | Because both sides ship them, a project does not need to carry their
    | bytes, so Certigniter leaves them out of the file by default.
    |
    | Keeping this list in step with Certigniter's own bundled families
    | matters: the moment Certigniter adds one and stops embedding it, a
    | server without the same .ttf here renders it in 'fallback_font'
    | instead - silently, since the file is otherwise perfectly valid.
    |
    | Any other fontFamily falls back to 'fallback_font' below, unless the
    | project carries that family's bytes in its own `embedded_fonts` (see
    | FontRegistrar).
    |
    | Keys are the exact `fontFamily` strings Certigniter stores in a
    | project's JSON. Paths are relative to this package's resources/fonts
    | directory.
    |
    */
    'fonts' => [
        'Playfair Display' => [
            'normal' => 'PlayfairDisplay/PlayfairDisplay-Regular.ttf',
            'bold' => 'PlayfairDisplay/PlayfairDisplay-Bold.ttf',
        ],
        'Cormorant Garamond' => [
            'normal' => 'CormorantGaramond/CormorantGaramond-Regular.ttf',
            'bold' => 'CormorantGaramond/CormorantGaramond-Bold.ttf',
        ],
        'Cinzel' => [
            'normal' => 'Cinzel/Cinzel-Regular.ttf',
            'bold' => 'Cinzel/Cinzel-Bold.ttf',
        ],
        'Roboto' => [
            'normal' => 'Roboto/Roboto-Regular.ttf',
            'bold' => 'Roboto/Roboto-Bold.ttf',
        ],
        'Montserrat' => [
            'normal' => 'Montserrat/Montserrat-Regular.ttf',
            'bold' => 'Montserrat/Montserrat-Bold.ttf',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback font
    |--------------------------------------------------------------------------
    |
    | Used for any fontFamily that is neither listed above nor carried as
    | font bytes inside the .igniter file itself. In practice that means a
    | system font from a project saved before Certigniter embedded those -
    | the file stores only the family *name*, which a server has no way to
    | resolve. Matches Certigniter's own fallback
    | (assets/fonts/Inter/Inter-VariableFont.ttf).
    |
    */
    'fallback_font' => 'Inter/Inter-VariableFont.ttf',

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
