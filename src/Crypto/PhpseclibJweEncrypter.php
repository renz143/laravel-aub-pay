<?php

namespace Prycegas\AubPay\Crypto;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Prycegas\AubPay\Exceptions\ConfigurationException;
use Prycegas\AubPay\Exceptions\SignatureException;
use Throwable;

/**
 * JWE for the direct card rail: `RSA-OAEP-256` key wrapping with `A256CBC-HS512` content
 * encryption, compact serialization (RFC 7516/7518). This is exactly what the vendor's Java
 * reference implementation produces with Nimbus, and unlike their JWS, it is standards-compliant.
 *
 * **Why this is assembled by hand instead of delegated to a JWE library.** Only one step is beyond
 * PHP's openssl extension: RSA-OAEP with SHA-256. `openssl_public_encrypt()` offers
 * `OPENSSL_PKCS1_OAEP_PADDING`, but that is OAEP with **SHA-1** MGF1 and PHP exposes no way to
 * change the digest, so it produces a token AUB rejects with error 07. phpseclib is used for that
 * one operation because its API is stable across PHP 8.1–8.4; the full JWE libraries that could do
 * the whole job change their builder signature between the version that supports PHP 8.1 and the
 * version that does not, which would mean branching on a dependency's major version inside a
 * payments package.
 *
 * The rest — content encryption and the tag — is RFC 7518 §5.2.5 followed literally, and is
 * verified in the test suite by having an independent implementation decrypt what this produces,
 * which is the only check that actually proves interoperability.
 *
 * Nothing here decrypts. AUB never sends an encrypted field back.
 */
class PhpseclibJweEncrypter implements JweEncrypter
{
    private const ALG = 'RSA-OAEP-256';

    private const ENC = 'A256CBC-HS512';

    /** A256CBC-HS512 takes a 512-bit key: the first half MACs, the second half encrypts. */
    private const KEY_BYTES = 64;

    private const IV_BYTES = 16;

    public function __construct(private readonly ?string $publicKey = null)
    {
    }

    public function encrypt(string $plaintext): string
    {
        if (blank($this->publicKey)) {
            throw ConfigurationException::missing('card.jwe_public_key', 'AUB encryption public key');
        }

        if (! class_exists(PublicKeyLoader::class)) {
            throw new ConfigurationException(
                'The AUB card rail needs a JWE implementation, which PHP\'s openssl extension cannot '
                . 'provide (it only offers RSA-OAEP with SHA-1). Run: composer require phpseclib/phpseclib'
            );
        }

        $header = $this->encodeSegment((string) json_encode([
            'alg' => self::ALG,
            'enc' => self::ENC,
        ], JSON_UNESCAPED_SLASHES));

        $cek = random_bytes(self::KEY_BYTES);
        $iv = random_bytes(self::IV_BYTES);

        $macKey = substr($cek, 0, 32);
        $encKey = substr($cek, 32, 32);

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $encKey, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new SignatureException(
                'Encrypting the AUB card field failed: ' . (openssl_error_string() ?: 'unknown error')
            );
        }

        // RFC 7518 §5.2.2.1: the AAD is the ASCII bytes of the encoded protected header, and the
        // trailing block is its length **in bits**, as a 64-bit big-endian integer. Using the byte
        // length here is the classic way to produce a tag that every other implementation rejects.
        $aad = $header;
        $al = pack('J', strlen($aad) * 8);

        $tag = substr(hash_hmac('sha512', $aad . $iv . $ciphertext . $al, $macKey, true), 0, 32);

        return implode('.', [
            $header,
            $this->encodeSegment($this->wrapKey($cek)),
            $this->encodeSegment($iv),
            $this->encodeSegment($ciphertext),
            $this->encodeSegment($tag),
        ]);
    }

    /**
     * RSA-OAEP-256: both the OAEP hash and the MGF1 hash must be SHA-256. phpseclib defaults MGF1
     * to the same hash as the OAEP digest, but both are set explicitly — the two being allowed to
     * diverge is precisely the failure this class exists to avoid.
     */
    private function wrapKey(string $cek): string
    {
        try {
            $key = PublicKeyLoader::loadPublicKey(KeyReader::toPem($this->publicKey, 'PUBLIC KEY'));
        } catch (Throwable $e) {
            throw new SignatureException(
                'The configured AUB encryption public key (aub-pay.card.jwe_public_key) could not be read: '
                . $e->getMessage()
            );
        }

        if (! $key instanceof RSA) {
            throw new SignatureException('The AUB encryption public key must be an RSA key.');
        }

        $encrypted = $key
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->encrypt($cek);

        if (! is_string($encrypted) || $encrypted === '') {
            throw new SignatureException('Wrapping the content encryption key for AUB failed.');
        }

        return $encrypted;
    }

    private function encodeSegment(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
