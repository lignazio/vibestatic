# VibeStatic module: ZIP

Packs the generated site into a ZIP archive you can download.

Part of [VibeStatic](https://github.com/lignazio/vibestatic), bundled with the
plugin.

## Using it

Enable **ZIP** on the Add-ons page, run a deployment, then open its page from
the gear icon: it shows the archive's size and when it was made, with a button
to download it and one to delete it.

The archive is written to `wp-content/uploads/wp2static-processed-site.zip`.

```bash
wp2static zip
```

There are no settings, which is why this module — alone among the six — does not
extend `WP2Static\Addon\Controller`: that base is for an add-on with an options
table and a settings form, and this one has neither.

## What this replaces

`wp2static-addon-zip`.

- **Its page was registered under one name and linked under another**: the page
  existed as `wp2static-addon-zip` while the "Refresh page" link and the redirect
  after deleting both pointed at `wp2static-zip`, which does not exist. Both
  landed on "you are not authorized".
- The page hung off `options.php` and then filtered `parent_file` to rewrite the
  global `$plugin_page` so the menu would stay highlighted — a workaround for a
  parent that was wrong to begin with, and one that left the page with no title,
  so `admin-header.php` called `strip_tags( null )`.
