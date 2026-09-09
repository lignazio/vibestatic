# VibeStatic module: FTP

Uploads the generated site over FTP or FTPS, sending only what changed.

Part of [VibeStatic](https://github.com/lignazio/vibestatic), bundled with the
plugin.

> If the server offers sFTP, use the **sFTP** module instead. It needs nothing
> beyond what the plugin already ships, and it is encrypted by construction.

## Settings

Under **VibeStatic → Add-ons → FTP → Configure**.

| Setting | Notes |
|---|---|
| Host | |
| Port | 21 unless the host says otherwise. |
| Username | |
| Password | Stored encrypted. Leave blank to keep the saved one. |
| Remote root | Where the site goes on the server, e.g. `/public_html`. Empty means the directory you land in on login. |
| Encrypt the connection (FTPS) | **Leave this on.** With it off, the password and then every byte of the site travel in the clear over whatever network is in between. It is here because some shared hosting still offers nothing else. |

The settings page says so when this PHP build has no `ext-ftp`, or no FTPS
support, rather than offering a form that cannot work.

```bash
wp2static ftp options list
```

## What this replaces

`wp2static-addon-ftp`. It used its own deploy-cache namespace of `default`,
shared with every other deployer: two modules pointing at different servers each
read the other's uploads as their own and skipped files they had never sent.

## Verified

Against a real FTP server, on 9 September 2026 — a local `pyftpdlib` speaking
the actual protocol, not a test double.

| | |
|---|---|
| First deploy | 1,808 files, 82 MB |
| File integrity | a PNG and an HTML page compared by SHA-1 against the source: identical |
| Second deploy, nothing changed | **0 sent** |
| A post deleted in WordPress | 1 removed from the server |

The integrity row exists because transferring a binary in ASCII mode is the
oldest FTP defect there is, and it does not announce itself: the file arrives,
it is simply broken.
