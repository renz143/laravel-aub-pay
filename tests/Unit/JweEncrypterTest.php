<?php

namespace Prycegas\AubPay\Tests\Unit;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;
use PHPUnit\Framework\TestCase;
use Prycegas\AubPay\Crypto\PhpseclibJweEncrypter;
use Prycegas\AubPay\Exceptions\ConfigurationException;

/**
 * The JWE used by the direct card rail.
 *
 * **These tests are the only real evidence this half is correct.** Nothing here decrypts in
 * production — AUB never sends an encrypted field back — so a broken implementation would look
 * fine from the inside and simply be rejected by the gateway with error 07. The check that
 * actually means something is therefore interoperability: an entirely independent JWE
 * implementation (web-token, which is a dev dependency for exactly this reason) must be able to
 * read what we produce. If it can, so can the Nimbus library the vendor's gateway uses.
 *
 * A keypair is generated per test because the decrypting half is needed, and AUB does not share
 * the private key matching the encryption key it publishes.
 */
class JweEncrypterTest extends TestCase
{
    private string $privatePem;

    private string $publicBare;

    protected function setUp(): void
    {
        parent::setUp();

        $keypair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        // openssl_pkey_export() writes by reference and cannot target a typed property directly.
        openssl_pkey_export($keypair, $privatePem);
        $this->privatePem = (string) $privatePem;

        // Stripped to the bare base64 DER body that AUB's portal hands out, so the key-reading
        // path under test is the one a real deployment uses.
        $this->publicBare = (string) preg_replace('/-----[^-]+-----|\s+/', '', openssl_pkey_get_details($keypair)['key']);
    }

    /**
     * web-token v3 takes the key-encryption and content-encryption managers as two arguments; v4
     * merged them into one. Both lines are installable — v4 needs PHP 8.2, so an 8.1 host resolves
     * to v3 — so the shape is chosen from the constructor actually present rather than pinned.
     */
    private function decrypter(): JWEDecrypter
    {
        $constructor = (new \ReflectionClass(JWEDecrypter::class))->getConstructor();

        return $constructor !== null && $constructor->getNumberOfRequiredParameters() >= 2
            ? new JWEDecrypter(new AlgorithmManager([new RSAOAEP256()]), new AlgorithmManager([new A256CBCHS512()]))
            : new JWEDecrypter(new AlgorithmManager([new RSAOAEP256(), new A256CBCHS512()]));
    }

    private function decrypt(string $jwe): ?string
    {
        $decrypter = $this->decrypter();
        $object = (new CompactSerializer())->unserialize($jwe);

        return $decrypter->decryptUsingKey($object, JWKFactory::createFromKey($this->privatePem), 0)
            ? $object->getPayload()
            : null;
    }

    public function test_an_independent_implementation_can_decrypt_what_we_produce(): void
    {
        $pan = '5491320330720068';

        $this->assertSame($pan, $this->decrypt((new PhpseclibJweEncrypter($this->publicBare))->encrypt($pan)));
    }

    public function test_it_uses_the_algorithms_aub_requires(): void
    {
        $jwe = (new PhpseclibJweEncrypter($this->publicBare))->encrypt('372');

        $header = json_decode((string) base64_decode(strtr(explode('.', $jwe)[0], '-_', '+/')), true);

        // API guide §4.2.1: both are fixed values. Anything else is error 07.
        $this->assertSame('RSA-OAEP-256', $header['alg']);
        $this->assertSame('A256CBC-HS512', $header['enc']);
    }

    public function test_it_produces_five_base64url_segments(): void
    {
        $jwe = (new PhpseclibJweEncrypter($this->publicBare))->encrypt('4200000000000000');

        $this->assertCount(5, explode('.', $jwe));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $jwe);
    }

    public function test_the_authentication_tag_rejects_a_tampered_ciphertext(): void
    {
        $segments = explode('.', (new PhpseclibJweEncrypter($this->publicBare))->encrypt('5491320330720068'));

        $ciphertext = $segments[3];
        $segments[3] = substr($ciphertext, 0, -1) . ($ciphertext[-1] === 'A' ? 'B' : 'A');

        $decrypted = null;

        try {
            $decrypted = $this->decrypt(implode('.', $segments));
        } catch (\Throwable) {
            // Rejecting by exception is as good as returning null.
        }

        $this->assertNull($decrypted, 'A tampered ciphertext must not decrypt.');
    }

    public function test_every_encryption_is_unique(): void
    {
        $encrypter = new PhpseclibJweEncrypter($this->publicBare);

        // A reused IV or content key across two encryptions of the same PAN would let an observer
        // tell that two payments used the same card.
        $this->assertNotSame($encrypter->encrypt('5491320330720068'), $encrypter->encrypt('5491320330720068'));
    }

    public function test_it_accepts_a_pem_wrapped_key(): void
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($this->publicBare, 64, "\n") . "-----END PUBLIC KEY-----\n";

        $this->assertSame('123', $this->decrypt((new PhpseclibJweEncrypter($pem))->encrypt('123')));
    }

    public function test_it_names_the_missing_configuration_rather_than_failing_obscurely(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/jwe_public_key/');

        (new PhpseclibJweEncrypter(null))->encrypt('5491320330720068');
    }
}
