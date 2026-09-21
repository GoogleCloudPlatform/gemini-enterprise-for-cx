<?php
/**
 * Translation catalogue directory.
 *
 * The plugin declares `Domain Path: /languages` and points
 * wp_set_script_translations() at this directory, so it has to exist in the
 * release archive even before any .mo/.l10n.php or .json catalogue is bundled.
 * Git cannot track an empty directory and WordPress.org's Plugin Check flags a
 * Domain Path that resolves to nothing, so this file keeps the directory real.
 *
 * It also follows the WordPress convention of placing an empty index.php in
 * asset directories, which stops servers with directory indexes enabled from
 * listing the catalogue files.
 *
 * @package Gemini_Enterprise_For_CX
 */

// Silence is golden.
