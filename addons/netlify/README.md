# VibeStatic module: Netlify

Uploads the generated site to Netlify, sending only what it does not already
hold.

Part of [VibeStatic](https://github.com/lignazio/vibestatic), bundled with the
plugin.

## Settings

Under **VibeStatic → Add-ons → Netlify → Configure**.

| Setting | Notes |
|---|---|
| Site ID | Found under Site configuration in the Netlify dashboard. |
| Personal access token | Stored encrypted. Create one under User settings / Applications. |

```bash
wp2static netlify options list
```

## How the upload works

Netlify's deploy API is a digest: the whole site is offered as a list of paths
and SHA-1 hashes, Netlify answers with the subset it does not have, and only
those files are sent. A site whose pages have not changed uploads nothing at
all.

## What this replaces

`wp2static-addon-netlify`. Its settings page echoed ten values with no escaping
at all, one of them the decrypted personal access token straight into a `value`
attribute — a token holding an apostrophe closed the attribute early.
