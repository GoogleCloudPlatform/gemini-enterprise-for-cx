<?php
/**
 * Fidelity tests for the sanitize_text_field() stub in tests/bootstrap.php.
 *
 * The stub used to be trim( strip_tags() ). That is a reasonable-looking
 * approximation and it is wrong in a way that matters: it omits the
 * percent-octet stripping loop, so a value like "cs_1234%ab5678" survived
 * sanitization intact under test and was mangled to "cs_12345678" in
 * production. Every test that asserted "this malformed input is rejected"
 * passed for the wrong reason.
 *
 * These tests pin the stub to core's actual behaviour. If someone simplifies
 * it again, this fails rather than the security assertions elsewhere silently
 * becoming meaningless.
 *
 * Expectations are taken from _sanitize_text_fields() in
 * wp-includes/formatting.php.
 *
 * @package Gemini_Enterprise_For_CX
 */

use PHPUnit\Framework\TestCase;

class SanitizeStubTest extends TestCase {

    /**
     * @dataProvider sanitize_text_field_cases
     */
    public function test_sanitize_text_field_matches_core( string $input, string $expected, string $why ): void {
        $this->assertSame( $expected, sanitize_text_field( $input ), $why );
    }

    public function sanitize_text_field_cases(): array {
        return [
            'a clean value is untouched' => [
                'cs_1234567890abcdef',
                'cs_1234567890abcdef',
                'Sanitization must not alter a value that is already well-formed.',
            ],
            'percent-octets are removed' => [
                'cs_1234%ab5678',
                'cs_12345678',
                'This is the step the old stub omitted. It is what turns a malformed secret into one an allowlist accepts.',
            ],
            'every percent-octet is removed, not just the first' => [
                '%3Cscript%3E',
                'script',
                'Core loops until no /%[a-f0-9]{2}/i remains.',
            ],
            'a lone percent sign survives' => [
                '100% cotton',
                '100% cotton',
                'Only two-hex-digit sequences are percent-octets.',
            ],
            'whitespace left by octet removal is collapsed' => [
                'a %20 b',
                'a b',
                'Core re-collapses spaces only when it removed an octet.',
            ],
            'newlines and tabs collapse to single spaces' => [
                "  spaced\tvalue\nhere  ",
                'spaced value here',
                'sanitize_text_field() passes $keep_newlines = false.',
            ],
            'script bodies are removed entirely' => [
                'a<script>alert(1)</script>b',
                'ab',
                'strip_tags() alone would leave "alert(1)" behind, which is why the old stub made malformed input look invalid when production would have accepted it.',
            ],
            'style bodies are removed entirely' => [
                'a<style>i{}</style>b',
                'ab',
                'wp_strip_all_tags() removes script and style content, not just their tags.',
            ],
            'a stray less-than is escaped, not dropped' => [
                'a<b',
                'a&lt;b',
                'wp_pre_kses_less_than() escapes a "<" that does not open a tag.',
            ],
            'ordinary tags are stripped but their text is kept' => [
                'a<em>b</em>c',
                'abc',
                'strip_tags() behaviour for non-script, non-style tags.',
            ],
            'invalid UTF-8 yields an empty string' => [
                "\xC3\x28",
                '',
                'wp_check_invalid_utf8() returns "" rather than attempting a repair.',
            ],
            'an empty string stays empty' => [
                '',
                '',
                '',
            ],
        ];
    }

    public function test_sanitize_textarea_field_keeps_newlines(): void {
        $this->assertSame(
            "line one\nline two",
            sanitize_textarea_field( "line one\nline two" ),
            'sanitize_textarea_field() passes $keep_newlines = true, so only the surrounding whitespace goes.'
        );
    }

    public function test_sanitize_textarea_field_still_strips_percent_octets(): void {
        $this->assertSame(
            "line one\nlinetwo",
            sanitize_textarea_field( "line one\nline%20two" ),
            'Keeping newlines does not exempt a value from the percent-octet loop.'
        );
    }
}
