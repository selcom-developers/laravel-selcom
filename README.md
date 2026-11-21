# laravel-selcom

Laravel integration for the Selcom API gateway, built on [`selcom-developers/selcom-php`](https://github.com/selcom-developers/selcom-php): environment-driven config, container bindings you can replace, an opt-in webhook route, optional models with a final-state guard, artisan commands, logging and testing helpers.

> **v1 is a full rewrite.** Earlier versions of this repository (`jasirilabs/laravel-selcom`, Laravel 9) were written by Bruno Alfred and released under the MIT licence. v1 shares no code with them: it is written from scratch against the shared spec and released under the Apache License 2.0. Earlier versions stay in the git history.
>
> **Not affiliated with Selcom Paytech Ltd.** This is a community project. "Selcom" names the API it talks to. For API access, credentials, contracts and support, go to Selcom: <https://developers.selcommobile.com/>. Where this README and Selcom's portal disagree about the API, the portal wins.

You keep control at every layer. The signer, the transport, the client, the replay store, the webhook store, the models and the commands are separate pieces; each is a container binding you can swap, and each works without the others. The rules the client follows (signing, result codes, retries, webhooks) live in the shared spec: <https://github.com/selcom-developers/selcom-spec> (tested against `v0.2.1`, recorded in [`SPEC_VERSION`](SPEC_VERSION)).

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Using the client](#using-the-client)
- [What is bound in the container](#what-is-bound-in-the-container)
- [Webhooks](#webhooks)
- [Events](#webhook-and-domain-events)
- [Models, migrations and the final-state guard](#models-migrations-and-the-final-state-guard)
- [Artisan commands and reconciliation](#artisan-commands-and-reconciliation)
- [Idempotency and retries](#idempotency-and-retries)
- [Logging](#logging)
- [Testing](#testing)
- [Security](#security)

## Install

Requirements: Laravel 12 (PHP 8.2 to 8.5) or Laravel 13 (PHP 8.3 to 8.5). CI runs exactly those pairs.

```
composer require selcom-developers/laravel-selcom
```

`selcom-php` is pulled in as a dependency. Composer reads `repositories` only from your own application's `composer.json`, so make sure it can find that package there:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/selcom-developers/selcom-php" }
    ]
}
```

The HTTP layer is PSR-18 and found by discovery; a Laravel application already has Guzzle, so nothing else is needed.

The service provider and the `Selcom` facade register themselves through package discovery. Publish the config:

```
php artisan vendor:publish --tag=selcom-config
```

## Configuration

Everything comes from the environment, through `config/selcom.php`:

```dotenv
SELCOM_ENVIRONMENT=sandbox          # "sandbox" or "production". REQUIRED, no default
SELCOM_API_KEY=...
SELCOM_API_SECRET=...
SELCOM_VENDOR=...                   # the vendor (till) ID
SELCOM_PIN=...                      # float PIN: payout operations only

# optional second credential set, used by checkout operations only
SELCOM_CHECKOUT_API_KEY=
SELCOM_CHECKOUT_API_SECRET=
SELCOM_CHECKOUT_VENDOR=

SELCOM_BASE_URL=                    # a simulator or proxy; its own explicit choice
SELCOM_TIMEOUT_QUERY_CONNECT=10
SELCOM_TIMEOUT_QUERY_READ=10
SELCOM_TIMEOUT_PAYMENT_CONNECT=15
SELCOM_TIMEOUT_PAYMENT_READ=180
```

- **There is no default environment**, and so no production default. A missing or unknown value is a `ConfigurationException` the first time the client is resolved. Nothing is resolved at boot, so `config:cache` and `package:discover` work without credentials.
- A missing key, secret or vendor fails the same way. Giving only half of the checkout pair is an error, not a silent fallback.
- Timeouts are per profile and no request runs without one. The long read timeout on payments is deliberate: timing out early does not stop the payment, it turns a known answer into an unknown one.
- The secret, the PIN and the full API key never appear in `print_r`, `var_dump`, logs or exception messages of the objects this package binds.

The webhook, logging, model and reconcile settings are described in their own sections below; the config file comments say the same.

## Using the client

The `Selcom` facade proxies the selcom-php `Client`, so [its README](https://github.com/selcom-developers/selcom-php#operations) documents every operation, request, result and error. One method per operation: `createOrder`, `createOrderMinimal`, `walletPayment`, `cardPayment`, `orderStatus`, `cancelOrder`, `listOrders`, `storedCards`, `deleteCard`, `utilityPayment`, `utilityLookup`, `utilityQuery`, `walletCashin`, `walletNameLookup`, `walletCashinQuery`, `vendorBalance`.

```php
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\Selcom\Request\OrderStatusRequest;
use SelcomDevelopers\Selcom\Request\UtilityPaymentRequest;
use SelcomDevelopers\Selcom\Status;

// Persist the transid BEFORE you call (see "Idempotency and retries"), then:
$result = Selcom::utilityPayment(new UtilityPaymentRequest(
    transid: 'TXN-UTL-0001', utilityCode: 'LUKU', utilityRef: '01234567891', amount: 8000,
));

match ($result->status) {
    Status::Success => /* paid */ null,
    Status::Failed => /* it did not happen */ null,
    Status::Pending, Status::Ambiguous => /* unknown: query later, never resubmit */ null,
    Status::NotFound => /* only on queries */ null,
};

$status = Selcom::orderStatus(new OrderStatusRequest('ORD-1001'));
```

You can also type-hint `SelcomDevelopers\Selcom\Client` and let the container inject it.

## What is bound in the container

Each of these is a singleton you can replace in your own service provider (bind after this package's provider, or use `$this->app->extend()`):

| Abstract | Default | Replace it to |
|---|---|---|
| `SelcomDevelopers\Selcom\Config` | built from `config/selcom.php` | change how settings are loaded |
| `Psr\Clock\ClockInterface` | system clock (only bound if you have not bound one) | fix time in tests |
| `...\Support\DigestSignerFactory` | HS256 with the credential's secret | swap the digest step (the request signer around it is a pure function) |
| `SelcomDevelopers\Selcom\Signing\DigestSigner` | the factory applied to your credentials | sign something yourself |
| `SelcomDevelopers\Selcom\Transport\Transport` | selcom-php's PSR-18 transport | use a proxy, a custom HTTP client, request logging, a fake |
| `SelcomDevelopers\Selcom\Redaction\Redactor` | selcom-php's redactor | change what is masked in logs |
| `SelcomDevelopers\Selcom\Webhook\ReplayStore` | `CacheReplayStore` on a Laravel cache store | keep replay state somewhere else |
| `SelcomDevelopers\Selcom\Webhook\WebhookVerifier` | secret, clock, replay store, window from config | change verification |
| `...\Webhook\WebhookStore` | none or database, by config | keep deliveries in your own store |
| `SelcomDevelopers\Selcom\Client` (alias `selcom`) | all of the above, plus logging if configured | replace the whole client |

```php
$this->app->singleton(Transport::class, fn () => new MyProxyTransport());
```

## Webhooks

A webhook is a **hint, not proof**. The package never fulfils an order because a webhook said so: it verifies, stores, acknowledges, and (optionally) queues an `order-status` confirmation, whose result your listener acts on.

Opt in in `.env`:

```dotenv
SELCOM_WEBHOOK_ENABLED=true
SELCOM_WEBHOOK_PATH=selcom/webhook/7f3c9a1e...   # put an unguessable token in the path
```

and send the URL on every order as `webhookUrl` (the request classes base64-encode it for you). Use HTTPS. The route is `POST` on that path, named `selcom.webhook`, outside the `web` group (the gateway sends no CSRF token). Add middleware, for example throttling, in `selcom.webhook.middleware`.

What the controller does, in order (the spec's handling steps):

1. Reads the raw body before anything parses it, and verifies it with selcom-php's `WebhookVerifier`. If the replay store itself fails (a cache outage), it answers **500** so the gateway delivers again.
2. Fires `WebhookReceived`, then the verdict event.
3. `INVALID` and `STALE` answer **401** and stop. `REPLAY` answers 200 and stops. `VALID` and `UNSIGNED` continue.
4. Stores the delivery (redacted) through the `WebhookStore`. A duplicate of an already stored `(order_id, transid)` is acknowledged and stops.
5. Acknowledges with **200**. If storing fails it answers **500** so the gateway tries again, and nothing else.
6. If `SELCOM_WEBHOOK_CONFIRM=true`, queues `ConfirmOrderStatus`, which calls `checkout.order-status` and fires `OrderStatusConfirmed`. A queue problem is reported but never changes the acknowledgement.

Unknown or unparseable fields in an unsigned body (an odd `amount`, say) never make the handler fail: the amount is simply absent from the payload. A body that is not a JSON object is answered with 400 and not stored.

| Setting | Meaning |
|---|---|
| `selcom.webhook.secret` | Secret that signs deliveries. Default: the checkout secret, else the API secret. Which key Selcom signs webhooks with is an open question in the spec; confirm it in your sandbox. |
| `selcom.webhook.window_seconds` | Accepted clock distance, default 300. |
| `selcom.webhook.mode` | `enforce` (default) or `report_only`: log and process with the verdict but never answer 401. Use `report_only` only to find out which key signs deliveries. |
| `selcom.webhook.cache_store` | Cache store behind the replay guard. Use a shared store (redis, database, memcached, file): `array` forgets per process, and PHP-FPM is a new process per request. |
| `selcom.webhook.store` | `none` (default) or `database` (needs the migrations). The unique `(order_id, transid)` key is the durable guard. |
| `selcom.webhook.confirm` | Queue the order-status confirmation. Use a real queue connection: the `sync` driver runs it inside the request. |

The replay guard is one atomic cache `add` per digest. It keeps each digest until max(now, signed time) + window, the time the verifier hands it.

### Webhook and domain events

All in `SelcomDevelopers\LaravelSelcom\Events`:

| Event | When |
|---|---|
| `WebhookReceived` | Every delivery that reaches the route, whatever the verdict |
| `WebhookValid`, `WebhookUnsigned`, `WebhookInvalid`, `WebhookStale`, `WebhookReplay` | One per verdict, fired after `WebhookReceived` |
| `OrderStatusConfirmed` | The queued confirmation got the order's status from the gateway. **Act on this, not on the webhook.** |
| `ConflictingResultFlagged` | A result contradicted a final state; the state was kept and the record flagged `needs_review` |
| `RecordReconciled` | `selcom:reconcile` queried a record and applied the answer |

Webhook events carry `verdict`, `payload` (a `WebhookPayload`: `orderId`, `transid`, `paymentStatus`, `amountTzs`, ...), and redacted `headers` and `redactedBody` that are safe to log. The payload holds personal data as received.

```php
Event::listen(OrderStatusConfirmed::class, function (OrderStatusConfirmed $e) {
    if ($e->result->status === Status::Success) {
        // check $e->result->order()?->amount against YOUR order, then fulfil, idempotently
    }
});
```

## Models, migrations and the final-state guard

Off unless you publish the migrations. Nothing is migrated for you:

```
php artisan vendor:publish --tag=selcom-migrations
php artisan migrate
```

Three tables (names, connection and model classes are configurable): `selcom_orders`, `selcom_payouts` and `selcom_webhook_deliveries`. Edit the published migrations freely.

| Model | Unique key | States |
|---|---|---|
| `SelcomOrder` | `order_id` | `pending`, `success` (final), `failed` (final), `not_found` |
| `SelcomPayout` | `transid` (`kind` is `utility` or `cashin`) | `submitting`, `pending`, `ambiguous`, `success` (final), `failed` (final), `not_found` |
| `SelcomWebhookDelivery` | `dedupe_key` (`order_id\|transid`) | the stored, redacted delivery |

`Services\FinalStateGuard` is the one place that applies a result, so a direct response, a webhook confirmation, a poll and the reconcile command cannot disagree. Each change is a compare-and-set under a row lock.

- `SUCCESS` is final. Once a record is final, nothing changes its state; a conflicting result is ignored and the record is flagged `needs_review` (and `ConflictingResultFlagged` fires).
- `PENDING` never overwrites anything.
- **A poll never marks a checkout order failed.** `order-status` `FAILED` describes the latest attempt, and an unexpired order can still be paid. Cancel it, and only after a confirmed cancel call `closeOrderAfterCancel($order, $cancelResult)`.
- A payout is marked `failed` only from the gateway's direct answer to the payment (`SOURCE_SUBMIT`). A `FAILED` from a status query is recorded and flagged for a person: Selcom does not publish the codes for rejected queries, so the spec says not to pay again on one such result.

```php
$guard = app(FinalStateGuard::class);
$guard->applyPayoutResult($payout, $result, FinalStateGuard::SOURCE_SUBMIT);
```

## Artisan commands and reconciliation

```
php artisan selcom:status ORD-1001                         # checkout.order-status
php artisan selcom:status TXN-UTL-0001 --type=utility      # utilitypayment.query
php artisan selcom:status TXN-CSH-0001 --type=cashin --raw # walletcashin.query, redacted raw body

php artisan selcom:reconcile                               # orders and payouts quiet for 3+ minutes
php artisan selcom:reconcile --older-than=10 --type=payouts --limit=50
php artisan selcom:reconcile --dry-run
```

`selcom:status` is read-only. `selcom:reconcile` is **query only**: it has no code path that sends a payment, an order, a cancel or any other write. It finds records in `pending` (orders) or `submitting`, `pending`, `ambiguous` (payouts) whose `updated_at` is older than N minutes (default 3, the spec's first-poll delay), asks the gateway with `orderStatus`, `utilityQuery` or `walletCashinQuery`, and applies the answer through the guard. A query that gets no answer leaves the record exactly as it was, and the next run asks again.

Schedule it:

```php
Schedule::command('selcom:reconcile')->everyThreeMinutes()->withoutOverlapping();
```

A payout the gateway reports as `not_found` is the only one that may be submitted again, with the same `transid`; that decision is yours. After a bounded number of tries, hand a stuck payout to a person with its `transid` and `reference` for Selcom support.

## Idempotency and retries

The client is stateless between calls. Staying safe is your side of the contract:

- **The ID is the idempotency key.** `transid` identifies a payout or payment attempt, `order_id` a checkout order. Generate it deterministically from your own records, so a crash and restart produces the same ID.
- **Persist the ID, with a `submitting` state, before calling the gateway** (`SelcomPayout` with its unique `transid` does this), so a retried job cannot create a second transaction. Then call, then apply the direct answer through the guard.
- **Nothing is retried automatically.** The package never retries a payment or a write, not after a timeout and not with the same ID, and it adds no retry middleware to any HTTP client. Do not add one for `POST`s. Read-only operations may repeat after a "never sent" error only if you set `SELCOM_MAX_NOT_SENT_RETRIES`.
- `TransportException` with `UnknownOutcome` is `AMBIGUOUS`: wait at least 3 minutes and query with the same ID. **Never mint a new ID while an outcome is unknown.**
- Do not put the job that submits a payment on a queue with automatic retries unless it is safe to run twice, which means it checks its own persisted state first.

## Logging

```dotenv
SELCOM_LOG_CHANNEL=stack     # any channel from config/logging.php; unset = no logging
SELCOM_LOG_LEVEL=info
```

Each call is logged (`selcom.response`, or `selcom.transport_error` with the failure class) with method, URL, headers, body, HTTP status and duration, all passed through the selcom-php redactor: secrets, PIN, `Authorization`, `Digest`, card tokens, phone numbers, e-mail addresses and names are masked, and so is the wallet `utilityref`. Pass anything else you store or log through `app(Redactor::class)` too.

## Testing

`Selcom::fake()` swaps the network for a fake transport. The real client keeps running (signing, classification), so your tests exercise the real path; nothing can reach the gateway by accident, and an operation with no planned response fails loudly.

```php
use SelcomDevelopers\LaravelSelcom\Facades\Selcom;
use SelcomDevelopers\LaravelSelcom\Testing\Scenarios;
use SelcomDevelopers\Selcom\Operation;

$fake = Selcom::fake([
    'checkout.order-status' => 'completed',                      // a scenario name
    'walletcashin.process' => ['pending', 'success'],            // a sequence; the last repeats
    'utilitypayment.process' => Scenarios::unknownOutcome(),     // a transport failure
    'vendor.balance' => fn ($request) => ['resultcode' => '000', 'data' => [['balance' => '1,000']]],
]);

// ... run your code ...

Selcom::assertSent(Operation::CheckoutOrderStatus, fn ($r) => $r->param('order_id') === 'ORD-1001');
Selcom::assertNotSent(Operation::WalletCashinProcess);
Selcom::assertSentCount(1);
```

Scenario presets are drawn from the spec's response vectors (`resources/vectors/responses`): use the vector's own name (`success`, `in-progress-111`, `ambiguous-999`, `failure`, `not-found-404`, `gateway-error-no-json`; for order status also `completed`, `cancelled`, `rejected`, `empty-data` and more), or the short aliases `pending`, `ambiguous`, `failed`, `not-found`, `no-verdict`. `Scenarios::notSent()` and `Scenarios::unknownOutcome()` give the two transport failures; `Scenarios::body([...])` gives a body of your own.

Running this package's own tests:

```
composer install
composer check      # validate, Larastan (level 8), PHPUnit
```

The suite runs every case and reject in `signing.json` (20 and 11), every response scenario (120) and every webhook sample (15) from the copy of the spec vectors in `resources/vectors`, without network. `scripts/refresh-spec.sh [tag]` refreshes that copy and rewrites `SPEC_VERSION`; CI checks the copy is byte-identical to the spec at that tag.

## Security

- No secret is ever written to a log, a dump, an exception message or a stored webhook; the stored body and headers are redacted.
- Keep `.env` out of version control. Report problems privately: see [SECURITY.md](SECURITY.md).

## Licence

Apache License 2.0, see [LICENSE](LICENSE). Earlier versions (before v1) by Bruno Alfred, MIT.
