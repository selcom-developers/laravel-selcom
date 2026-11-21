# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-21

First release of v1.

### Added

- Laravel 12 (PHP 8.2 to 8.5) and Laravel 13 (PHP 8.3 to 8.5).
- Publishable, environment-driven config (`selcom`): explicit environment with no default, key, secret, vendor, float PIN, optional checkout credentials, base URL, timeouts per profile, logging, webhook, model and reconcile settings.
- A service provider that binds the selcom-php config, clock, digest signer factory, signer, transport, redactor, replay store, webhook verifier, webhook store and client as overridable singletons, and a `Selcom` facade.
- An opt-in webhook route (configurable path and middleware) and controller: verify with the selcom-php verifier on a Laravel cache-backed replay store, store (database or none), acknowledge per the spec (5xx only if storing fails), optionally queue an order-status confirmation. Events: `WebhookReceived`, `WebhookValid`, `WebhookUnsigned`, `WebhookInvalid`, `WebhookStale`, `WebhookReplay`, `OrderStatusConfirmed`.
- Optional, publishable migrations and Eloquent models for orders, payouts and webhook deliveries, and a `FinalStateGuard` (compare-and-set, final states, no failing a checkout order from a poll).
- Artisan commands `selcom:status` (read-only) and `selcom:reconcile` (query only; never resends a payment).
- Logging through a configurable channel with the selcom-php redactor.
- Testing helpers: `Selcom::fake()` with scenario presets drawn from the spec's response vectors, transport-failure presets and assertions.
- Tests that run every case, reject, response scenario and webhook sample of selcom-spec v0.2.1 (copy in `resources/vectors`, recorded in `SPEC_VERSION`, refreshed by `scripts/refresh-spec.sh`).
- GitHub Actions CI over the Laravel and PHP matrix, with Larastan (level 8), tests, a package build and a check that the vectors match the spec, with actions pinned by commit SHA.
- Webhook route behaviour: events fire after the delivery is stored (a throwing listener is reported, never a 5xx); the replay digest is forgotten when a delivery is answered with 5xx; a stronger verdict (VALID over UNSIGNED) upgrades the stored row and queues confirmation again; a per-client throttle, a body size limit, an optional refusal of unsigned deliveries, and no route unless `SELCOM_WEBHOOK_PATH` carries a token.
- `ConfirmOrderStatus` re-checks `PENDING` and `AMBIGUOUS` orders every 3 minutes for a bounded window and applies the answer through the guard; `OrderStatusConfirmed` carries the guard's outcome; `OrderSucceeded` and `PayoutSucceeded` fire once, at the transition to success.
- Guard checks: the paid amount and currency must match the order, a result must belong to the record (`ResultMismatchException`), and a submit `FAILED` is not finalised for a payout last seen as not found or ambiguous.
- The webhook throttle limiter is registered even when routes are cached; the confirmation job is unique per order, backs off after an exception and fails on errors that retrying cannot fix; pending orders are polled for a bounded time; reconcile carries on when a listener throws; a missing webhook path logs a warning and skips the route instead of stopping the application.
- Reconcile bounds: `not_found` orders are polled for a bounded time and records flagged for review are skipped; `submitting` payouts wait out the payment timeout.
- Events serialise redacted results and payloads; the fake's recorded requests mask the PIN, secrets and card tokens.

### Changed

- Requires `selcom-developers/selcom-php` `^0.1`. Until selcom-php is on Packagist, add its repository to your application's `repositories`.
- The cache replay store follows selcom-php's `ReplayStore` contract (keep until max(now, signed time) + window) and turns a cache failure into `ReplayStoreException`; the webhook controller answers 500 for it so the gateway delivers again.

[Unreleased]: https://github.com/selcom-developers/laravel-selcom/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/selcom-developers/laravel-selcom/releases/tag/v0.1.0
