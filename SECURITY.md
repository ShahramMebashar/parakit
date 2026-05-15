# Security Policy

**Report vulnerabilities privately via GitHub Security Advisories** — on this
repository, open the *Security* tab → *Report a vulnerability*. This routes
to the maintainer with audit-logged access controls and never appears in
public issues.

(A `security@parakit.dev` mailbox is planned alongside the v1.0 release;
until that domain is monitored, GitHub Security Advisories is the
authoritative channel.)

- Public disclosure window: **90 days** from acknowledgement.
- Supported versions: the **latest two minor releases**.
- CVEs (when applicable) are published in `CHANGELOG.md`.

## Guarantees

- **Webhook signature verification has no off-switch.** No config flag, no env var, no driver override disables it.
- **Constant-time comparison via `hash_equals`** for HMAC and JWT signature checks.
- **TLS verification is on by default** for every gateway HTTP client — `verify: false` is never shipped.
- **Secrets are redacted before they hit `payment_logs`**, by configured key names + a Luhn-gated PAN regex.
- **Replay protection** rejects webhooks whose `occurredAt` is older than `parakit.webhooks.tolerance_seconds` (default 300s).
- **DB-level webhook idempotency** via a unique index on `(gateway, event_id)` — race-safe under concurrent delivery.
- **Algorithm pinning** on JWT decode (HS256 only for ZainCash) to defend against `alg: none` confusion attacks.
- **Correlation IDs are validated** against `^[A-Za-z0-9_-]{8,64}$` to prevent log-injection via client-supplied headers.

## What to report

- Authentication / signature-verification bypasses
- Idempotency-failure leading to double-charge or double-refund
- Webhook-replay vulnerabilities
- Information disclosure (secret/PII leakage in logs, events, or responses)
- Denial-of-service via webhook abuse, retry storms, or circuit-breaker bypass

## What's out of scope for v0.1

- Per-merchant credential rotation tooling (planned v0.3)
- Encrypted-at-rest token cache (currently relies on the host's cache-store ACLs)
- Rate-limit tuning for the public webhook URL (use Laravel's `api` group throttle or your edge proxy)
