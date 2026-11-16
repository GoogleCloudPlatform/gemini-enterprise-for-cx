<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package GECX
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\Tests\Integration;

use Google\Gemini_Enterprise_For_CX\Storefront;

/**
 * Exposes the protected URL resolver so asset origins can be asserted.
 */
class StorefrontUrlProbe extends Storefront {

	/**
	 * Resolves widget URLs.
	 *
	 * @return array{script: string, style: string}
	 */
	public function widget_urls(): array {
		return $this->resolve_widget_urls();
	}
}
