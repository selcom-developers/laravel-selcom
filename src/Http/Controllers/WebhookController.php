<?php

declare(strict_types=1);

namespace SelcomDevelopers\LaravelSelcom\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use SelcomDevelopers\LaravelSelcom\Events\WebhookInvalid;
use SelcomDevelopers\LaravelSelcom\Events\WebhookReceived;
use SelcomDevelopers\LaravelSelcom\Events\WebhookReplay;
use SelcomDevelopers\LaravelSelcom\Events\WebhookStale;
use SelcomDevelopers\LaravelSelcom\Events\WebhookUnsigned;
use SelcomDevelopers\LaravelSelcom\Events\WebhookValid;
use SelcomDevelopers\LaravelSelcom\Jobs\ConfirmOrderStatus;
use SelcomDevelopers\LaravelSelcom\Webhook\ForgetsDigests;
use SelcomDevelopers\LaravelSelcom\Webhook\WebhookStore;
use SelcomDevelopers\Selcom\Exception\ReplayStoreException;
use SelcomDevelopers\Selcom\Exception\ValidationException;
use SelcomDevelopers\Selcom\Redaction\Redactor;
use SelcomDevelopers\Selcom\Webhook\ReplayStore;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;
use SelcomDevelopers\Selcom\Webhook\WebhookVerifier;

/**
 * The webhook route (spec: webhooks.md, "Handling, step by step").
 *
 *  1. Refuse oversized bodies, then read the raw body before anything parses it.
 *  2. Verify. INVALID and STALE get 401 (unless the route runs in report_only mode).
 *  3. Deduplicate on (order_id, transid) when a store is configured.
 *  4. Store the delivery (redacted).
 *  5. Acknowledge with 200. A 5xx only when storing failed, so the gateway tries again, and the
 *     replay digest is forgotten first so that the redelivery is processed.
 *  6. Fire the events (a throwing listener is reported, never a reason for a 5xx), then queue the
 *     order-status confirmation, when configured.
 *
 * A webhook is a hint, not proof: this controller never fulfils anything.
 */
final class WebhookController
{
    public function __invoke(
        Request $request,
        WebhookVerifier $verifier,
        ReplayStore $replayStore,
        WebhookStore $store,
        Dispatcher $events,
        ConfigRepository $config,
        Redactor $redactor,
        LoggerInterface $log,
    ): Response {
        $raw = $request->getContent();
        $max = $config->get('selcom.webhook.max_body_bytes', 65536);
        if (is_int($max) && $max > 0 && strlen($raw) > $max) {
            return new Response('', 413);
        }

        $headers = $this->headers($request);
        try {
            $verdict = $verifier->verify($headers, $raw);
        } catch (ReplayStoreException $e) {
            // Our own store failed, so there is no verdict: ask the gateway to deliver again.
            report($e);

            return new Response('', 500);
        } catch (\Throwable) {
            // Anything else unexpected is an invalid delivery, never an exception to the gateway.
            $verdict = WebhookVerdict::Invalid;
        }

        $redactedHeaders = $redactor->redactHeaders($headers);
        $redactedBody = $redactor->redactJson($raw);
        $payload = $this->payload($raw);

        $reportOnly = $config->get('selcom.webhook.mode') === 'report_only';
        $refuseUnsigned = $config->get('selcom.webhook.refuse_unsigned') === true;
        $process = $verdict->shouldProcess()
            || ($reportOnly && ($verdict === WebhookVerdict::Invalid || $verdict === WebhookVerdict::Stale));
        if ($verdict === WebhookVerdict::Unsigned && $refuseUnsigned && !$reportOnly) {
            $process = false;
        }
        $status = $process ? 200 : ($verdict === WebhookVerdict::Unsigned ? 401 : $verdict->httpStatus());

        if ($verdict === WebhookVerdict::Invalid || $verdict === WebhookVerdict::Stale || $status === 401) {
            $log->warning('selcom.webhook_rejected', [
                'verdict' => $verdict->value,
                'report_only' => $reportOnly,
                'headers' => $redactedHeaders,
                'body' => $redactedBody,
            ]);
        }

        $fire = fn () => $this->fire($events, $verdict, $payload, $redactedHeaders, $redactedBody);

        if (!$process) {
            $fire();

            return new Response('', $status);
        }
        if ($payload === null) {
            // Nothing to store or deduplicate on: it is not a JSON object.
            $fire();

            return new Response('', 400);
        }

        try {
            $outcome = $store->store($payload, $verdict, $redactedHeaders, $redactedBody);
        } catch (\Throwable $e) {
            report($e);
            $this->forget($replayStore, $headers);

            // Storing failed: ask the gateway to deliver again, and let that redelivery through.
            return new Response('', 500);
        }

        $fire();

        if ($outcome->isNew() && $config->get('selcom.webhook.confirm') === true && $payload->orderId !== null) {
            try {
                $job = new ConfirmOrderStatus($payload->orderId, $payload->transid);
                $connection = $config->get('selcom.webhook.queue.connection');
                $queue = $config->get('selcom.webhook.queue.name');
                if (is_string($connection) && $connection !== '') {
                    $job->onConnection($connection);
                }
                if (is_string($queue) && $queue !== '') {
                    $job->onQueue($queue);
                }
                dispatch($job);
            } catch (\Throwable $e) {
                // The delivery is stored. Confirmation is the merchant's to retry (and selcom:reconcile
                // will find the order), never a reason to make the gateway resend.
                report($e);
            }
        }

        return new Response('', 200);
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function forget(ReplayStore $store, array $headers): void
    {
        $digest = $headers['digest'] ?? null;
        if ($store instanceof ForgetsDigests && is_string($digest) && $digest !== '') {
            $store->forget($digest);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = $values[0] ?? '';
        }

        return $headers;
    }

    private function payload(string $raw): ?WebhookPayload
    {
        try {
            return WebhookPayload::fromRawBody($raw);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Fire WebhookReceived and the verdict event. A listener that throws is reported and ignored:
     * it must not change the acknowledgement of a delivery that is already stored.
     *
     * @param array<string, mixed> $headers
     */
    private function fire(Dispatcher $events, WebhookVerdict $verdict, ?WebhookPayload $payload, array $headers, string $body): void
    {
        $verdictEvent = match ($verdict) {
            WebhookVerdict::Valid => new WebhookValid($verdict, $payload, $headers, $body),
            WebhookVerdict::Unsigned => new WebhookUnsigned($verdict, $payload, $headers, $body),
            WebhookVerdict::Invalid => new WebhookInvalid($verdict, $payload, $headers, $body),
            WebhookVerdict::Stale => new WebhookStale($verdict, $payload, $headers, $body),
            WebhookVerdict::Replay => new WebhookReplay($verdict, $payload, $headers, $body),
        };

        foreach ([new WebhookReceived($verdict, $payload, $headers, $body), $verdictEvent] as $event) {
            try {
                $events->dispatch($event);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
