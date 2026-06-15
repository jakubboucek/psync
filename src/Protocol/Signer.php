<?php

declare(strict_types=1);

namespace JakubBoucek\Psync\Protocol;

use RuntimeException;

/**
 * Ed25519 signing of requests (libsodium).
 *
 * The client holds the private (secret) key and signs; the server (agent) holds
 * only the public key and verifies. A leak of the agent file therefore does not
 * allow forging a request.
 *
 * Canonical message (must be byte-identical on client and agent):
 *
 *     action LF ts LF nonce LF sha256_hex(body)
 *
 * The body (`body`) enters the signature only via its sha256 digest – the
 * signature thus covers body integrity even for binary (upload) requests,
 * without keeping the whole body in memory for signing.
 */
final readonly class Signer
{
    private string $secretKey;

    public function __construct(string $privateKeyBase64)
    {
        $key = base64_decode($privateKeyBase64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Invalid private key in config.');
        }
        $this->secretKey = $key;
    }

    /**
     * Generates a new key pair (for `install`).
     *
     * @return array{public: string, private: string} both base64
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'private' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * Derives the public key from a private (secret) key – the Ed25519 secret key
     * embeds its public half, so `re-install` can re-render the agent without the
     * public key being stored anywhere (the config holds only the private key).
     *
     * @return string base64 public key
     */
    public static function publicKeyFromPrivate(string $privateKeyBase64): string
    {
        $secret = base64_decode($privateKeyBase64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Invalid private key in config.');
        }
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    }

    /**
     * Canonical message for signing/verification. Shared definition with the agent.
     */
    public static function canonical(string $action, int $ts, string $nonce, string $body): string
    {
        return $action . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    /**
     * Builds the signature headers for the given request.
     *
     * @return array<string, string>
     */
    public function headers(string $action, string $body, ?int $ts = null, ?string $nonce = null): array
    {
        $ts ??= time();
        $nonce ??= bin2hex(random_bytes(16));
        $message = self::canonical($action, $ts, $nonce, $body);
        $sig = sodium_crypto_sign_detached($message, $this->secretKey);

        return [
            Protocol::HEADER_TS => (string) $ts,
            Protocol::HEADER_NONCE => $nonce,
            Protocol::HEADER_SIG => base64_encode($sig),
        ];
    }

    /**
     * Signature verification (mirrors the agent's logic – used in tests).
     */
    public static function verify(
        string $publicKeyBase64,
        string $action,
        int $ts,
        string $nonce,
        string $body,
        string $sigBase64,
    ): bool {
        $pub = base64_decode($publicKeyBase64, true);
        $sig = base64_decode($sigBase64, true);
        if (
            $pub === false || $sig === false
            || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            return false;
        }
        $message = self::canonical($action, $ts, $nonce, $body);
        return sodium_crypto_sign_verify_detached($sig, $message, $pub);
    }
}
