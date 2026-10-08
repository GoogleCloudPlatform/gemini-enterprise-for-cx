# How to Become a Contributor and Submit Code

## Contributor License Agreements

We'd love to accept your patches! Before we can take them, we have to jump a couple of legal hurdles.

Please fill out either the individual or corporate Contributor License Agreement (CLA):

* **Individual CLA:** https://cla.developers.google.com/about/google-individual
* **Corporate CLA:** https://cla.developers.google.com/about/google-corporate

Once we receive your signed CLA, we'll be able to review and accept pull requests.

## Contributing Workflow

1. Submit an issue describing your proposed change or bug fix.
2. Fork the repository and develop your changes on a feature branch.
3. Ensure your code follows WordPress and WooCommerce coding standards.
4. Verify that existing tests pass.
5. Submit a pull request referencing the original issue.

## Local Development

```bash
composer install     # installs PHPUnit, PHPCS, WPCS and PHPCompatibility
composer test        # PHPUnit, the same suite CI runs
composer lint        # PHPCS, errors and warnings; this is what CI gates on
composer lint:all    # PHPCS including warnings
composer lint:fix    # PHPCBF, auto-fixes what it can
```

Pull requests additionally run [Plugin Check](https://wordpress.org/plugins/plugin-check/),
which enforces the WordPress.org plugin directory requirements, and a check that
the version in `gecx-agent.php`, `GECX_VERSION`, the `Stable tag` in `readme.txt`
and the newest `changelog.txt` entry all name the same release.

Plugin Check runs in strict mode, so one of its warnings fails the build exactly
as an error does. That is deliberate: its warnings are directory review findings,
and the cheapest time to deal with one is before submission rather than after a
reviewer rejects the release.

`readme.txt` is the only supported place to declare `Tested up to`. Do not add it
back to the plugin headers.

If you change the URL of a remote asset or service the plugin contacts — the
chat widget SDK on `gstatic.com`, or the console on `gecx.cloud.google.com` —
update the "3rd Party Services" and FAQ disclosures in `readme.txt` in the same
pull request. The WordPress.org directory requires every remote request to be
disclosed, and an out-of-date disclosure is grounds for removal.

## Assets and Stylesheets

`assets/css/theme.css` provides the default storefront theme and layout for the `<chat-messenger>` custom element so that no stylesheet is loaded from an external origin at runtime.

* **Upstream Provenance:** Built from the Google Customer Engagement Suite chat-messenger bundle, concatenated from `chat-messenger-layout.css` and `chat-messenger-default.css` (published to `https://www.gstatic.com/gecx/chat-widget/theme.css`). The SHA-256 of the reconciled upstream asset is recorded in the `Upstream-SHA256:` header of `assets/css/theme.css`.
* **Automated Sync:** `.github/workflows/sync-theme-css.yml` runs `.github/scripts/sync-theme-css.py` daily to compare the upstream SHA-256 against `Upstream-SHA256:`. When the digest changes, it regenerates `assets/css/theme.css` (preserving the GPL-3.0 header, unminified formatting, stripped `sourceMappingURL` directives, and local WordPress rules) and opens a pull request.
* **Manual Refresh:** To check for or apply upstream changes manually:
  ```bash
  python3 .github/scripts/sync-theme-css.py --check   # exit 0 if up to date, 1 if drifted
  python3 .github/scripts/sync-theme-css.py           # regenerate assets/css/theme.css
  ```

## Releases

Version numbers follow [Semantic Versioning](https://semver.org/) against the
plugin's documented interface: hooks, shortcodes, blocks, REST routes,
JavaScript events and globals, and saved settings. Bump the major version for a
release that breaks any of those, the minor version for new features or notable
internal changes such as a new archive layout, and the patch version for fixes
only.

Releases are built by `.github/workflows/release.yml` from a `v*` tag. The
archive is produced from the workspace using `.distignore` and includes the
production Composer classmap autoloader (`vendor/autoload.php`); never hand-zip
the working tree. The workflow refuses to publish if the tag and the plugin
version disagree, if the four version declarations disagree, or if development
files reach the archive.

Pushing a `v*` tag (`git tag v0.3.12 && git push origin v0.3.12`) builds the
zip, attaches it to a GitHub release, and deploys it to WooCommerce.com and to
the WordPress.org Plugin Directory (trunk, `tags/<version>` and the
`.wordpress-org/` listing assets). A WordPress.org commit is the one step that
cannot be taken back: stores with auto-updates install it within hours.

Only a tag deploys. A manual run packages whatever "Use workflow from" names
and does a WordPress.org dry run; run it from a `v*` tag to redeploy that tag,
and untick `wordpress_org_dry_run` to let it commit to WordPress.org.

The deploy jobs run in two GitHub environments, which hold the credentials and
keep a single compromised account from publishing to every store.
Configure both under Settings > Environments:

| Environment     | Secrets                                      | Protection                                                                 |
|-----------------|----------------------------------------------|----------------------------------------------------------------------------|
| `wordpress-org` | `SVN_USERNAME`, `SVN_PASSWORD`               | Required reviewers with "Prevent self-review"; deployment tags `v*` only |
| `woocommerce`   | `WOO_DEPLOY_USER`, `WOO_DEPLOY_APP_PASSWORD` | Required reviewers with "Prevent self-review"; deployment tags `v*` only |

Keep those secrets out of the repository-level secrets: any workflow on any
branch can read repository secrets, so a write-access account could read them
without going through a reviewer. Add a tag ruleset that limits creating `v*`
tags to release managers.

After a release, confirm `Stable tag` in `readme.txt` names the tag you just
created. WordPress.org serves whichever release the stable tag names.
