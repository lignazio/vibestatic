#!/usr/bin/env bash
#
# Re-renders the icon and the banner from the two HTML sources beside this
# script, into the directory above.
#
#   .wordpress-org/src/render.sh
#
# The sources are HTML and not a binary from a design tool on purpose: the whole
# design is thirty lines of CSS on a declared grid, so it can be read, changed
# and rebuilt by whoever comes next — including the choice of every measurement,
# which is written down where it is used.
#
# Chrome is the renderer because it is the one already on the machine that
# rasterises text the way a browser does, and because `--screenshot` with an
# exact `--window-size` gives an exact pixel size with no resampling step.
#
# The 1x sizes are rendered natively rather than downscaled from the 2x ones: a
# 3px rule reduced by half becomes 1.5px and blurs, while a second render puts
# the glyphs on the pixel grid at their real size.
#
# Chrome headless does not always exit after writing the file, so each render is
# started in the background, waited for by watching the file appear, and then
# killed. Without that the script hangs for as long as the shell allows.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$(dirname "$HERE")"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"

[ -x "$CHROME" ] || { echo "Chrome non trovato: $CHROME (si puo' passare in \$CHROME)" >&2; exit 1; }

PROFILE="$(mktemp -d)"
trap 'rm -rf "$PROFILE"' EXIT

render() {
    local out="$1" src="$2" size="$3"

    rm -f "$OUT/$out"

    "$CHROME" --headless --disable-gpu --hide-scrollbars --no-first-run \
        --user-data-dir="$PROFILE" --window-size="$size" \
        --screenshot="$OUT/$out" --virtual-time-budget=3000 \
        "file://$HERE/$src" > /dev/null 2>&1 &

    local pid=$!

    for _ in $(seq 1 30); do
        [ -s "$OUT/$out" ] && break
        perl -e 'select undef, undef, undef, 0.4'
    done

    perl -e 'select undef, undef, undef, 0.8'
    kill "$pid" 2> /dev/null || true
    wait "$pid" 2> /dev/null || true

    [ -s "$OUT/$out" ] || { echo "$out non prodotto" >&2; exit 1; }

    printf '%-22s %s\n' "$out" \
        "$(sips -g pixelWidth -g pixelHeight "$OUT/$out" | awk '/pixel/ { printf "%s ", $2 }')"
}

# The 1x variants differ from the 2x ones only in the measurements at the top of
# each source, so they are produced by overriding those and nothing else.
half() {
    local src="$1" out="$2"
    shift 2
    sed "$@" "$HERE/$src" > "$HERE/.$out"
    echo ".$out"
}

render icon-256x256.png icon.html 256,256
render banner-1544x500.png banner.html 1544,500

ICON_1X="$(half icon.html icon-1x.html \
    -e 's/--cella: 56px/--cella: 28px/' \
    -e 's/--gutter: 12px/--gutter: 6px/' \
    -e 's/width: 256px; height: 256px/width: 128px; height: 128px/')"

BANNER_1X="$(half banner.html banner-1x.html \
    -e 's/html, body { width: 1544px; height: 500px; }/html, body { width: 772px; height: 250px; overflow: hidden; }\n  .testo, .mark { zoom: 0.5; }/')"

render icon-128x128.png "$ICON_1X" 128,128
render banner-772x250.png "$BANNER_1X" 772,250

rm -f "$HERE/$ICON_1X" "$HERE/$BANNER_1X"
