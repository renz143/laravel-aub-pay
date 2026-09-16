<?php

namespace Prycegas\AubPay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prycegas\AubPay\Crypto\JwsSigner;
use Prycegas\AubPay\Exceptions\SignatureException;
use Prycegas\AubPay\Tests\TestCase as PackageTestCase;

/**
 * Pins AUB's JWS scheme, which is deliberately not a stock JWS — see Crypto\JwsSigner.
 *
 * What these can and cannot prove: they pin the wire format, show that a signature this class
 * produces verifies against the matching public key, and that tampering with either half breaks
 * it. Byte-for-byte parity with the vendor's Java implementation was checked separately against
 * the openssl CLI over the same signing input; there is no committed test for that because it
 * would mean shelling out of PHP.
 */
class JwsSignerTest extends TestCase
{
    private function signer(): JwsSigner
    {
        return new JwsSigner(PackageTestCase::PRIVATE_KEY, PackageTestCase::PUBLIC_KEY);
    }

    public function test_it_uses_the_detached_double_dot_serialization(): void
    {
        $signature = $this->signer()->sign('{"orderInformation":{"orderId":"PG-1"}}');

        // header .. signature — an empty payload segment, not the RFC 7515 compact form.
        $this->assertSame(1, substr_count($signature, '..'));

        [$header, $sign] = explode('..', $signature, 2);
        $this->assertNotSame('', $header);
        $this->assertNotSame('', $sign);

        // Unpadded base64url on both halves.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $header);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $sign);
    }

    public function test_the_protected_header_carries_alg_and_a_string_timestamp(): void
    {
        $header = $this->signer()->parseHeader($this->signer()->sign('{}', 1621065520));

        $this->assertSame('RS256', $header['alg']);
        // A *string*, not an integer — the vendor's own header does this and changing it changes
        // the bytes that get signed.
        $this->assertSame('1621065520', $header['timestamp']);
        $this->assertIsString($header['timestamp']);
    }

    public function test_a_signature_verifies_against_the_matching_public_key(): void
    {
        $body = '{"code":"00","message":"Approved"}';

        $this->assertTrue($this->signer()->verify($body, $this->signer()->sign($body)));
    }

    public function test_it_rejects_a_signature_over_a_different_body(): void
    {
        $signature = $this->signer()->sign('{"amount":100}');

        $this->assertFalse($this->signer()->verify('{"amount":100000}', $signature));
    }

    public function test_it_rejects_a_tampered_signature(): void
    {
        $signature = $this->signer()->sign('{}');
        $tampered = substr($signature, 0, -4) . 'AAAA';

        $this->assertFalse($this->signer()->verify('{}', $tampered));
    }

    public function test_it_rejects_malformed_signatures_without_throwing(): void
    {
        foreach (['', 'not-a-signature', 'only.one.dot', 'aGVhZGVy..'] as $malformed) {
            $this->assertFalse($this->signer()->verify('{}', $malformed), "should reject `{$malformed}`");
        }
    }

    public function test_verification_reuses_the_transmitted_header_rather_than_recomputing_it(): void
    {
        // Signed with a timestamp well in the past: if verify() rebuilt the header from time() the
        // signing input would differ and this would fail.
        $body = '{"code":"00"}';
        $signature = $this->signer()->sign($body, 1500000000);

        $this->assertTrue($this->signer()->verify($body, $signature));
    }

    public function test_it_accepts_a_pem_wrapped_key_as_well_as_a_bare_one(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(PackageTestCase::PRIVATE_KEY, 64, "\n")
            . "-----END PRIVATE KEY-----\n";

        $body = '{}';
        $signature = (new JwsSigner($pem, PackageTestCase::PUBLIC_KEY))->sign($body);

        $this->assertTrue($this->signer()->verify($body, $signature));
    }

    public function test_it_tolerates_whitespace_in_a_key_pasted_from_the_portal(): void
    {
        // §2.1.3.7 of the integration guide tells you to strip these by hand; we do it instead.
        $mangled = wordwrap(PackageTestCase::PRIVATE_KEY, 40, "\n", true);

        $this->assertTrue($this->signer()->verify('{}', (new JwsSigner($mangled))->sign('{}')));
    }

    public function test_it_throws_when_no_private_key_is_configured(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessageMatches('/private key/i');

        (new JwsSigner(null, PackageTestCase::PUBLIC_KEY))->sign('{}');
    }

    public function test_verification_returns_false_when_no_public_key_is_configured(): void
    {
        // A blank verification key must not throw: it makes successes unverifiable, which the
        // transport reports on its own terms, but it is not a crash.
        $signer = new JwsSigner(PackageTestCase::PRIVATE_KEY, null);

        $this->assertFalse($signer->verify('{}', $signer->sign('{}')));
        $this->assertFalse($signer->canVerify());
    }
}
