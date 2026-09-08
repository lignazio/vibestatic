# Changelog

## VibeStatic 8.0.0-rc2 (2026-09-08)

The fork picks up from WP2Static 7.2. The commit history describes each change
one by one, with the reasoning; this is the summary.

### Added

- **The published site can shrink.** A page that stops being part of the site is
  removed from the crawl queue, the crawled copy, the post-processed copy and
  the destination, empty directories included. Guarded three times over, because
  a failure upstream presents as a shorter list rather than as an error: an
  empty detection run prunes nothing, one that loses more than half of what it
  knew prunes nothing and logs why, and the function that deletes re-checks the
  fraction against the files on disk. `wp2static_prune_stale_files` switches it
  off, `wp2static_max_stale_fraction` moves the threshold.
- **Incremental deployment.** `DeployCache::plan()` compares the generated site
  with what is already published — in one query — and reports what it will
  upload, remove and leave alone before acting. The bundled
  `directory-deployment` add-on uses it: 13 files instead of 1806 after editing
  one post, and zero writes when nothing changed.
- **Translations.** The plugin had no wrapped strings and one `__()` using
  another plugin's text domain. It now ships `languages/vibestatic.pot`, and CI
  fails if a string carries the wrong domain or the `.pot` falls behind.
- **Updates for zip installs.** `src/Updater.php` serves updates from this
  repository's GitHub releases through the native `update_plugins_<hostname>`
  filter, with no added dependency.
- **Schema versioning.** Tables used to be created only in
  `register_activation_hook`, which does not fire on a plugin update, so a new
  column never reached existing sites.
- Development environment under `dev/` — native WordPress, no Docker.

### Changed

- Requires PHP 8.2 and WordPress 6.5. Runs clean on PHP 8.2, 8.3 and 8.4.
- `guzzlehttp/guzzle` 8.1 upstream, prefixed at install time by Strauss,
  replacing a 2020 fork that never received the security patches published
  since. It is now the only production dependency: `wa72/url` was removed
  without a replacement, because the PSR-7 implementation Guzzle already ships
  does the same job.
- Renamed to VibeStatic in everything a person reads and everything on disk.
  Nothing an add-on can call changed: the `WP2Static\` namespace, the 30
  `wp2static_*` hooks, the 8 `wp_wp2static_*` tables, the admin page slugs and
  the `admin_post_wp2static_*` actions all stay. `wp wp2static` answers
  alongside `wp vibestatic`.
- The four global `remove_action` calls that stripped emoji, wlwmanifest and
  wp-embed from **every** page for **every** visitor are now an option, off by
  default. A plugin whose job is exporting a site should not rewrite it
  unasked.
- Removed all Strattic/Elementor promotional code, including the tracking pixel
  that transmitted the site URL and deploy URL to an external server on every
  admin page load.
- Comments and documentation are in English throughout.

### Fixed

- **An error page was saved as the page.** The crawler special-cased 404 and
  the redirects and wrote everything else to the static site verbatim, body and
  all. Found on a real site whose WordPress lives behind HTTP basic auth: 152
  pages crawled, 152 identical "401 Unauthorized" pages written, post-processed
  and deployed, with nothing in the log to say the site had not been seen at
  all. Any status outside 2xx is now logged, not written and not cached — so
  the previously published file stays where it is — and a crawl where
  everything failed says so on a line of its own.
- **`directory-deployment` emptied the target directory before every
  deployment**, because that was the seeded default while the settings page
  described the option as one to leave off. On a fresh install pointed at a
  document root, the first deployment deleted the published site and rebuilt it
  from nothing — with the site absent while it ran, and with incremental
  deployment reduced to a full upload every time. It now defaults to off.
- **A real SQL injection** in the add-on toggle, plus every other interpolated
  query. All queries now go through `prepare()`, with `%i` for identifiers.
- **Three handlers verified the nonce after writing**, and `current_user_can()`
  was missing from all twenty-four `admin_post_*` handlers. The linter
  configuration excluded exactly the five sniffs that would have found this;
  they are on now and block CI.
- **Encryption keys written into the source** as a fallback when `AUTH_KEY` or
  `AUTH_SALT` are missing. It now fails with an explanation instead.
- **WP-Cron did not fire behind HTTP basic auth** — the `cron_request` filter
  returned only the headers, dropping the URL and arguments, so it broke
  precisely in the configuration the feature exists for.
- **Deactivated plugins were published.** `DetectPluginAssets` asked whether an
  active plugin's name appeared anywhere in the absolute path, rather than
  whether the file was inside an active plugin's directory.
- **The bulk "Remove" action on the queue pages** had never worked, and reached
  a destructive operation over GET with no nonce and no capability check.
- **`wp2static_add_menu_items` had been dead since 9 May 2020**, leaving the
  sftp, s3 and netlify add-ons with no settings page at all. The core fires it
  again.
- **Saving the Advanced page wiped the exclusion lists** when their fields were
  not submitted, because `strval( null )` made "not sent" and "deliberately
  emptied" the same thing.
- `uninstall.php` left one table and two complete copies of the site on disk.
- Hidden admin pages had no title, which produced a WordPress deprecation at the
  top of each of them.
- Diagnostics reported an unlimited `max_execution_time` as a problem, and
  checked for PHP 7.4 while the plugin requires 8.2.
- A `plugin_action_links()` function in the global namespace — the most generic
  possible name — which would fatal on redeclaration alongside any other plugin
  defining it.
- The Deploy Cache page raised an undefined-array-key warning on a fresh install,
  and "Delete the Deploy Cache" left every other deployer's cache in place.
- Two dead links pointed users at the abandoned upstream project for support.
- `JobQueue`: a guard comparing an array against an integer that never fired, a
  `COMMIT` inside the loop that closed the transaction after the first job type,
  and a property read on a possibly-null result.

### Development

- 175 unit tests, up from 43, over classes that now receive their dependencies
  from outside. Nine test files registered expectations that were never checked.
- PHPStan at level max with a baseline of 147, down from 675.
- CI on PHP 8.2/8.3/8.4, with blocking security and i18n steps and a check that
  the `.pot` matches the code.

---

## WP2Static 7.2 (2023-02-01)

 - [#876](https://github.com/elementor/wp2static/pull/876): Fix #240: ignore SSL errors when fetching sitemap from local site with self-signed certificate. @timothylcooke
 - [d3977eab](d3977eab6be24c4985d998a7f4bf07409ef4a71b): Create an index on `wp2static_jobs.status`. @john-shaffer
 - [#785](https://github.com/elementor/wp2static/issues/785): Accept self-signed certs during sitemap crawling. @working-name, @john-shaffer
 - [#806](https://github.com/elementor/wp2static/pull/806): Detect dead jobs and mark as failed. @john-shaffer
 - [#806](https://github.com/elementor/wp2static/pull/806): Mark duplicated waiting jobs as skipped on jobs page. @john-shaffer
 - [#794](https://github.com/elementor/wp2static/issues/794): Add an option to process the queue immediately. @john-shaffer
 - [#809](https://github.com/elementor/wp2static/pull/809): Add ability to rewrite hosts specified on a new advanced options page. @john-shaffer
   - As part of this, changed the host replacement function to use strtr instead of str_replace to avoid replacing things that we just replaced.
 - [#809](https://github.com/elementor/wp2static/pull/809): Add advanced option to skip URL rewriting. @john-shaffer
 - [#812](https://github.com/elementor/wp2static/pull/812): Add .editorconfig. @bookwyrm
 - [#816](https://github.com/elementor/wp2static/pull/816): Add wp2static_siteinfo filter. @palmiak
 - [bbc8abba](https://github.com/elementor/wp2static/commit/bbc8abba9103d097a62a6bbbd8d7a4229e788f4b): Fix error when a sitemap path starts with `//`. @jhatmaker, @john-shaffer
 - [#829](https://github.com/elementor/wp2static/pull/829): Move options labels and definitions out of the db and into code. @john-shaffer
 - [#826](https://github.com/elementor/wp2static/pull/826): Allow multiple redirects and report on redirects in wp-cli. @bookwyrm, @jhatmaker
 - [28fc58e5](https://github.com/elementor/wp2static/commit/28fc58e5f7694129e5919530adcd6c57435391fb): Add warning-level log messages. @john-shaffer
 - [#834](https://github.com/elementor/wp2static/pull/834): Implement concurrent crawling. @palmiak
   - Deprecate Crawler::crawlURL.
 - [#836](https://github.com/elementor/wp2static/pull/835): Add wp2static_option_\* filters and option types
 - [#833](https://github.com/elementor/wp2static/pull/833): Add advanced options for specifying directories, files, and file extensions to ignore @john-shaffer
 - [#837](https://github.com/elementor/wp2static/pull/837): Require PHP 7.4 or later; bump dependencies @leonstafford
 - [#811](https://github.com/elementor/wp2static/issues/811): Optimize FilesHelper::getListOfLocalFilesByDir @bookwyrm
 - [#805](https://github.com/elementor/wp2static/issues/805): Fix warning on cache page when there are no deployment namespaces @john-shaffer
 - [#843](https://github.com/elementor/wp2static/issues/843): Always fire post-deployment action from the CLI, matching the normal behavior @michaelfig
 - [#848](https://github.com/elementor/wp2static/issues/848): Fix error from IF EXISTS syntax when the db user can't see the schema. @utchy
 - [#849](https://github.com/elementor/wp2static/issues/849): Make job locking work with multisite. @utchy
 - [#844](https://github.com/elementor/wp2static/issues/844): Fix crawling of basic auth sites. @thecodeassassin, @vladstanca
 - [#855](https://github.com/elementor/wp2static/issues/855): Allow setting options to empty values from the CLI. @john-shaffer
 - [#850](https://github.com/elementor/wp2static/issues/850): Fix to write content to disk, even for cache hits when "Use CrawlCache" is ON. @utchy
 - [#877](https://github.com/elementor/wp2static/issues/877): Detect from web UI was not adding any URLs. @timothylcooke
 - [#878](https://github.com/elementor/wp2static/issues/878): Fix deletion of old pages when crawl returns 404. @timothylcooke
 - [#868](https://github.com/elementor/wp2static/pull/868): Detect files in Divi et-cache/ directory when present. @dunklerfox
 - [#881](https://github.com/elementor/wp2static/pull/881): Crawl XSL files. @vladstanca
 - [#891](https://github.com/elementor/wp2static/pull/891): Promote Strattic by Elementor. @leonstafford

## WP2Static 7.1.7 (2021-09-04)

 - logging and fixes for Sitemap detection @palmiak, @john-shaffer
 - fix #793 properly dequeue + deregister scripts @mrwweb
 - fix diagnostics uploadsWritable description @yilinjuang
 - fix #730 detect network-wide enabled plugins @stefanullinger
 - `INSERT IGNORE` to silence add-on duplicate insert warnings
 - add filters to deployment webhook:
  - `wp2static_deploy_webhook_user_agent`
  - `wp2static_deploy_webhook_body`
  - `wp2static_deploy_webhook_headers`
 - improved unit test coverage for Detection classes
 - add trailing slash to detected category pagination URLs @john-shaffer
 - rm `autoload-dev` from composer.json @szepeviktor
 - extend PHPStan coverage to view/template files
 - allow toggling an add-on via WP-CLI
 - new `wp2static_detect` hook fires at URL detection start @john-shaffer
 - diagnostics checks for trailing slash in permalinks @john-shaffer, @jonmhutch7
 - use sfely namespaced Guzzle to avoid conflicts with other plugins
 - default to showing DeployCache paths across all namespaces #745
 - allow setting deploy webhook headers/body/user-agent via filter
 - fix PostsPaginationURL detection #758 @petewilcock, @john-shaffer
 - use custom request options for sitemap crawling
 - move from cURL to Guzzle for requests
 - fix incompatibilities with PHP8
 - import SitemapParser as internal class
 - fix MySQL issue preventing Add-on activations @john-shaffer, @TheLQ

## WP2Static 7.1.6 (2020-12-04)

 - code quality improvements (thanks @szepeviktor)

## WP2Static 7.1.5 (2020-12-04)

 - fix PHP version check to >=7.3
 - fix errors during sitemap detection (thanks @fromcouch)
 - fix errors during cache table initialisation
 - fix pagination URLs not using correct schema
 - fix CLI command registration issue

## WP2Static 7.1.2 (2020-11-03)

 - update dependencies
 - add CHANGELOG
 - #682 only toggle other deploy addons, not other types when enabling a deployer
 - rm redundant Composer workaround
 - quieten build output
 - code quality improvements (thanks @szepeviktor!)

## WP2Static &lt; 7.1.2

 - didn't maintain Changelog or use tags, please review version control if curious

