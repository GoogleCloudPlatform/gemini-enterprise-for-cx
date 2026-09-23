#!/usr/bin/env python3
# Copyright 2026 Google LLC
#
# SPDX-License-Identifier: GPL-3.0-or-later
"""Synchronizes assets/css/theme.css with the upstream gstatic stylesheet.

Checks the SHA-256 of https://www.gstatic.com/gecx/chat-widget/theme.css against
the `Upstream-SHA256:` header recorded in `assets/css/theme.css`. When the
upstream hash is unchanged, exits 0 without touching the working tree.

When upstream changes, strips sourceMappingURL directives and the global
`.grecaptcha-badge` rule, formats the CSS as unminified human-readable source,
re-applies the local `body.gecx-chat-open` selector and `prefers-reduced-motion`
accessibility rules, and writes the updated GPL-3.0 header with the new
`Upstream-SHA256` digest.
"""

import hashlib
from pathlib import Path
import re
import sys
import urllib.request

UPSTREAM_URL = 'https://www.gstatic.com/gecx/chat-widget/theme.css'
THEME_CSS_PATH = Path(__file__).resolve().parents[2] / 'assets/css/theme.css'

HEADER_TEMPLATE = """/**
 * Copyright 2026 Google LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Gemini Enterprise for CX chat widget theme.
 *
 * Layout and default theme variables for the <chat-messenger> custom element
 * rendered on the storefront. Derived from the upstream Google Cloud
 * Customer Engagement Suite chat-messenger stylesheets (concatenating
 * chat-messenger-layout.css and chat-messenger-default.css, published to
 * https://www.gstatic.com/gecx/chat-widget/theme.css) and maintained directly
 * as unminified, human-readable source in this repository so that no external
 * stylesheet or source map is fetched at runtime.
 *
 * Local modifications applied over upstream:
 *   1. Expand top-level `body` transition rule with `body.gecx-chat-open` and
 *      `body:has(chat-messenger.slide-in:not(.messenger-hidden))`.
 *   2. Omit upstream `.grecaptcha-badge { visibility: hidden; }` so the plugin
 *      does not hide a merchant store's own reCAPTCHA badge.
 *   3. Append `@media (prefers-reduced-motion: reduce)` accessibility rules.
 *
 * Upstream-SHA256: __UPSTREAM_SHA256__
 */

"""

REDUCED_MOTION_BLOCK = """@media (prefers-reduced-motion: reduce) {
  body,
  body.gecx-chat-open,
  body:has(chat-messenger.slide-in:not(.messenger-hidden)) {
    -webkit-transition: none;
    transition: none;
  }
  chat-messenger.slide-in,
  chat-messenger.slide-over,
  chat-messenger.slide-in.messenger-hidden,
  chat-messenger.slide-over.messenger-hidden {
    --chat-messenger-internal-chat-wrapper-transition: none;
  }
}
"""


def format_upstream_css(css: str) -> str:
  """Formats minified upstream CSS while applying local WordPress overrides."""
  css = re.sub(r'/\*#\s*sourceMappingURL=.*?\*/\s*', '', css).strip()
  css = re.sub(r'\.grecaptcha-badge\{visibility:hidden\}\s*$', '', css)
  css = re.sub(
      r'^body\{',
      'body,\nbody.gecx-chat-open,\n'
      'body:has(chat-messenger.slide-in:not(.messenger-hidden)){',
      css,
  )

  out = []
  depth = 0
  i = 0
  n = len(css)
  while i < n:
    ch = css[i]
    if ch == '{':
      out.append(' {\n')
      depth += 1
      out.append('  ' * depth)
      i += 1
    elif ch == ';':
      out.append(';\n')
      if i + 1 < n and css[i + 1] == '}':
        depth -= 1
        out.append('  ' * depth + '}\n')
        i += 2
        if depth > 0 and i < n and css[i] != '}':
          out.append('  ' * depth)
      else:
        out.append('  ' * depth)
        i += 1
    elif ch == '}':
      if not (out and out[-1].endswith('\n')):
        out.append(';\n')
      depth -= 1
      out.append('  ' * depth + '}\n')
      i += 1
      if depth > 0 and i < n and css[i] != '}':
        out.append('  ' * depth)
    elif ch == ':' and depth > 0:
      rest = css[i + 1 :]
      next_brace = rest.find('{')
      next_semi = rest.find(';')
      next_close = rest.find('}')
      candidates = [x for x in (next_semi, next_close) if x != -1]
      decl_end = min(candidates) if candidates else 10**9
      if next_brace != -1 and next_brace < decl_end:
        out.append(':')
      else:
        out.append(': ')
      i += 1
    else:
      out.append(ch)
      i += 1

  return ''.join(out) + REDUCED_MOTION_BLOCK


def main() -> int:
  check_only = '--check' in sys.argv
  req = urllib.request.Request(
      UPSTREAM_URL, headers={'User-Agent': 'gecx-theme-sync/1.0'}
  )
  with urllib.request.urlopen(req, timeout=30) as resp:
    raw = resp.read()

  upstream_sha = hashlib.sha256(raw).hexdigest()
  current = THEME_CSS_PATH.read_text(encoding='utf-8')
  match = re.search(r'^ \* Upstream-SHA256: ([0-9a-f]{64})$', current, re.M)
  recorded_sha = match.group(1) if match else ''

  if upstream_sha == recorded_sha:
    print(f'Up to date with upstream ({upstream_sha}).')
    return 0

  print(f'Upstream drift detected: recorded={recorded_sha or "none"} -> upstream={upstream_sha}')
  if check_only:
    return 1

  header = HEADER_TEMPLATE.replace('__UPSTREAM_SHA256__', upstream_sha)
  body = format_upstream_css(raw.decode('utf-8'))
  THEME_CSS_PATH.write_text(header + body, encoding='utf-8')
  print(f'Updated {THEME_CSS_PATH} for upstream SHA-256 {upstream_sha}.')
  return 0


if __name__ == '__main__':
  sys.exit(main())
