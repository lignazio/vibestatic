# patches/

Patch applicate alle dipendenze da `cweagans/composer-patches`.

## `wp2staticpromises-nullable-params.patch`

**Va via alla fase 4**, quando `leonstafford/wp2staticpromises` viene sostituito da
`guzzlehttp/promises` upstream prefissato con php-scoper. Non è una correzione da mantenere:
è un ponte.

Il fork è fermo al 10 dicembre 2020 e dichiara sei parametri con nullable implicito
(`callable $onFulfilled = null`), deprecato da PHP 8.4. Le sei deprecation vengono emesse al
**caricamento dell'autoloader**, cioè prima che qualunque codice giri.

Il danno non è il rumore nei log. È che PHPUnit, per i test marcati
`@runTestsInSeparateProcesses`, parla col processo figlio attraverso il suo output: sei righe
di `Deprecated:` in mezzo lo rendono illeggibile, e **ogni** test isolato fallisce con un
messaggio d'errore vuoto. Misurato: `SimpleRewriterTest` e `FileHelperTest`, **32 test su 64**,
fallivano tutti. Con la patch: `OK (64 tests, 119 assertions)`.

Metà della suite era spenta da una dipendenza, e falliva in un modo che non nominava la
dipendenza. È la ragione più concreta per cui il fork di Guzzle va abbandonato.
