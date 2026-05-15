# Changelog

All notable changes to `shah/parakit` are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
