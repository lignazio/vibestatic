# VibeStatic

Static site generation and publishing for WordPress.

VibeStatic is a fork of [WP2Static](https://github.com/elementor/wp2static),
created by Leon Stafford and maintained by Strattic (Elementor) until 2024. The
original's last code commit is from 2023; the project had 1470 stars and no
successor. The original is released into the public domain (Unlicense); this
fork ships under **GPLv2 or later**, which is the WordPress standard.

## Incremental deployment

This is the feature that separates this fork from Simply Static free, which
republishes the whole site on every save.

The crawler already recognises which pages changed by itself — it compares the
hash of each downloaded page with the one from last time, so the pages that
depend on a modified post are discovered on their own, with nobody having to
declare who depends on whom. What was missing was the deploy side:
`DeployCache::plan()` compares the generated site with what has already been
published and says what to upload, what to remove, and what to leave alone, in a
single query.

Measured on the development environment, 1806 files, after modifying **one**
post:

```
Deploy plan: 13 to upload, 0 to remove, 1793 unchanged.
Directory deployment complete: 13 copied, 0 removed.
```

Half a second instead of three and a half. With nothing to change, zero files
and no writes at all.

**And the published site can shrink.** A page that stops being part of the site
is removed from the crawl queue, from the crawled copy, from the post-processed
copy, and from the destination — directories that end up empty included, so no
dead URLs are left online. That path has three safety catches, because a failure
upstream does not present as an error, it presents as a shorter list: a
detection run that finds nothing prunes nothing; one that loses more than half
of what it knew prunes nothing and logs why; and the function that actually
deletes re-checks the fraction against the files on disk rather than the URLs.
`wp2static_prune_stale_files` switches the whole thing off,
`wp2static_max_stale_fraction` moves the threshold.

The report is printed **before** acting: an incremental deploy that does not say
how many files it touches cannot be verified, and whoever cannot verify it ends
up re-uploading everything.

The `addons/directory-deployment` add-on is adopted into this fork. The original
never worked: its last commit, from 2021, left a half-finished rename with five
independent faults — it would not activate, and if it did it would not deploy,
and its settings page gave a fatal error.

## What else changed from the original

- **PHP 8.2, 8.3 and 8.4.** The code parses and runs on all three without a
  single deprecation.
- **Security violations went from 302 to zero.** Among them a real SQL
  injection, three handlers that verified the nonce *after* writing, and
  `current_user_can()` missing on twenty-four handlers out of twenty-four. The
  linter's configuration excluded exactly the five checks that would have found
  them; they are on now and they block CI.
- **Guzzle updated.** The original shipped a 2020 fork without the security
  patches published since. This carries upstream Guzzle, prefixed at install
  time by [Strauss](https://github.com/BrianHenryIE/strauss) so it cannot
  collide with another plugin's copy.
- **No promotional code.** Gone is the table of advertising notices and the
  tracking pixel that transmitted the site URL and the deploy URL to an external
  server on every admin page load.
- **Real tests.** The classes that decide what changed and what gets
  republished now receive their dependencies from outside and have a suite
  covering them.
- **Translatable.** The plugin had zero wrapped strings and one `__()` using a
  text domain from a different plugin. It now ships a `.pot`, and CI fails if a
  string is wrapped with the wrong domain or if the `.pot` falls behind the code.
- **Updates for zip installs.** `Update URI` tells WordPress not to look on
  wordpress.org; `src/Updater.php` points it at this repository's releases
  instead, using the native `update_plugins_<hostname>` filter rather than a
  library.

## Add-on compatibility

**The API does not change.** The rename applies to what people read and to what
sits on disk; it touches nothing an add-on can call:

| Stays `wp2static` | Becomes `vibestatic` |
|---|---|
| the PHP namespace `WP2Static\` | the plugin name and the interface strings |
| the 30 `wp2static_*` hooks | the main file and the plugin directory |
| the 8 `wp_wp2static_*` tables | the text domain |
| the admin page slugs | the `VIBESTATIC_PATH` and `VIBESTATIC_VERSION` constants |
| the `admin_post_wp2static_*` actions | the WP-CLI command |

`wp wp2static` keeps answering alongside `wp vibestatic`: it lives in the deploy
scripts and cron entries of everyone who already used the plugin.

One thing was repaired rather than preserved. `wp2static_add_menu_items` — the
filter add-ons use to register a settings page — stopped being fired by the core
on 9 May 2020, while sftp, s3 and netlify kept registering on it. Those three
add-ons install, activate, hook into the deploy, and have nowhere to put their
credentials. The filter is fired again here, with the same contract as before.

## Installation

- from this source: `git clone`, then `composer install` in the plugin
  directory — the install also builds the prefixed dependencies;
- from a zip: `composer run-script build` produces
  `dist/vibestatic-<version>.zip`.

Requires PHP 8.2 or later and WordPress 6.5 or later.

## Development

```
composer test        # everything that runs in CI and blocks
composer phpunit     # unit tests only
composer phpcs       # style and documentation (non-blocking, known debt)
composer i18n        # regenerate the .pot files
composer build       # the distributable zip, into dist/
```

`composer install` runs Strauss by itself, which generates `vendor-prefixed/`:
without that directory the plugin has no HTTP client. Do not run it by hand.

The tests that make real network requests live in a separate suite,
`composer phpunit-external`, and do not run in CI: they fail when somebody
else's site changes, and in that case they say nothing about this code.

The original's integration tests were six Clojure `deftest`s on NixOS pinned to
nixpkgs 22.11, out of support since 2023: they are frozen, not removed, along
with the Nix files they need (`default.nix`, `nix/`, `.envrc`). See
`integration-tests/` and `.github/workflows/README.md`.

There is a development environment under `dev/` — native WordPress, no Docker.
`source dev/env.sh` then `vs-help`.

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md). Security reports go through
[SECURITY.md](./SECURITY.md), not through public issues.

## Two readmes

This file is the project's documentation on GitHub. `readme.txt` is the plugin's
public listing in the wordpress.org format, which has its own required structure
and section headers; both are in English.

## Licence

GPL-2.0-or-later. See [LICENSE](./LICENSE).
