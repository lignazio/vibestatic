# Workflows

## `quality.yml`
Replaces `codequality.yml`, which was **dead, not degraded**: it used
`actions/checkout@v2` and `actions/cache@v1`, both disabled by GitHub, so every job failed at
its first step. It tested PHP 7.4/8.0/8.1; the matrix is now 8.2/8.3/8.4.

## `release.yml`
Runs on a `v*` tag. It checks that the tag matches the version declared in the plugin header,
runs `composer test` **before** publishing — a release is immutable for anyone who has already
downloaded it — builds the zip with the same `tools/build_release.sh` used locally, and
attaches it to the GitHub Release. A version containing a hyphen (`-rc`, `-beta`, `-dev`) is
published as a prerelease, which `releases/latest` does not serve, so `src/Updater.php` does
not offer it to production sites.

## `integration-tests.yml.frozen`
**Frozen, not repaired**, and that is a decision rather than an oversight.

That workflow revived a Nix environment pinned to nixpkgs `release-22.11` — out of support
since 2023, and where `php80`/`php81` no longer exist in recent releases — in order to run
**six** `deftest`s written in **Clojure** against WordPress **6.1.1**. Putting it back on its
feet means resurrecting three obsolete toolchains for six assertions.

In their place, the same four behaviours (crawl, detect, options, post-process) are verified
against the development environment in `dev/`, using the WP-CLI commands the plugin already
exposes in `src/CLI.php`, on current WordPress. The `.frozen` extension makes GitHub ignore it
while leaving it readable: if somebody ever wants it back, the code is there.
