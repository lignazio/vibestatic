# Assets for the wordpress.org plugin directory

These files do **not** go into the plugin zip. They live in the `assets/`
directory at the top of the plugin's Subversion repository, alongside `trunk/`
and `tags/`, and wordpress.org serves them on the plugin's page:

```
https://plugins.svn.wordpress.org/vibestatic/
├── assets/     ← the contents of this directory, except src/
├── tags/
└── trunk/      ← the plugin itself
```

The directory is named `.wordpress-org` because that is where
`10up/action-wordpress-plugin-deploy` looks for them, should the release ever be
automated.

## What is here

| | |
|---|---|
| `icon-256x256.png`, `icon-128x128.png` | The icon, in search results and in "Add Plugins" |
| `banner-1544x500.png`, `banner-772x250.png` | The header of the plugin's page |
| `screenshot-1.png` | The log of a finished run, with the deploy plan |
| `screenshot-2.png` | Options |
| `screenshot-3.png` | Add-ons: the six destinations included |
| `screenshot-4.png` | Caches |

The screenshots were captured at 1280 px wide against the development
environment in `dev/`, on a site of some two thousand URLs, so the numbers on
them are real. Their captions are the numbered list under `== Screenshots ==` in
`readme.txt`, in order: keep the two in step, because the caption comes from the
readme and the image from here, and nothing checks that they still describe each
other.

## The design

A 3×3 grid of squares, eight neutral and one red. It is not decoration: it is
what the plugin does — a site of nine pages where one changed, and only that one
is published again. The same red cell is the only accent on the banner, where it
opens the rule under the wordmark rather than sitting next to the text as a
bullet.

Swiss, in the sense of the things it refuses: one typeface (Helvetica Neue, the
grotesque the style was built on), one accent, flush-left everything, no
gradient, no shadow, no rounded corner, and every measurement on a declared
grid — twelve columns of 90.67 px inside a 96 px margin, and the mark on the
last two.

Nine filled squares and not nine outlines, because the icon has to survive being
drawn at 64 px in the plugin list, where a hairline disappears.

**The bottom 104 px of the banner is deliberately empty.** In the plugin details
panel inside wp-admin, WordPress lays the plugin's name over the bottom-left of
the banner; anything put there would be read through a title.

## Rebuilding them

```bash
.wordpress-org/src/render.sh
```

The sources are the two HTML files in `src/`, not a binary from a design tool:
the whole thing is thirty lines of CSS, so it can be read and changed by
whoever comes next. `render.sh` drives headless Chrome and writes all four PNGs
here. The 1× sizes are rendered natively rather than downscaled — a 3 px rule
halved becomes 1.5 px and blurs.

Both the icon and the banner are optional as far as the directory is concerned:
a plugin without them is approved and listed just the same, with a grey
rectangle where the banner would be. They can be replaced at any time by
committing to `assets/` in SVN; no plugin release is involved, and the page
updates within minutes.
