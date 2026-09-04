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
#
# L'ordine dei passi non è arbitrario. Le dipendenze di terze parti vengono
# prefissate da Strauss, che è una dipendenza di SVILUPPO: quindi
# l'installazione completa deve venire prima, non dopo. Un
# `composer install --no-dev` per primo lascerebbe il plugin senza client HTTP,
# perché Strauss non ci sarebbe per generarlo; e uno per ultimo rimetterebbe in
# vendor/ le copie NON prefissate che Strauss aveva appena tolto — cioè
# esattamente la collisione di namespace che si sta cercando di evitare.
# Da qui `dump-autoload --no-dev`, che riscrive l'autoloader senza reinstallare
# niente.

set -euo pipefail

command -v zip > /dev/null || { echo "Serve 'zip'. Installalo e riprova." >&2; exit 1; }

# Composer, ma non quello che si trova nel PATH. Quando questo script parte da
# `composer run-script build`, Composer antepone vendor/bin/ al PATH — e da
# quando Strauss e' fra le dipendenze di sviluppo, in vendor/bin/ c'e' un
# `composer` suo, che e' la libreria composer/composer e non l'eseguibile. Ogni
# chiamata qui dentro finirebbe li' dentro e morirebbe con un «Class
# XdebugHandler not found» che non dice niente di cio' che e' successo.
# $COMPOSER_BINARY e' il percorso del Composer realmente in esecuzione, ed e'
# Composer stesso a esportarlo.
COMPOSER="${COMPOSER_BINARY:-$(command -v composer || true)}"
[ -n "$COMPOSER" ] || { echo "Serve 'composer'." >&2; exit 1; }

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_FILE="$ROOT/vibestatic.php"
DIST="$ROOT/dist"

VERSION="$(grep -m1 -E '^\s*\*\s*Version:' "$PLUGIN_FILE" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Versione non trovata in $PLUGIN_FILE" >&2; exit 1; }

SLUG="vibestatic"
NAME="${1:-$SLUG-$VERSION}"
BUILD="$(mktemp -d)"

# Qualunque cosa succeda, il progetto torna con le dipendenze di sviluppo.
cleanup() {
    rm -rf "$BUILD"
    # Niente --quiet: se il ripristino fallisce (lock disallineato, rete giù)
    # si deve vedere. Con --quiet il progetto restava senza phpunit e senza
    # phpstan e non era ovvio perché.
    if ! ( cd "$ROOT" && "$COMPOSER" install ); then
        echo "ATTENZIONE: dipendenze di sviluppo non ripristinate. Lancia 'composer install'." >&2
    fi
}
trap cleanup EXIT

echo "Costruisco $NAME.zip (versione $VERSION)"

rm -rf "$ROOT/vendor" "$ROOT/vendor-prefixed"

# Installazione completa: serve Strauss, che gira da sé come post-install-cmd e
# produce vendor-prefixed/ togliendo da vendor/ le copie non prefissate.
( cd "$ROOT" && "$COMPOSER" install --quiet )

[ -d "$ROOT/vendor-prefixed" ] || {
    echo "vendor-prefixed/ non generata: Strauss non ha girato." >&2
    exit 1
}

# Autoloader di sola produzione, senza reinstallare: le dipendenze di sviluppo
# restano fisicamente in vendor/ ma non finiscono nella mappa, e soprattutto non
# finiscono nello zip — sotto si copiano solo autoload.php e composer/.
( cd "$ROOT" && "$COMPOSER" dump-autoload --quiet --no-dev --optimize --classmap-authoritative )

mkdir -p "$BUILD/$SLUG"
for item in src views languages vendor-prefixed; do
    [ -e "$ROOT/$item" ] && cp -R "$ROOT/$item" "$BUILD/$SLUG/"
done

# Di vendor/ serve il solo autoloader: le librerie di terze parti sono in
# vendor-prefixed/, e tutto il resto qui dentro è roba di sviluppo.
#
# `-maxdepth 1 -type f` non è pignoleria: dentro vendor/composer/ non ci sono
# solo i file generati dell'autoloader, c'è anche vendor/composer/composer/,
# cioè la LIBRERIA composer/composer, che arriva come dipendenza di Strauss.
# Un `cp -R` della cartella infilava nel plugin 371 file di un gestore di
# pacchetti — codice eseguibile che non ha niente a che fare con l'export di un
# sito statico.
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
