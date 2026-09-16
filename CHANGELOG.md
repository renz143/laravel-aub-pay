# Changelog

## Unreleased

First release. Extracted from the AUB Card Cashier integration in `prycegas-api`
(`app/Support/Aub/Signer.php`, `app/Http/Clients/AubPaymentClient.php`, `config/aub.php`,
`app/Http/Controllers/API/AubWebhookController.php`) and widened to cover all three of AUB's APIs.

### Added

- **Cashier rail** — hosted payment page: `createOrder()`, `inquire()`, `refund()`. Ported from the
  production implementation, including the response-code-before-signature ordering and the
  `00`/`S0000` success handling.
- **Wallet rail** — GCash, GrabPay, QRPh, Instapay, Alipay+, WeChat, UnionPay over the XML gateway:
  `charge()`, `query()`, `refund()`, `refundQuery()`, `close()`. New.
- **Card rail** — the direct `/online/v1/*` API with 3-D Secure, pre-authorization, capture, reverse
  and unfreeze. New, and off by default: it carries a PCI-DSS SAQ-D consequence.
- **JWE** (RSA-OAEP-256 + A256CBC-HS512) for PAN/CVV, which the previous implementation deliberately
  omitted because the Cashier rail never needs it.
- Webhook endpoints for both notification formats, each answering in the exact form its gateway
  expects (`SUCCESS` for cashier, `success` for wallet), with `PaymentSucceeded` / `PaymentFailed` /
  `PaymentPending` / `NotificationReceived` events.
- `ResponseCode` enum over the §6.1 table, sorting codes into configuration faults, request faults,
  declines and indeterminate outcomes.
- `Testing\AubPayFake` for correctly-signed fake responses.
- `aub-pay:ping` connectivity check.

### Changed from the `prycegas-api` original

Application-specific behaviour was deliberately left behind, because it is what made the original
unreusable: the PayMongo-shaped return objects, the `reference_number` session id, the `o`/`g`/`p`
PGCM `attach` encoding, and the `OrderService`/Salesforce callbacks. An application wanting the
PayMongo shape writes a thin adapter over `CashierClient`.
