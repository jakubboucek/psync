<?php

declare(strict_types=1);

use PhpSync\Protocol\FrameHeader;
use PhpSync\Protocol\Protocol;
use PhpSync\Protocol\Wire;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('NDJSON roundtrip s base64 cestou (i non-UTF8)', function () {
    $weird = "soubor-\x9a\x9d.txt"; // Windows-1250, neplatné UTF-8
    $line = Wire::ndjson(['p' => Wire::encPath($weird), 's' => 42, 'm' => 7]);
    Assert::same("\n", substr($line, -1));

    $obj = Wire::parseNdjson($line);
    Assert::same($weird, Wire::decPath($obj['p']));
    Assert::same(42, $obj['s']);
    Assert::same(7, $obj['m']);
});


test('decPath odmítne neplatné base64', function () {
    Assert::exception(
        fn() => Wire::decPath('!!!neplatne!!!'),
        RuntimeException::class,
    );
});


test('binární frame – pack/read roundtrip více framů + EOF', function () {
    $payload = random_bytes(3000);
    $h1 = new FrameHeader('a/b.txt', Protocol::FLAG_GZIP, 1700000000, 9999, strlen($payload), md5($payload, true));
    $h2 = new FrameHeader('c.bin', 0, 1, 2, 3, md5('xyz', true));

    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, Wire::packFrameHeader($h1));
    fwrite($stream, $payload);
    fwrite($stream, Wire::packFrameHeader($h2));
    fwrite($stream, 'xyz');
    rewind($stream);

    $r1 = Wire::readFrameHeader($stream);
    Assert::same('a/b.txt', $r1->path);
    Assert::true($r1->isGzipped());
    Assert::same(1700000000, $r1->mtime);
    Assert::same(9999, $r1->origSize);
    Assert::same(strlen($payload), $r1->payloadLen);
    Assert::same(md5($payload), $r1->md5Hex());
    Assert::same($payload, Wire::readExact($stream, $r1->payloadLen));

    $r2 = Wire::readFrameHeader($stream);
    Assert::same('c.bin', $r2->path);
    Assert::false($r2->isGzipped());
    Assert::same('xyz', Wire::readExact($stream, $r2->payloadLen));

    Assert::null(Wire::readFrameHeader($stream));
    fclose($stream);
});


test('readExact vyhodí výjimku při useknutí', function () {
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, 'abc');
    rewind($stream);
    Assert::exception(
        fn() => Wire::readExact($stream, 10),
        RuntimeException::class,
        '%a%očekáváno 10%a%',
    );
    fclose($stream);
});


test('tryReadExact vrátí null na čistém EOF', function () {
    $stream = fopen('php://temp', 'r+b');
    Assert::null(Wire::tryReadExact($stream, 4));
    fclose($stream);
});
