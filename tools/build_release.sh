#!/usr/bin/env bash
#
# Costruisce lo ZIP distribuibile del plugin in dist/.
#
#   tools/build_release.sh [nome-file-senza-estensione]
#
# Senza argomento il nome viene dalla versione dichiarata nel file principale
# del plugin, che è l'unica sorgente di verità: prima andava passato a mano, e
# nulla impediva di produrre uno zip che diceva 7.1 con dentro la 7.2.
#
# Rispetto alla versione precedente:
#   - la destinazione è dist/, non $HOME/Downloads hardcoded;
#   - `rm -Rf "$DIR/vendor/*"` era quotato, quindi il glob non si espandeva e
#     non cancellava niente: la "pulizia" delle dipendenze di sviluppo non
#     avveniva mai, e funzionava per caso perché `composer install --no-dev`
#     le rimuove comunque. Ora la cartella si cancella davvero;
#   - le dipendenze di sviluppo vengono ripristinate anche se lo script
#     fallisce a metà, con una trap: prima un errore lasciava il progetto
#     senza phpunit e senza phpstan, e non era ovvio perché.

set -euo pipefail

command -v zip > /dev/null || { echo "Serve 'zip'. Installalo e riprova." >&2; exit 1; }
command -v composer > /dev/null || { echo "Serve 'composer'." >&2; exit 1; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_FILE="$ROOT/wp2static.php"
DIST="$ROOT/dist"

VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$PLUGIN_FILE" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Versione non trovata in $PLUGIN_FILE" >&2; exit 1; }

SLUG="wp2static"
NAME="${1:-$SLUG-$VERSION}"
BUILD="$(mktemp -d)"

# Qualunque cosa succeda, il progetto torna con le dipendenze di sviluppo.
cleanup() {
    rm -rf "$BUILD"
    # Niente --quiet: se il ripristino fallisce (lock disallineato, rete giù)
    # si deve vedere. Con --quiet il progetto restava senza phpunit e senza
    # phpstan e non era ovvio perché.
    if ! ( cd "$ROOT" && composer install ); then
        echo "ATTENZIONE: dipendenze di sviluppo non ripristinate. Lancia 'composer install'." >&2
    fi
}
trap cleanup EXIT

echo "Costruisco $NAME.zip (versione $VERSION)"

rm -rf "$ROOT/vendor"
( cd "$ROOT" && composer install --quiet --no-dev --optimize-autoloader --classmap-authoritative )

mkdir -p "$BUILD/$SLUG"
for item in src vendor views languages; do
    [ -e "$ROOT/$item" ] && cp -R "$ROOT/$item" "$BUILD/$SLUG/"
done
cp "$ROOT"/*.php "$BUILD/$SLUG/"
[ -f "$ROOT/readme.txt" ] && cp "$ROOT/readme.txt" "$BUILD/$SLUG/"
[ -f "$ROOT/LICENSE" ] && cp "$ROOT/LICENSE" "$BUILD/$SLUG/"

find "$BUILD" -type d -exec chmod 755 {} \;
find "$BUILD" -type f -exec chmod 644 {} \;

mkdir -p "$DIST"
rm -f "$DIST/$NAME.zip"
( cd "$BUILD" && zip --quiet -r -9 "$DIST/$NAME.zip" "./$SLUG" )

echo "dist/$NAME.zip  ($(du -h "$DIST/$NAME.zip" | cut -f1))"
