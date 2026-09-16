<?php

namespace Prycegas\AubPay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Prycegas\AubPay\Crypto\ParameterSignature;

/**
 * The wallet rail's body signature.
 *
 * The specification prints a worked example, and **half of it is usable**: its `string1` is a real
 * golden vector and is pinned below, but the expected hash it states cannot be reproduced from its
 * own inputs by any construction (see ParameterSignature's class docblock). So assembly is tested
 * against the document, and the hash step is tested for its properties — determinism, key
 * sensitivity, tamper rejection — rather than against a number known to be wrong.
 */
class ParameterSignatureTest extends TestCase
{
    private const KEY = '06af0776878e8fbdd45f2ac8a916573e';

    /** The exact fields of the §4.2 example. */
    private function documentedFields(): array
    {
        return [
            'body' => 'TestPay',
            'device_info' => '100',
            'mch_create_ip' => '127.0.0.1',
            'mch_id' => '127500000158',
            'nonce_str' => '2209193392',
            'notify_url' => 'http://zeming.dev.swiftpass.cn/pytest/notify_suc',
            'out_trade_no' => '127500000158112233001',
            'service' => 'pay.weixin.native.intl',
            'sign_type' => 'SHA256',
            'total_fee' => '100',
        ];
    }

    private function signature(string $type = ParameterSignature::SHA256): ParameterSignature
    {
        return new ParameterSignature($type, self::KEY);
    }

    public function test_it_reproduces_the_documented_signature_string(): void
    {
        $expected = 'body=TestPay&device_info=100&mch_create_ip=127.0.0.1&mch_id=127500000158'
            . '&nonce_str=2209193392&notify_url=http://zeming.dev.swiftpass.cn/pytest/notify_suc'
            . '&out_trade_no=127500000158112233001&service=pay.weixin.native.intl'
            . '&sign_type=SHA256&total_fee=100';

        $this->assertSame($expected, $this->signature()->payload($this->documentedFields()));
    }

    public function test_the_sign_field_never_participates(): void
    {
        $withSign = $this->documentedFields() + ['sign' => 'PREVIOUS-SIGNATURE'];

        $this->assertSame(
            $this->signature()->payload($this->documentedFields()),
            $this->signature()->payload($withSign)
        );
    }

    public function test_empty_values_are_omitted_entirely_rather_than_sent_as_empty(): void
    {
        // The gateway's own samples carry <time_expire><![CDATA[]]></time_expire>; it contributes
        // nothing at all, not `time_expire=`.
        $payload = $this->signature()->payload([
            'body' => 'TestPay',
            'time_expire' => '',
            'time_start' => null,
        ]);

        $this->assertSame('body=TestPay', $payload);
    }

    public function test_urls_are_not_encoded(): void
    {
        $payload = $this->signature()->payload(['notify_url' => 'https://a.test/x?y=1&z=2']);

        $this->assertStringContainsString('notify_url=https://a.test/x?y=1&z=2', $payload);
    }

    public function test_field_order_does_not_matter(): void
    {
        $shuffled = array_reverse($this->documentedFields(), true);

        $this->assertSame(
            $this->signature()->sign($this->documentedFields()),
            $this->signature()->sign($shuffled)
        );
    }

    public function test_it_produces_uppercase_hex(): void
    {
        $sign = $this->signature()->sign($this->documentedFields());

        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $sign);
    }

    public function test_md5_mode_produces_a_32_character_digest(): void
    {
        $sign = $this->signature(ParameterSignature::MD5)->sign($this->documentedFields());

        $this->assertMatchesRegularExpression('/^[0-9A-F]{32}$/', $sign);
    }

    public function test_a_different_key_produces_a_different_signature(): void
    {
        $other = new ParameterSignature(ParameterSignature::SHA256, 'a-different-key');

        $this->assertNotSame(
            $this->signature()->sign($this->documentedFields()),
            $other->sign($this->documentedFields())
        );
    }

    public function test_it_verifies_its_own_signature_and_rejects_a_tampered_amount(): void
    {
        $fields = $this->documentedFields();
        $fields['sign'] = $this->signature()->sign($fields);

        $this->assertTrue($this->signature()->verify($fields));

        $fields['total_fee'] = '1';
        $this->assertFalse($this->signature()->verify($fields));
    }

    public function test_verification_accepts_lowercase_hex(): void
    {
        $fields = $this->documentedFields();
        $fields['sign'] = strtolower($this->signature()->sign($fields));

        $this->assertTrue($this->signature()->verify($fields));
    }

    public function test_verification_fails_when_there_is_no_signature(): void
    {
        $this->assertFalse($this->signature()->verify($this->documentedFields()));
    }

    public function test_a_field_the_package_does_not_know_still_participates(): void
    {
        // §4.1 rule 3: responses may gain fields, and verification has to tolerate that by signing
        // whatever is present rather than a fixed list.
        $fields = $this->documentedFields() + ['some_future_field' => 'value'];
        $fields['sign'] = $this->signature()->sign($fields);

        $this->assertTrue($this->signature()->verify($fields));
        $this->assertStringContainsString('some_future_field=value', $this->signature()->payload($fields));
    }
}
