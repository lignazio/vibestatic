# VibeStatic module: sFTP

Uploads the generated site to a remote server over sFTP, sending only what
changed.

Part of [VibeStatic](https://github.com/lignazio/vibestatic), bundled with the
plugin. The SSH implementation is phpseclib 3, which ships inside VibeStatic
prefixed: nothing to install, and no dependency on the server having an SSH
client.

## Settings

Under **VibeStatic → Add-ons → sFTP → Configure**.

| Setting | Notes |
|---|---|
| Remote host | |
| Port | 22 when left empty. |
| Username | |
| Password | Stored encrypted. Leave empty when authenticating with a key, or to keep the saved one. |
| Private key path | A path **on this server** to the key. Takes precedence over the password. |
| Private key passphrase | Stored encrypted. |
| Remote root path | Where the site is uploaded to. Empty means the login directory. |
| Directory / file permissions | `0755` and `0644` by default. |
| Remote owner / group | Optional. |

```bash
wp2static sftp options list
```

## What this replaces

`wp2static-addon-sftp`.

- **The port was read only when a password was set** — `getValue( 'password' ) ?
  (int) getValue( 'port' ) : 22`. Whoever configured a non-standard port and
  authenticated with a key silently got 22, and the deploy failed against a host
  listening one line above in the same form.
- phpseclib 2 could not read a key in OpenSSH format, which is what
  `ssh-keygen` has produced by default since 2021. Version 3's
  `PublicKeyLoader` works the format out itself.
