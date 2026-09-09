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

## Verified

Against a real Netlify site, on 9 September 2026: a WordPress of 1,806 files
deployed to the free plan.

| | |
|---|---|
| First deploy | **75 uploaded, 0 failed** |
| Second deploy, nothing changed | **0 uploaded, 1,806 unchanged** |
| A post deleted in WordPress | its path leaves the digest, so it leaves the deploy |

The middle row is the one that proves the digest, and it proves the first row
too. Netlify holds a deploy by path and content hash; being told, on the second
run, that it already has all 1,806 of them means the first deploy really did
declare all 1,806 — not the 75 that were physically uploaded.

Which is worth spelling out, because the first run looks alarming: 1,806 files
and only 75 sent. The site has 88 HTML pages; the other 1,718 files are stock
WordPress assets, and Netlify's blob store is content-addressed, so it already
had them. Netlify's own summary of the deploy — "73 generated pages and 12
assets" — is the same 85-ish number seen from its side. Uploading what the other
end says it lacks is the whole point of the protocol.

**What was not watched** is the published site being served over HTTP: Netlify
now creates projects private by default, and the test site was left that way.
That is Netlify's half of the arrangement rather than this module's.
