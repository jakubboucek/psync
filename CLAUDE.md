# psync — development notes

rsync-like bidirectional synchronization over an HTTP agent for legacy hostings (FTP-only).
Client PHP 8.4+, agent PHP 7.4+ (no dependencies, just ext-sodium). Detailed user
documentation is in [README.md](README.md); here are the things important for editing the code.

## Architecture

- **Client** (`src/`, namespace `JakubBoucek\Psync\`) — Symfony Console, installable as `composer global`.
- **Agent** (`agent/agent.template.php`) — a template; `install` bakes in the public key and
  the protect-list (placeholders `PSYNC_PUBLICKEY_PLACEHOLDER` and `/* PSYNC_PROTECT */`).
  Rendered by `JakubBoucek\Psync\Install\AgentBuilder`. `install` writes it under a **randomized
  filename** `psync-agent-<nonce>.php` (6 hex chars via `random_bytes(3)`) so the URL can't be
  scanned for; pass `-o` to override. The template's header comment is an on-purpose reassurance for
  anyone auditing the site later (it's a maintenance tool, not a webshell; safe to delete) — keep it.
  The agent lives in its own namespace `JakubBoucek\Psync\Agent` purely so its ~30 procedural
  functions/constants don't pollute or clash (e.g. `handle_upload`) with a host project in an IDE; it
  runs as its own HTTP entry point. Built-in functions and global constants fall back to global inside
  a namespace, so the agent body stays unqualified (no prefixing needed).
- The rich config (mapping, ignore) lives **on the client**; the agent only knows the public key, its own root
  (`__DIR__`), and the protect-list. That is why `install` is repeated only on key rotation / protocol change.

## Protocol (version in `Protocol::VERSION`)

- Everything is **POST**, a single endpoint. The action is in the JSON body; only **upload** carries it in the
  `X-Psync-Action` header (the body is binary).
- **Ed25519 signature** over the canonical message `action ⧺ ts ⧺ nonce ⧺ sha256(body)`
  (`Signer::canonical` on the client, mirrored by hand in the agent's `authenticate()`).
  **When you change this message, update BOTH sides and bump `Protocol::VERSION`.**
- **Version enforcement**: the client sends `X-Psync-Version` on every request; the agent rejects a
  missing/mismatched version with **HTTP 426 before auth** (`check_protocol_version`), except
  `capabilities` which stays exempt so the client can discover the agent's version. The client also
  hard-fails up front in `HttpClient::capabilities()`. Version is **not** in the signature (a tampered
  header only self-DoSes a MITM). A `Protocol::VERSION` bump therefore forces a re-`install`.
- Endpoints: `capabilities`, `list`, `hash` (NDJSON), `download` (binary framing response),
  `upload` (binary framing body, NDJSON response), `delete` (NDJSON), `mkdir` (NDJSON).

### Wire formats (held in two places — client `Wire`/`FrameWriter`, agent inline — must be byte-identical!)
- **NDJSON**: paths are **base64** (names on legacy servers tend to be non-UTF8/Windows-1250,
  and `json_encode` would fail on them). A listing ends with `{"end":true}`.
- **Entry type (`t`)**: directories are first-class **presence-only** entries. The `list` line carries
  `t` (a one-letter type code: `'d'` for a directory); a regular **file omits `t`** (lazy default, so the
  common line stays `{p,s,m}`). `delete` likewise takes `{paths:[{p,t?}]}` — the client declares each
  entry's type and the agent acts on it **strictly** (`unlink` for a file, **non-recursive** `rmdir` for
  a dir, refusing the wrong type). `mkdir` takes `{paths:[base64]}` (always dirs) → `{p,ok,err}`. The `t`
  scheme is forward-compatible (a future `'l'` for symlinks slots in; the client skips-and-warns on an
  unknown code). Type lives on the client as the `FileType` enum (`src/Sync/FileType.php`).
- **Binary frame**: `[u32 pathLen][path][u8 flags][u64 mtime][u64 origSize][u64 payloadLen][16 md5]`,
  big-endian (`pack N/C/J`), the fixed part after the path = **41 B**. `flags` bit0 = gzip payload.
  Definitions: `Wire::packFrameHeader/readFrameHeader`; agent `frame_pack_header/frame_read_header`.

## Key mechanisms

- **2-phase comparison** (`Comparator`): listing → candidates (identical size, different mtime) →
  md5 (locally + in batches on the server, ≤100 MB/≤1000). `--checksum` hashes everything.
- **Auto-ignored, never uploaded** (`AbstractSyncCommand::buildIgnore`): the state cache
  (`.psync-state.json`) and the **config file itself** (it holds the private key) — the config is ignored
  by its real path relative to `localRoot`, regardless of how `--config` named/located it, so the key
  can't leak even with a renamed/relocated config.
- **StateCache** (`.psync-state.json` in the local root, auto-ignored): key `base64(rel)`,
  the equality verdict is reused only when the local size+mtime and the remote mtime match. This way a
  file that only has a differing mtime (FTP clock mismatch) is not hashed repeatedly.
- **Transfers**: per-file frame, optionally gzip (deflate/inflate streamed through a temp file).
  The target is always written **atomically** (tmp + rename) + `touch()` the source mtime + md5 verification.
- **Batching & limits**: the client reads `capabilities` and batches accordingly. Upload is limited
  by the real `post_max_size` (the request body) — **a file larger than post_max_size will not pass through bulk**
  and `Uploader` skips it with a message (chunked upload = a future TODO). Download is not limited by this.
- **Resumability is the primary** correctness guarantee; the NDJSON stream is only for progress. After a server
  crash, the command is rerun (idempotent; finished files are skipped).

## Watch out (why it is the way it is)

- **Capabilities reads the original values BEFORE `prepare_runtime()`**: `set_time_limit(0)` zeroes out
  `max_execution_time` and the agent disables `zlib.output_compression` — that is why both are captured into
  `$CONFIG['_maxExecutionTime']` / `['_zlibOutputCompression']` at the start. Do not move it after
  `prepare_runtime()`, otherwise capabilities will lie.
- **zlib.output_compression** is disabled at runtime by the agent (`ini_set` + `no-gzip` + `Content-Encoding: identity`),
  to avoid double compression. Verified to work even with `zlib.output_compression=On`.
- **The signature/key length** is checked in the agent before `sodium_*verify` — otherwise a malformed
  signature would throw an exception → HTTP 500 instead of 403.
- **Deletion**: `protect` is filtered by both client and agent (two lines of defense). Protect prevents **deletion**,
  not overwriting during download (extra protected directories typically belong to `ignore` as well).
- **Directories** (`Walker`/agent `walk_files` emit them, `Comparator` compares by presence): created on
  sync (incl. empty ones — that is the whole point) via `mkdir -p` (remote = `mkdir` action, local =
  direct). Removed only with `--delete`, **non-recursive**: the client orders deletions deepest-first
  (`AbstractSyncCommand::sortDeepestFirst`) so contents go before the dir; the agent's `rmdir` fails on a
  non-empty dir (e.g. content hidden by the client's `ignore`) — surfaced as an error (run continues,
  exits non-zero), never force-deleted. A file-vs-dir clash is a `Comparison::$conflict`, reported and
  skipped (never auto-resolved). The directory-aware `ignore` semantics (`/temp` drops the folder,
  `/temp/*` keeps it but ignores its contents) fall out of `IgnoreMatcher` unchanged once dirs are entities.
- **The macOS client cannot create a non-UTF8 name** (APFS) — so Windows-1250 names are covered only by
  `tests/Unit/Wire.phpt` at the Wire level, not by integration tests.
- **Unicode NFC/NFD**: macOS lists names as NFD, Linux servers as NFC, so the same file would otherwise be
  both `>` and `<`. `Comparator` keys its maps by `PathNormalizer::key()` (NFC, needs `ext-intl`; falls back to
  raw bytes without it or for non-UTF8), but keeps the **original per-side bytes** in `FileEntry->path` for all
  I/O. Transfers carry an explicit `TransferItem{sourcePath,targetPath}` so a modified accented file overwrites
  the existing entry on the far side instead of creating a duplicate; the agent always receives the remote
  original bytes for `hash`/`download`/`delete`.

## Quality control and testing

- **`composer check`** = `lint` (parallel-lint, incl. .phpt) + `phpstan` + `tester`. Run it before committing.
- **PHPStan level 8 + strict-rules** on `src/` + `bin/` (`phpstan.neon`). The agent is excluded (different target
  version, procedural) — it is covered by parallel-lint, linting on 7.4, and integration tests. Level `max` is
  deliberately not used: it would report "cast mixed" at the JSON/config boundaries, where coercion is intentional.
- **Unit tests** (Nette Tester, `tests/Unit/*.phpt`): `IgnoreMatcher`, `Wire`, `Signer`, `FrameWriter`,
  `StateCache`, `Config` — pure logic without network/IO (except temp files).
- `php tests/agent_smoke.php render|check` — an integration test of the agent (capabilities/list/hash/auth)
  against a running server; requires docker compose.
- `docker-compose.yml` — server PHP 7.4 (`jakubboucek/lamp-devstack-php:7.4-legacy`, note that
  legacy versions have the suffix `-legacy`; the CLI variant is `-legacy-cli`) + client PHP 8.4.
  Hosting limits are simulated by `tests/docker/limits.ini` (post_max_size=4M…); the variant
  `limits-zlib.ini` tests the zlib workaround.
- **Opcache**: after re-rendering the agent in `tests/remote`, a `docker compose restart server` is required,
  otherwise the server may keep the old version for a while.
- `tests/{local*,remote}/`, `tests/*.config.php`, and `tests/.smoke_priv` are in `.gitignore`
  (they contain generated content and private keys).

## Verifying functionality (practical playbook)

Static checks alone are not enough — exercise the real **client↔agent** path for any non-trivial change.
The client is PHP 8.4 on the host; the agent only ever runs on real **PHP 7.4** (docker), never the host.

**End-to-end via docker-compose** (server = real 7.4 + Apache, client = 8.4 CLI):
```bash
docker compose up -d
# Host reaches the agent at http://localhost:8088/agent.php ;
# the client *container* reaches it at http://server/agent.php (compose network).
# A test config is a gitignored tests/*.config.php with url, privateKey, and
# mapping.local = the CONTAINER path /app/tests/local (the repo is mounted at /app).
docker compose exec -T client php bin/psync compare -c tests/<x>.config.php
```
- **Agent-only smoke**: `php tests/agent_smoke.php render tests/remote > tests/.smoke_priv` →
  `docker compose restart server` (opcache!) → wait until `GET /agent.php` returns **any** HTTP status
  (a bare GET is rejected — currently **426**, missing the version header, since the version is checked
  before auth) → `php tests/agent_smoke.php check http://localhost:8088/agent.php "$(cat tests/.smoke_priv)"`.
- After re-rendering the agent into `tests/remote` you MUST `docker compose restart server`, or opcache
  serves the stale agent.

**Gotchas that bite (learned the hard way):**
- **Linting the agent is NOT enough.** `php -l` checks only syntax — it happily passes a PHP-8.0 function
  (e.g. `str_starts_with`) that is undefined at runtime on 7.4. After ANY agent change also run
  `docker run --rm -v "$PWD/agent:/a:ro" jakubboucek/lamp-devstack-php:7.4-legacy-cli php -l /a/agent.template.php`,
  **grep the agent for 8.0 functions**, and run the smoke test on the 7.4 server. (This is why Rector for the
  agent skips the `str_*` rules.)
- **macOS can't create NFD / non-UTF8 filenames**, and the shell won't write raw bytes: `printf "…\xcc\x81…"`
  in `sh` writes literal backslashes, and APFS may re-normalize. To test NFC/NFD or Windows-1250 names, create
  files **inside the Linux container with PHP** (`php -r 'file_put_contents("/tmp/loc/Deni\xcc\x81k.txt", …)'`).
  For full byte control with no mount/opcache surprises, run a self-contained scenario inside the client
  container: render the agent to `/tmp/srv`, `php -S 127.0.0.1:9000 -t /tmp/srv &`, point a config at it.
- **Progress and verbose logs go to STDERR**, the result to STDOUT. To see them, split streams (`1>out 2>err`)
  and force `--ansi` (a non-TTY suppresses the live progress bar).
- **Simulate a protocol-version mismatch**: render the agent, then
  `perl -pi -e "s/'protocolVersion' => 1,/'protocolVersion' => 99,/" tests/remote/agent.php`, restart the
  server → the client must hard-fail with the "regenerate the agent" message.
- **Ignore PhpStorm-injected "phpstan … requires PHP >= 8.4.1 … running 8.2" errors** — that is
  PhpStorm's bundled PHP; the real `composer check` / `vendor/bin/phpstan` runs on the host PHP 8.4.
