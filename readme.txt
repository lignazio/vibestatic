=== VibeStatic ===
Contributors: lignazio
Tags: static site generator, static, deployment, performance, security
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 9.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your WordPress site as static files, re-uploading only what actually changed.

== Description ==

VibeStatic walks your site the way a visitor would, saves every page as a file,
rewrites the URLs and delivers the result wherever you want it.

= Incremental deployment =

This is what sets VibeStatic apart from most free alternatives, which re-upload
the whole site every time you hit publish.

The crawler works out which pages changed on its own: it compares a fingerprint
of every page it downloads with the one from last time. Pages that depend on an
edited post — archives, pagination, sitemaps — are therefore found by
themselves, without anyone having to declare what depends on what.

Before publishing, VibeStatic tells you what is about to change:

`Deploy plan: 13 to upload, 0 to remove, 1793 unchanged.`

Measured on a site of eighteen hundred files: editing one post uploads thirteen
files instead of eighteen hundred. Half a second instead of three and a half. If
nothing changed, nothing is written. And when you delete a post, the file goes
away at the destination too, so no dead URLs are left online.

= How it works =

1. **Detect** — find the URLs: posts, pages, custom post types, archives,
   pagination, sitemaps, uploads, theme and active-plugin assets.
2. **Crawl** — fetch them concurrently and save what changed.
3. **Post-process** — rewrite URLs to point at the destination.
4. **Deploy** — deliver, incrementally.

Each stage is a queued job you can run by hand, from WP-Cron, or from WP-CLI
(`wp vibestatic crawl`).

= Destinations included =

Six, all in the plugin, with nothing further to install:

* a directory on the same server
* a downloadable ZIP archive
* FTP and FTPS
* sFTP
* Amazon S3, with a CloudFront invalidation
* Netlify

More destinations — Google Cloud Storage, Azure Blob Storage, BunnyCDN,
Cloudflare Workers KV, and a commit to GitHub, GitLab or Bitbucket — exist as
separate add-on plugins in the project's own repository. VibeStatic does not
download or install them: they are ordinary plugins you install yourself, and
the plugin only shows their settings pages once they are active.

= What it does not do =

There is no account to create, no key to enter and no service behind this
plugin. It sends nothing anywhere except to the destination you configure, and
it has no analytics, no notices advertising anything, and no paid tier.

== Installation ==

1. Install and activate the plugin.
2. Open **VibeStatic → Options** and set the deployment URL — the address the
   static copy will be served from.
3. Open **VibeStatic → Add-ons** and enable one deployment target.
4. Configure that target on its own settings page.
5. Open **VibeStatic → Run** and press **Start**.

On a large site, run it from the command line instead, where nothing can time
out:

`wp vibestatic full_workflow`

PHP 8.2 or later is required, and the `zip` extension for the ZIP destination.

== Frequently Asked Questions ==

= What is this a fork of? =

VibeStatic is a fork of WP2Static, created by Leon Stafford and maintained by
Strattic (Elementor) until 2024. The original is public domain; this fork is
released under GPLv2 or later. Compared to the original it runs on PHP 8.2, 8.3
and 8.4, ships an up-to-date and namespace-isolated HTTP client, contains no
advertising or tracking code, and its security violations went from 302 to zero.

= Do WP2Static add-ons still work? =

Yes. The PHP namespace, the thirty hooks, the eight database tables, the admin
page slugs and the form actions are unchanged. The plugin's name changed, not its
programming interface.

= Do I need WP-Cron? =

No. You can run everything by hand from the Run page, or from the command line
with `wp vibestatic detect|crawl|post_process|deploy`. WP-Cron is only needed for
publishing automatically on a schedule.

= Does it work on a password-protected site? =

Yes. Set the basic auth username and password in Options: the crawler uses them,
and so does WP-Cron when it publishes on its own.

= Where are the credentials for my destination kept? =

In the plugin's own database tables, encrypted with `AUTH_KEY` and `AUTH_SALT`
from your `wp-config.php`. A site without those two constants is refused rather
than served a fallback key: a key stored in the plugin's source would not be
encryption.

= Will it publish my media library? =

Only the files your pages actually reference, which is the default. There is an
option to export the whole `uploads` directory instead, including every
generated image size — useful occasionally, and much larger.

= Does the static copy include forms, search or comments? =

No, and nothing can: those need PHP. Search and comments have to move to a
third-party service. Forms can, and VibeStatic will rewrite their `action` to an
endpoint you give it.

== External services ==

VibeStatic contacts nothing on its own. Two kinds of external communication are
possible, and both happen only because you configured them:

**The destination you choose.** Enabling the Amazon S3 destination and entering
your keys makes the plugin upload the generated site to Amazon Web Services
(<https://aws.amazon.com/service-terms/>, <https://aws.amazon.com/privacy/>), and
optionally ask CloudFront to invalidate the paths that changed. Enabling the
Netlify destination uploads it to Netlify
(<https://www.netlify.com/legal/terms-of-use/>,
<https://www.netlify.com/privacy/>). The FTP, sFTP, directory and ZIP
destinations contact no third party: they write to a server or a disk you name.
What is sent is the static copy of your own site, plus the credentials needed to
authenticate. Nothing is sent unless a destination is enabled and configured.

**Snipcart, for a WooCommerce shop.** WooCommerce's cart is PHP and cannot be
published as static files. If — and only if — you enter a Snipcart public key in
Options, the exported pages get Snipcart's stylesheet and script added from
`cdn.snipcart.com`, and its add-to-cart buttons in place of WooCommerce's. Those
two files are then loaded by visitors *of the published static site*, not by
your WordPress installation. See <https://snipcart.com/legal/terms> and
<https://snipcart.com/legal/privacy-policy>. Leaving the key empty leaves every
page untouched.

The plugin sends no analytics, no usage statistics and no error reports
anywhere, and it does not check in with any server of ours — there is not one.

== Screenshots ==

1. The log of a finished run: what was crawled, what was skipped as unchanged, and the deploy plan — 375 to upload, 3 to remove, 1,748 unchanged.
2. Options: what to detect, how to crawl, and where the static copy will live.
3. Add-ons: the six destinations included, and which one is switched on.
4. Caches: how much has been detected, crawled and published, and what each destination already holds.

== Changelog ==

= 9.1.0 =
* The PHPStan baseline is empty and the file is gone: 155 suppressed findings
  corrected instead of hidden. Four of them were failures waiting to happen —
  three filters whose return value was never checked, a `get_row()` result read
  without a null check in the loop that runs on every export, and ten
  `query( prepare() )` pairs that are a TypeError on PHP 8 when the placeholders
  do not match.
* The filesystem and URL calls WordPress asks plugins to prefer: `wp_parse_url`,
  `wp_delete_file`, `wp_mkdir_p`, `wp_is_writable`. The remaining direct calls
  are documented where they are, with the reason.
* Every file that runs something when included now refuses to be requested
  directly.
* An exception message written in Italian, which nobody outside the author's
  desk could read, is in English and translatable.
* Dead debug code removed from `SiteInfo`.

= 9.0.0 =
* The add-on base moved into the plugin as `WP2Static\Addon\`: four files that
  every separate add-on used to copy, 976 byte-identical lines each, plus six
  bundled modules that had no abstraction at all and rewrote the same methods by
  hand. 1,677 lines of module controller and 809 of views became a declaration
  of options and fields.
* All six bundled destinations verified against the real thing — a filesystem,
  a real archive, a real FTP server, a real sFTP server, a real S3 bucket, a
  real Netlify site. For each: a second deploy with nothing changed sends
  nothing, a page deleted in WordPress disappears from the published site, and a
  failed request appears in the log as a failure.
* `options list` reported a secret as set when it was not: it judged by the
  stored value, and an encrypted empty string is thirty-two characters long.
* Every admin page has exactly one `h1`, which is what a screen reader announces
  on arrival.

= 8.1.1 =
* The gear on the Add-ons page opened a settings page that answered "you do not
  have permission", for every add-on and all six bundled modules. It now asks
  WordPress which of the two possible page slugs is actually registered instead
  of guessing.
* The package contained the Unlicense while the headers, this readme and
  `composer.json` all said GPL.

= 8.1.0 =
* Exports what the pages reference rather than the whole `uploads` directory.
  Existing sites keep the setting they already have.
* The crawler reads `data-src`, `data-srcset`, `data-lazy-src`,
  `data-lazy-srcset` and `data-poster` as well as `src`, so lazy-loaded images
  and videos are published. Thirteen videos on a real site were being missed,
  each referenced by fifteen to eighteen pages, with nothing in the log to say
  so — a missing file nobody asked for is not an error.

= 8.0.0 =
* First release of the fork. Requires PHP 8.2 and WordPress 6.5; runs clean on
  PHP 8.2, 8.3 and 8.4.
* A SQL injection in the add-on toggle, `current_user_can()` missing from all
  twenty-four `admin_post_` handlers, encryption keys hardcoded as a fallback,
  and a tracking pixel that sent the site's URL and its deploy URL to an
  external server on every admin page load: all gone.
* The three abandoned 2020 forks of Guzzle, Promises and PSR-7 replaced by
  upstream Guzzle, namespace-prefixed at build time so it cannot collide with
  another plugin's copy. Two production dependencies remain.
* The directory-deployment module no longer empties its target before each
  deploy. Pointed at a document root, that default deleted the published site
  every time.
* The crawler no longer saves the body of an error response as if it were the
  page. Without basic auth credentials a real site published 4,121 copies of the
  same "401 Unauthorized" page, and nothing in the log said so.
* 175 unit tests, up from 43.

Earlier history, including WP2Static's own, is in `CHANGELOG.md` in the
repository.

== Upgrade Notice ==

= 9.1.0 =
Internal corrections and stricter analysis. No settings change and no migration.

= 9.0.0 =
Rewrites the six bundled destination modules onto a shared base. Your existing
settings are read in place and are not migrated or moved. If you wrote your own
add-on against the copied boilerplate, it keeps working: the base is additive.
