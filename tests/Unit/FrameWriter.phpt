<?php

declare(strict_types=1);

use PhpSync\Protocol\FrameWriter;
use PhpSync\Protocol\Wire;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** Creates a temporary file with the given content and mtime. */
function makeFile(string $content, int $mtime): string
{
    $path = tempnam(sys_get_temp_dir(), 'phpsync_src_');
    file_put_contents($path, $content);
    touch($path, $mtime);
    return $path;
}

/** Reads a single frame from the temp file: [header, payload]. */
function readSingleFrame(string $frameTmp): array
{
    $in = fopen($frameTmp, 'rb');
    $header = Wire::readFrameHeader($in);
    $payload = Wire::readExact($in, $header->payloadLen);
    Assert::null(Wire::readFrameHeader($in)); // only one frame
    fclose($in);
    return [$header, $payload];
}


test('uncompressed frame carries exact metadata and content', function () {
    $content = "file content\n";
    $src = makeFile($content, 1700000123);
    $frame = FrameWriter::buildFrame('dir/file.txt', $src, false);

    [$h, $payload] = readSingleFrame($frame['tmp']);
    Assert::same('dir/file.txt', $h->path);
    Assert::false($h->isGzipped());
    Assert::same(1700000123, $h->mtime);
    Assert::same(strlen($content), $h->origSize);
    Assert::same(strlen($content), $h->payloadLen);
    Assert::same(md5($content), $h->md5Hex());
    Assert::same($content, $payload);

    @unlink($src);
    @unlink($frame['tmp']);
});


test('gzip frame: payload is compressed, content and md5 match after inflate', function () {
    $content = str_repeat("compressible text ", 5000);
    $src = makeFile($content, 1700000200);
    $frame = FrameWriter::buildFrame('big.txt', $src, true);

    [$h, $payload] = readSingleFrame($frame['tmp']);
    Assert::true($h->isGzipped());
    Assert::same(strlen($content), $h->origSize);
    Assert::same(strlen($payload), $h->payloadLen);
    Assert::true($h->payloadLen < $h->origSize); // actually shrank
    Assert::same(md5($content), $h->md5Hex());   // md5 is of the ORIGINAL

    $decompressed = gzdecode($payload);
    Assert::same($content, $decompressed);

    @unlink($src);
    @unlink($frame['tmp']);
});
