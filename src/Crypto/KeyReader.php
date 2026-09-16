<?php

namespace Prycegas\AubPay\Crypto;

use OpenSSLAsymmetricKey;
use Prycegas\AubPay\Exceptions\SignatureException;

/**
 * Turns whatever form an AUB key arrives in into something openssl will load.
 *
 * AUB's merchant portal and the vendor's Java reference implementation both hand out keys as a
 * bare base64 DER body with no PEM armour and, depending on how they were generated, embedded
 * newlines — the integration guide has a whole step (§2.1.3.7) telling you to strip the whitespace
 * by hand. Rather than make every deployment remember that, both forms are accepted here: a key
 * that already carries a PEM header is used as-is, anything else is stripped and wrapped.
 *
 * Private keys are PKCS#8, public keys are X.509 SubjectPublicKeyInfo — which is what
 * `openssl pkcs8 -topk8` and `openssl rsa -pubout` produce, and what the guide instructs.
 */
class KeyReader
{
    public static function privateKey(?string $key): OpenSSLAsymmetricKey
    {
        if (blank($key)) {
            throw new SignatureException('No AUB merchant private key is configured (aub-pay.private_key).');
        }

        $resource = openssl_pkey_get_private(self::toPem($key, 'PRIVATE KEY'));

        if ($resource === false) {
            throw new SignatureException(
                'The configured AUB merchant private key could not be read: ' . self::opensslError()
            );
        }

        return $resource;
    }

    public static function publicKey(?string $key, string $configKey = 'aub-pay.jws_public_key'): OpenSSLAsymmetricKey
    {
        if (blank($key)) {
            throw new SignatureException("No AUB public key is configured ({$configKey}).");
        }

        $resource = openssl_pkey_get_public(self::toPem($key, 'PUBLIC KEY'));

        if ($resource === false) {
            throw new SignatureException(
                "The configured AUB public key ({$configKey}) could not be read: " . self::opensslError()
            );
        }

        return $resource;
    }

    /**
     * Wrap a bare base64 DER key in the PEM armour openssl needs. A key that already carries a PEM
     * header is passed through untouched, so either form can sit in the environment file.
     */
    public static function toPem(string $key, string $label): string
    {
        $key = trim($key);

        if (str_starts_with($key, '-----BEGIN')) {
            return $key;
        }

        return "-----BEGIN {$label}-----\n"
            . chunk_split((string) preg_replace('/\s+/', '', $key), 64, "\n")
            . "-----END {$label}-----\n";
    }

    /**
     * Drain openssl's error queue. It stacks errors, and the first one off the queue is usually
     * the informative one ("no start line" for a mangled key, for instance), so take that and
     * clear the rest rather than leaking them into the next unrelated call.
     */
    private static function opensslError(): string
    {
        $first = openssl_error_string();

        while (openssl_error_string() !== false) {
            // drain
        }

        return $first === false ? 'no further detail from openssl' : $first;
    }
}
