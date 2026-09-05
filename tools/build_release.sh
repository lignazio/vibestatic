#!/usr/bin/env bash
#
# Builds the distributable plugin ZIP into dist/.
#
#   tools/build_release.sh [filename-without-extension]
#
# With no argument the name comes from the version declared in the plugin's main
# file, which is the single source of truth: it used to be passed by hand, and
# nothing stopped you producing a zip that said 7.1 with 7.2 inside it.
#
# Compared with the previous version:
#   - the destination is dist/, not a hardcoded $HOME/Downloads;
#   - `rm -Rf "$DIR/vendor/*"` was quoted, so the glob never expanded and
#     deleted nothing: the "cleanup" of dev dependencies never happened, and it
#     worked by accident because `composer install --no-dev` removes them
#     anyway. The directory is now actually deleted;
#   - dev dependencies are restored even if the script fails halfway, via a
#     trap: an error used to leave the project without phpunit and phpstan, and
#     it was not obvious why.
#
# The order of the steps is not arbitrary. Third-party dependencies are prefixed
# by Strauss, which is a DEV dependency: so the full install has to come first,
# not last. A `composer install --no-dev` first would leave the plugin without
# an HTTP client, because Strauss would not be there to generate it; and one
# last would put the NON-prefixed copies Strauss had just removed back into
# vendor/ — that is, exactly the namespace collision being avoided. Hence
# `dump-autoload --no-dev`, which rewrites the autoloader without reinstalling
# anything.

set -euo pipefail

command -v zip > /dev/null || { echo "Serve 'zip'. Installalo e riprova." >&2; exit 1; }

# Composer, but not the one found on the PATH. When this script is started by
# `composer run-script build`, Composer prepends vendor/bin/ to the PATH — and
# since Strauss became a dev dependency, vendor/bin/ holds a `composer` of its
# own, which is the composer/composer library and not the executable. Every call
# in here would end up there and die with a "Class XdebugHandler not found" that
# says nothing about what actually happened. $COMPOSER_BINARY is the path of the
# Composer actually running, and Composer itself exports it.
COMPOSER="${COMPOSER_BINARY:-$(command -v composer || true)}"
[ -n "$COMPOSER" ] || { echo "Serve 'composer'." >&2; exit 1; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_FILE="$ROOT/vibestatic.php"
DIST="$ROOT/dist"

VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$PLUGIN_FILE" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Versione non trovata in $PLUGIN_FILE" >&2; exit 1; }

# The version lives in two places — the header, which WordPress reads as text,
# and the constant, which the code reads — and there is no way to reduce them to
# one without re-reading the file on every load. What can be done is not letting
# them diverge silently.
CONSTANT_VERSION="$(grep -m1 -E "define\( 'VIBESTATIC_VERSION'" "$PLUGIN_FILE" | sed -E "s/.*'([^']+)' *\).*/\1/")"
if [ "$VERSION" != "$CONSTANT_VERSION" ]; then
    echo "L'header dice $VERSION, VIBESTATIC_VERSION dice $CONSTANT_VERSION." >&2
    exit 1
fi

SLUG="vibestatic"
NAME="${1:-$SLUG-$VERSION}"
BUILD="$(mktemp -d)"

# Whatever happens, the project comes back with its dev dependencies.
cleanup() {
    rm -rf "$BUILD"
    # No --quiet: if the restore fails (lock out of step, network down) it has
    # to be visible. With --quiet the project was left without phpunit and
    # phpstan and it was not obvious why.
    if ! ( cd "$ROOT" && "$COMPOSER" install ); then
        echo "ATTENZIONE: dipendenze di sviluppo non ripristinate. Lancia 'composer install'." >&2
    fi
}
trap cleanup EXIT

echo "Costruisco $NAME.zip (versione $VERSION)"

rm -rf "$ROOT/vendor" "$ROOT/vendor-prefixed"

# Full install: Strauss is needed, and it runs by itself as post-install-cmd,
# producing vendor-prefixed/ and removing the unprefixed copies from vendor/.
( cd "$ROOT" && "$COMPOSER" install --quiet )

[ -d "$ROOT/vendor-prefixed" ] || {
    echo "vendor-prefixed/ non generata: Strauss non ha girato." >&2
    exit 1
}

# Production-only autoloader, without reinstalling: the dev dependencies stay
# physically in vendor/ but do not enter the map, and above all do not enter the
# zip — below, only autoload.php and composer/ are copied.
( cd "$ROOT" && "$COMPOSER" dump-autoload --quiet --no-dev --optimize --classmap-authoritative )

mkdir -p "$BUILD/$SLUG"
for item in src views languages vendor-prefixed; do
    [ -e "$ROOT/$item" ] && cp -R "$ROOT/$item" "$BUILD/$SLUG/"
done

# From vendor/ only the autoloader is needed: the third-party libraries are in
# vendor-prefixed/, and everything else in here is development material.
#
# `-maxdepth 1 -type f` is not pedantry: vendor/composer/ holds more than the
# generated autoloader files, it also holds vendor/composer/composer/ — the
# composer/composer LIBRARY, which arrives as a dependency of Strauss. A `cp -R`
# of the directory pushed 371 files of a package manager into the plugin —
# executable code with nothing to do with exporting a static site.
mkdir -p "$BUILD/$SLUG/vendor/composer"
cp "$ROOT/vendor/autoload.php" "$BUILD/$SLUG/vendor/"
find "$ROOT/vendor/composer" -maxdepth 1 -type f -exec cp {} "$BUILD/$SLUG/vendor/composer/" \;
cp "$ROOT"/*.php "$BUILD/$SLUG/"
[ -f "$ROOT/readme.txt" ] && cp "$ROOT/readme.txt" "$BUILD/$SLUG/"
[ -f "$ROOT/LICENSE" ] && cp "$ROOT/LICENSE" "$BUILD/$SLUG/"

find "$BUILD" -type d -exec chmod 755 {} \;
find "$BUILD" -type f -exec chmod 644 {} \;

mkdir -p "$DIST"
rm -f "$DIST/$NAME.zip"
( cd "$BUILD" && zip --quiet -r -9 "$DIST/$NAME.zip" "./$SLUG" )

echo "dist/$NAME.zip  ($(du -h "$DIST/$NAME.zip" | cut -f1))"
