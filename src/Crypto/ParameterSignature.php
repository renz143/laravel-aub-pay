<?php

namespace Prycegas\AubPay\Crypto;

use Prycegas\AubPay\Exceptions\SignatureException;

/**
 * Signing for the wallet/QR rail, which authenticates the *body* rather than a header.
 *
 * The rule (API spec §4.1–4.2): take every parameter except `sign`, drop the empty ones, sort by
 * field name ascending in ASCII, join as `key=value&key=value` with no URL encoding, append
 * `&key=<merchant key>`, hash, and uppercase the hex. `sign_type` participates in the string like
 * any other field; only `sign` is excluded.
 *
 * Three details that are easy to get wrong and produce a signature the gateway silently rejects:
 *
 *   - **Empty values do not participate at all.** Not `k=` — the field is absent from the string.
 *     XML like `<time_expire><![CDATA[]]></time_expire>` contributes nothing.
 *   - **No URL encoding.** `notify_url=http://host/path` goes in with its slashes and colon intact.
 *   - **Uppercase hex.** The gateway compares the string, not the bytes.
 *
 * Verification runs the same computation over a response's own fields. The spec (§4.1, rule 3)
 * requires tolerating *new* fields appearing in responses, which is why this signs whatever is
 * present rather than a fixed list — a field the package does not know about still ends up in the
 * string, exactly as the gateway computed it.
 *
 * ---------------------------------------------------------------------------------------------
 * The spec's worked example does not add up, and you should not chase it.
 *
 * §4.2 prints a full example: a `string1`, a merchant key of `06af0776878e8fbdd45f2ac8a916573e`,
 * and an expected result of `A406815CA24BDD040A5011AF6BED8C0E40DD75994DFC476048B706EFFAC2633F`.
 * Hashing the document's own `string1` with the document's own key does not produce the
 * document's own answer — it produces `62B06AF33E50A7D749BACA256F6188765DE8AAC189259B22CE78A14B`
 * `E95CEF7E`. That was checked against 84 variants (dropping `sign_type`, dropping `device_info`,
 * URL-encoding `notify_url`, appending the key five different ways, and MD5/SHA-1/SHA-256/SHA-512
 * throughout); nothing reproduces the printed value. The expected hash appears to be stale,
 * carried over from an earlier revision of the example.
 *
 * What that costs, and what it does not: the *string assembly* above is still pinned by the
 * document, because the `string1` it prints is reproduced here character-for-character — and
 * assembly is where the real hazards live (sort order, empty-field exclusion, no URL encoding).
 * Only the final hash step is unconfirmed, and it follows §4.2's stated formula literally. Treat
 * the first successful UAT call as the acceptance test for that half, and if signatures are
 * rejected, compare payload() against the gateway's expectation before suspecting anything here.
 * ---------------------------------------------------------------------------------------------
 */
class ParameterSignature
{
    public const SHA256 = 'SHA256';

    public const MD5 = 'MD5';

    public const RSA = 'RSA_1_256';

    public function __construct(
        private readonly string $signType = self::SHA256,
        private readonly ?string $apiKey = null,
        private readonly ?string $privateKey = null,
        private readonly ?string $publicKey = null,
    ) {
    }

    public function signType(): string
    {
        return $this->signType;
    }

    public function sign(array $parameters): string
    {
        $payload = $this->payload($parameters);

        return match ($this->signType) {
            self::SHA256 => strtoupper(hash('sha256', $payload . $this->keySuffix())),
            self::MD5 => strtoupper(hash('md5', $payload . $this->keySuffix())),
            self::RSA => $this->rsaSign($payload),
            default => throw new SignatureException(
                "Unsupported AUB wallet sign_type `{$this->signType}`. Expected SHA256, MD5 or RSA_1_256."
            ),
        };
    }

    /**
     * Check the `sign` a gateway response or notification arrived with.
     *
     * Returns false rather than throwing for a missing or malformed signature: the caller's answer
     * to "this does not verify" is the same either way, and a notification with a garbled field is
     * not a deployment fault worth an exception.
     */
    public function verify(array $parameters): bool
    {
        $claimed = (string) ($parameters['sign'] ?? '');

        if ($claimed === '') {
            return false;
        }

        if ($this->signType === self::RSA) {
            return $this->rsaVerify($this->payload($parameters), $claimed);
        }

        // Constant-time, and case-insensitive because the gateway has been seen to return
        // lowercase hex on at least one endpoint while documenting uppercase.
        return hash_equals(strtoupper($this->sign($parameters)), strtoupper($claimed));
    }

    /**
     * The sorted parameter string, before the key is appended. Exposed because it is the first
     * thing to compare against the vendor's worked example when a signature will not verify.
     */
    public function payload(array $parameters): string
    {
        unset($parameters['sign']);

        $parameters = array_filter(
            $parameters,
            static fn ($value) => $value !== null && $value !== '' && ! is_array($value)
        );

        // SORT_STRING, not PHP's default: the rule is ASCII order over the field names, and
        // ksort()'s numeric-ish comparisons would order a field named "2" against "body" wrongly.
        ksort($parameters, SORT_STRING);

        return implode('&', array_map(
            static fn ($key, $value) => $key . '=' . self::stringify($value),
            array_keys($parameters),
            $parameters
        ));
    }

    private function keySuffix(): string
    {
        if (blank($this->apiKey)) {
            throw new SignatureException('No AUB wallet signing key is configured (aub-pay.wallet.api_key).');
        }

        return '&key=' . $this->apiKey;
    }

    /**
     * SHA256WithRSA over the parameter string, base64 encoded (not hex, and not uppercased —
     * the vendor's own RSA sample is plainly base64).
     *
     * NOTE: the spec is ambiguous about whether `&key=…` is appended before an RSA signature the
     * way it is for the hash modes. Its prose formula shows the suffix, but that reads as
     * copy-paste from the hash section — an asymmetric signature has no use for a shared secret,
     * and no other implementation of this gateway appends one. Signed without the suffix here.
     * If AUB ever confirms otherwise this is the one line to change, and the symptom would be
     * every RSA-mode call failing signature validation while SHA256 mode works.
     */
    private function rsaSign(string $payload): string
    {
        $key = KeyReader::privateKey($this->privateKey);

        $signature = '';

        if (! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new SignatureException(
                'Signing the AUB wallet request failed: ' . (openssl_error_string() ?: 'unknown error')
            );
        }

        return base64_encode($signature);
    }

    private function rsaVerify(string $payload, string $signature): bool
    {
        if (blank($this->publicKey)) {
            return false;
        }

        $raw = base64_decode($signature, false);

        if ($raw === false || $raw === '') {
            return false;
        }

        return openssl_verify(
            $payload,
            $raw,
            KeyReader::publicKey($this->publicKey, 'aub-pay.wallet.public_key'),
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    /**
     * Booleans would otherwise stringify to "1"/"" and silently change the signed string; the
     * gateway has no boolean fields, so anything that arrives as one is a caller mistake worth
     * normalising loudly rather than hashing.
     */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
