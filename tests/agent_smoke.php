<?php

declare(strict_types=1);

/**
 * Integrační smoke test agenta. Dva režimy:
 *
 *   php tests/agent_smoke.php render <dir>          – vyrenderuje agenta + ukázkový strom
 *                                                     do <dir>, privátní klíč vypíše na STDOUT
 *   php tests/agent_smoke.php check <url> <privkey>  – pro. signed requesty proti běžícímu agentovi
 *
 * Renderování zde je zjednoduchá kopie toho, co bude dělat `install` (fáze 4).
 */

use PhpSync\Protocol\Protocol;
use PhpSync\Protocol\Signer;
use PhpSync\Protocol\Wire;

require __DIR__ . '/../vendor/autoload.php';

$mode = $argv[1] ?? '';

if ($mode === 'render') {
    $dir = $argv[2] ?? '';
    if ($dir === '') {
        fwrite(STDERR, "Chybí cílový adresář.\n");
        exit(2);
    }
    @mkdir($dir . '/sub', 0777, true);
    file_put_contents($dir . '/a.txt', "alpha\n");
    file_put_contents($dir . '/sub/b.txt', "beta beta\n");
    // název s diakritikou a mezerou – test base64 cest (non-UTF8/Windows-1250
    // názvy nejdou na macOS APFS vytvořit, ty pokrývá protocol_test.php)
    file_put_contents($dir . '/Žluťoučký kůň.txt', "weird\n");

    $pair = Signer::generateKeyPair();
    $tpl = file_get_contents(__DIR__ . '/../agent/agent.template.php');
    $tpl = str_replace('PHPSYNC_PUBLICKEY_PLACEHOLDER', $pair['public'], $tpl);
    file_put_contents($dir . '/agent.php', $tpl);

    echo $pair['private'] . "\n";
    exit(0);
}

if ($mode === 'check') {
    $url = $argv[2] ?? '';
    $priv = $argv[3] ?? '';
    if ($url === '' || $priv === '') {
        fwrite(STDERR, "Použití: check <url> <privkey>\n");
        exit(2);
    }
    $signer = new Signer($priv);
    $failed = 0;
    $assert = static function (bool $cond, string $msg) use (&$failed): void {
        echo($cond ? "  ✓ " : "  ✗ ") . $msg . "\n";
        if (!$cond) {
            $failed++;
        }
    };

    /** Pošle podepsaný JSON request, vrátí [httpCode, rawBody]. */
    $call = static function (string $action, array $payload, ?Signer $s, ?string $forceSig = null) use ($url): array {
        $body = json_encode(array_merge(['action' => $action], $payload));
        $headers = $s ? $s->headers($action, $body) : [];
        if ($forceSig !== null) {
            $headers[Protocol::HEADER_SIG] = $forceSig;
        }
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        $h[] = 'Content-Type: application/json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, (string) $resp];
    };

    echo "capabilities:\n";
    [$code, $resp] = $call('capabilities', [], $signer);
    $caps = json_decode($resp, true);
    $assert($code === 200, "HTTP 200 (dostal $code)");
    $assert(is_array($caps) && ($caps['protocolVersion'] ?? null) === Protocol::VERSION, 'protocolVersion sedí');
    $assert(($caps['postMaxSize'] ?? 0) > 0 && ($caps['memoryLimit'] ?? 0) !== 0, 'limity vyplněné');
    $assert(in_array('md5', $caps['hashAlgos'] ?? [], true), 'md5 dostupné');

    echo "auth:\n";
    [$code] = $call('capabilities', [], $signer, 'AAAA'); // podvržený podpis
    $assert($code === 403, "podvržený podpis → 403 (dostal $code)");
    [$code] = $call('capabilities', [], null); // bez podpisu
    $assert($code === 403, "bez podpisu → 403 (dostal $code)");

    echo "list:\n";
    [$code, $resp] = $call('list', ['path' => ''], $signer);
    $lines = array_filter(explode("\n", trim($resp)));
    $files = [];
    $end = false;
    foreach ($lines as $ln) {
        $o = Wire::parseNdjson($ln);
        if (($o['end'] ?? false) === true) {
            $end = true;
        } elseif (isset($o['p'])) {
            $files[Wire::decPath($o['p'])] = $o;
        }
    }
    $assert($end, 'list končí {"end":true}');
    $assert(isset($files['a.txt']) && $files['a.txt']['s'] === 6, 'a.txt s velikostí');
    $assert(isset($files['sub/b.txt']), 'rekurze do sub/');
    $assert(isset($files['Žluťoučký kůň.txt']), 'název s diakritikou/mezerou přežije (base64)');

    echo "list scope + traversal guard:\n";
    [, $resp] = $call('list', ['path' => 'sub'], $signer);
    $scoped = array_filter(explode("\n", trim($resp)), static fn($l) => strpos($l, '"p"') !== false);
    $assert(count($scoped) === 1, 'scope sub/ vrací jen 1 soubor');
    [, $resp] = $call('list', ['path' => '../../etc'], $signer);
    $errLine = false;
    $leaked = false;
    foreach (array_filter(explode("\n", trim($resp))) as $ln) {
        $o = Wire::parseNdjson($ln);
        if (isset($o['error'])) {
            $errLine = true;
        }
        if (isset($o['p'])) {
            $leaked = true;
        }
    }
    $assert($errLine && !$leaked, 'path traversal odmítnut (error, žádné soubory)');

    echo "hash:\n";
    $p64 = Wire::encPath('a.txt');
    [$code, $resp] = $call('hash', ['paths' => [$p64, Wire::encPath('neexistuje.txt')]], $signer);
    $hashes = [];
    foreach (array_filter(explode("\n", trim($resp))) as $ln) {
        $o = Wire::parseNdjson($ln);
        if (isset($o['p'])) {
            $hashes[Wire::decPath($o['p'])] = $o['h'];
        }
    }
    $assert(($hashes['a.txt'] ?? '') === md5("alpha\n"), 'md5 a.txt sedí');
    $assert(array_key_exists('neexistuje.txt', $hashes) && $hashes['neexistuje.txt'] === null, 'chybějící soubor → h:null');

    echo $failed === 0 ? "\nAGENT OK\n" : "\nSELHALO: $failed\n";
    exit($failed === 0 ? 0 : 1);
}

fwrite(STDERR, "Neznámý režim. Použij 'render' nebo 'check'.\n");
exit(2);
