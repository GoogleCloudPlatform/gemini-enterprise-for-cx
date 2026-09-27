#!/usr/bin/env bash
# Copyright 2026 Google LLC
#
# SPDX-License-Identifier: GPL-3.0-or-later
#
# Runs the themes Playwright project against the plugin on real themes in
# wp-env, one theme at a time.
#
#   bash tests/e2e/bin/run-theme-matrix.sh              # every theme below
#   bash tests/e2e/bin/run-theme-matrix.sh astra kadence
#
# WP_ENV_PORT (default 8898) picks the port, so the environment can run
# beside other WordPress installs. Needs Docker.
#
# To use a WordPress you already run instead of wp-env, set WP_BASE_URL and
# GECX_E2E_WP_CLI to a command that runs wp-cli against it, for example
#   GECX_E2E_WP_CLI="docker exec -u 33 my-wordpress wp"
# The plugin and WooCommerce must be active there; missing themes are
# installed.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
ENV_DIR="${ROOT}/tests/e2e/env"
PORT="${WP_ENV_PORT:-8898}"
WP_ENV="${ROOT}/node_modules/.bin/wp-env"
PLAYWRIGHT="${ROOT}/node_modules/.bin/playwright"

# Free themes from WordPress.org covering the placement patterns that
# differ: a hamburger at 768px (Storefront), at 921px (Astra), at 1024px
# (Kadence), a link-based toggle with separate desktop and mobile menus
# (OceanWP), and block navigation (Twenty Twenty-Five, bundled with core).
ALL_THEMES=(storefront astra kadence oceanwp twentytwentyfive)
if [[ $# -gt 0 ]]; then
  THEMES=("$@")
else
  THEMES=("${ALL_THEMES[@]}")
fi

if [[ -n "${GECX_E2E_WP_CLI:-}" ]]; then
  BASE_URL="${WP_BASE_URL:?WP_BASE_URL must be set with GECX_E2E_WP_CLI}"
  # Word splitting is intended: the variable holds a command and its arguments.
  # shellcheck disable=SC2086
  wp() { ${GECX_E2E_WP_CLI} "$@"; }
  for theme in "${THEMES[@]}"; do
    wp theme is-installed "${theme}" || wp theme install "${theme}"
  done
else
BASE_URL="http://localhost:${PORT}"
mkdir -p "${ENV_DIR}"
# WooCommerce is listed before the plugin so it is active first; see
# .github/workflows/wp-env.yml for why that order matters.
cat > "${ENV_DIR}/.wp-env.json" <<JSON
{
  "port": ${PORT},
  "testsEnvironment": false,
  "plugins": [
    "https://downloads.wordpress.org/plugin/woocommerce.zip",
    "../../.."
  ],
  "themes": [
    "https://downloads.wordpress.org/theme/storefront.zip",
    "https://downloads.wordpress.org/theme/astra.zip",
    "https://downloads.wordpress.org/theme/kadence.zip",
    "https://downloads.wordpress.org/theme/oceanwp.zip"
  ],
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_DISPLAY": false
  }
}
JSON

wp() {
  (cd "${ENV_DIR}" && "${WP_ENV}" run cli --env-cwd=/var/www/html wp "$@")
}

(cd "${ENV_DIR}" && "${WP_ENV}" start)
fi

wp rewrite structure '/%postname%/' --hard
wp option update woocommerce_coming_soon no
wp option update gecx_agent_name 'projects/123/locations/global/agents/e2e'
wp option update gecx_agent_enabled 1
wp option update gecx_button_placement nav_menu
wp option update gecx_pdp_prompts_enabled 1

if [[ -z "$(wp post list --post_type=product --name=e2e-mug --field=ID 2>/dev/null | tr -d '[:space:]')" ]]; then
  wp wc product create --name='E2E Mug' --slug=e2e-mug --type=simple --regular_price=12 --user=1 --porcelain
fi

if ! wp menu list --format=csv --fields=slug | grep -qx 'e2e-main'; then
  wp menu create 'E2E Main'
  for item in Shop Men Women Sale About; do
    wp menu item add-custom e2e-main "${item}" "/?e2e=${item}"
  done
fi

failed=()
for theme in "${THEMES[@]}"; do
  echo "::group::${theme}"
  wp theme activate "${theme}"
  # Give the menu to the header and mobile locations, as a merchant would.
  for location in $(wp menu location list --format=csv | tail -n +2 | cut -d, -f1); do
    if [[ "${location}" =~ (primary|main|menu[_-]1|header|mobile|handheld|expanded) && ! "${location}" =~ (footer|social|top|secondary) ]]; then
      wp menu location assign e2e-main "${location}"
    fi
  done
  if ! GECX_THEME="${theme}" WP_BASE_URL="${BASE_URL}" "${PLAYWRIGHT}" test --project=themes; then
    failed+=("${theme}")
  fi
  echo "::endgroup::"
done

if [[ ${#failed[@]} -gt 0 ]]; then
  echo "Failed on: ${failed[*]}"
  exit 1
fi
echo "Passed on: ${THEMES[*]}"
