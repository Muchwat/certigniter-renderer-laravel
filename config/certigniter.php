<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Encryption key
    |--------------------------------------------------------------------------
    |
    | The manifest inside every .igniter file is AES-256-CBC encrypted with
    | a key shared by every party that creates or opens those files. It is a
    | shared secret, not a per-application secret like APP_KEY, and must
    | match the key the files were written with exactly, byte for byte (32
    | bytes for AES-256).
    |
    | Always set CERTIGNITER_ENCRYPTION_KEY in production. The fallback below
    | only exists so the package works out of the box with files encrypted
    | under Certigniter's default key.
    |
    */
    'encryption_key' => env('CERTIGNITER_ENCRYPTION_KEY', 'nA9bC5eD1fG7hJ3kL2pQ8rT6vW0yZ4xU'),

    /*
    |--------------------------------------------------------------------------
    | Group rotation/opacity composition
    |--------------------------------------------------------------------------
    |
    | A grouped element's rotation and opacity are stored relative to its
    | parent group, and must be composed with the group's own rotation and
    | opacity to get the element's true appearance on the page. Leave this
    | enabled unless you must reproduce, exactly, PDFs issued by a tool that
    | rendered groups without composing them.
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
