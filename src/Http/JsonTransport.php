<?php

namespace Prycegas\AubPay\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prycegas\AubPay\Crypto\JwsSigner;
use Prycegas\AubPay\Enums\ResponseCode;
use Prycegas\AubPay\Exceptions\AubApiException;

/**
 * Sign, send, check, verify, unwrap — the transport shared by the Cashier and direct-card rails.
 *
 * The one thing to understand before changing anything here is **the order of the last two steps**,
 * which is deliberate and was arrived at the hard way. See unwrap().
 */
class JsonTransport
{
    /**
     * Response codes the gateway uses for "approved".
     *
     * The API guide is internally inconsistent: §5.1.4 and the §6.1 error table both document `00`,
     * while the refund sample in §5.3.4 shows `S0000`. Both are accepted until AUB confirms which
     * the live gateway returns — treating a success as a failure would strand a customer who has
     * already been charged.
     */
    private const SUCCESS_CODES = [ResponseCode::Success, ResponseCode::SuccessAlternate];

    public function __construct(
        private readonly JwsSigner $signer,
        private readonly string $baseUrl,
        private readonly ?string $merchantId,
        private readonly int $timeout = 30,
        private readonly bool $verifyResponses = true,
        private readonly ?string $logChannel = null,
    ) {
    }

    /**
     * @param  string  $path  e.g. `/cashier/v1/payment`
     * @return array the decoded `{code, message, data}` envelope, guaranteed to be a success
     */
    public function post(string $path, array $payload): array
    {
        // The signature covers the exact bytes on the wire, so the body is encoded once here and
        // handed to the HTTP client as a raw string. Letting it re-encode the array would sign one
        // document and send another — the failure mode is a uniform error 06 on every request,
        // which looks like a key problem and is not.
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $requestId = $this->requestId();

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json;charset=utf-8',
                'Accept' => 'application/json',
                'Authorization' => $this->signer->sign($body),
                'Merchant-Id' => (string) $this->merchantId,
                'Customer-Request-Id' => $requestId,
                'Accept-Language' => 'en-US',
            ])
                ->timeout($this->timeout)
                ->withBody($body, 'application/json')
                ->post($this->url($path));
        } catch (ConnectionException $e) {
            // A timeout is not a decline: the gateway may have processed the payment and simply
            // failed to tell us. Surfaced as indeterminate so callers inquire rather than assume.
            throw new AubApiException(
                "Could not reach AUB for {$path}: {$e->getMessage()}",
                ResponseCode::TransactionTimeout->value,
                $requestId,
            );
        }

        return $this->unwrap($response, $requestId, $path);
    }

    private function unwrap(Response $response, string $requestId, string $path): array
    {
        $raw = $response->body();

        if ($response->failed()) {
            throw new AubApiException(
                "AUB returned HTTP {$response->status()} for {$path}.",
                null,
                $requestId,
                ['body' => $raw],
            );
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new AubApiException(
                "AUB returned a non-JSON body for {$path}.",
                null,
                $requestId,
                ['body' => $raw],
            );
        }

        $code = (string) data_get($decoded, 'code', '');

        // The rejection is reported *before* the signature is checked, and deliberately so: AUB
        // does not sign its error replies. A correctly signed request with a bad Merchant-Id comes
        // back `{"code":"99","message":"System error"}` with no `Authorization` response header at
        // all — verified against the live gateway. Verifying first therefore turned every gateway
        // error into "the signature could not be verified", which buries the one thing a caller
        // can act on. Nothing is conceded by trusting a failure claim: forging one can only deny a
        // payment that was already refused, which anyone able to intercept the response could do
        // by dropping it instead.
        if (! in_array(ResponseCode::tryFrom($code), self::SUCCESS_CODES, true)) {
            throw new AubApiException(
                data_get($decoded, 'message') ?: "AUB rejected the request to {$path} (code {$code}).",
                $code,
                $requestId,
                $decoded,
            );
        }

        // A *success*, though, is exactly what an attacker would want us to believe, so it is only
        // believed once AUB's own key has vouched for the bytes it arrived in.
        $this->verifySignature($response, $raw, $requestId, $path);

        return $decoded;
    }

    /**
     * A response we cannot authenticate is not a response we act on — an unverified "paid" is
     * exactly the thing an attacker would want us to believe. The escape hatch exists only for
     * chasing key misconfiguration against UAT.
     */
    private function verifySignature(Response $response, string $raw, string $requestId, string $path): void
    {
        if (! $this->verifyResponses) {
            return;
        }

        if ($this->signer->verify($raw, (string) $response->header('Authorization'))) {
            return;
        }

        $this->log()->error('AUB response signature failed verification.', [
            'path' => $path,
            'customer_request_id' => $requestId,
            'had_authorization_header' => filled($response->header('Authorization')),
        ]);

        throw new AubApiException(
            "The signature on AUB's response to {$path} could not be verified.",
            null,
            $requestId,
        );
    }

    /**
     * Every AUB request carries a unique id; it is echoed on the response and is what their support
     * traces a transaction by. Reusing one is error 10, "[Customer-Request-Id] IS EXIST".
     */
    private function requestId(): string
    {
        return mb_substr(Str::replace('-', '', (string) Str::uuid()), 0, 64);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    private function log(): \Psr\Log\LoggerInterface
    {
        return $this->logChannel ? Log::channel($this->logChannel) : Log::getFacadeRoot();
    }
}
