# VibeStatic Add-on: Deploy to local directory

Deploys the generated static site to a directory on the same machine, copying
only what changed.

This add-on is part of [VibeStatic](https://github.com/lignazio/vibestatic).
VibeStatic writes its post-processed static site under
`wp-content/uploads/wp2static-processed-site`; most deployment add-ons target an
external API such as S3, Netlify or Cloudflare, while this one copies to a local
path.

### Use cases

- you have one server for both the WordPress site and the static site;
- you want another tool to handle the actual publishing, by watching a target
  directory and syncing it (`inotify` + `rsync`, for instance);
- you deploy to a bind mount, a container volume, or a directory served
  directly by nginx.

### What it copies

Only what changed. The core works out the difference between the generated site
and what is already published — see `WP2Static\DeployPlan` — and this add-on
copies those files, deletes the ones that have gone along with any directories
left empty, and leaves the rest alone. Measured on 1806 files, editing one post
means 13 copies rather than 1806.

Emptying the target directory before each deployment is still available as an
option, but it is no longer the normal path: it deletes unchanged files too, and
while it runs the published site is not there. It is for starting over.

### Options

- **Target directory (absolute path)** — where the site is copied.
- **Delete target directory before deployment** — off by default; see above.
- **Additional source directory** — files that do not come from the generated
  site but should end up at the destination anyway. These do not go through the
  deploy plan; they are copied as they are.

### History

Adopted into VibeStatic. The original is by Adam Twardoch, released into the
public domain (Unlicense). Its last commit, "wip further renaming" of 29 August
2021, left a half-finished rename with five independent faults: the add-on threw
a fatal error on activation; once past that, it read three option names that
`seedOptions()` never wrote, so any configuration produced "You must specify the
target folder"; `array_map( 'rrmdir', … )` referenced an unqualified function
inside a namespace and fatally errored while emptying the destination; the
settings page had a filename the controller never required; and the fields
inside it carried the old option names. It had not worked since 2021.

The slug stays `wp2static-addon-directory-deployment`: it is the key the add-on
is registered under and the key its options are saved under.

### Licence

GPL-2.0-or-later, as part of VibeStatic.
