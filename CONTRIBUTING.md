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
composer lint        # PHPCS, error severity only; this is what CI gates on
composer lint:all    # PHPCS including warnings
composer lint:fix    # PHPCBF, auto-fixes what it can
```

Pull requests additionally run [Plugin Check](https://wordpress.org/plugins/plugin-check/),
which enforces the WordPress.org plugin directory requirements, and a check that
the version in `gecx-agent.php`, `GECX_VERSION`, the `Stable tag` in `readme.txt`
and the newest `changelog.txt` entry all name the same release.

`readme.txt` is the only supported place to declare `Tested up to`. Do not add it
back to the plugin headers.

If you change the URL of a remote asset or service the plugin contacts — the
chat widget SDK on `gstatic.com`, or the console on `gecx.cloud.google.com` —
update the "3rd Party Services" and FAQ disclosures in `readme.txt` in the same
pull request. The WordPress.org directory requires every remote request to be
disclosed, and an out-of-date disclosure is grounds for removal.

## Releases

Releases are built by `.github/workflows/release.yml` from a `v*` tag. The
archive is produced with `git archive`, so the `export-ignore` rules in
`.gitattributes` decide what ships; never hand-zip the working tree. The
workflow refuses to publish if the tag and the plugin version disagree, if the
four version declarations disagree, or if development files reach the archive.
