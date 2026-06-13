<?php

declare(strict_types=1);

use PhpSync\Protocol\Protocol;
use PhpSync\Protocol\Signer;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


test('generated pair signs and verifies', function () {
    $pair = Signer::generateKeyPair();
    $signer = new Signer($pair['private']);
    $body = '{"action":"list"}';

    $h = $signer->headers('list', $body, 1700000000, 'nonce-1');
    Assert::same('1700000000', $h[Protocol::HEADER_TS]);
    Assert::same('nonce-1', $h[Protocol::HEADER_NONCE]);

    Assert::true(Signer::verify($pair['public'], 'list', 1700000000, 'nonce-1', $body, $h[Protocol::HEADER_SIG]));
});


test('canonical message is deterministic', function () {
    Assert::same(
        Signer::canonical('list', 100, 'n', 'body'),
        Signer::canonical('list', 100, 'n', 'body'),
    );
    Assert::notSame(
        Signer::canonical('list', 100, 'n', 'body'),
        Signer::canonical('delete', 100, 'n', 'body'),
    );
});


test('changing the body, action or nonce breaks verification', function () {
    $pair = Signer::generateKeyPair();
    $signer = new Signer($pair['private']);
    $body = '{"action":"list"}';
    $sig = $signer->headers('list', $body, 100, 'n')[Protocol::HEADER_SIG];

    Assert::false(Signer::verify($pair['public'], 'list', 100, 'n', $body . 'x', $sig));
    Assert::false(Signer::verify($pair['public'], 'delete', 100, 'n', $body, $sig));
    Assert::false(Signer::verify($pair['public'], 'list', 100, 'other-nonce', $body, $sig));
    Assert::false(Signer::verify($pair['public'], 'list', 101, 'n', $body, $sig));
});


test('foreign public key and malformed signature both fail', function () {
    $pair = Signer::generateKeyPair();
    $other = Signer::generateKeyPair();
    $signer = new Signer($pair['private']);
    $sig = $signer->headers('list', 'b', 1, 'n')[Protocol::HEADER_SIG];

    Assert::false(Signer::verify($other['public'], 'list', 1, 'n', 'b', $sig));
    Assert::false(Signer::verify($pair['public'], 'list', 1, 'n', 'b', 'AAAA')); // short signature
    Assert::false(Signer::verify('!!!', 'list', 1, 'n', 'b', $sig));             // invalid key
});


testException('invalid private key in constructor', function () {
    new Signer('this-is-not-a-key');
}, RuntimeException::class);
