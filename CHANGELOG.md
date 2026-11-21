# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

First version of v1 (0.1.0), not yet published.

**v1 is a full rewrite.** Earlier versions of this repository (`jasirilabs/laravel-selcom`, Laravel 9) were written by Bruno Alfred and released under the MIT licence. v1 shares no code with them: it is a clean-room package named `selcom-developers/laravel-selcom`, built on `selcom-developers/selcom-php` and released under the Apache License 2.0. Earlier versions remain in the git history.

### Added

- Laravel 12 (PHP 8.2 to 8.5) and Laravel 13 (PHP 8.3 to 8.5).
- Publishable, environment-driven config (`selcom`): explicit environment with no default, key, secret, vendor, float PIN, optional checkout credentials, base URL, timeouts per profile, logging, webhook, model and reconcile settings.
- A service provider that binds the selcom-php config, clock, digest signer factory, signer, transport, redactor, replay store, webhook verifier, webhook store and client as overridable singletons, and a `Selcom` facade.
- An opt-in webhook route (configurable path and middleware) and controller: verify with the selcom-php verifier on a Laravel cache-backed replay store, store (database or none), acknowledge per the spec (5xx only if storing fails), optionally queue an order-status confirmation. Events: `WebhookReceived`, `WebhookValid`, `WebhookUnsigned`, `WebhookInvalid`, `WebhookStale`, `WebhookReplay`, `OrderStatusConfirmed`.
- Optional, publishable migrations and Eloquent models for orders, payouts and webhook deliveries, and a `FinalStateGuard` (compare-and-set, final states, no failing a checkout order from a poll).
- Artisan commands `selcom:status` (read-only) and `selcom:reconcile` (query only; never resends a payment).
- Logging through a configurable channel with the selcom-php redactor.
- Testing helpers: `Selcom::fake()` with scenario presets drawn from the spec's response vectors, transport-failure presets and assertions.
- Tests that run every case, reject, response scenario and webhook sample of selcom-spec v0.1.0 (copy in `resources/vectors`, recorded in `SPEC_VERSION`, refreshed by `scripts/refresh-spec.sh`).
- GitHub Actions CI over the Laravel and PHP matrix, with Larastan (level 8), tests, a package build and a check that the vectors match the spec, with actions pinned by commit SHA.

### Changed

- Requires `selcom-developers/selcom-php` through a Composer VCS repository on `dev-feat/v1` until selcom-php v0.1.0 is tagged; the constraint then moves to `^0.1`.
