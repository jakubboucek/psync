<?php

declare(strict_types=1);

use JakubBoucek\Psync\Sync\PathNormalizer;
use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/../bootstrap.php';


test('NFD and NFC of the same name produce the same key', function () {
    if (!PathNormalizer::isAvailable()) {
        Environment::skip('ext-intl is required for Unicode normalization');
    }
    $nfd = "Deni\xcc\x81k.jpg";  // "Den" + i + combining acute U+0301 + "k"
    $nfc = "Den\xc3\xadk.jpg";   // "Den" + precomposed í U+00ED + "k"

    Assert::notSame($nfd, $nfc);                          // different bytes on disk
    Assert::same(PathNormalizer::key($nfd), PathNormalizer::key($nfc)); // but the same key
    Assert::same($nfc, PathNormalizer::key($nfd));         // canonical form is NFC
});


test('ASCII path is returned unchanged', function () {
    Assert::same('wp-content/uploads/a.txt', PathNormalizer::key('wp-content/uploads/a.txt'));
});


test('non-UTF8 (Windows-1250) path is returned unchanged (no crash)', function () {
    $win1250 = "soubor-\x9a\x9d.txt"; // invalid UTF-8
    Assert::same($win1250, PathNormalizer::key($win1250));
});
