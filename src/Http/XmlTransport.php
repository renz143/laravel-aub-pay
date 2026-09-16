<?php

namespace Prycegas\AubPay\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prycegas\AubPay\Crypto\ParameterSignature;
use Prycegas\AubPay\Exceptions\AubApiException;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * Transport for the wallet/QR rail — a different gateway in every respect from the card one.
 *
 * XML in and XML out, flat and single-level (§3.2: "First-level node only. No nested nodes."). The
 * signature lives in the body rather than a header, and identity is a `mch_id` field rather than a
 * `Merchant-Id` header.
 *
 * **Success is two-layered, and conflating the layers is the classic bug here.** `status` reports
 * whether the *call* was understood — it is a communication flag, and §1.4 says so explicitly.
 * `result_code` reports whether the *transaction* succeeded. A perfectly successful call about a
 * declined payment returns `status=0` with a non-zero `result_code`, so checking only `status`
 * reads a decline as an approval.
 */
class XmlTransport
{
    private const COMMUNICATION_OK = '0';

    private const BUSINESS_OK = '0';

    public function __construct(
        private readonly ParameterSignature $signature,
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
        private readonly ?string $logChannel = null,
    ) {
    }

    /**
     * @param  array  $parameters  flat request fields; `service` selects the operation
     * @return array the parsed response fields, guaranteed to be a business success
     */
    public function post(array $parameters): array
    {
        $parameters = $this->prepare($parameters);
        $parameters['sign'] = $this->signature->sign($parameters);

        $service = (string) ($parameters['service'] ?? 'unknown');

        try {
            $response = Http::withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
                ->timeout($this->timeout)
                ->withBody($this->toXml($parameters), 'text/xml')
                ->post($this->baseUrl);
        } catch (ConnectionException $e) {
            throw new AubApiException("Could not reach the AUB wallet gateway for {$service}: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new AubApiException(
                "The AUB wallet gateway returned HTTP {$response->status()} for {$service}.",
                null,
                null,
                ['body' => $response->body()],
            );
        }

        return $this->unwrap($this->parseXml($response->body()), $service);
    }

    /**
     * The envelope fields every request carries. Supplied here rather than by each caller so a
     * missing `nonce_str` — which the gateway rejects, and which is easy to forget — cannot happen.
     */
    private function prepare(array $parameters): array
    {
        return array_filter(array_merge([
            'version' => '2.0',
            'charset' => 'UTF-8',
            'sign_type' => $this->signature->signType(),
            'nonce_str' => $this->nonce(),
        ], $parameters), static fn ($value) => $value !== null && $value !== '');
    }

    private function unwrap(array $fields, string $service): array
    {
        $status = (string) ($fields['status'] ?? '');

        if ($status !== self::COMMUNICATION_OK) {
            // A protocol-level rejection is not signed — like the card rail's error replies, there
            // is nothing to verify, and demanding a signature here would hide the message.
            throw new AubApiException(
                $fields['message'] ?? "The AUB wallet gateway rejected the {$service} call (status {$status}).",
                $status !== '' ? $status : null,
                null,
                $fields,
            );
        }

        // The call was understood; only now is there a signature worth checking, and only now does
        // the business outcome mean anything.
        $this->verify($fields, $service);

        $resultCode = (string) ($fields['result_code'] ?? '');

        if ($resultCode !== self::BUSINESS_OK) {
            throw new AubApiException(
                $fields['err_code_des'] ?? $fields['message'] ?? "The AUB wallet {$service} transaction failed.",
                $fields['err_code'] ?? ($resultCode !== '' ? $resultCode : null),
                null,
                $fields,
            );
        }

        return $fields;
    }

    private function verify(array $fields, string $service): void
    {
        if ($this->signature->verify($fields)) {
            return;
        }

        $this->logger()->error('AUB wallet response signature failed verification.', [
            'service' => $service,
            'had_sign' => filled($fields['sign'] ?? null),
        ]);

        throw new AubApiException("The signature on the AUB wallet response to {$service} could not be verified.");
    }

    /**
     * Values are wrapped in CDATA because the gateway's own samples do and because an unescaped
     * `&` in a `notify_url` would otherwise produce XML it rejects. The signature is computed over
     * the raw values, never over this encoding.
     */
    private function toXml(array $parameters): string
    {
        $body = '<xml>';

        foreach ($parameters as $key => $value) {
            $body .= "<{$key}><![CDATA[{$value}]]></{$key}>";
        }

        return $body . '</xml>';
    }

    /**
     * LIBXML_NOCDATA unwraps the CDATA the gateway wraps everything in; LIBXML_NONET forbids the
     * parser from fetching anything over the network. External entity substitution is off by
     * default in modern libxml, and nothing here re-enables it.
     */
    private function parseXml(string $body): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new AubApiException('The AUB wallet gateway returned a body that is not valid XML.', null, null, ['body' => $body]);
        }

        return array_map(static fn ($value) => is_scalar($value) ? (string) $value : $value, (array) $xml);
    }

    private function nonce(): string
    {
        return Str::lower(Str::random(32));
    }

    private function logger(): LoggerInterface
    {
        return $this->logChannel ? Log::channel($this->logChannel) : Log::getFacadeRoot();
    }
}
