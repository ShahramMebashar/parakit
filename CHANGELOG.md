# Changelog

All notable changes to `froshly/parakit` are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project follows [Semantic Versioning](https://semver.org/).

## [0.9.0] — 2026-05-21

### Added
- QiCard driver: hosted-page card payment (Visa / Mastercard) for the Iraqi market, with 3D Secure handled inside QiCard's hosted form. Implements `SupportsStatusCheck`, `SupportsRefund` (full + partial), and `SupportsCancel`. Webhook authenticity is provable — every QiCard notification is verified against the configured RSA-2048 public key (`OPENSSL_ALGO_SHA256`, algorithm-pinned). When no public key is configured, parakit logs `parakit.qicard.webhook.unverified` and falls back to a server-to-server status re-check rather than trusting the inbound body. `parakit:doctor` validates the required QiCard config fields; `parakit:webhook:simulate qicard --sign-with=key.pem` emits a signed test notification end-to-end.
- `PaymentLogger` is now wired into `AbstractGateway::charge`: every charge outcome (success or failure) writes a redacted audit-trail row to `payment_logs` carrying gateway, action, duration, sanitized request/response, correlation id, and the error message on failure. Gated by the existing `parakit.logging.enabled` flag. Previously the table was created but never populated by the package itself.

### Fixed
- **FIB no longer retries 4xx responses.** `FibClient` and `FibTokenCache` previously threw `GatewayUnavailableException` for any non-2xx, which `AbstractGateway` treats as retryable — so a bad credential burned 3 charge attempts and 3 circuit-breaker failure ticks. 5xx stays retryable; 4xx now surfaces as the non-retryable `FibApiException` (carries the HTTP status). Mirrors the FastPay / QiCard pattern.
- **Webhook record-and-apply is now atomic.** The webhook controller used to commit the dedupe event row first and then run `applyToTransaction` in a separate transaction; a worker crash between the two left an orphaned event row that caused subsequent gateway retries to dedupe-200 without applying. `WebhookProcessor::process()` now wraps both steps in a single DB transaction.

### Security
- **Webhook amount=0 no longer free-passes the mismatch check.** A `Paid` webhook reporting amount 0 against a non-zero transaction is now treated as a mismatch (logged in default mode, rejected in `reject` mode). Closes the silent-Paid path where a buggy gateway response could mark a row Paid for the originally charged amount.
- **`PayloadRedactor` now does word-boundary matching.** Splits keys on `_ - / whitespace` and camelCase boundaries before matching against the configured tokens, so gateway-specific credential keys (`store_password`, `refund_secret_key`, `basic_token`, `client_secret`, `api_key`, `public_key`) are redacted by default — without false positives on `secretary`, `keyboard`, `discard`. The default `redact_keys` list now includes `key`, `cvv`, `cvc`, `pan`, and `pin`.
- **`QiCardSignatureVerifier` no longer caches parsed public keys.** A static cache survived Octane requests, meaning a rotated public key kept validating against the old key until the worker recycled. PEM parsing is microseconds; the cache was not worth the staleness risk.

## [0.8.0] — 2026-05-20

### Added
- Receipts: generate branded PDF receipts (and refund receipts) from a transaction via the new `Receipt` facade — `Receipt::for($tx)->template('classic')->generate()`. Ships three runtime-selectable templates (`modern` default, `classic`, `minimal`), all RTL-aware with en/ar/ckb translations. Deliver via `stream()`, `download()`, or `save($disk)`. Customer name/email/phone are overridable with a `CustomerDetails` DTO or a plain array, falling back to transaction metadata. Bundles `barryvdh/laravel-dompdf`; templates are publishable with `--tag=parakit-views`. Preview template designs without a real payment via `php artisan parakit:receipt:preview` (`--all` dumps every template × type × locale).
- Events: hook into the full payment lifecycle from your app — listen for `PaymentInitiated`, `PaymentSucceeded`, `PaymentFailed`, `PaymentCancelled`, `PaymentRefunded`, `WebhookReceived`, `WebhookVerificationFailed`, `GatewayTimeout`, and `CircuitOpened` to wire notifications, ledgers, analytics, or alerting without patching the package. Gateway timeouts now raise a typed `GatewayTimeoutException` that carries the timeout context, and circuit-breaker trips fire `CircuitOpened`. See `docs/reference/events.md`.
- Documentation site: VitePress-powered docs under `docs/`, deployed to GitHub Pages via `.github/workflows/docs.yml` — covers installation, configuration, every gateway, and topic guides (charging, webhooks, refunds, receipts, reliability, multi-tenant, custom gateways, testing).

### Changed
- `parakit:doctor` now validates ZainCash v2 required configuration fields.
- `parakit:simulate-webhook` accepts additional transaction options and covers more gateway payload shapes.

## [0.7.1] — 2026-05-19

### Added
- Nass Wallet driver: hosted-portal charge, transaction status check, and callback webhook handling, with driver registration. (#8)

> Supersedes the unreleased `0.7.0` tag, which was removed because a merge dropped closing braces in `PaymentManager` and the `nasswallet` config block, leaving it unparseable.

## [0.6.0] — 2026-05-19

### Added
- FastPay driver: hosted-page charge, transaction status check, refund, and IPN webhook handling, with driver registration. (#7)

## [0.5.1] — 2026-05-17

### Security
- Webhook amount integrity: `WebhookProcessor` now compares a `Paid` webhook's amount against the stored transaction amount. A non-zero mismatch is logged as `parakit.webhook.amount_mismatch`; the new `parakit.webhooks.on_amount_mismatch` setting (`log` default, or `reject`) controls whether the transition is still applied. A reported amount of `0` is treated as "not reported" and never flagged. (#5)

## [0.5.0] — 2026-05-17

### Added
- Nass Pay driver: bearer-token cache with automatic re-login on 401, typed HTTP client, charge flow with driver registration, transaction status check, and webhook handling re-verified via `checkStatus`.
- Nass `responseCode` maps to `PaymentStatus` and `PaymentErrorCode`, plus a currency-code map.

### Changed
- FIB driver: completed field coverage against the official create-payment spec and corrected the gateway against current FIB docs.

## [0.4.0] — 2026-05-16

### Added
- ZainCash v2 rewrite: OAuth2 `client_credentials` token cache, v2 HTTP client (init/inquiry/reverse), hosted-page redirect charge, transaction inquiry, full reversal/refund, and webhook/redirect callback verification.
- Decode-only JWT verifier and v2 config block for ZainCash.

### Security
- CI workflow now declares explicit `permissions` (code-scanning alert fix).

## [0.3.0] — 2026-05-15

### Changed
- Renamed the package namespace to `Froshly` and migrated the test suite accordingly.
- `composer.json` now supports Laravel 13 with updated Testbench compatibility.

## [0.2.0] — 2026-05-15

### Added
- `Payment::resolveMerchantUsing()` — register a callback that supplies gateway config at request time, so multi-tenant apps can source per-merchant credentials from a database (or any store) without declaring them in `config/parakit.php`.
- Octane safety: `PaymentManager` resolved-driver cache is flushed after every request via `RequestHandled` (and Octane's `RequestTerminated`), so tenant credentials never leak across requests on a reused worker.

### Fixed
- `FibTokenCache` cache key is now scoped per OAuth realm + client (`base_url` + `client_id`). Previously a hardcoded `parakit:fib:token` key meant two FIB configs shared a cached token.

## [0.1.0] — 2026-05-14

### Added
- Core `PaymentManager` with driver resolver, memoisation, and `extend()` for custom drivers.
- `Payment` facade and fluent `Payment::for($order)->driver()->amount()->charge()` builder.
- `final readonly` DTOs — `PaymentRequest`, `PaymentResponse`, `PaymentError`, `WebhookPayload`, `RefundRequest`, `RefundResponse`.
- Enums — `Currency` (IQD/USD), `PaymentStatus` (with state-machine transitions), `PaymentErrorCode`, `Gateway`.
- Capability contracts — `PaymentGateway`, `SupportsRefund`, `SupportsStatusCheck`, `SupportsTokenization`.
- `AbstractGateway` with idempotency cache, retry-with-jitter, circuit breaker, and correlation-ID propagation.
- FIB driver: OAuth2 token cache, typed HTTP client, charge with QR/deep-link/readable-code, status, refund, and webhook verification by re-fetching status.
- ZainCash driver: HS256-pinned JWT helper (alg-confusion guard), hosted-page redirect charge, status, JWT-verified webhook.
- DB schema — `payment_transactions` (ULID PK), `payment_webhook_events` (unique `(gateway, event_id)`), `payment_logs`.
- Webhook controller with replay protection, DB-level idempotency, locked state-machine apply, redacted `WebhookVerificationFailed` events.
- Domain events — `PaymentInitiated`, `PaymentSucceeded`, `PaymentFailed`, `PaymentCancelled`, `PaymentRefunded`, `WebhookReceived`, `WebhookVerificationFailed`, `GatewayTimeout`, `CircuitOpened`.
- `WebhookProcessor` and `PaymentLogger` with `PayloadRedactor` (Luhn-gated PAN detection).
- Console commands — `parakit:install`, `parakit:doctor`, `parakit:sweep-pending`, `parakit:test-charge`, `parakit:webhook:simulate`, `parakit:logs:prune`.
- Pending-sweeper auto-scheduled every 5 minutes; logs:prune auto-scheduled daily.
- Translations — English, Arabic, Kurdish Sorani.
- Pest test suite with 104 tests / 223 assertions and PHPStan level 6 (with larastan).

### Security
- Mandatory webhook signature verification with `hash_equals`.
- TLS verification on by default for every gateway HTTP client.
- Secrets redacted before they hit `payment_logs`.
- Replay protection on webhooks via `parakit.webhooks.tolerance_seconds`.
- `X-Correlation-Id` validated against a strict ULID/base64url-ish regex.
- `Authorization`/`Cookie`/`X-Api-Key` headers stripped from `WebhookVerificationFailed` events.
- `firebase/php-jwt ^7.0` (clean of advisory PKSA-y2cr-5h3j-g3ys that affects 6.x).

### Known limitations
- Orphan webhook events (`processed_at IS NULL`, no local tx) are preserved but not auto-replayed; a v0.2 reconciler is planned.
- PaymentLogger cache may grow large on busy systems — `parakit:logs:prune` is scheduled daily and configurable via `parakit.logging.retention_days`.
- Tokenization (saved payment methods) is roadmap (v1.0).
