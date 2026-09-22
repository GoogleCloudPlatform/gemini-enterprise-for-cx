<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Asserts that every place the plugin version is written agrees.
 *
 * WordPress.org serves whichever version the "Stable tag" in readme.txt names,
 * while WordPress itself reports the "Version" plugin header, so a mismatch
 * ships one version and advertises another. This runs in CI.
 *
 * Usage: php .github/scripts/check-version.php
 *
 * @package Gemini_Enterprise_For_CX
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );

/**
 * Reads the first capture group of a pattern out of a file.
 *
 * @param string $path    Absolute file path.
 * @param string $pattern Regular expression with one capture group.
 * @param string $label   Human readable name used in error messages.
 * @return string Captured value.
 */
function gecx_capture( string $path, string $pattern, string $label ): string {
    $contents = file_get_contents( $path );
    if ( false === $contents ) {
        fwrite( STDERR, sprintf( "Could not read %s\n", $path ) );
        exit( 1 );
    }

    if ( 1 !== preg_match( $pattern, $contents, $matches ) ) {
        fwrite( STDERR, sprintf( "Could not find %s in %s\n", $label, $path ) );
        exit( 1 );
    }

    return trim( $matches[1] );
}

$versions = [
    'gecx-agent.php "Version" header' => gecx_capture(
        $root . '/gecx-agent.php',
        '/^\s*\*\s*Version:\s*(\S+)\s*$/m',
        'the Version header'
    ),
    'gecx-agent.php GECX_VERSION'     => gecx_capture(
        $root . '/gecx-agent.php',
        "/define\(\s*'GECX_VERSION',\s*'([^']+)'\s*\)/",
        'the GECX_VERSION constant'
    ),
    'readme.txt "Stable tag"'         => gecx_capture(
        $root . '/readme.txt',
        '/^Stable tag:\s*(\S+)\s*$/m',
        'the Stable tag'
    ),
    'changelog.txt newest entry'      => gecx_capture(
        $root . '/changelog.txt',
        '/^\d{4}-\d{2}-\d{2} - version (\S+)\s*$/m',
        'the newest changelog entry'
    ),
];

$unique = array_unique( array_values( $versions ) );

foreach ( $versions as $label => $version ) {
    printf( "%-35s %s\n", $label, $version );
}

if ( count( $unique ) > 1 ) {
    fwrite( STDERR, "\nVersion mismatch. All four must name the same release.\n" );
    exit( 1 );
}

printf( "\nAll version declarations agree on %s.\n", (string) reset( $unique ) );
exit( 0 );
