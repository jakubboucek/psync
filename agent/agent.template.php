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
        case 'upload':
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
        'maxExecutionTime'      => (int) ini_get('max_execution_time'),
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
    $rootReal = rtrim($root, '/');
    $candidate = $rootReal . '/' . $rel;

    // Pokud existuje, ověř realpath uvnitř rootu (obrana proti symlinkům v cestě).
    $real = realpath($candidate);
    if ($real !== false) {
        $prefix = $rootReal . '/';
        if ($real !== $rootReal && strncmp($real . '/', $prefix, strlen($prefix)) !== 0) {
            return null;
        }
        return $real;
    }
    // Neexistuje (typicky cíl uploadu) – stačí, že je textově uvnitř rootu.
    return $candidate;
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
