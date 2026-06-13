<?php

declare(strict_types=1);

namespace PhpSync\Protocol;

use RuntimeException;

/**
 * Ed25519 podpis requestů (libsodium).
 *
 * Klient drží privátní (secret) klíč a podepisuje; server (agent) drží jen
 * veřejný klíč a ověřuje. Únik agent-souboru tak neumožní podvrhnout request.
 *
 * Kanonická zpráva (musí být bajt-identická na klientu i agentovi):
 *
 *     action LF ts LF nonce LF sha256_hex(body)
 *
 * Tělo (`body`) vstupuje do podpisu jen přes svůj sha256 otisk – podpis tak
 * pokrývá integritu těla i u binárních (upload) requestů, aniž bychom drželi
 * celé tělo v paměti kvůli podpisu.
 */
final class Signer
{
    private readonly string $secretKey;

    public function __construct(string $privateKeyBase64)
    {
        $key = base64_decode($privateKeyBase64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Neplatný privátní klíč v configu.');
        }
        $this->secretKey = $key;
    }

    /**
     * Vygeneruje nový pár klíčů (pro `install`).
     *
     * @return array{public: string, private: string} oba base64
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
     * Kanonická zpráva pro podpis/ověření. Sdílená definice s agentem.
     */
    public static function canonical(string $action, int $ts, string $nonce, string $body): string
    {
        return $action . "\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    /**
     * Vytvoří podpisové hlavičky pro daný request.
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
     * Ověření podpisu (zrcadlí logiku agenta – využito v testech).
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
