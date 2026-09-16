<?php

namespace Prycegas\AubPay\Requests;

use InvalidArgumentException;
use Prycegas\AubPay\Crypto\JweEncrypter;

/**
 * Raw card data for the direct card rail.
 *
 * ⚠ **Everything about this class is PCI-scoped.** A PAN reaching your application is what moves
 * you from SAQ-A to SAQ-D. Do not log an instance of this, do not persist it, do not put it in a
 * queued job payload, and do not include it in an exception report. It exists to be constructed,
 * encrypted and discarded within a single request.
 *
 * `pan` and `securityCode` are JWE-encrypted with AUB's *encryption* public key before they go on
 * the wire; the expiry fields travel in clear, which is the gateway's design, not a choice here.
 */
class CardDetails
{
    public function __construct(
        public readonly string $pan,
        public readonly string $expirationMonth,
        public readonly string $expirationYear,
        public readonly ?string $securityCode = null,
    ) {
        if (! preg_match('/^\d{12,19}$/', $pan)) {
            throw new InvalidArgumentException('A card number must be 12-19 digits, with no spaces or dashes.');
        }

        if (! preg_match('/^\d{1,2}$/', $expirationMonth) || (int) $expirationMonth < 1 || (int) $expirationMonth > 12) {
            throw new InvalidArgumentException('The expiry month must be 1-12.');
        }

        if (! preg_match('/^\d{4}$/', $expirationYear)) {
            throw new InvalidArgumentException('The expiry year must be four digits, e.g. 2027.');
        }

        if ($securityCode !== null && ! preg_match('/^\d{3,4}$/', $securityCode)) {
            throw new InvalidArgumentException('A security code must be 3 or 4 digits.');
        }
    }

    public function toArray(JweEncrypter $encrypter): array
    {
        return array_filter([
            'pan' => $encrypter->encrypt($this->pan),
            // Zero-padded: the gateway's own reference implementation sends a two-character month,
            // and a bare "1" for January has been seen rejected as malformed.
            'expirationMonth' => str_pad($this->expirationMonth, 2, '0', STR_PAD_LEFT),
            'expirationYear' => $this->expirationYear,
            'securityCode' => $this->securityCode === null ? null : $encrypter->encrypt($this->securityCode),
        ], static fn ($value) => $value !== null);
    }

    /**
     * Keep the PAN out of stack traces, `dd()` output and log lines that stringify the object.
     */
    public function __debugInfo(): array
    {
        return [
            'pan' => '****' . substr($this->pan, -4),
            'expirationMonth' => $this->expirationMonth,
            'expirationYear' => $this->expirationYear,
            'securityCode' => $this->securityCode === null ? null : '***',
        ];
    }
}
