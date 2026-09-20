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
