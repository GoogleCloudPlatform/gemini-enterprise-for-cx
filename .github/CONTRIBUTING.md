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

* **Upstream Provenance:** Built from the Google Customer Engagement Suite chat-messenger bundle, concatenated from `chat-messenger-layout.css` and `chat-messenger-default.css` (published to `https://www.gstatic.com/gecx/chat-widget/theme.css`).
* **Automated Sync:** `.github/workflows/sync-theme-css.yml` polls the deployed production stylesheet daily and automatically opens a pull request when upstream changes are detected.
* **Manual Refresh:** To check for or apply upstream changes manually:
  ```bash
  curl -fsSL https://www.gstatic.com/gecx/chat-widget/theme.css -o /tmp/upstream-theme.css
  diff -u assets/css/theme.css /tmp/upstream-theme.css
  ```
  Note that `assets/css/theme.css` retains the GPL license header and unminified formatting for WordPress.org compliance.

## Releases

Releases are built by `.github/workflows/release.yml` from a `v*` tag. The
archive is produced with `git archive`, so the `export-ignore` rules in
`.gitattributes` decide what ships; never hand-zip the working tree. The
workflow refuses to publish if the tag and the plugin version disagree, if the
four version declarations disagree, or if development files reach the archive.

Publishing to WordPress.org is a manual step, on purpose — it is the one action
that is not reversible:

1. Tag the release (`git tag v0.3.12 && git push origin v0.3.12`) and let the
   workflow build and attach the zip.
2. Download that zip. It is the exact artifact to publish; do not rebuild it.
3. Copy its contents into the `trunk/` directory of the plugin's SVN checkout,
   `svn cp trunk tags/<version>`, and `svn ci`.
4. Copy WordPress.org directory assets (banners, icons, screenshots) from
   `.wordpress-org/` into the top-level `assets/` directory of the SVN checkout
   (alongside `trunk/` and `tags/`, not inside `trunk/`), and commit them with
   `svn ci assets`.
5. Confirm `Stable tag` in `trunk/readme.txt` names the tag you just created.
   WordPress.org serves whichever release the stable tag names, regardless of
   what else is in SVN.
