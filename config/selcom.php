<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Selcom
|--------------------------------------------------------------------------
|
| Every value below is read from the environment. Nothing here has a default
| that could send traffic to the wrong place: there is deliberately NO default
| environment. Set SELCOM_ENVIRONMENT to "sandbox" or "production".
*/

return [

    // "sandbox" or "production". No default: a missing value is an error the first
    // time the client is resolved.
    'environment' => env('SELCOM_ENVIRONMENT'),

    // Credentials. The secret and the PIN never appear in logs, dumps or exceptions.
    'key' => env('SELCOM_API_KEY'),
    'secret' => env('SELCOM_API_SECRET'),
    'vendor' => env('SELCOM_VENDOR'),
    // The float PIN: needed by payout operations only (utility payments, wallet cash-in, vendor balance).
    'pin' => env('SELCOM_PIN'),

    // Optional second credential set, used by checkout operations only. Leave both unset to use the
    // credentials above for everything. The vendor falls back to the one above.
    'checkout' => [
        'key' => env('SELCOM_CHECKOUT_API_KEY'),
        'secret' => env('SELCOM_CHECKOUT_API_SECRET'),
        'vendor' => env('SELCOM_CHECKOUT_VENDOR'),
    ],

    // A simulator or proxy instead of the environment's preset URL. Its own explicit choice.
    'base_url' => env('SELCOM_BASE_URL'),

    'timezone' => env('SELCOM_TIMEZONE', 'Africa/Dar_es_Salaam'),

    // Opt-in repeats of READ-ONLY operations after a "never sent" transport error. Payments and
    // writes are never repeated, whatever this says. 0 to 5.
    'max_not_sent_retries' => (int) env('SELCOM_MAX_NOT_SENT_RETRIES', 0),

    // Seconds. The long read timeout on payments is deliberate: timing out early does not stop the
    // payment, it only turns a known answer into an unknown one.
    'timeouts' => [
        'query_connect' => (float) env('SELCOM_TIMEOUT_QUERY_CONNECT', 10),
        'query_read' => (float) env('SELCOM_TIMEOUT_QUERY_READ', 10),
        'payment_connect' => (float) env('SELCOM_TIMEOUT_PAYMENT_CONNECT', 15),
        'payment_read' => (float) env('SELCOM_TIMEOUT_PAYMENT_READ', 180),
    ],

    // Logging of gateway calls through one of your logging channels, with the selcom-php redactor
    // (secrets, PIN, Authorization, Digest, phones, e-mails and names are masked).
    // Off while "channel" is null.
    'logging' => [
        'channel' => env('SELCOM_LOG_CHANNEL'),
        'level' => env('SELCOM_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks (opt-in)
    |--------------------------------------------------------------------------
    |
    | Off by default: no route exists until "enabled" is true. A webhook is a hint, not proof.
    | The controller verifies, stores, acknowledges, and (optionally) queues an order-status
    | confirmation. It never fulfils anything itself.
    */
    'webhook' => [
        'enabled' => (bool) env('SELCOM_WEBHOOK_ENABLED', false),

        // REQUIRED when enabled, and it must carry an unguessable token, for example
        // "selcom/webhook/7f3c9a...". With it empty or the bare "selcom/webhook" the route is NOT
        // registered and a warning is logged; the application keeps working.
        'path' => env('SELCOM_WEBHOOK_PATH'),
        'route_name' => 'selcom.webhook',
        // Keep CSRF-protected groups ("web") out of this list: the gateway sends no CSRF token.
        // The default throttles by client IP; the limit is "rate_limit" requests a minute.
        'middleware' => [\Illuminate\Routing\Middleware\ThrottleRequests::class . ':selcom-webhook'],
        'rate_limit' => (int) env('SELCOM_WEBHOOK_RATE_LIMIT', 120),

        // Bodies larger than this are refused with 413 before anything parses or logs them.
        'max_body_bytes' => (int) env('SELCOM_WEBHOOK_MAX_BODY', 65536),

        // true: a delivery with no Digest header (UNSIGNED) is refused with 401 and not processed.
        // The spec accepts UNSIGNED (order-status confirmation is then the only protection), so the
        // default is false; turn this on once you have confirmed that your deliveries are signed.
        'refuse_unsigned' => (bool) env('SELCOM_WEBHOOK_REFUSE_UNSIGNED', false),

        // The secret that signs deliveries. Null: the checkout secret, else the API secret.
        // (Which key Selcom signs webhooks with is an open question in the spec.)
        'secret' => env('SELCOM_WEBHOOK_SECRET'),
        'window_seconds' => (int) env('SELCOM_WEBHOOK_WINDOW', 300),

        // "enforce": INVALID and STALE get HTTP 401 and are not processed (the safe default).
        // "report_only": log and fire events with the verdict, but process and answer 200 anyway.
        // Use it only to find out which key Selcom signs with in your sandbox.
        'mode' => env('SELCOM_WEBHOOK_MODE', 'enforce'),

        // The cache store behind the replay guard. Must be shared between workers (redis,
        // database, memcached, file): "array" forgets everything per process. Null: the default store.
        'cache_store' => env('SELCOM_WEBHOOK_CACHE_STORE'),
        'cache_prefix' => 'selcom:webhook:',

        // Where a verified delivery is stored before it is acknowledged:
        //   "database": the selcom_webhook_deliveries table (publish the migrations), deduplicated on
        //               (order_id, transid) by a unique key. The durable guard.
        //   "none":     nothing is stored here: listen to the events and store it yourself.
        'store' => env('SELCOM_WEBHOOK_STORE', 'none'),

        // After storing, queue a job that calls checkout.order-status for the order and fires
        // OrderStatusConfirmed. Use a real queue connection: the sync driver runs it in the request.
        'confirm' => (bool) env('SELCOM_WEBHOOK_CONFIRM', false),
        // While the order is PENDING or AMBIGUOUS the job asks again every "confirm_interval_seconds"
        // (the spec's 3 minutes) for at most "confirm_window_minutes"; after that selcom:reconcile
        // takes over.
        'confirm_interval_seconds' => (int) env('SELCOM_WEBHOOK_CONFIRM_INTERVAL', 180),
        'confirm_window_minutes' => (int) env('SELCOM_WEBHOOK_CONFIRM_WINDOW', 30),
        'queue' => [
            'connection' => env('SELCOM_WEBHOOK_QUEUE_CONNECTION'),
            'name' => env('SELCOM_WEBHOOK_QUEUE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models and migrations (opt-in)
    |--------------------------------------------------------------------------
    |
    | Nothing is migrated for you. To use the Eloquent models, the final-state guard and the
    | reconcile command, publish the migrations (php artisan vendor:publish --tag=selcom-migrations),
    | edit them if you like, and migrate. Swap in your own model classes below.
    */
    'models' => [
        'order' => \SelcomDevelopers\LaravelSelcom\Models\SelcomOrder::class,
        'payout' => \SelcomDevelopers\LaravelSelcom\Models\SelcomPayout::class,
        'webhook_delivery' => \SelcomDevelopers\LaravelSelcom\Models\SelcomWebhookDelivery::class,
    ],

    'tables' => [
        'orders' => 'selcom_orders',
        'payouts' => 'selcom_payouts',
        'webhook_deliveries' => 'selcom_webhook_deliveries',
    ],

    'database_connection' => env('SELCOM_DB_CONNECTION'),

    // Defaults for php artisan selcom:reconcile.
    'reconcile' => [
        'older_than_minutes' => 3,
        'limit' => 100,
        // Pending orders are polled for this long (from creation), then flagged `needs_review` and
        // left alone. Rows that stay unpaid for days should not poll forever.
        'pending_max_age_minutes' => 2880,
        // Orders the gateway does not know are polled for this long (from creation), then flagged
        // `needs_review` and left alone.
        'not_found_max_age_minutes' => 60,
        // A payout still "submitting" may have a request in flight: it is not queried until the
        // payment timeout (connect + read) plus this margin has passed.
        'submitting_margin_seconds' => 30,
    ],
];
