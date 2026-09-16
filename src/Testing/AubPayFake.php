<?php

namespace Prycegas\AubPay\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Prycegas\AubPay\Crypto\JwsSigner;
use Prycegas\AubPay\Crypto\ParameterSignature;

/**
 * Builders for faked AUB responses.
 *
 * Faking this gateway by hand is more awkward than it looks: a *successful* response is only
 * believed once its `Authorization` header verifies, so a naive `Http::fake(['*' => ...])` produces
 * a response the client correctly refuses, and the test fails for a reason that has nothing to do
 * with what it is testing. These helpers sign the exact bytes they return.
 *
 * They are also the only correct way to fake an *error*, which must be returned **unsigned** —
 * because that is what AUB does, and a test that signs its errors will not catch code that checks
 * the signature too early.
 */
class AubPayFake
{
    /**
     * A signed success for the JSON rails.
     *
     * The body is serialised once and signed as the exact string that is returned; re-encoding it
     * anywhere in between would produce a signature over different bytes, which is the same trap
     * the real client avoids.
     */
    public static function success(array $data = [], ?JwsSigner $signer = null, string $code = '00'): PromiseInterface
    {
        $body = (string) json_encode([
            'code' => $code,
            'message' => 'Approved',
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);

        return Http::response($body, 200, array_filter([
            'Content-Type' => 'application/json',
            'Authorization' => $signer?->sign($body),
        ]));
    }

    /**
     * An error reply, deliberately **unsigned** — AUB does not sign these, and faking one with a
     * signature would hide the bug the real client's ordering exists to avoid.
     */
    public static function error(string $code = '99', string $message = 'System error'): PromiseInterface
    {
        return Http::response(
            (string) json_encode(['code' => $code, 'message' => $message], JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * A success whose signature does not match the body — what an intercepted or tampered response
     * looks like. The client must refuse it.
     */
    public static function tampered(array $data = [], ?JwsSigner $signer = null): PromiseInterface
    {
        $signed = (string) json_encode(['code' => '00', 'message' => 'Approved', 'data' => $data], JSON_UNESCAPED_SLASHES);
        $sent = (string) json_encode(['code' => '00', 'message' => 'Approved', 'data' => $data + ['tampered' => true]], JSON_UNESCAPED_SLASHES);

        return Http::response($sent, 200, array_filter([
            'Content-Type' => 'application/json',
            'Authorization' => $signer?->sign($signed),
        ]));
    }

    /**
     * A signed XML reply for the wallet rail. `status` is the communication flag and `result_code`
     * the business one — pass them separately, because the whole point of the transport's layering
     * is that they differ.
     */
    public static function walletResponse(
        array $fields,
        ParameterSignature $signature,
        string $status = '0',
        ?string $resultCode = '0',
    ): PromiseInterface {
        $fields = array_filter(array_merge([
            'version' => '2.0',
            'charset' => 'UTF-8',
            'sign_type' => $signature->signType(),
            'status' => $status,
            'result_code' => $resultCode,
        ], $fields), static fn ($value) => $value !== null && $value !== '');

        $fields['sign'] = $signature->sign($fields);

        return Http::response(self::toXml($fields), 200, ['Content-Type' => 'text/xml']);
    }

    public static function toXml(array $fields): string
    {
        $body = '<xml>';

        foreach ($fields as $key => $value) {
            $body .= "<{$key}><![CDATA[{$value}]]></{$key}>";
        }

        return $body . '</xml>';
    }
}
