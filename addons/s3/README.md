# VibeStatic module: S3

Uploads the generated site to an Amazon S3 bucket, sending only what changed,
and tells CloudFront which paths to invalidate.

Part of [VibeStatic](https://github.com/lignazio/vibestatic), bundled with the
plugin: there is nothing to install separately.

## Settings

Under **VibeStatic → Add-ons → S3 → Configure**.

| Setting | Notes |
|---|---|
| Bucket | |
| Region | The region the bucket is in, e.g. `eu-south-1`. It is part of the signature, so a wrong one is refused rather than redirected. |
| Path in the bucket | Optional prefix. Empty publishes at the bucket root. |
| Access key ID / Secret access key | The secret is stored encrypted. Leave it blank to keep the saved one. |
| Object ACL | Leave empty unless the bucket needs one. A bucket created since 2023 has ACLs disabled and refuses a request carrying one; older buckets serving a public site want `public-read`. |
| Cache-Control | Optional, sent with every object. There is no safe default: what suits a hashed asset is the opposite of what suits a page. |
| CloudFront distribution ID | Optional. With one set, the paths that changed are invalidated after each deploy. |
| Most paths to invalidate | Above this many changes it invalidates everything instead — one request rather than thousands. CloudFront gives a thousand paths a month free and charges beyond that. |
| CloudFront access key ID / secret | Optional. Empty means the S3 credentials above. |

The credentials need only `s3:PutObject` and `s3:DeleteObject` on the bucket,
plus `cloudfront:CreateInvalidation` if a distribution is set. An account with
more than that is an account that can do more than publish a site.

```bash
wp2static s3 options list
wp2static s3 options set s3Bucket my-bucket
```

## What this replaces

`wp2static-addon-s3`, which required `aws/aws-sdk-php`: twenty-four megabytes,
seven and a half once the unused services are stripped, and the reason the
roadmap had this one staying outside the plugin as a separate install. What the
deployer asks of it is three signed requests, and a signature is a hundred
lines — so it is a hundred lines, in `src/Signer.php`, and this ships in the
plugin's zip with nothing added to it.

- **`cfMaxPathsToInvalidate` had no default**, so with it unset the original
  invalidated `/*` — the whole distribution — on every single deploy. That one
  cost money.
- The slug, the options table and the option names are the original's, so an
  installation that had the add-on keeps its bucket and its credentials.

## Verified

Against a real bucket, on 9 September 2026: a WordPress of 1,819 URLs deployed
to `eu-north-1`.

| | |
|---|---|
| First deploy | **1,807 sent, 0 failed** — every SigV4 signature accepted |
| File integrity | `index.html` and a PNG compared against S3's ETag, which for a single-part upload is the object's MD5: identical both times |
| Content type | `html` and `png`, from the module's own table |
| Second deploy, nothing changed | **0 sent**, 1,807 unchanged |
| A post deleted in WordPress | 1 removed, and its prefix is empty in the bucket |

The whole test cost about a penny: S3 charges $0.005 per thousand PUTs, deletes
are free, and 82 MB for an hour rounds to nothing.

The credentials were an IAM user with `s3:PutObject` and `s3:DeleteObject` on
that bucket and nothing else — which is the point of the permissions note above,
now confirmed rather than assumed.
