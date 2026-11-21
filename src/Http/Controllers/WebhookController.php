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
use SelcomDevelopers\LaravelSelcom\Webhook\WebhookStore;
use SelcomDevelopers\Selcom\Exception\ReplayStoreException;
use SelcomDevelopers\Selcom\Exception\ValidationException;
use SelcomDevelopers\Selcom\Redaction\Redactor;
use SelcomDevelopers\Selcom\Webhook\WebhookPayload;
use SelcomDevelopers\Selcom\Webhook\WebhookVerdict;
use SelcomDevelopers\Selcom\Webhook\WebhookVerifier;

/**
 * The webhook route (spec: webhooks.md, "Handling, step by step").
 *
 *  1. Read the raw body before anything parses it.
 *  2. Verify. INVALID and STALE get 401 (unless the route runs in report_only mode).
 *  3. Deduplicate on (order_id, transid) when a store is configured.
 *  4. Store the delivery (redacted).
 *  5. Acknowledge with 200. A 5xx only when storing failed, so the gateway tries again.
 *  6. Queue the order-status confirmation, when configured. Never fail the response because of it.
 *
 * A webhook is a hint, not proof: this controller never fulfils anything.
 */
final class WebhookController
{
    public function __invoke(
        Request $request,
        WebhookVerifier $verifier,
        WebhookStore $store,
        Dispatcher $events,
        ConfigRepository $config,
        Redactor $redactor,
        LoggerInterface $log,
    ): Response {
        $raw = $request->getContent();
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
        $process = $verdict->shouldProcess()
            || ($reportOnly && ($verdict === WebhookVerdict::Invalid || $verdict === WebhookVerdict::Stale));
        $status = $process ? 200 : $verdict->httpStatus();

        $events->dispatch(new WebhookReceived($verdict, $payload, $redactedHeaders, $redactedBody));
        $events->dispatch($this->verdictEvent($verdict, $payload, $redactedHeaders, $redactedBody));

        if ($verdict === WebhookVerdict::Invalid || $verdict === WebhookVerdict::Stale) {
            $log->warning('selcom.webhook_rejected', [
                'verdict' => $verdict->value,
                'report_only' => $reportOnly,
                'headers' => $redactedHeaders,
                'body' => $redactedBody,
            ]);
        }

        if (!$process) {
            return new Response('', $status);
        }
        if ($payload === null) {
            // Nothing to store or deduplicate on: it is not a JSON object.
            return new Response('', 400);
        }

        try {
            $isNew = $store->store($payload, $verdict, $redactedHeaders, $redactedBody);
        } catch (\Throwable $e) {
            report($e);

            // Storing failed: ask the gateway to deliver again.
            return new Response('', 500);
        }
        if (!$isNew) {
            return new Response('', 200);
        }

        if ($config->get('selcom.webhook.confirm') === true && $payload->orderId !== null) {
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
     * @param array<string, mixed> $headers
     */
    private function verdictEvent(WebhookVerdict $verdict, ?WebhookPayload $payload, array $headers, string $body): object
    {
        return match ($verdict) {
            WebhookVerdict::Valid => new WebhookValid($verdict, $payload, $headers, $body),
            WebhookVerdict::Unsigned => new WebhookUnsigned($verdict, $payload, $headers, $body),
            WebhookVerdict::Invalid => new WebhookInvalid($verdict, $payload, $headers, $body),
            WebhookVerdict::Stale => new WebhookStale($verdict, $payload, $headers, $body),
            WebhookVerdict::Replay => new WebhookReplay($verdict, $payload, $headers, $body),
        };
    }
}
