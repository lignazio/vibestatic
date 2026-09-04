# Workflow

## `quality.yml`
Sostituisce `codequality.yml`, che era **morto, non degradato**: usava
`actions/checkout@v2` e `actions/cache@v1`, entrambe disattivate da GitHub, quindi ogni job
falliva al primo passo. Testava PHP 7.4/8.0/8.1; ora la matrice è 8.2/8.3/8.4.

## `integration-tests.yml.frozen`
**Congelato, non riparato**, ed è una decisione, non una dimenticanza.

Quel workflow faceva rivivere un ambiente Nix pinnato a nixpkgs `release-22.11` — fuori
supporto dal 2023, e dove `php80`/`php81` non esistono più nelle release recenti — per
eseguire **sei** `deftest` scritti in **Clojure** contro WordPress **6.1.1**. Rimetterlo in
piedi significa resuscitare tre catene di strumenti obsolete per sei asserzioni.

Al loro posto, gli stessi quattro comportamenti (crawl, detect, opzioni, post-process) si
verificano sull'ambiente di sviluppo in `dev/`, con i comandi WP-CLI che il plugin già espone
in `src/CLI.php`, su WordPress corrente. L'estensione `.frozen` fa sì che GitHub lo ignori pur
lasciandolo leggibile: se un giorno qualcuno vuole recuperarlo, il codice c'è.
