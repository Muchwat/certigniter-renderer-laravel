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
    | These are the only font families Certigniter itself guarantees look
    | identical wherever a certificate is exported, because it bundles their
    | actual .ttf files rather than relying on whatever's installed on the
    | machine doing the rendering (see Certigniter's
    | lib/services/batch_pdf_generator.dart). Any other fontFamily value
    | found in a project falls back to 'fallback' below.
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
    | Used for any fontFamily not listed above (e.g. a system font the
    | Certigniter desktop app picked up locally, which this server has no
    | way to reproduce faithfully - the .igniter file only ever stores the
    | family *name*, never the font bytes). Matches Certigniter's own
    | fallback (assets/fonts/Inter/Inter-VariableFont.ttf).
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
];
