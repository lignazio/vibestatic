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

## Partly verified

There is no throwaway S3 to run this against for free, so what could be checked
was checked, on 9 September 2026. The deployer was pointed at a real bucket name
in a real region with deliberately invalid credentials, and it **reached AWS**:
the endpoint was built, the request signed, and Amazon answered

    403 InvalidAccessKeyId — The AWS Access Key Id you provided does not exist
    in our records

which means the request and its `Authorization` header were well-formed enough
for AWS to parse and to identify which key was being claimed. A malformed
signature answers `SignatureDoesNotMatch` or `AuthorizationHeaderMalformed`
instead.

**What that does not prove** is that the signature itself is right: AWS looks
the key up before it verifies anything. That needs one deploy to a real bucket,
which costs about a penny.

What it does prove is the part its ancestor got wrong: 1,806 files failed, and
**not one of them was recorded as deployed**. Unconfigured, it says `S3 bucket
is not set.` and stops.
