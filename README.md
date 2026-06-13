# php-sync

Obousměrná, rsync-podobná synchronizace souborů přes HTTP agenta pro **legacy hostingy, kde je jediný přístup FTP** (žádné SSH/rsync).

Na server se jednorázově přes FTP nahraje jeden self-contained PHP soubor (agent). Klient (CLI, instalovaný jako `composer global`) ho pak volá přes HTTP(S) a umí soubory **porovnávat, nahrávat i stahovat** – i u aplikací s desítkami tisíc souborů, s ohledem na tvrdé limity sdílených hostingů.

- **Klient:** PHP 8.4+
- **Agent (server):** PHP 7.4+, bez Composer závislostí (jen `ext-sodium`)

## Jak to funguje

1. `php-sync install` vygeneruje pár klíčů **Ed25519** a serverového agenta s **veřejným** klíčem. Privátní klíč jde do tvého configu (a nikam jinam).
2. Agenta nahraješ přes FTP do adresáře, který má být remote rootem.
3. Klient podepisuje každý request privátním klíčem; agent ho ověří veřejným. **Únik agenta neumožní útočníkovi nic podepsat.**

## Instalace

```bash
composer global require jakubboucek/php-sync
```

## Konfigurace

`php-sync install` vygeneruje `php-sync.php`. Doplň `url` a `mapping.local`:

```php
<?php
return [
    'url'        => 'https://example.com/agent.php',
    'privateKey' => 'base64…',                 // z install, drž v tajnosti
    'mapping'    => ['local' => __DIR__, 'remote' => '/'],
    'ignore'     => ['/.git', '/vendor', '*.log', '/temp', '/uploads'],
    'protect'    => ['/uploads', '/temp'],     // nikdy se nemažou
    'checksum'   => false,                       // jako rsync -c
    'compress'   => true,                        // GZ při přenosu
    'compressSkipExt' => ['jpg','png','zip','gz','pdf','mp4'],
];
```

## Příkazy

```bash
php-sync install [-o agent.php] [-c php-sync.php]   # vygeneruj agenta + klíče
php-sync compare  [cesta] [-c …] [-v] [--checksum]  # vypiš rozdíly (nic nepřenáší)
php-sync upload   [cesta] [--delete] [--dry-run]    # local → remote
php-sync download [cesta] [--delete] [--dry-run]    # remote → local
```

- Volitelná **`cesta`** omezí operaci na podadresář/soubor.
- **`--delete`** smaže přebývající soubory (na druhé straně), **kromě `protect`**. Bez něj se nic nemaže.
- **`--dry-run`** jen vypíše, co by se přeneslo/smazalo.
- **`--checksum`** počítá hash vždy (ignoruje mtime i cache).

Legenda `compare`: `>` jen lokálně · `<` jen na serveru · `M` liší se · `=` shodné.

## Jak se detekují změny (2 fáze)

1. **Rychlá fáze** – listing (jméno, velikost, mtime) na obou stranách.
2. **Hash fáze** – jen pro soubory se shodnou velikostí a rozdílným mtime se dávkově (≤ 100 MB / ≤ 1000) spočítá md5. Výsledek se cachuje (`.php-sync-state.json`), takže se podruhé nehashuje.

Každý přenesený soubor dostane **mtime podle zdroje**, zápis je **atomický** (tmp + rename).

## Bezpečnost

- Podpisy **Ed25519**; server drží jen veřejný klíč. Funguje i přes plain HTTP (podpis chrání integritu i identitu, timestamp + nonce brání replay).
- **Privátní klíč v configu drž v tajnosti** a mimo veřejný git.
- Všechny requesty jsou **POST** (akce v těle, ne v URL) kvůli WAF; upload má GZ default zapnutý i pro text, aby WAF neoznačil PHP zdroj za RCE.
- Agent tvrdě **sanitizuje cesty** (žádné `../`, vše uvnitř rootu).

## Limity a poznámky

- **Upload souboru většího než `post_max_size` serveru** bulk mechanismem neprojde – soubor se přeskočí s jasnou hláškou (chunked upload je možné budoucí rozšíření). Download velkých souborů funguje.
- Přenáší se obsah a mtime; **vlastník/práva ne** (HTTP/FTP to neumí). Symlinky se nesledují.
- Operace jsou **resumovatelné**: po předčasném pádu serveru stačí příkaz zopakovat (dokončené soubory se přeskočí, zbytek se dopočítá).

## Vývoj a testování

Testovací prostředí je v `docker-compose.yml` (server = PHP 7.4 Apache, klient = PHP 8.4 CLI):

```bash
docker compose up -d
docker compose exec client php bin/php-sync compare -c tests/sync.config.php
php tests/protocol_test.php          # unit testy protokolu
```
