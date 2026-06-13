<?php

declare(strict_types=1);

/**
 * Jednoduchý self-test protokolové vrstvy (bez frameworku).
 * Spustit: php tests/protocol_test.php
 */

use PhpSync\Protocol\FrameHeader;
use PhpSync\Protocol\Protocol;
use PhpSync\Protocol\Signer;
use PhpSync\Protocol\Wire;

require __DIR__ . '/../vendor/autoload.php';

$failed = 0;
$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if ($cond) {
        echo "  ✓ $msg\n";
    } else {
        echo "  ✗ $msg\n";
        $failed++;
    }
};

echo "Signer (Ed25519):\n";
$pair = Signer::generateKeyPair();
$signer = new Signer($pair['private']);
$body = '{"action":"list","path":""}';
$headers = $signer->headers(Protocol::ACTION_LIST, $body, ts: 1_700_000_000, nonce: 'abc123');

$assert(
    Signer::verify($pair['public'], Protocol::ACTION_LIST, 1_700_000_000, 'abc123', $body, $headers[Protocol::HEADER_SIG]),
    'platný podpis projde ověřením',
);
$assert(
    !Signer::verify($pair['public'], Protocol::ACTION_LIST, 1_700_000_000, 'abc123', $body . 'x', $headers[Protocol::HEADER_SIG]),
    'změna těla → podpis neplatí',
);
$assert(
    !Signer::verify($pair['public'], Protocol::ACTION_DELETE, 1_700_000_000, 'abc123', $body, $headers[Protocol::HEADER_SIG]),
    'změna akce → podpis neplatí (akce je podepsaná)',
);
$other = Signer::generateKeyPair();
$assert(
    !Signer::verify($other['public'], Protocol::ACTION_LIST, 1_700_000_000, 'abc123', $body, $headers[Protocol::HEADER_SIG]),
    'cizí veřejný klíč → podpis neplatí',
);

echo "Wire NDJSON:\n";
$weird = "soubor-\x9a\x9d.txt"; // non-UTF8 (Windows-1250) název
$line = Wire::ndjson(['p' => Wire::encPath($weird), 's' => 123, 'm' => 456]);
$parsed = Wire::parseNdjson($line);
$assert(Wire::decPath($parsed['p']) === $weird, 'non-UTF8 cesta přežije NDJSON roundtrip (base64)');
$assert($parsed['s'] === 123 && $parsed['m'] === 456, 'NDJSON skalár roundtrip');

echo "Wire binární framing:\n";
$payload = random_bytes(5000);
$h = new FrameHeader(
    path: $weird,
    flags: Protocol::FLAG_GZIP,
    mtime: 1_700_000_123,
    origSize: 9999,
    payloadLen: strlen($payload),
    md5: md5($payload, true),
);
$stream = fopen('php://temp', 'r+b');
fwrite($stream, Wire::packFrameHeader($h));
fwrite($stream, $payload);
// druhý frame, ať ověříme čtení po sobě
$h2 = new FrameHeader('a/b.php', 0, 1, 2, 3, md5('xyz', true));
fwrite($stream, Wire::packFrameHeader($h2));
fwrite($stream, 'xyz');
rewind($stream);

$r1 = Wire::readFrameHeader($stream);
$assert($r1->path === $weird, 'frame1: cesta');
$assert($r1->isGzipped() && $r1->mtime === 1_700_000_123 && $r1->origSize === 9999, 'frame1: flags/mtime/origSize');
$assert($r1->payloadLen === strlen($payload) && $r1->md5Hex() === md5($payload), 'frame1: payloadLen/md5');
$got = Wire::readExact($stream, $r1->payloadLen);
$assert($got === $payload, 'frame1: payload bajt-přesně');

$r2 = Wire::readFrameHeader($stream);
$assert($r2->path === 'a/b.php' && !$r2->isGzipped() && $r2->payloadLen === 3, 'frame2: hlavička');
$assert(Wire::readExact($stream, 3) === 'xyz', 'frame2: payload');

$assert(Wire::readFrameHeader($stream) === null, 'čistý EOF → null');
fclose($stream);

echo $failed === 0 ? "\nVŠE OK\n" : "\nSELHALO: $failed\n";
exit($failed === 0 ? 0 : 1);
