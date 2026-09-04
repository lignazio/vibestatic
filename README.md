# VibeStatic

Generazione e pubblicazione di siti statici da WordPress.

VibeStatic è un fork di [WP2Static](https://github.com/elementor/wp2static), creato
da Leon Stafford e mantenuto da Strattic (Elementor) fino al 2024. L'ultimo commit
di codice dell'originale è del 2023; il progetto aveva 1470 stelle e nessun erede.
L'originale è rilasciato nel pubblico dominio (Unlicense); questo fork esce sotto
**GPLv2 o successiva**, che è lo standard di WordPress.

## Cosa cambia rispetto all'originale

- **PHP 8.2, 8.3 e 8.4.** Il codice si parsa e gira su tutte e tre senza una
  deprecation.
- **Le violazioni di sicurezza passano da 302 a zero.** Fra queste una SQL
  injection reale, tre handler che verificavano il nonce *dopo* aver scritto, e
  `current_user_can()` assente su ventiquattro handler su ventiquattro. La
  configurazione del linter escludeva esattamente i cinque controlli che le
  avrebbero trovate; ora sono accesi e bloccano la CI.
- **Guzzle aggiornato.** L'originale imbarcava un fork del 2020 senza le patch
  di sicurezza successive. Qui c'è Guzzle upstream, prefissato al momento
  dell'installazione da [Strauss](https://github.com/BrianHenryIE/strauss) perché
  non collida con quello di altri plugin.
- **Nessun codice promozionale.** Sparita la tabella di annunci pubblicitari e il
  pixel di tracciamento che a ogni caricamento di una pagina admin trasmetteva a
  un server esterno l'URL del sito e quello di deploy.
- **Test veri.** Le classi che decidono cosa è cambiato e cosa va ripubblicato
  ora ricevono le proprie dipendenze dall'esterno e hanno una suite che le copre.

## In lavorazione

**Deploy incrementale.** Il crawler sa già riconoscere quali pagine sono
cambiate — misurato: modificando un post, 7 pagine su 1828 — ma il deployer su
directory ricopia tutto lo stesso, 1822 file su 1822. È la funzionalità che
distingue questo fork, ed è il prossimo lavoro grosso.

## Compatibilità con gli addon

**L'API non cambia.** Il rinominamento vale per quello che si legge e per quello
che sta su disco; non tocca niente che un addon possa chiamare:

| Resta `wp2static` | Diventa `vibestatic` |
|---|---|
| il namespace PHP `WP2Static\` | il nome del plugin e le stringhe dell'interfaccia |
| i 30 hook `wp2static_*` | il file principale e la cartella del plugin |
| le 8 tabelle `wp_wp2static_*` | il text domain |
| gli slug delle pagine admin | le costanti `VIBESTATIC_PATH` e `VIBESTATIC_VERSION` |
| le azioni `admin_post_wp2static_*` | il comando WP-CLI |

`wp wp2static` continua a rispondere accanto a `wp vibestatic`: è in script di
deploy e in cron di chi il plugin lo usava già.

## Installazione

- da questo codice sorgente: `git clone` e poi `composer install` nella cartella
  del plugin — l'installazione compila anche le dipendenze prefissate;
- da zip: `composer run-script build` produce `dist/vibestatic-<versione>.zip`.

Richiede PHP 8.2 o successivo.

## Sviluppo

```
composer test        # tutto quello che gira in CI ed è bloccante
composer phpunit     # solo i test unitari
composer phpcs       # stile e documentazione (non bloccante, debito noto)
composer build       # lo zip distribuibile in dist/
```

`composer install` lancia da sé Strauss, che genera `vendor-prefixed/`: senza
quella cartella il plugin non ha un client HTTP. Non va lanciato a mano.

I test che fanno richieste di rete reale stanno in una suite a parte,
`composer phpunit-external`, e non girano in CI: falliscono quando cambia il
sito di qualcun altro, e in quel caso non dicono niente su questo codice.

I test d'integrazione dell'originale erano sei `deftest` in Clojure su NixOS
puntati a nixpkgs 22.11, fuori supporto dal 2023: sono congelati, non rimossi.
Vedi `integration-tests/` e `.github/workflows/README.md`.

## Licenza

GPL-2.0-or-later. Vedi [LICENSE](./LICENSE).
