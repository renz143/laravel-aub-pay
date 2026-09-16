<?php

namespace Prycegas\AubPay\Crypto;

use Prycegas\AubPay\Exceptions\SignatureException;

/**
 * JWS signing and verification for AUB's JSON APIs (the Cashier and direct-card rails).
 *
 * AUB's scheme is *JWS-shaped but not a JWS a library will produce*, which is why this is written
 * by hand rather than pulled from a JWT package. Two deviations from RFC 7515:
 *
 *   1. The signature is **detached** and serialized with an empty payload segment — the wire form
 *      is `base64url(header) . ".." . base64url(signature)` (note the doubled dot). The body being
 *      signed travels as the HTTP body, not inside the token.
 *   2. The protected header carries a non-standard `timestamp` claim (seconds since epoch, as a
 *      string) alongside `alg`.
 *
 * The signing input is still the standard `base64url(header) . "." . base64url(payload)`, signed
 * RS256 (PKCS#1 v1.5 over SHA-256), and every segment is unpadded base64url.
 *
 * Outbound: sign() the exact JSON string that will be sent, and put the result in the
 * `Authorization` request header. Inbound: AUB returns its own signature in the `Authorization`
 * *response* header, computed over the raw response body — hand both to verify().
 */
class JwsSigner
{
    private const ALGORITHM = 'RS256';

    public function __construct(
        private readonly ?string $privateKey = null,
        private readonly ?string $publicKey = null,
    ) {
    }

    /**
     * Sign a request body, returning the value for the `Authorization` header.
     *
     * @param  string  $body  The exact JSON string that will be sent — re-encoding it after
     *                        signing changes the bytes and invalidates the signature.
     * @param  int|null  $timestamp  Overridable only so tests can pin the header.
     */
    public function sign(string $body, ?int $timestamp = null): string
    {
        $header = $this->encodeSegment((string) json_encode([
            'alg' => self::ALGORITHM,
            'timestamp' => (string) ($timestamp ?? time()),
        ], JSON_UNESCAPED_SLASHES));

        $key = KeyReader::privateKey($this->privateKey);

        $signature = '';

        if (! openssl_sign($header . '.' . $this->encodeSegment($body), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new SignatureException('Signing the AUB request failed: ' . (openssl_error_string() ?: 'unknown error'));
        }

        return $header . '..' . $this->encodeSegment($signature);
    }

    /**
     * Verify a signature AUB produced over the given body.
     *
     * Returns false — rather than throwing — for anything malformed, so a garbled signature and a
     * wrong one are handled the same way by callers: the response is not trusted. A key that
     * cannot be *loaded* is different, and still throws, because that is a deployment fault the
     * operator has to see rather than a response to distrust.
     */
    public function verify(string $body, string $signature): bool
    {
        if (blank($this->publicKey) || blank($signature)) {
            return false;
        }

        // The header is transmitted inside the signature, so it has to be read back out and
        // reused verbatim — recomputing it here would sign a different timestamp.
        $separator = strpos($signature, '..');

        if ($separator === false) {
            return false;
        }

        $header = substr($signature, 0, $separator);
        $raw = $this->decodeSegment(substr($signature, $separator + 2));

        if ($raw === '') {
            return false;
        }

        $key = KeyReader::publicKey($this->publicKey);

        return openssl_verify(
            $header . '.' . $this->encodeSegment($body),
            $raw,
            $key,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    /**
     * Read the protected header back out of a signature — the `timestamp` in it is what AUB's
     * support will ask for when a request has to be traced.
     */
    public function parseHeader(string $signature): ?array
    {
        $separator = strpos($signature, '..');

        if ($separator === false) {
            return null;
        }

        $decoded = json_decode($this->decodeSegment(substr($signature, 0, $separator)), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function canVerify(): bool
    {
        return filled($this->publicKey);
    }

    public function encodeSegment(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Accepts both alphabets and tolerates missing padding, matching the Apache Commons codec the
     * gateway's own reference implementation decodes with.
     */
    public function decodeSegment(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), false);
    }
}
