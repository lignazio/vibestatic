#!/usr/bin/env bash
#
# Builds the distributable plugin ZIP into dist/.
#
#   tools/build_release.sh [--wporg] [filename-without-extension]
#
# With no argument the name comes from the version declared in the plugin's main
# file, which is the single source of truth: it used to be passed by hand, and
# nothing stopped you producing a zip that said 7.1 with 7.2 inside it.
#
# --wporg builds the package for the wordpress.org directory, which is the same
# plugin minus the ability to update itself. Guideline 8 forbids a plugin hosted
# there from "serving updates or otherwise installing plugins, themes, or
# add-ons from servers other than WordPress.org's". Three differences, and only
# three:
#
#   - `src/Updater.php` deleted;
#   - `src/Addon/Updater.php` replaced by the inert shim in tools/wporg/, not
#     deleted — an add-on published at 1.0.0 calls it from `plugins_loaded`, and
#     a missing class there is a white screen. Testing the package is what
#     established that, not reasoning about it;
#   - the `Update URI` header removed, which is the thing the directory's Plugin
#     Check actually names.
#
# There is no second copy of the source: this is one build of one tree.
#
# The GitHub package keeps all three, because there they are the only way a site
# installed from a zip receives so much as a security fix.
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

WPORG=0
ARGS=()
for arg in "$@"; do
    case "$arg" in
        --wporg) WPORG=1 ;;
        -*) echo "Opzione non riconosciuta: $arg" >&2; exit 1 ;;
        *) ARGS+=("$arg") ;;
    esac
done
set -- ${ARGS+"${ARGS[@]}"}

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
if [ "$WPORG" -eq 1 ]; then
    NAME="${1:-$SLUG-$VERSION-wporg}"
else
    NAME="${1:-$SLUG-$VERSION}"
fi
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
# addons/ is in this list, and that is the whole point of it being here: the
# bundled deployers used to be separate plugins outside the package, so a zip
# install could crawl a site and process it and then had nowhere to put it.
for item in src views languages vendor-prefixed addons; do
    [ -e "$ROOT/$item" ] && cp -R "$ROOT/$item" "$BUILD/$SLUG/"
done

# Whatever development left inside a module. There are no per-module
# dependencies today — phpseclib went into the core's own, prefixed by Strauss
# — but a vendor/ or a composer.lock reaching a release is how an unprefixed
# copy of a common library ends up in someone's WordPress.
if [ -d "$BUILD/$SLUG/addons" ]; then
    find "$BUILD/$SLUG/addons" -maxdepth 2 -name vendor -type d -exec rm -rf {} +
    find "$BUILD/$SLUG/addons" -maxdepth 2 \
        \( -name 'composer.json' -o -name 'composer.lock' -o -name '.gitignore' \) \
        -delete
fi

# What the dependencies ship for their own developers, and which has no business
# being in a WordPress plugin. This is not tidiness: wordpress.org's uploader
# refused the package outright over one of these files —
#
#   Error: The plugin contains unexpected files. The following files are not
#   permitted in plugins: build-phar.sh.
#
# — which is `paragonie/random_compat/build-phar.sh`, a five-line wrapper for
# building a phar, in a polyfill for PHP 5 that phpseclib requires and that does
# nothing at all on 8.2. The directory's own Plugin Check does not look at this,
# so only the upload found it.
#
# Deleted by category rather than by name. A dependency shipping a shell script,
# a psalm config or a docs/ directory is the normal case, not this one package's
# quirk, and naming the file would leave the next one to be found the same way.
#
# What is NOT touched: anything a library needs at runtime. phpseclib's
# `openssl.cnf` is a real data file — it is passed to OpenSSL when generating
# keys — which is why this is a list of what development material looks like and
# not an allowlist of `*.php` plus licences.
if [ -d "$BUILD/$SLUG/vendor-prefixed" ]; then
    find "$BUILD/$SLUG/vendor-prefixed" -mindepth 2 \
        \( -name '*.sh' -o -name '*.bat' -o -name '*.ps1' -o -name '*.phar' \
           -o -name '*.md' -o -name '*.asc' -o -name '*.pubkey' \
           -o -name 'psalm.xml' -o -name 'psalm-autoload.php' \
           -o -name 'phpunit.xml*' -o -name 'phpstan*' -o -name '.editorconfig' \
           -o -name 'composer.json' -o -name 'composer.lock' \) \
        -type f -delete

    find "$BUILD/$SLUG/vendor-prefixed" -mindepth 2 \
        \( -name docs -o -name tests -o -name test -o -name build -o -name dist \
           -o -name '.github' \) \
        -type d -exec rm -rf {} +

    # Licences stay: guideline 1 is about being able to see what everything in
    # here is licensed under, and every one of the eleven ships its own.
    LICENCES="$(find "$BUILD/$SLUG/vendor-prefixed" -iname 'LICENSE*' -type f | wc -l | tr -d ' ')"
    [ "$LICENCES" -ge 11 ] || {
        echo "Solo $LICENCES licenze in vendor-prefixed/: la potatura ne ha prese di troppo." >&2
        exit 1
    }
fi

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

# macOS leaves one of these in every directory it has been looked at in, and
# they were going into the zip.
find "$BUILD" -name '.DS_Store' -delete

# Hidden files are refused outright by the directory's Plugin Check, in either
# build: there is nothing in this package that has any business being invisible.
find "$BUILD/$SLUG" -name '.*' -not -name '.' -delete

if [ "$WPORG" -eq 1 ]; then
    # The core's own Updater, out. Nothing calls it unconditionally —
    # WordPressAdmin asks class_exists() first — so its absence is a plugin that
    # does not look for updates, not a plugin that breaks.
    rm -f "$BUILD/$SLUG/src/Updater.php"

    # The add-ons' Updater, replaced rather than removed, and the difference was
    # measured: deleting it made every add-on published at 1.0.0 fatal with
    # `Class "WP2Static\Addon\Updater" not found` during plugins_loaded, because
    # each one calls register() from there. The shim accepts the call and does
    # nothing — no request, no filter, no update served.
    cp "$ROOT/tools/wporg/addon-updater-noop.php" "$BUILD/$SLUG/src/Addon/Updater.php"

    grep -q 'api.github.com' "$BUILD/$SLUG/src/Addon/Updater.php" && {
        echo "Lo shim dell'Addon Updater parla ancora con GitHub." >&2
        exit 1
    }

    # And the header, which is the thing Plugin Check actually names. Deleting
    # the line rather than blanking the value: an empty `Update URI` is still an
    # `Update URI`, and WordPress reads the presence of the header, not its
    # content, when deciding whether to skip wordpress.org.
    sed -i.bak '/^ \* Update URI:/d' "$BUILD/$SLUG/vibestatic.php"
    rm -f "$BUILD/$SLUG/vibestatic.php.bak"

    # Anchored to the header line. An unanchored 'Update URI' also matches the
    # docblock prose that explains the header, which is not a header and which
    # the first version of this check failed the build over.
    grep -qE '^ \* Update URI:' "$BUILD/$SLUG/vibestatic.php" && {
        echo "L'header Update URI e' ancora nel pacchetto wordpress.org." >&2
        exit 1
    }

    # The classmap was generated while both files were still there, and the map
    # is authoritative: `class_exists( Updater::class )` would send the
    # autoloader straight to a `require` of a file that is not in the zip. A
    # fatal error, raised by the very check meant to establish the class is
    # absent. So the two entries come out of both generated maps.
    #
    # Filtered on the file path rather than the class name, so that what is
    # removed and what is verified are the same string. The first version
    # matched the class name with `\(Addon\)\?`, which is a GNU sed extension:
    # BSD sed, which is the sed on the machine this is built on, matched nothing
    # and the build failed on its own check.
    for map in autoload_classmap.php autoload_static.php; do
        file="$BUILD/$SLUG/vendor/composer/$map"

        grep -v "/src/Updater.php'" "$file" > "$file.new"
        mv "$file.new" "$file"

        if grep -q "'/src/Updater.php'" "$file"; then
            echo "$map nomina ancora src/Updater.php." >&2
            exit 1
        fi

        # And Addon/Updater.php had better still be in there: the shim is at
        # that path, and an authoritative classmap that does not name it means
        # the add-ons' call finds nothing after all.
        if ! grep -q "/src/Addon/Updater.php'" "$file"; then
            echo "$map non nomina piu' src/Addon/Updater.php." >&2
            exit 1
        fi

        php -l "$file" > /dev/null
    done
fi

find "$BUILD" -type d -exec chmod 755 {} \;
find "$BUILD" -type f -exec chmod 644 {} \;

mkdir -p "$DIST"
rm -f "$DIST/$NAME.zip"
( cd "$BUILD" && zip --quiet -r -9 "$DIST/$NAME.zip" "./$SLUG" )

echo "dist/$NAME.zip  ($(du -h "$DIST/$NAME.zip" | cut -f1))"
