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

## Deploy incrementale

È la funzionalità che distingue questo fork da Simply Static free, che
ripubblica tutto il sito a ogni salvataggio.

Il crawler riconosce già da sé quali pagine sono cambiate — confronta l'hash di
ogni pagina scaricata con quello dell'ultima volta, quindi le pagine dipendenti
da un post modificato si scoprono da sole, senza che nessuno debba dichiarare
chi dipende da chi. Quello che mancava era il lato deploy: `DeployCache::plan()`
confronta il sito generato con quello già pubblicato e dice cosa caricare, cosa
rimuovere e cosa lasciare stare, in una query sola.

Misurato sull'ambiente di sviluppo, 1806 file: dopo aver modificato **un** post,

```
Deploy plan: 13 to upload, 0 to remove, 1793 unchanged.
Directory deployment complete: 13 copied, 0 removed.
```

mezzo secondo invece di tre e mezzo. Con niente da cambiare, zero file e nessuna
scrittura. Cancellando un post, il file e la cartella rimasta vuota spariscono
anche a destinazione — così non restano URL morti online.

Il rapporto viene stampato **prima** di agire: un deploy incrementale che non
dice quanti file tocca non è verificabile, e chi non riesce a verificarlo
finisce per ricaricare tutto.

L'addon `addons/directory-deployment` è adottato nel fork. L'originale non ha
mai funzionato: il suo ultimo commit, del 2021, aveva lasciato un rinominamento
a metà con cinque guasti indipendenti — non si attivava, e se si attivava non
deployava e la sua pagina di configurazione dava un fatal error.

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

## Due readme, due lingue

Questo file è per chi lavora sul progetto, ed è in italiano come i commenti nel
codice e i messaggi dei commit. `readme.txt` è la scheda pubblica del plugin nel
formato di wordpress.org, ed è in inglese come l'interfaccia del plugin: chi lo
installa legge inglese, chi lo modifica legge italiano.

## Licenza

GPL-2.0-or-later. Vedi [LICENSE](./LICENSE).
