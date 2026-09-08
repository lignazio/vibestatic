# Contributing

## Branches and versioning

Work happens on `vibestatic`, which carries a `-dev` version in the plugin
header (currently `8.0.0-rc3`). A release is a `v*` tag whose number matches
that header exactly — `release.yml` refuses to publish if they disagree, and
`tools/build_release.sh` refuses if the header and `VIBESTATIC_VERSION`
disagree. Between them the version cannot diverge in three places silently,
which it previously could.

The `develop` and `master` branches are the upstream project's history, kept for
reference. Nothing is merged into them.

Changing the version means five files, not one: the header and
`VIBESTATIC_VERSION` in `vibestatic.php`, `Stable tag` in `readme.txt`, the
heading in `CHANGELOG.md`, the fallback in `tests/unit/ModulesTest.php` — and
then `composer i18n`, because `Project-Id-Version` in the `.pot` carries the
version too and the `pot` job in CI compares it.

## Before opening a pull request

```
composer test
```

That is what CI runs and what blocks: lint on the current PHP, PHP 8.2+
compatibility, PHPStan at level max, the unit tests, and the security and i18n
sniffs. It has to be green.

Two things run in CI but do not block, deliberately:

- `composer phpcs` in full — style and documentation, with a known backlog. It
  is a separate step so that a red build over a missing docblock cannot hide a
  new SQL injection in the same output.
- the external test suite, which downloads sitemaps from third-party sites and
  fails for reasons unrelated to this code. Run it by hand with
  `composer phpunit-external`.

If you touch a user-visible string, run `composer i18n` and commit the
regenerated `.pot` files. CI compares them against the code and fails if they
have drifted.

If you add to the PHPStan baseline, say why in the pull request. The baseline is
a list of things to fix, not a way of not seeing them.

## What this project is careful about

- **The add-on API does not change.** The `WP2Static\` namespace, the 30
  `wp2static_*` hooks, the 8 `wp_wp2static_*` tables, the admin page slugs and
  the `admin_post_wp2static_*` actions are what twenty-one existing add-ons
  call. New names are added alongside the old ones, never in place of them.
- **Anything that deletes files from a published site gets a safety catch and a
  test.** Unpublishing a live page is the only category of damage this code can
  do.
- **Comments explain why, not what.** A comment that restates the line below it
  ages badly; one that records the reason a decision was taken is why this
  codebase can be picked up again. They are written in English.
- **Measure before claiming.** "Faster", "fixed", "no longer happens" are
  claims: the pull request should carry the number, the log line, or the before
  and after.

## Development environment

There is a native WordPress setup under `dev/`, no Docker:

```
source dev/env.sh
vs-help
```

It needs PHP via Homebrew, a running MariaDB and wp-cli. `vs-serve` starts it on
`localhost:8080`.

The upstream integration tests were six Clojure `deftest`s on NixOS pinned to
nixpkgs 22.11, out of support since 2023. They are frozen rather than removed,
together with the Nix files they need (`default.nix`, `nix/`, `.envrc`); see
`integration-tests/README.md` and `.github/workflows/README.md`. The same four
behaviours are exercised against `dev/` through the WP-CLI commands in
`src/CLI.php`.

## Reporting a security issue

Not through a public issue. See [SECURITY.md](./SECURITY.md).

## Licence

Contributions are accepted under GPL-2.0-or-later, the licence this fork ships
under. The upstream project it derives from is public domain (Unlicense).
