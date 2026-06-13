<?php
/**
 * php-sync agent (server-side) – VYGENEROVANÝ SOUBOR.
 *
 * Tento soubor se nahrává na cílový server přes FTP a volá se přes HTTP(S).
 * Cílová kompatibilita: PHP 7.4+ (bez Composer závislostí, jen ext-sodium).
 *
 * Bezpečnost: drží POUZE veřejný klíč. Každý request musí být podepsán
 * privátním klíčem (Ed25519), který má jen klient. Únik tohoto souboru
 * neumožní útočníkovi nic podepsat.
 *
 * NEUPRAVUJ ručně – přegeneruj příkazem `php-sync install`.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Konfigurace (hodnoty doplňuje `install`)
// ---------------------------------------------------------------------------
$CONFIG = array(
    'publicKey'       => 'PHPSYNC_PUBLICKEY_PLACEHOLDER', // base64 veřejného klíče
    'protocolVersion' => 1,
    'root'            => __DIR__,                          // remote root = adresář agenta
    'protect'         => array(/* PHPSYNC_PROTECT */),     // glob vzory, které se nikdy nemažou
);

// ---------------------------------------------------------------------------
// Konstanty protokolu (musí ladit s klientem – PhpSync\Protocol\Protocol)
// ---------------------------------------------------------------------------
const HEADER_TS = 'X-Sync-Ts';
const HEADER_NONCE = 'X-Sync-Nonce';
const HEADER_SIG = 'X-Sync-Sig';
const HEADER_ACTION = 'X-Sync-Action';
const TIME_WINDOW = 300;
const FLAG_GZIP = 1;
const HASH_ALGO = 'md5';
const CHUNK = 65536;

(static function (array $CONFIG): void {
    // Původní časový limit zachyť DŘÍV, než ho prepare_runtime() vynuluje
    // přes set_time_limit(0) – klient ho potřebuje pro dávkování.
    $CONFIG['_maxExecutionTime'] = (int) ini_get('max_execution_time');
    prepare_runtime();

    try {
        // Upload má binární tělo a akci v hlavičce; JSON akce mají akci v těle.
        $actionHeader = header_value(HEADER_ACTION);
        $isUpload = ($actionHeader === 'upload');
        $body = read_body($isUpload); // JSON akce = string; upload = ['tmp' => path, 'sha256' => hex]
        $action = detect_action($actionHeader, $body);
        authenticate($CONFIG, $action, $body);
        dispatch($CONFIG, $action, $body);
    } catch (AgentError $e) {
        send_error($e->getCode() ?: 400, $e->getMessage());
    } catch (\Throwable $e) {
        send_error(500, 'Interní chyba agenta: ' . $e->getMessage());
    }
})($CONFIG);


// ===========================================================================
// Runtime příprava
// ===========================================================================

/**
 * Vypne transparentní kompresi/buffering serveru, aby nekolidovaly s vlastním
 * streamem a per-file GZ, a zkusí zrušit časový limit (best-effort).
 */
function prepare_runtime(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (set_time_limit_available()) {
        @set_time_limit(0);
    }
    @ignore_user_abort(false);
}

function set_time_limit_available(): bool
{
    if (!function_exists('set_time_limit')) {
        return false;
    }
    $disabled = explode(',', (string) ini_get('disable_functions'));
    foreach ($disabled as $fn) {
        if (trim($fn) === 'set_time_limit') {
            return false;
        }
    }
    return true;
}


// ===========================================================================
// Request: akce, tělo, autentizace
// ===========================================================================

class AgentError extends \RuntimeException
{
}

/**
 * Určí akci. Upload ji nese v hlavičce; JSON akce v poli 'action' těla.
 * Akci sice bereme z (zatím neověřeného) těla, ale tělo je součástí podpisu
 * přes svůj sha256 otisk – jakákoli změna akce v těle shodí ověření podpisu.
 *
 * @param string|array $body
 */
function detect_action(?string $actionHeader, $body): string
{
    if ($actionHeader !== null && $actionHeader !== '') {
        return $actionHeader;
    }
    if (is_string($body)) {
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['action'])) {
            return (string) $data['action'];
        }
    }
    return '';
}

/**
 * Načte tělo requestu.
 *  - Upload (binární): streamuje php://input do temp souboru a zároveň počítá
 *    sha256 (kvůli podpisu) – nikdy nedrží celé tělo v paměti.
 *  - Ostatní (JSON): malé tělo se načte do paměti.
 *
 * @return string|array
 */
function read_body(bool $isUpload)
{
    if ($isUpload) {
        $tmp = tempnam(sys_get_temp_dir(), 'phpsync_up_');
        if ($tmp === false) {
            throw new AgentError('Nelze vytvořit dočasný soubor.', 500);
        }
        $in = fopen('php://input', 'rb');
        $out = fopen($tmp, 'wb');
        $ctx = hash_init('sha256');
        if ($in === false || $out === false) {
            throw new AgentError('Nelze otevřít vstupní/dočasný stream.', 500);
        }
        while (!feof($in)) {
            $chunk = fread($in, CHUNK);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                hash_update($ctx, $chunk);
                fwrite($out, $chunk);
            }
        }
        fclose($in);
        fclose($out);
        return array('tmp' => $tmp, 'sha256' => hash_final($ctx));
    }

    // JSON akce – tělo je malé.
    $raw = file_get_contents('php://input');
    return $raw === false ? '' : $raw;
}

/**
 * Ověří podpis, časové okno a (best-effort) replay nonce.
 *
 * @param string|array $body
 */
function authenticate(array $CONFIG, string $action, $body): void
{
    $ts = (int) header_value(HEADER_TS);
    $nonce = (string) header_value(HEADER_NONCE);
    $sigB64 = (string) header_value(HEADER_SIG);

    if ($action === '' || $nonce === '' || $sigB64 === '') {
        throw new AgentError('Chybí povinné hlavičky podpisu.', 403);
    }
    if (abs(time() - $ts) > TIME_WINDOW) {
        throw new AgentError('Časové razítko mimo povolené okno.', 403);
    }

    $bodyHash = is_array($body) ? $body['sha256'] : hash('sha256', $body);
    $message = $action . "\n" . $ts . "\n" . $nonce . "\n" . $bodyHash;

    $pub = base64_decode((string) $CONFIG['publicKey'], true);
    $sig = base64_decode($sigB64, true);
    if (
        $pub === false || $sig === false
        || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
    ) {
        throw new AgentError('Neplatný podpis.', 403);
    }
    if (!sodium_crypto_sign_verify_detached($sig, $message, $pub)) {
        throw new AgentError('Neplatný podpis.', 403);
    }

    check_nonce_replay($nonce, $ts);
}

/**
 * Best-effort ochrana proti replay: ukládá nedávné nonce do stavového souboru.
 * Pokud zápis selže (read-only FS), spoléhá se jen na časové okno.
 */
function check_nonce_replay(string $nonce, int $ts): void
{
    $store = sys_get_temp_dir() . '/phpsync_nonces';
    $fh = @fopen($store, 'c+');
    if ($fh === false) {
        return; // best-effort
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return;
    }
    $now = time();
    $seen = array();
    rewind($fh);
    while (($line = fgets($fh)) !== false) {
        $parts = explode(' ', trim($line));
        if (count($parts) !== 2) {
            continue;
        }
        if ((int) $parts[1] >= $now - TIME_WINDOW) {
            $seen[$parts[0]] = (int) $parts[1];
        }
    }
    if (isset($seen[$nonce])) {
        flock($fh, LOCK_UN);
        fclose($fh);
        throw new AgentError('Replay detekován (nonce už použit).', 403);
    }
    $seen[$nonce] = $ts;
    ftruncate($fh, 0);
    rewind($fh);
    foreach ($seen as $n => $t) {
        fwrite($fh, $n . ' ' . $t . "\n");
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}


// ===========================================================================
// Dispatch
// ===========================================================================

/**
 * @param string|array $body
 */
function dispatch(array $CONFIG, string $action, $body): void
{
    switch ($action) {
        case 'capabilities':
            handle_capabilities($CONFIG);
            return;
        case 'list':
            handle_list($CONFIG, json_body($body));
            return;
        case 'hash':
            handle_hash($CONFIG, json_body($body));
            return;
        case 'download':
            handle_download($CONFIG, json_body($body));
            return;
        case 'upload':
            handle_upload($CONFIG, $body);
            return;
        case 'delete':
            throw new AgentError("Akce '$action' bude implementována v další fázi.", 501);
        default:
            throw new AgentError("Neznámá akce: '$action'.", 400);
    }
}

/**
 * @param string|array $body
 * @return array
 */
function json_body($body): array
{
    if (is_array($body)) {
        throw new AgentError('Neočekávané binární tělo.', 400);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new AgentError('Neplatné JSON tělo.', 400);
    }
    return $data;
}


// ===========================================================================
// Handler: capabilities
// ===========================================================================

function handle_capabilities(array $CONFIG): void
{
    header('Content-Type: application/json; charset=utf-8');
    $caps = array(
        'protocolVersion'       => (int) $CONFIG['protocolVersion'],
        'serverTime'            => time(),
        'phpVersion'            => PHP_VERSION,
        'postMaxSize'           => ini_bytes((string) ini_get('post_max_size')),
        'uploadMaxFilesize'     => ini_bytes((string) ini_get('upload_max_filesize')),
        'maxFileUploads'        => (int) ini_get('max_file_uploads'),
        'memoryLimit'           => ini_bytes((string) ini_get('memory_limit')),
        'maxExecutionTime'      => isset($CONFIG['_maxExecutionTime'])
            ? (int) $CONFIG['_maxExecutionTime']
            : (int) ini_get('max_execution_time'),
        'setTimeLimitAvailable' => set_time_limit_available(),
        'hashAlgos'             => array_values(array_intersect(array('md5', 'sha1', 'crc32b'), hash_algos())),
        'zlibOutputCompression' => ini_get('zlib.output_compression') ? true : false,
    );
    echo json_encode($caps);
}


// ===========================================================================
// Handler: list (fáze 1 – rychlý sken)
// ===========================================================================

/**
 * Streamuje NDJSON {p: base64(relpath), s: size, m: mtime} v deterministickém
 * pořadí. Na konci úplného průchodu pošle {"end": true}.
 *
 * Resumabilita: list je rychlá metadatová fáze a očekává se, že se vejde do
 * limitu. Pokud klient nedostane {"end": true} (timeout/pád), zopakuje `list`
 * od začátku – průchod je deterministický a idempotentní. Drahá je až fáze
 * `hash`, kterou si klient dávkuje sám (tam je resumabilita zásadní).
 */
function handle_list(array $CONFIG, array $req): void
{
    $root = $CONFIG['root'];
    $scope = isset($req['path']) ? (string) $req['path'] : '';
    $base = resolve_scope($root, $scope);

    header('Content-Type: application/x-ndjson; charset=utf-8');

    if ($base === null) {
        emit(array('error' => 'Cesta mimo povolený rozsah.'));
        return;
    }
    if (!file_exists($base)) {
        emit(array('end' => true)); // scope neexistuje na serveru = prázdno
        return;
    }

    walk_files($root, $base, function (string $rel, $stat): void {
        emit(array('p' => base64_encode($rel), 's' => (int) $stat['size'], 'm' => (int) $stat['mtime']));
    });

    emit(array('end' => true));
}


// ===========================================================================
// Handler: hash (fáze 2)
// ===========================================================================

/**
 * Vstup: {paths: [base64, ...]}. Pro každou cestu streamuje md5 (hash_file).
 * Výstup NDJSON: {p, h} nebo {p, h:null} pokud soubor chybí/nečitelný.
 */
function handle_hash(array $CONFIG, array $req): void
{
    $root = $CONFIG['root'];
    $paths = isset($req['paths']) && is_array($req['paths']) ? $req['paths'] : array();

    header('Content-Type: application/x-ndjson; charset=utf-8');

    foreach ($paths as $p64) {
        $rel = base64_decode((string) $p64, true);
        if ($rel === false) {
            continue;
        }
        $abs = resolve_scope($root, $rel);
        if ($abs === null || !is_file($abs)) {
            emit(array('p' => $p64, 'h' => null));
            continue;
        }
        $h = @hash_file(HASH_ALGO, $abs);
        emit(array('p' => $p64, 'h' => $h === false ? null : $h));
    }
    emit(array('end' => true));
}


// ===========================================================================
// Handler: download (remote → klient, binární framing)
// ===========================================================================

/**
 * Vstup: {files:[base64], compress:bool, skipExt:[ext]}.
 * Streamuje per-soubor frame: hlavička + (volitelně gz) payload.
 * Chybějící/nečitelné soubory tiše přeskočí (klient si je doptá / nahlásí).
 */
function handle_download(array $CONFIG, array $req): void
{
    $root = $CONFIG['root'];
    $files = isset($req['files']) && is_array($req['files']) ? $req['files'] : array();
    $compress = !empty($req['compress']);
    $skip = array();
    if (isset($req['skipExt']) && is_array($req['skipExt'])) {
        foreach ($req['skipExt'] as $e) {
            $skip[strtolower((string) $e)] = true;
        }
    }

    header('Content-Type: application/octet-stream');

    foreach ($files as $p64) {
        $rel = base64_decode((string) $p64, true);
        if ($rel === false) {
            continue;
        }
        $abs = resolve_scope($root, $rel);
        if ($abs === null || !is_file($abs)) {
            continue;
        }
        $size = (int) filesize($abs);
        $mtime = (int) filemtime($abs);
        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
        $gz = $compress && !isset($skip[$ext]);

        if ($gz) {
            $tmp = gz_to_temp($abs, $md5raw, $plen);
            if ($tmp === null) {
                continue;
            }
            echo frame_pack_header($rel, FLAG_GZIP, $mtime, $size, $plen, $md5raw);
            $fh = fopen($tmp, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    $c = fread($fh, CHUNK);
                    if ($c !== false && $c !== '') {
                        echo $c;
                    }
                }
                fclose($fh);
            }
            @unlink($tmp);
        } else {
            $md5raw = md5_file($abs, true);
            if ($md5raw === false) {
                continue;
            }
            echo frame_pack_header($rel, 0, $mtime, $size, $size, $md5raw);
            $fh = fopen($abs, 'rb');
            if ($fh !== false) {
                while (!feof($fh)) {
                    $c = fread($fh, CHUNK);
                    if ($c !== false && $c !== '') {
                        echo $c;
                    }
                }
                fclose($fh);
            }
        }
        @flush();
    }
}


// ===========================================================================
// Handler: upload (klient → remote, binární framing)
// ===========================================================================

/**
 * Tělo (už streamnuté do temp v read_body) obsahuje sekvenci framů.
 * Každý soubor se zapíše atomicky (tmp + rename), nastaví se mtime zdroje,
 * ověří se md5. Výsledek per-soubor jako NDJSON.
 *
 * @param array $body ['tmp' => path, 'sha256' => hex]
 */
function handle_upload(array $CONFIG, $body): void
{
    if (!is_array($body)) {
        throw new AgentError('Upload očekává binární tělo.', 400);
    }
    $root = $CONFIG['root'];
    header('Content-Type: application/x-ndjson; charset=utf-8');

    $in = fopen($body['tmp'], 'rb');
    if ($in === false) {
        throw new AgentError('Nelze otevřít tělo uploadu.', 500);
    }

    while (($h = frame_read_header($in)) !== null) {
        $rel = $h['path'];
        $abs = resolve_scope($root, $rel);
        if ($abs === null) {
            skip_bytes($in, $h['payloadLen']);
            emit(array('p' => base64_encode($rel), 'ok' => false, 'err' => 'cesta mimo rozsah'));
            continue;
        }
        $err = write_upload_file($abs, $in, $h);
        emit(array('p' => base64_encode($rel), 'ok' => $err === null, 'err' => $err));
    }

    fclose($in);
    @unlink($body['tmp']);
    emit(array('end' => true));
}

/**
 * Zapíše jeden soubor z framu atomicky. Vrátí null při úspěchu, jinak chybu.
 *
 * @param resource $in
 * @param array $h hlavička framu
 * @return string|null
 */
function write_upload_file(string $abs, $in, array $h)
{
    $dir = dirname($abs);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        skip_bytes($in, $h['payloadLen']);
        return 'nelze vytvořit adresář';
    }

    $tmp = $abs . '.phpsync-' . substr(md5($abs . $h['mtime']), 0, 8) . '.tmp';
    $out = fopen($tmp, 'wb');
    if ($out === false) {
        skip_bytes($in, $h['payloadLen']);
        return 'nelze otevřít cílový temp';
    }

    $ctx = hash_init('md5');
    $gz = ($h['flags'] & FLAG_GZIP) !== 0;
    $inflate = $gz ? inflate_init(ZLIB_ENCODING_GZIP) : null;
    $remaining = $h['payloadLen'];

    while ($remaining > 0) {
        $chunk = fread($in, $remaining < CHUNK ? $remaining : CHUNK);
        if ($chunk === false || $chunk === '') {
            fclose($out);
            @unlink($tmp);
            return 'useknutý payload';
        }
        $remaining -= strlen($chunk);
        if ($gz) {
            $chunk = inflate_add($inflate, $chunk, ZLIB_NO_FLUSH);
            if ($chunk === false) {
                fclose($out);
                @unlink($tmp);
                return 'chyba dekomprese';
            }
        }
        if ($chunk !== '') {
            hash_update($ctx, $chunk);
            fwrite($out, $chunk);
        }
    }
    if ($gz) {
        $tail = inflate_add($inflate, '', ZLIB_FINISH);
        if ($tail === false) {
            fclose($out);
            @unlink($tmp);
            return 'chyba dekomprese (finish)';
        }
        if ($tail !== '') {
            hash_update($ctx, $tail);
            fwrite($out, $tail);
        }
    }
    fclose($out);

    if (hash_final($ctx, true) !== $h['md5']) {
        @unlink($tmp);
        return 'md5 nesouhlasí';
    }
    if (!@rename($tmp, $abs)) {
        @unlink($tmp);
        return 'rename selhal';
    }
    @touch($abs, $h['mtime']);
    return null;
}


// ===========================================================================
// Binární framing (musí ladit s klientem – PhpSync\Protocol\Wire)
// ===========================================================================

function frame_pack_header(string $path, int $flags, int $mtime, int $origSize, int $payloadLen, string $md5raw): string
{
    return pack('N', strlen($path)) . $path
        . pack('C', $flags)
        . pack('J', $mtime)
        . pack('J', $origSize)
        . pack('J', $payloadLen)
        . $md5raw;
}

/**
 * Přečte hlavičku framu. Vrátí null na čistém EOF.
 *
 * @param resource $in
 * @return array|null
 */
function frame_read_header($in)
{
    $lenRaw = stream_read_exact($in, 4, true);
    if ($lenRaw === null) {
        return null;
    }
    $pathLen = unpack('N', $lenRaw)[1];
    $path = $pathLen > 0 ? stream_read_exact($in, $pathLen) : '';
    $fixed = stream_read_exact($in, 41);
    return array(
        'path' => $path,
        'flags' => unpack('C', substr($fixed, 0, 1))[1],
        'mtime' => unpack('J', substr($fixed, 1, 8))[1],
        'origSize' => unpack('J', substr($fixed, 9, 8))[1],
        'payloadLen' => unpack('J', substr($fixed, 17, 8))[1],
        'md5' => substr($fixed, 25, 16),
    );
}

/**
 * @param resource $in
 * @return string|null
 */
function stream_read_exact($in, int $n, bool $allowEof = false)
{
    if ($n === 0) {
        return '';
    }
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($in, $n - strlen($buf));
        if ($chunk === false || $chunk === '') {
            if ($allowEof && $buf === '') {
                return null;
            }
            throw new AgentError('Useknutý frame ve streamu uploadu.', 400);
        }
        $buf .= $chunk;
    }
    return $buf;
}

/**
 * @param resource $in
 */
function skip_bytes($in, int $n): void
{
    while ($n > 0) {
        $chunk = fread($in, $n < CHUNK ? $n : CHUNK);
        if ($chunk === false || $chunk === '') {
            return;
        }
        $n -= strlen($chunk);
    }
}

/**
 * Zkomprimuje soubor do dočasného souboru (streamovaně, gzip).
 * Naplní $md5raw (md5 originálu, raw) a $plen (velikost komprimátu).
 * Vrátí cestu k temp souboru nebo null při chybě.
 *
 * @param string $md5raw (out)
 * @param int $plen (out)
 * @return string|null
 */
function gz_to_temp(string $abs, &$md5raw, &$plen)
{
    $in = fopen($abs, 'rb');
    $tmp = tempnam(sys_get_temp_dir(), 'phpsync_dl_');
    if ($in === false || $tmp === false) {
        if ($in !== false) {
            fclose($in);
        }
        return null;
    }
    $out = fopen($tmp, 'wb');
    if ($out === false) {
        fclose($in);
        @unlink($tmp);
        return null;
    }
    $ctx = hash_init('md5');
    $deflate = deflate_init(ZLIB_ENCODING_GZIP);
    while (!feof($in)) {
        $chunk = fread($in, CHUNK);
        if ($chunk === false) {
            break;
        }
        if ($chunk !== '') {
            hash_update($ctx, $chunk);
            fwrite($out, deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
        }
    }
    fwrite($out, deflate_add($deflate, '', ZLIB_FINISH));
    fclose($in);
    fclose($out);

    $md5raw = hash_final($ctx, true);
    $plen = (int) filesize($tmp);
    return $tmp;
}


// ===========================================================================
// Souborové utility
// ===========================================================================

/**
 * Rekurzivně projde soubory pod $base (setříděně) a zavolá $cb($relPath, $stat).
 * Nesleduje symlinky (ochrana před smyčkami / únikem z rootu).
 */
function walk_files(string $root, string $base, callable $cb): void
{
    $rootLen = strlen(rtrim($root, '/')) + 1;
    $stack = array($base);

    while (!empty($stack)) {
        $dir = array_pop($stack);
        $entries = @scandir($dir);
        if ($entries === false) {
            continue;
        }
        sort($entries, SORT_STRING);
        $subdirs = array();
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue; // symlinky ignorujeme
            }
            if (is_dir($full)) {
                $subdirs[] = $full;
            } elseif (is_file($full)) {
                $stat = @stat($full);
                if ($stat !== false) {
                    $cb(substr($full, $rootLen), $stat);
                }
            }
        }
        // setříděně a tak, aby se zpracovaly před dalšími sourozeneckými adresáři
        rsort($subdirs, SORT_STRING);
        foreach ($subdirs as $sd) {
            $stack[] = $sd;
        }
    }
}

/**
 * Sanitizace klientem dodané relativní cesty.
 * Vrátí absolutní cestu uvnitř $root, nebo null při pokusu o únik.
 */
function resolve_scope(string $root, string $rel)
{
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '') {
        return rtrim($root, '/');
    }
    // odmítni ../ ještě před realpath (realpath na neexistujícím vrátí false)
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '..' || $seg === '.') {
            return null;
        }
    }
    $rootResolved = realpath($root);
    $rootReal = rtrim($rootResolved !== false ? $rootResolved : $root, '/');
    $candidate = $rootReal . '/' . $rel;

    // Pokud existuje, ověř realpath uvnitř rootu (obrana proti symlinkům v cestě).
    $real = realpath($candidate);
    if ($real !== false) {
        return path_within($real, $rootReal) ? $real : null;
    }

    // Neexistuje (typicky cíl uploadu) – ověř nejhlubšího existujícího předka,
    // ať symlinkovaný rodič neumožní únik z rootu.
    $parent = $candidate;
    do {
        $parent = dirname($parent);
    } while ($parent !== '/' && $parent !== '.' && $parent !== '' && !file_exists($parent));

    $parentReal = realpath($parent);
    if ($parentReal !== false && !path_within($parentReal, $rootReal)) {
        return null;
    }
    return $candidate;
}

/** Je $path uvnitř (nebo roven) $root? */
function path_within(string $path, string $root): bool
{
    $path = rtrim($path, '/');
    $root = rtrim($root, '/');
    return $path === $root || strncmp($path . '/', $root . '/', strlen($root) + 1) === 0;
}

function ini_bytes(string $val): int
{
    $val = trim($val);
    if ($val === '') {
        return 0;
    }
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int) $val;
    switch ($last) {
        case 'g':
            $num *= 1024;
            // fallthrough
        case 'm':
            $num *= 1024;
            // fallthrough
        case 'k':
            $num *= 1024;
    }
    return $num;
}


// ===========================================================================
// Výstup
// ===========================================================================

function emit(array $obj): void
{
    echo json_encode($obj) . "\n";
    @flush();
}

function send_error(int $code, string $msg): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/x-ndjson; charset=utf-8');
    }
    echo json_encode(array('error' => $msg, 'code' => $code)) . "\n";
    @flush();
}

function header_value(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }
    // fallback přes getallheaders (některé SAPI)
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
    }
    return null;
}
