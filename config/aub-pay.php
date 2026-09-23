<?php

/**
 * AUB (Asia United Bank) payment APIs.
 *
 * AUB resells Wallyt's gateway (`wepayez.com`) under its own brand, and what arrives as one
 * "AUB integration" is really three unrelated APIs. They share a vendor and nothing else — not a
 * host, not a body format, not a signing scheme, not even a notion of what "success" looks like:
 *
 *   cashier  JSON over paymentapi.wepayez.com, detached JWS, hosted payment page. No card data
 *            reaches you, so this rail keeps you in PCI-DSS SAQ-A.
 *   card     Same host and signing, plus JWE-encrypted PAN/CVV. Raw card numbers pass through
 *            your servers — enabling this rail moves you to SAQ-D. Off by default.
 *   wallet   XML over gateway.wepayez.com, signed with a hashed parameter string and a shared
 *            key. GCash, GrabPay, QRPh, Instapay, Alipay+, WeChat Pay. Off by default.
 *
 * Enable only the rails you have been onboarded for; each one is separate paperwork with AUB.
 */
return [

    /*
    |---------------------------------------------------------------------------
    | Cashier rail — hosted payment page (default)
    |---------------------------------------------------------------------------
    */

    /*
     * Gateway host. Production is documented in the API guide §4.4. The UAT host
     * (https://paymentapi-uat.wepayez.com/gateway/payment) appears only in the vendor's Java
     * reference implementation, not in the guide — confirm it with AUB before relying on it.
     */
    'base_url' => env('AUB_BASE_URL', 'https://paymentapi.wepayez.com/gateway/payment'),

    /*
     * Merchant ID assigned by AUB after onboarding. Sent as the `Merchant-Id` header.
     */
    'merchant_id' => env('AUB_MERCHANT_ID'),

    /*
     * Merchant RSA private key used to JWS-sign every outbound request body. You generate it; the
     * matching public key is uploaded to AUB's merchant portal. Accepts either a PEM block or the
     * bare base64 PKCS#8 body, which is what the portal and the vendor's reference code hand out.
     */
    'private_key' => env('AUB_PRIVATE_KEY'),

    /*
     * AUB's public key ("Countersign_pubkey" in the merchant portal), used to verify the JWS
     * signature on responses. A blank or wrong key here makes every *successful* answer
     * unverifiable; errors read through regardless, because AUB does not sign them.
     */
    'jws_public_key' => env('AUB_JWS_PUBLIC_KEY'),

    /*
     * Where AUB sends the customer after payment, and where it POSTs the result. `notify_url`
     * must be publicly reachable and must answer with the literal body `SUCCESS`.
     */
    'callback_url' => env('AUB_CALLBACK_URL'),
    'notify_url' => env('AUB_NOTIFY_URL'),

    /*
     * How long a cashier order stays payable, in minutes (API guide §5.1.3, default 10). This is
     * the only expiry lever AUB gives you — there is no "expire this session now" endpoint. Keep
     * it at or below whatever window your app prunes unpaid orders on, or a cancelled order stays
     * payable in a stale browser tab.
     */
    'validity_period' => (int) env('AUB_VALIDITY_PERIOD', 30),

    /*
     * Seconds to wait on the gateway before giving up.
     */
    'timeout' => (int) env('AUB_TIMEOUT', 30),

    /*
     * Refuse to act on a *successful* response whose JWS signature does not verify. Only ever turn
     * this off against UAT, and only while chasing a key-configuration problem.
     */
    'verify_responses' => (bool) env('AUB_VERIFY_RESPONSES', true),

    /*
    |---------------------------------------------------------------------------
    | Card rail — direct card API (/online/v1/*)
    |---------------------------------------------------------------------------
    |
    | PCI WARNING: this rail accepts raw PANs and CVVs. Turning it on takes you out of SAQ-A and
    | into SAQ-D. Leave it off unless you have made that decision deliberately.
    |
    | It also needs a JWE implementation, because PHP's openssl extension cannot do RSA-OAEP-256
    | (it only offers OAEP with SHA-1 MGF1): `composer require web-token/jwt-library`.
    */

    'card' => [
        'enabled' => (bool) env('AUB_CARD_ENABLED', false),

        /*
         * AUB's encryption public key ("Encrypted_pubkey" in the merchant portal). A *different*
         * key from jws_public_key above — mixing the two is a common first-integration mistake and
         * surfaces as error 07, "encryption or decryption error".
         */
        'jwe_public_key' => env('AUB_JWE_PUBLIC_KEY'),

        /*
         * Where the issuer sends the cardholder back after a 3-D Secure challenge.
         */
        'redirect_url' => env('AUB_CARD_REDIRECT_URL'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Wallet rail — QR and e-wallet gateway
    |---------------------------------------------------------------------------
    |
    | A different API entirely: different host, XML bodies, and a signature computed over the
    | request parameters rather than sent in a header. The credentials below are also separate
    | from the card ones — `mch_id` is not `merchant_id`, and `api_key` is a shared secret, not
    | an RSA key.
    */

    'wallet' => [
        'enabled' => (bool) env('AUB_WALLET_ENABLED', false),

        'base_url' => env('AUB_WALLET_BASE_URL', 'https://gateway.wepayez.com/pay/gateway'),

        'mch_id' => env('AUB_WALLET_MCH_ID'),

        /*
         * The shared signing key, appended to the sorted parameter string before hashing.
         */
        'api_key' => env('AUB_WALLET_API_KEY'),

        /*
         * SHA256 (recommended), MD5, or RSA_1_256. Must match what AUB configured for the
         * merchant — a mismatch surfaces as a signature error on every call.
         */
        'sign_type' => env('AUB_WALLET_SIGN_TYPE', 'SHA256'),

        'notify_url' => env('AUB_WALLET_NOTIFY_URL'),
        'callback_url' => env('AUB_WALLET_CALLBACK_URL'),

        'timeout' => (int) env('AUB_WALLET_TIMEOUT', 30),
    ],

    /*
    |---------------------------------------------------------------------------
    | Hosted checkout — one page in front of the QR Ph and card rails
    |---------------------------------------------------------------------------
    |
    | A checkout session is an order summary at an address of its own
    | (https://checkout.example/{32 hex characters}) where the customer chooses how to pay: QR Ph
    | through the wallet rail, or card through the cashier rail. The package serves the page, opens
    | the payment with AUB, and fires CheckoutSessionPaid once one of them clears.
    |
    | It keeps state, so it ships a migration: run `php artisan migrate` after turning it on.
    */

    'checkout' => [
        'enabled' => (bool) env('AUB_CHECKOUT_ENABLED', false),

        /*
         * Where the page lives. With a URL, its host becomes the route domain and every link the
         * checkout hands out is built on it, so https://checkout.prycegas.com gives
         * https://checkout.prycegas.com/{id} even for a session created from another host. The app
         * must answer for that host. Leave it unset to serve under `path` on the app's own host
         * instead, which is what you want locally.
         */
        'url' => env('AUB_CHECKOUT_URL'),
        'path' => env('AUB_CHECKOUT_PATH', 'checkout'),

        /*
         * Minutes a session stays payable, and minutes each payment it opens — a QR code, a cashier
         * order — stays payable within it.
         *
         * The second is the one that matters. Neither kind can be withdrawn once issued (QR Ph has
         * no close, the cashier rail no cancel), so a customer who abandons a QR code for card can
         * still scan it until it lapses. Keeping it short is the only control there is over paying
         * twice.
         */
        'expires_after' => (int) env('AUB_CHECKOUT_EXPIRES_AFTER', 60),
        'attempt_lifetime' => (int) env('AUB_CHECKOUT_ATTEMPT_LIFETIME', 15),

        /*
         * Seconds between gateway checks while a customer waits on the page. The notification is
         * what normally settles a payment; this is the fallback for when it is late or lost.
         */
        'inquiry_interval' => (int) env('AUB_CHECKOUT_INQUIRY_INTERVAL', 15),

        /*
         * The buttons on offer, by key. Each is a Checkout\PaymentMethod: add a class here to offer
         * another (a GCash redirect, say), then list its key in a session's paymentMethods.
         */
        'methods' => [
            'qrph' => \Prycegas\AubPay\Checkout\Methods\QrPh::class,
            'card' => \Prycegas\AubPay\Checkout\Methods\Card::class,
        ],

        'merchant' => [
            // Falls back to app.name.
            'name' => env('AUB_CHECKOUT_MERCHANT_NAME'),
            // Square image for the header; the name's first letter is shown without one.
            'logo_url' => env('AUB_CHECKOUT_LOGO_URL'),
            // Linked from "By completing your purchase, you agree to …". Omitted when unset.
            'privacy_url' => env('AUB_CHECKOUT_PRIVACY_URL'),
        ],

        /*
         * Hex colours only. `accent_text` is the label on the accent button — dark by default,
         * because white on this green does not meet contrast for body-size text.
         */
        'theme' => [
            'header' => env('AUB_CHECKOUT_HEADER_COLOR', '#144037'),
            'accent' => env('AUB_CHECKOUT_ACCENT_COLOR', '#15a349'),
            'accent_text' => env('AUB_CHECKOUT_ACCENT_TEXT_COLOR', '#11272b'),
        ],

        /*
         * `web` for the session, CSRF and route-model binding the page needs. The throttle is per
         * IP and generous on purpose: a waiting page polls every few seconds.
         */
        'middleware' => ['web', 'throttle:120,1'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Webhook routes
    |---------------------------------------------------------------------------
    |
    | The package ships endpoints that answer AUB's notification in the exact form it expects and
    | then confirm the payment by asking the gateway back. Set `register_routes` to false if you
    | would rather point your own routes at the shipped controllers.
    */

    'webhooks' => [
        'register_routes' => (bool) env('AUB_WEBHOOK_ROUTES', true),

        'prefix' => env('AUB_WEBHOOK_PREFIX', 'aub'),

        /*
         * AUB posts server-to-server from its own infrastructure and carries no credential you can
         * check, so these routes cannot sit behind auth. They are safe to leave public because the
         * notification is treated as a hint, never as proof — see Webhooks\ConfirmsByInquiry.
         * A throttle is still worth keeping.
         */
        'middleware' => ['throttle:60,1'],
    ],

    /*
     * Log channel for gateway diagnostics. Null uses the application default.
     */
    'log_channel' => env('AUB_LOG_CHANNEL'),

];
