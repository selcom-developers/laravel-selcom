# Contributing

Thanks for helping. This is a community Laravel package for the Selcom API gateway, and it is **not affiliated with Selcom Paytech Ltd.**

## Ground rules

- **The spec is the source of truth.** Signing, result codes, retries and webhooks are defined in [selcom-spec](https://github.com/selcom-developers/selcom-spec), and implemented by [selcom-php](https://github.com/selcom-developers/selcom-php). This package is the Laravel layer on top: keep its coupling to selcom-php's public API.
- **Clean room.** Write your own code. Do not copy from Selcom's official clients or from other Selcom libraries. Reading them to understand behaviour is fine.
- **No secrets and no real data.** Tests use made-up values only: phones `2557000000xx`, IDs like `ORD-1001` and `TXN-...`, keys like `test-key-0001`, or the sample values printed in Selcom's public documentation.
- **No automatic retry of payments or writes.** This is a hard rule. Reconciliation is query only.

## Development

Requires PHP 8.2 or later (8.3 for Laravel 13) and Composer.

```
composer install
composer check       # validate, Larastan (level 8), PHPUnit
```

CI runs Laravel 12 on PHP 8.2 to 8.5 and Laravel 13 on PHP 8.3 to 8.5. To try one line locally, pin it as the workflow does (`composer require --no-update illuminate/*:13.* ...` and `orchestra/testbench:11.*`).

## Spec vectors

The tests and the `Selcom::fake()` presets use the copy in `resources/vectors`. To move to another spec tag:

```
scripts/refresh-spec.sh v0.2.0
```

This rewrites `resources/vectors` and `SPEC_VERSION`. Never edit the vectors by hand; CI checks they match the spec at that tag.

## Pull requests

Branch from `main`, keep a pull request to one concern, add tests, and describe what changed and why. Update `CHANGELOG.md` under Unreleased.
