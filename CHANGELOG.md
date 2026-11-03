# Changelog

All notable changes to `teepay/teepay-php` are documented here. This project follows
[Semantic Versioning](https://semver.org/).

## 0.1.0 - 2026-11-03

First release.

- Client `TeePay\TeePay` with API key from the constructor or `TEEPAY_API_KEY`; sandbox/live detected from the key prefix.
- Resources: `whoami()`, `capabilities()`, `collections`, `checkoutSessions`, `controlNumbers`, `disbursements` (create, retrieve, list, listAll; payout quote).
- Automatic idempotency keys for creates, reused across retries; override per call.
- Retries with exponential backoff and `Retry-After` support, only for requests that are safe to repeat.
- Typed exceptions, including `AccountRestrictedError` for `403 account_restricted`.
- Response metadata: request ID, rate-limit headers, idempotent replay flag.
- Webhook verification (`Webhook::verify`) with constant-time comparison and timestamp tolerance.
