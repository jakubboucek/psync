# php-sync — poznámky pro vývoj

rsync-podobná obousměrná synchronizace přes HTTP agenta pro legacy hostingy (jen FTP).
Klient PHP 8.4+, agent PHP 7.4+ (bez závislostí, jen ext-sodium). Detailní uživatelská
dokumentace je v [README.md](README.md); tady jsou věci důležité pro úpravy kódu.

## Architektura

- **Klient** (`src/`, namespace `PhpSync\`) – Symfony Console, instalovatelný jako `composer global`.
- **Agent** (`agent/agent.template.php`) – šablona; `install` do ní zazdí veřejný klíč a
  protect-list (placeholdery `PHPSYNC_PUBLICKEY_PLACEHOLDER` a `/* PHPSYNC_PROTECT */`).
  Renderuje `PhpSync\Install\AgentBuilder`.
- Bohatý config (mapping, ignore) je **na klientu**; agent zná jen veřejný klíč, svůj root
  (`__DIR__`) a protect-list. Proto se `install` opakuje jen při rotaci klíče / změně protokolu.

## Protokol (verze v `Protocol::VERSION`)

- Vše **POST**, jeden endpoint. Akce v JSON těle; jen **upload** ji nese v hlavičce
  `X-Sync-Action` (tělo je binární).
- **Podpis Ed25519** přes kanonickou zprávu `action ⧺ ts ⧺ nonce ⧺ sha256(body)`
  (`Signer::canonical` na klientu, ručně zrcadleno v agentovi v `authenticate()`).
  **Když měníš tuto zprávu, uprav OBĚ strany a zvyš `Protocol::VERSION`.**
- Endpointy: `capabilities`, `list`, `hash` (NDJSON), `download` (binární framing odpověď),
  `upload` (binární framing tělo, NDJSON odpověď), `delete` (NDJSON).

### Wire formáty (drženy dvakrát — klient `Wire`/`FrameWriter`, agent inline — musí být bajt-identické!)
- **NDJSON**: cesty jsou **base64** (názvy na legacy serverech bývají non-UTF8/Windows-1250,
  `json_encode` by na nich selhal). Listing končí `{"end":true}`.
- **Binární frame**: `[u32 pathLen][path][u8 flags][u64 mtime][u64 origSize][u64 payloadLen][16 md5]`,
  big-endian (`pack N/C/J`), fixní část za cestou = **41 B**. `flags` bit0 = gzip payload.
  Definice: `Wire::packFrameHeader/readFrameHeader`; agent `frame_pack_header/frame_read_header`.

## Klíčové mechanismy

- **2-fázové porovnání** (`Comparator`): listing → kandidáti (shodná velikost, jiný mtime) →
  md5 (lokálně + dávkově na serveru, ≤100 MB/≤1000). `--checksum` hashuje vše.
- **StateCache** (`.php-sync-state.json` v local rootu, auto-ignorováno): klíč `base64(rel)`,
  verdikt rovnosti se reusne jen když sedí lokální size+mtime i remote mtime. Tím se
  nehashuje opakovaně soubor, který má jen rozdílný mtime (FTP nesedící čas).
- **Přenosy**: per-file frame, volitelně gzip (deflate/inflate streamovaně přes temp).
  Zápis cíle vždy **atomicky** (tmp + rename) + `touch()` mtime zdroje + ověření md5.
- **Dávkování & limity**: klient čte `capabilities` a podle nich dávkuje. Upload je omezen
  reálným `post_max_size` (tělo requestu) — **soubor větší než post_max_size bulk neprojde**
  a `Uploader` ho přeskočí s hláškou (chunked upload = budoucí TODO). Download tímto omezen není.
- **Resumabilita je primární** záruka korektnosti; NDJSON stream je jen průběžnost. Po pádu
  serveru se příkaz zopakuje (idempotentní; hotové soubory se přeskočí).

## Pozor (proč to tak je)

- **Capabilities čte původní hodnoty PŘED `prepare_runtime()`**: `set_time_limit(0)` vynuluje
  `max_execution_time` a agent vypíná `zlib.output_compression` — proto se obě zachytávají do
  `$CONFIG['_maxExecutionTime']` / `['_zlibOutputCompression']` na začátku. Nepřesouvej to za
  `prepare_runtime()`, jinak capabilities lžou.
- **zlib.output_compression** agent za běhu vypíná (`ini_set` + `no-gzip` + `Content-Encoding: identity`),
  aby nedošlo ke dvojí kompresi. Ověřeno, že to funguje i při `zlib.output_compression=On`.
- **Délka podpisu/klíče** se v agentovi kontroluje před `sodium_*verify` — jinak by malformed
  podpis házel výjimku → HTTP 500 místo 403.
- **Mazání**: `protect` filtruje klient i agent (dvě obranné linie). Protect brání **mazání**,
  ne přepisu při download (přebývající chráněné adresáře typicky patří i do `ignore`).
- **macOS klient neumí vytvořit non-UTF8 název** (APFS) — Windows-1250 názvy proto pokrývá jen
  `tests/protocol_test.php` na úrovni Wire, ne integrační testy.

## Kontrola kvality a testování

- **`composer check`** = `lint` (parallel-lint, vč. .phpt) + `phpstan` + `tester`. Pusť před commitem.
- **PHPStan level 8 + strict-rules** na `src/` + `bin/` (`phpstan.neon`). Agent je mimo (jiná cílová
  verze, procedurální) — kryje ho parallel-lint, lint na 7.4 a integrační testy. Level `max` se
  nedrží záměrně: hlásil by „cast mixed" na hranicích JSON/config, kde je koerce úmyslná.
- **Unit testy** (Nette Tester, `tests/Unit/*.phpt`): `IgnoreMatcher`, `Wire`, `Signer`, `FrameWriter`,
  `StateCache`, `Config` — čistá logika bez sítě/IO (kromě temp souborů).
- `php tests/agent_smoke.php render|check` — integrační test agenta (capabilities/list/hash/auth)
  proti běžícímu serveru; vyžaduje docker compose.
- `docker-compose.yml` — server PHP 7.4 (`jakubboucek/lamp-devstack-php:7.4-legacy`, pozn.
  legacy verze mají suffix `-legacy`; CLI varianta `-legacy-cli`) + klient PHP 8.4.
  Limity hostingu simuluje `tests/docker/limits.ini` (post_max_size=4M…), varianta
  `limits-zlib.ini` testuje zlib workaround.
- **Opcache**: po re-renderu agenta v `tests/remote` je nutný `docker compose restart server`,
  jinak server může chvíli držet starou verzi.
- `tests/{local*,remote}/`, `tests/*.config.php` a `tests/.smoke_priv` jsou v `.gitignore`
  (obsahují generovaný obsah a privátní klíče).
