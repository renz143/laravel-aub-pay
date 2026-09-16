<?php

namespace Prycegas\AubPay\Requests;

use Illuminate\Http\Request;

/**
 * The browser fingerprint 3-D Secure v2 requires (API guide; `javascriptEnabled` is mandatory).
 *
 * These values describe the **cardholder's browser**, not your server, and most of them can only
 * be read in JavaScript on the checkout page — `screen.width`, `screen.colorDepth`,
 * `new Date().getTimezoneOffset()`, `navigator.javaEnabled()`. Collect them client-side, post them
 * with the payment form, and hand them here. `fromRequest()` covers the two that the HTTP request
 * itself carries.
 *
 * Getting these wrong does not fail the payment outright; it degrades the issuer's risk assessment
 * and pushes more customers into a challenge.
 */
class BrowserInformation
{
    /** Challenge window sizes: 1) 250x400 2) 390x400 3) 500x600 4) 600x400 5) fullscreen. */
    public const WINDOW_FULLSCREEN = 5;

    public function __construct(
        public readonly bool $javascriptEnabled = true,
        public readonly ?string $acceptHeader = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $language = null,
        public readonly ?string $ip = null,
        public readonly ?bool $javaEnabled = false,
        public readonly ?int $screenWidth = null,
        public readonly ?int $screenHeight = null,
        public readonly ?int $screenColorDepth = null,
        public readonly ?int $timezone = null,
        public readonly ?int $challengeWindow = self::WINDOW_FULLSCREEN,
    ) {
    }

    /**
     * Fill in what the incoming HTTP request already tells us; the caller supplies the rest from
     * the client-side values it collected.
     */
    public static function fromRequest(Request $request, array $clientValues = []): self
    {
        return new self(
            javascriptEnabled: (bool) ($clientValues['javascriptEnabled'] ?? true),
            acceptHeader: $request->header('Accept'),
            userAgent: $request->userAgent(),
            language: $clientValues['language'] ?? $request->getPreferredLanguage(),
            ip: $request->ip(),
            javaEnabled: (bool) ($clientValues['javaEnabled'] ?? false),
            screenWidth: isset($clientValues['screenWidth']) ? (int) $clientValues['screenWidth'] : null,
            screenHeight: isset($clientValues['screenHeight']) ? (int) $clientValues['screenHeight'] : null,
            screenColorDepth: isset($clientValues['screenColorDepth']) ? (int) $clientValues['screenColorDepth'] : null,
            timezone: isset($clientValues['timezone']) ? (int) $clientValues['timezone'] : null,
            challengeWindow: isset($clientValues['challengeWindow']) ? (int) $clientValues['challengeWindow'] : self::WINDOW_FULLSCREEN,
        );
    }

    public function toArray(): array
    {
        // javascriptEnabled and javaEnabled are kept even when false: they are booleans the issuer
        // reads, and "absent" does not mean the same thing to it as "false".
        return array_filter([
            'javascriptEnabled' => $this->javascriptEnabled,
            'javaEnabled' => $this->javaEnabled,
            'acceptHeader' => $this->acceptHeader,
            'userAgent' => $this->userAgent,
            'language' => $this->language,
            'ip' => $this->ip,
            'screenWidth' => $this->screenWidth,
            'screenHeight' => $this->screenHeight,
            'screenColorDepth' => $this->screenColorDepth,
            'timezone' => $this->timezone,
            'challengeWindow' => $this->challengeWindow,
        ], static fn ($value) => $value !== null && $value !== '');
    }
}
