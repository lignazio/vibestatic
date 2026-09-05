# Security policy

## Supported versions

The `vibestatic` branch and the most recent release. This fork exists because
the project it derives from stopped receiving security patches in 2023; older
VibeStatic releases are not patched either — upgrade instead.

## Reporting a vulnerability

**Do not open a public issue.** Use GitHub's private reporting on this
repository (Security → Report a vulnerability), or email the address on
<https://lucenti.studio>.

Useful things to include: the affected version, whether the site is single or
multisite, what an attacker needs to already have (an account, a role, a nonce),
and the smallest reproduction you have.

This is a small project maintained by one person. Expect an acknowledgement
within a week. If a fix is warranted it ships as a release, and the advisory
credits you unless you prefer otherwise.

## What is in scope

The plugin's own code: the `src/` classes, the admin pages under `views/`, the
WP-CLI commands, and the add-ons kept in `addons/`.

Two categories deserve a specific mention, because they are where this plugin
can do the most harm:

- **Anything that writes or deletes outside the plugin's own directories.** The
  deploy target is a path the site owner configures, and the pruning code
  deletes files from a published site.
- **Anything that lets a lower-privileged user reach an admin action.** Every
  `admin_post_*` handler is meant to check capability first, then the nonce,
  before any write. A handler that does otherwise is a vulnerability even if no
  exploit is shown.

## What is out of scope

- Third-party add-ons not in this repository. Report those to their authors.
- Vulnerabilities in WordPress itself, or in a dependency, where this plugin
  only passes the input through. Report those upstream; tell us anyway if the
  plugin's usage makes them reachable when they otherwise would not be.
- A site whose `AUTH_KEY` and `AUTH_SALT` are missing. The plugin refuses to
  encrypt credentials in that case rather than falling back to a key that would
  be readable in this repository, which is the correct behaviour and not a
  vulnerability in itself.
