=== VibeStatic ===
Contributors: lignazio
Tags: static site generator, static, deployment, performance, security
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 8.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate a static copy of your WordPress site and publish it, re-uploading only
what actually changed.

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

= Destinations =

Deployment to a directory on the same server is included. Other destinations are
separate add-ons: S3, Netlify, GitHub, GitLab, Bitbucket, FTP, SFTP, Azure,
Google Cloud Storage, BunnyCDN, Cloudflare Workers, ZIP.

== Frequently Asked Questions ==

= What is this a fork of? =

VibeStatic is a fork of WP2Static, created by Leon Stafford and maintained by
Strattic (Elementor) until 2024. The original is public domain; this fork is
released under GPLv2 or later. Compared to the original it runs on PHP 8.2, 8.3
and 8.4, ships an up-to-date and namespace-isolated Guzzle, contains no
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

== Changelog ==

See CHANGELOG.md in the repository.
