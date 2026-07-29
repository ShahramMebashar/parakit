# Security Policy

## Reporting A Vulnerability

Report suspected vulnerabilities through
[GitHub private vulnerability reporting](https://github.com/ShahramMebashar/parakit/security/advisories/new).
Private reporting is enabled for this repository.

Do not open a public issue, discussion, or pull request containing vulnerability
details before a fix is available.

Please include:

- The affected Parakit version, Laravel version, gateway, and configuration.
- The security impact and the conditions required to reproduce it.
- Reproduction steps or a minimal proof of concept.
- Relevant logs or payloads with credentials, tokens, payment details, and
  personal data removed.
- Any known workaround or mitigation.

The maintainer will triage the report privately, coordinate a fix and release,
and agree on disclosure timing with the reporter. Confirmed vulnerabilities
will be published as GitHub Security Advisories. A CVE will be requested when
appropriate, and release notes will identify the patched version.

## Supported Versions

Parakit follows semantic versioning. Security fixes are provided for the latest
minor line of the current major release.

| Version | Supported |
| --- | --- |
| Latest `1.x` minor line | Yes |
| Earlier `1.x` minor lines | No |
| `<1.0` | No |

At present, the supported line is `1.0.x`. Users should upgrade to the newest
patch release before reporting an issue that may already be fixed. Parakit
supports only the Laravel and PHP versions declared in `composer.json`; an
upstream runtime reaching end of security support may be removed in a Parakit
major release.

## Security Model

### Webhook Authenticity

No shipped gateway trusts an unauthenticated callback body as authoritative
payment state:

- ZainCash callbacks require an HS256 JWT verified with the configured API key.
  The accepted algorithm is pinned.
- QiCard callbacks require an RSA-SHA256 `X-Signature` when a public key is
  configured. Without a public key, Parakit logs a warning and replaces the
  callback body with a server-to-server status response.
- FIB, Nass Pay, Nass Wallet, and FastPay callbacks carry no usable provider
  signature. Parakit reads only the transaction identifier and re-fetches the
  authoritative state from the provider API.

A failed signature check or failed authoritative recheck is rejected. Custom
gateways are responsible for implementing an equivalent trust boundary.

### Replay And Idempotency

- Webhook timestamps outside the configured positive or negative clock-skew
  window are rejected. The default
  `parakit.webhooks.tolerance_seconds` value is `300`.
- A database unique index on `(gateway, event_id)` prevents concurrent duplicate
  webhook processing.
- Charge and refund retries use stable provider identifiers where the provider
  supports them. An uncertain outcome remains pending rather than being
  represented as a terminal failure.
- FIB create requests are not retried because FIB does not accept a
  merchant-supplied idempotency identifier.

These controls require a shared, durable database and cache in multi-worker
deployments.

### Amount Verification

For a `Paid` webhook, Parakit compares the reported amount with the stored
transaction amount. Every mismatch, including a reported amount of zero, is
logged as `parakit.webhook.amount_mismatch`.

The default `parakit.webhooks.on_amount_mismatch=log` policy records the
mismatch but still applies the transition for backward compatibility. Set it
to `reject` when payment state must fail closed on an amount mismatch.

### Sensitive Data

- `payment_logs` request and response payloads are recursively redacted using
  configured key components, sensitive URL query parameters, and Luhn-valid
  PAN detection.
- Package-owned persisted gateway payloads are stored and redacted by default.
  Set `PARAKIT_STORE_RAW_PAYLOADS=false` to store no raw payloads.
- Setting `PARAKIT_REDACT_RAW_PAYLOADS=false` deliberately stores provider
  payloads without Parakit's redaction. Applications enabling this are
  responsible for access control, encryption, retention, and privacy
  compliance.
- Fresh `PaymentResponse` and `RefundResponse` objects contain the provider
  response in memory. Application logging and event listeners must handle that
  data as sensitive.
- Standard credential-bearing headers are redacted in
  `WebhookVerificationFailed` events.

Redaction is defense in depth, not a substitute for avoiding unnecessary
collection of payment or personal data.

### Transport And Credentials

- Shipped HTTP clients use Laravel's default TLS certificate verification and
  do not set `verify: false`.
- Gateway hosts are configurable. Applications must use the provider's correct
  production HTTPS endpoints and a valid system trust store.
- OAuth and gateway tokens are cached in the host application's configured
  cache store. Parakit scopes token keys by merchant identity, but does not
  encrypt cache values. The cache must be private, access-controlled, and
  encrypted at rest when required by the deployment's threat model.
- Credentials must be supplied through protected application configuration or
  a secret manager and must never be committed to source control.

## Deployment Responsibilities

Applications using Parakit should:

- Keep Parakit, Laravel, PHP, and Composer dependencies on supported,
  security-patched versions.
- Configure the QiCard public key when available.
- Use `PARAKIT_WEBHOOK_ON_AMOUNT_MISMATCH=reject` for strict settlement
  integrity.
- Apply suitable rate limiting and edge protections to the public webhook
  route. Parakit uses the application's configured webhook middleware
  (`api` by default) but does not impose a universal rate limit.
- Protect the database and cache, run scheduled reconciliation commands, and
  monitor Parakit security warnings.
- Restrict access to logs, stored payloads, receipts, and exported diagnostics.
- Rotate credentials immediately after suspected exposure.

## Scope

Please report flaws in Parakit that could affect confidentiality, integrity, or
availability, including:

- Authentication, signature, or provider-reverification bypasses.
- Double-charge or double-refund paths.
- Webhook replay, deduplication, state-transition, or amount-integrity flaws.
- Secret, payment-data, or personal-data disclosure.
- Cross-merchant credential or cache isolation failures.
- Retry or circuit-breaker behavior that can cause unsafe provider requests.

The following are outside Parakit's security boundary unless caused by this
package:

- Vulnerabilities in a payment provider's systems or merchant dashboard.
- Compromised application hosts, databases, caches, or provider credentials.
- Custom gateway implementations.
- Application-specific authorization, receipt delivery, retention, and rate
  limiting.
- Findings that require testing against accounts or data you do not own.

Use sandbox accounts and data you control. Do not perform denial-of-service
testing, access another user's data, or disrupt live payment systems.
